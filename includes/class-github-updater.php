<?php
/**
 * GitHub-based auto-updater for LaunchLocal Showcase Sync.
 *
 * Hooks into WordPress's update system so sites running this plugin
 * receive update notices whenever a new GitHub release is published.
 *
 * Also adds plugin-row action links for:
 *   - "Check for Update"  — forces an immediate update check.
 *   - "Uninstall Plugin"  — wipes all settings/transients/cron then deletes plugin files.
 */

declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GithubUpdater {

	private const GITHUB_REPO = 'jaig-eye/launchlocal-showcase-sync';
	private const CACHE_KEY   = 'ghl_sync_github_release';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

	/** Every option key the plugin stores — kept in one place for uninstall. */
	private const ALL_OPTIONS = [
		'ghl_sync_token',
		'ghl_sync_location_id',
		'ghl_sync_schema_key',
		'ghl_sync_cron_schedule',
		'ghl_sync_cron_offset',
		'ghl_sync_batch_size',
		'ghl_sync_debug',
		'ghl_sync_publisher_id',
		'ghl_sync_delete_missing',
		'ghl_sync_obey_changes',
		'ghl_sync_exclude_draft',
		'ghl_sync_delete_on_draft',
		'ghl_sync_field_map',
		'ghl_sync_last_log',
		'ghl_back_sync_last_log',
		'ghl_seo_override_enabled',
		'ghl_seo_title_pattern',
		'ghl_seo_desc_pattern',
		'ghl_seo_img_name_pattern',
		'ghl_seo_img_alt_pattern',
		'ghl_seo_img_title_pattern',
		'ghl_seo_auto_fill_keywords',
	];

	private string $slug;
	private string $plugin_file;
	private string $version;

	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename( $plugin_file );
		$this->version     = $version;
	}

	public function register(): void {
		// Auto-update hooks.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update'  ]        );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info'    ], 10, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'purge_cache'    ], 10, 2 );

		// Plugin-row action links.
		add_filter( 'plugin_action_links_' . $this->slug, [ $this, 'add_action_links' ] );

		// Admin action handlers (fire when admin.php?action=... is requested).
		add_action( 'admin_action_ghl_check_update',     [ $this, 'handle_check_update' ] );
		add_action( 'admin_action_ghl_uninstall_plugin', [ $this, 'handle_uninstall'    ] );

		// Success notice after "Check for Update".
		add_action( 'admin_notices', [ $this, 'maybe_show_notices' ] );
	}

	// ── Plugin-row links ───────────────────────────────────────────────────────

	public function add_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=ghl-showcase-sync' );

		$check_url = wp_nonce_url(
			admin_url( 'admin.php?action=ghl_check_update' ),
			'ghl_check_update'
		);

		$uninstall_url = wp_nonce_url(
			admin_url( 'admin.php?action=ghl_uninstall_plugin' ),
			'ghl_uninstall_plugin'
		);

		$confirm_msg = esc_js(
			__( 'This will permanently delete ALL plugin settings and remove the plugin files. Showcase posts in WordPress will NOT be deleted. This cannot be undone — continue?', 'ghl-showcase-sync' )
		);

		array_unshift(
			$links,
			sprintf(
				'<a href="%s" style="font-weight:600;">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'ghl-showcase-sync' )
			)
		);

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $check_url ),
				esc_html__( 'Check for Update', 'ghl-showcase-sync' )
			)
		);

		$links[] = sprintf(
			'<a href="%s" style="color:#b32d2e;font-weight:600;" onclick="return confirm(\'%s\')">%s</a>',
			esc_url( $uninstall_url ),
			$confirm_msg,
			esc_html__( 'Uninstall Plugin', 'ghl-showcase-sync' )
		);

		return $links;
	}

	// ── "Check for Update" handler ─────────────────────────────────────────────

	public function handle_check_update(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghl-showcase-sync' ) );
		}
		check_admin_referer( 'ghl_check_update' );

		// Clear our release cache so the next check hits GitHub fresh.
		delete_transient( self::CACHE_KEY );

		// Force WordPress to re-check all plugin updates on next load.
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php?ghl_update_checked=1' ) );
		exit;
	}

	// ── "Uninstall Plugin" handler ─────────────────────────────────────────────

	public function handle_uninstall(): void {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ghl-showcase-sync' ) );
		}
		check_admin_referer( 'ghl_uninstall_plugin' );

		self::wipe_plugin_data();

		// Deactivate first (required before deletion).
		deactivate_plugins( $this->slug );

		// Load WP's plugin/file helpers if not already present.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		delete_plugins( [ $this->slug ] );

		wp_safe_redirect( admin_url( 'plugins.php?ghl_uninstalled=1' ) );
		exit;
	}

	// ── Admin notices ──────────────────────────────────────────────────────────

	public function maybe_show_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		if ( ! empty( $_GET['ghl_update_checked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'LaunchLocal Showcase Sync: update cache cleared. WordPress will re-check for updates now.', 'ghl-showcase-sync' )
				. '</p></div>';
		}

		if ( ! empty( $_GET['ghl_uninstalled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'LaunchLocal Showcase Sync has been uninstalled and all plugin data has been removed.', 'ghl-showcase-sync' )
				. '</p></div>';
		}
	}

	// ── Data wipe (shared with uninstall.php) ──────────────────────────────────

	public static function wipe_plugin_data(): void {
		foreach ( self::ALL_OPTIONS as $option ) {
			delete_option( $option );
		}

		delete_transient( 'ghl_connection_verified' );
		delete_transient( self::CACHE_KEY );

		wp_clear_scheduled_hook( 'ghl_scheduled_sync' );
		wp_clear_scheduled_hook( 'ghl_scheduled_back_sync' );
	}

	// ── Update transient injection ─────────────────────────────────────────────

	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $this->version, $latest, '<' ) ) {
			$transient->response[ $this->slug ] = (object) [
				'slug'        => dirname( $this->slug ),
				'plugin'      => $this->slug,
				'new_version' => $latest,
				'url'         => $release['html_url'],
				'package'     => $release['zipball_url'],
			];
		} else {
			$transient->no_update[ $this->slug ] = (object) [
				'slug'        => dirname( $this->slug ),
				'plugin'      => $this->slug,
				'new_version' => $latest,
				'url'         => $release['html_url'],
				'package'     => '',
			];
		}

		return $transient;
	}

	// ── Plugin info modal ──────────────────────────────────────────────────────

	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'LaunchLocal Showcase Sync',
			'slug'          => dirname( $this->slug ),
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://launchlocal.io">LaunchLocal</a>',
			'homepage'      => 'https://launchlocal.io',
			'download_link' => $release['zipball_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'sections'      => [
				'changelog' => nl2br( esc_html( $release['body'] ?? '' ) ),
			],
		];
	}

	// ── Folder rename after extraction ─────────────────────────────────────────
	// GitHub zipballs extract to "owner-repo-{hash}/" — rename to the correct
	// plugin directory so WordPress activates the right plugin.

	public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra = [] ): string {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $source;
		}

		$correct = trailingslashit( dirname( $source ) ) . dirname( $this->slug ) . '/';

		if ( $source === $correct ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem->move( $source, $correct, true ) ) {
			return $correct;
		}

		return $source;
	}

	// ── Cache management ───────────────────────────────────────────────────────

	public function purge_cache( $upgrader, array $hook_extra ): void {
		if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->slug ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	// ── GitHub API ─────────────────────────────────────────────────────────────

	private function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			[
				'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}
}
