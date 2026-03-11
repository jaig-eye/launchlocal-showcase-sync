<?php
/**
 * Plugin Name:  LaunchLocal Showcase Sync
 * Plugin URI:   https://launchlocal.io
 * Description:  Syncs LaunchLocal Custom Object (Showcase) records to WordPress with SEO optimisation, taxonomy support, and two-way sync.
 * Version:      4.5.1
 * Author:       LaunchLocal
 * Author URI:   https://launchlocal.io
 * Text Domain:  ghl-showcase-sync
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GHL_SYNC_VERSION', '4.5.1' );
define( 'GHL_SYNC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'GHL_SYNC_URL',     plugin_dir_url( __FILE__ ) );
define( 'GHL_SYNC_SLUG',    'ghl-showcase-sync' );

spl_autoload_register( function ( string $class ): void {
	$prefix = 'GHL\\ShowcaseSync\\';
	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;
	$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
	$file     = GHL_SYNC_PATH . 'includes' . DIRECTORY_SEPARATOR . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->load_includes();
		$this->register_hooks();
	}

	private function load_includes(): void {
		require_once GHL_SYNC_PATH . 'includes/class-api-client.php';
		require_once GHL_SYNC_PATH . 'includes/class-post-type.php';
		require_once GHL_SYNC_PATH . 'includes/class-seo-engine.php';
		require_once GHL_SYNC_PATH . 'includes/class-sync-engine.php';
		require_once GHL_SYNC_PATH . 'includes/class-back-sync-engine.php';
		require_once GHL_SYNC_PATH . 'includes/class-cron-manager.php';
		require_once GHL_SYNC_PATH . 'includes/class-github-updater.php';
		require_once GHL_SYNC_PATH . 'admin/class-settings-page.php';
	}

	private function register_hooks(): void {
		( new GithubUpdater( __FILE__, GHL_SYNC_VERSION ) )->register();

		PostType::instance()->register();

		if ( is_admin() ) {
			( new \GHL\ShowcaseSync\Admin\SettingsPage() )->register();
		}

		// Forward-sync (GHL → WP) AJAX handlers.
		add_action( 'wp_ajax_ghl_verify_connection',        [ $this, 'ajax_verify_connection'        ] );
		add_action( 'wp_ajax_ghl_save_connected_transient', [ $this, 'ajax_save_connected_transient' ] );
		add_action( 'wp_ajax_ghl_run_sync',                 [ $this, 'ajax_run_sync'                 ] );
		add_action( 'wp_ajax_ghl_get_pending',        [ $this, 'ajax_get_pending'        ] );
		add_action( 'wp_ajax_ghl_save_field_map',     [ $this, 'ajax_save_field_map'     ] );

		// Back-sync (WP → GHL) AJAX handlers.
		add_action( 'wp_ajax_ghl_run_back_sync',  [ $this, 'ajax_run_back_sync'  ] );
		add_action( 'wp_ajax_ghl_get_wp_posts',   [ $this, 'ajax_get_wp_posts'   ] );

		// User search AJAX (for publisher dropdown).
		add_action( 'wp_ajax_ghl_search_users',   [ $this, 'ajax_search_users'   ] );

		// Cron: forward sync (GHL → WP).
		add_action( 'ghl_scheduled_sync',      [ SyncEngine::class,     'run_cron' ] );
		// Cron: back-sync (WP → GHL) — fires on the same schedule, 30 s offset.
		add_action( 'ghl_scheduled_back_sync', [ BackSyncEngine::class, 'run_cron' ] );

		// One-time origin migration — stamps _ghl_origin on existing untagged posts.
		add_action( 'init', [ SyncEngine::class, 'maybe_migrate_origins' ] );

		// Self-healing cron: reschedule both events if cleared without deactivation.
		// Reads from the autoloaded 'cron' option — no extra DB query.
		add_action( 'init', [ CronManager::class, 'maybe_reschedule' ] );

		register_activation_hook(   __FILE__, [ CronManager::class, 'activate'   ] );
		register_deactivation_hook( __FILE__, [ CronManager::class, 'deactivate' ] );
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	public function ajax_verify_connection(): void {
		$this->verify_ajax_request();
		$result = ApiClient::make()->verify_connection();
		if ( is_wp_error( $result ) ) {
			delete_transient( 'ghl_connection_verified' );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		} else {
			// Save verified transient for 7 days so badge persists across sessions.
			set_transient( 'ghl_connection_verified', 1, 7 * DAY_IN_SECONDS );
			wp_send_json_success( $result );
		}
	}

	public function ajax_save_connected_transient(): void {
		$this->verify_ajax_request();
		set_transient( 'ghl_connection_verified', 1, 7 * DAY_IN_SECONDS );
		wp_send_json_success( [ 'ok' => true ] );
	}

	public function ajax_run_sync(): void {
		$this->verify_ajax_request();
		$batch_size = (int) ( $_POST['batch_size'] ?? 0 );
		$offset     = (int) ( $_POST['offset']     ?? 0 );
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$result = SyncEngine::run( $batch_size, $offset );
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

	public function ajax_get_pending(): void {
		$this->verify_ajax_request();
		$result = SyncEngine::get_all_records_with_status();
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

	public function ajax_save_field_map(): void {
		$this->verify_ajax_request();
		$raw     = wp_unslash( $_POST['field_map'] ?? '' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => 'Invalid field map data.' ] );
		}
		$allowed_types = [ 'post', 'meta', 'image_single', 'image_gallery', 'taxonomy' ];
		$clean = array_values( array_filter( array_map( function( $row ) use ( $allowed_types ) {
			if ( ! is_array( $row ) ) return null;
			return [
				'ghl'  => sanitize_key( $row['ghl'] ?? '' ),
				'wp'   => sanitize_key( $row['wp']  ?? '' ),
				'type' => in_array( $row['type'] ?? '', $allowed_types, true ) ? $row['type'] : 'meta',
			];
		}, $decoded ), fn( $r ) => $r && $r['ghl'] && $r['wp'] ) );

		update_option( 'ghl_sync_field_map', wp_json_encode( $clean ), false );
		wp_send_json_success( [ 'message' => 'Field map saved.', 'count' => count( $clean ) ] );
	}

	public function ajax_run_back_sync(): void {
		$this->verify_ajax_request();
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// batch_size from request, fallback to global setting, fallback to 5.
		$batch_size_post    = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : -1;
		$batch_size_setting = (int) get_option( 'ghl_sync_batch_size', 0 );
		if ( $batch_size_post > 0 ) {
			$batch_size = $batch_size_post;
		} elseif ( $batch_size_setting > 0 ) {
			$batch_size = $batch_size_setting;
		} else {
			$batch_size = 5; // safe default when nothing is configured
		}
		$result = BackSyncEngine::run_backlog_batch( $batch_size );
		is_wp_error( $result )
			? wp_send_json_error( [ 'message' => $result->get_error_message() ] )
			: wp_send_json_success( $result );
	}

	public function ajax_get_wp_posts(): void {
		$this->verify_ajax_request();
		$items = BackSyncEngine::get_all_wp_posts_with_status();
		wp_send_json_success( [ 'items' => $items, 'total' => count( $items ) ] );
	}

	public function ajax_search_users(): void {
		$this->verify_ajax_request();
		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$page   = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$per    = 10;

		$args = [
			'number'  => $per,
			'offset'  => ( $page - 1 ) * $per,
			'orderby' => 'capabilities', // roles first
			'order'   => 'DESC',
			'fields'  => [ 'ID', 'display_name', 'user_login' ],
		];
		if ( $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'display_name', 'user_email' ];
		}

		$users      = get_users( $args );
		$total      = (int) ( new \WP_User_Query( array_merge( $args, [ 'count_total' => true, 'number' => -1 ] ) ) )->get_total();
		$formatted  = array_map( fn( $u ) => [
			'id'    => $u->ID,
			'label' => $u->display_name . ' (' . $u->user_login . ')',
		], $users );

		wp_send_json_success( [ 'users' => $formatted, 'total' => $total, 'page' => $page ] );
	}

	private function verify_ajax_request(): void {
		if (
			! isset( $_POST['_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'ghl_sync_nonce' )
		) {
			wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
	}
}

Plugin::instance();
