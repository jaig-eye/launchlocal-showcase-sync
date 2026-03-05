<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync\Admin;

use GHL\ShowcaseSync\ApiClient;
use GHL\ShowcaseSync\CronManager;
use GHL\ShowcaseSync\SyncEngine;
use GHL\ShowcaseSync\BackSyncEngine;
use GHL\ShowcaseSync\SeoEngine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SettingsPage {

	private const PAGE_SLUG    = 'ghl-showcase-sync';
	private const OPTION_GROUP = 'ghl_sync_options';
	private const MENU_CAP     = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_menu_page'        ] );
		add_action( 'admin_init',            [ $this, 'register_settings'    ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'       ] );
		add_action( 'admin_post_ghl_save_settings', [ $this, 'handle_save_settings' ] );
	}

	public function add_menu_page(): void {
		add_menu_page(
			__( 'LaunchLocal Showcase Sync', 'ghl-showcase-sync' ),
			__( 'Showcase Sync', 'ghl-showcase-sync' ),
			self::MENU_CAP,
			self::PAGE_SLUG,
			[ $this, 'render' ],
			'dashicons-update-alt',
			80
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) return;
		wp_enqueue_style(  'ghl-sync-admin', GHL_SYNC_URL . 'admin/assets/style.css', [], GHL_SYNC_VERSION );
		wp_enqueue_script( 'ghl-sync-admin', GHL_SYNC_URL . 'admin/js/admin.js', [ 'jquery' ], GHL_SYNC_VERSION, true );
		wp_localize_script( 'ghl-sync-admin', 'GHL_SYNC', [
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'ghl_sync_nonce' ),
			'default_map'     => SyncEngine::get_default_field_map(),
			'saved_map'       => SyncEngine::get_field_map(),
			'seo_tokens'      => SeoEngine::get_tokens_reference(),
			'active_tab'      => sanitize_key( $_GET['tab'] ?? 'sync' ),
			'batch_size'      => (int) get_option( 'ghl_sync_batch_size', 0 ),
			'strings'         => [
				'syncing'          => __( 'Syncing&hellip;', 'ghl-showcase-sync' ),
				'sync_done'        => __( 'Sync Complete', 'ghl-showcase-sync' ),
				'verifying'        => __( 'Verifying&hellip;', 'ghl-showcase-sync' ),
				'loading'          => __( 'Loading&hellip;', 'ghl-showcase-sync' ),
				'saving'           => __( 'Saving&hellip;', 'ghl-showcase-sync' ),
				'confirm_sync'     => __( 'Run a full sync now?', 'ghl-showcase-sync' ),
				'confirm_backsync' => __( 'Push all WP-only showcases to LaunchLocal? This is a one-time operation.', 'ghl-showcase-sync' ),
				'error_generic'    => __( 'An error occurred. Please try again.', 'ghl-showcase-sync' ),
				'back_syncing'     => __( 'Back-syncing to LaunchLocal&hellip;', 'ghl-showcase-sync' ),
				'back_sync_done'   => __( 'Back-Sync Complete', 'ghl-showcase-sync' ),
			],
		] );
	}

	public function register_settings(): void {
		$opts = [
			'ghl_sync_token', 'ghl_sync_location_id', 'ghl_sync_schema_key',
			'ghl_sync_cron_schedule', 'ghl_sync_batch_size', 'ghl_sync_debug',
			'ghl_sync_publisher_id', 'ghl_sync_taxonomy_slug', 'ghl_sync_delete_missing',
			'ghl_sync_obey_changes', 'ghl_sync_exclude_draft', 'ghl_sync_delete_on_draft',
			SeoEngine::OPT_SEO_OVERRIDE,
			SeoEngine::OPT_TITLE_PATTERN, SeoEngine::OPT_DESC_PATTERN,
			SeoEngine::OPT_IMG_NAME_PATTERN, SeoEngine::OPT_IMG_ALT_PATTERN, SeoEngine::OPT_IMG_TITLE_PATTERN,
		];
		foreach ( $opts as $opt ) {
			register_setting( self::OPTION_GROUP, $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
		}
		register_setting( self::OPTION_GROUP, SeoEngine::OPT_AUTO_FILL, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( self::MENU_CAP ) ) wp_die( 'Permission denied.' );
		check_admin_referer( 'ghl_save_settings' );

		// Determine which tab to return to after save.
		$save_tab = sanitize_key( $_POST['_save_tab'] ?? 'settings' );
		if ( ! in_array( $save_tab, [ 'sync', 'backsync', 'settings', 'seo', 'mapping' ], true ) ) {
			$save_tab = 'settings';
		}

		// Core settings.
		update_option( 'ghl_sync_token',         sanitize_text_field( wp_unslash( $_POST['ghl_sync_token']         ?? '' ) ) );
		update_option( 'ghl_sync_location_id',   sanitize_text_field( wp_unslash( $_POST['ghl_sync_location_id']   ?? '' ) ) );
		update_option( 'ghl_sync_schema_key',    \GHL\ShowcaseSync\ApiClient::sanitize_schema_key( wp_unslash( $_POST['ghl_sync_schema_key'] ?? 'custom_objects.showcases' ) ) );
		update_option( 'ghl_sync_batch_size',    absint( $_POST['ghl_sync_batch_size'] ?? 0 ) );
		update_option( 'ghl_sync_debug',         isset( $_POST['ghl_sync_debug'] ) ? 1 : 0 );
		update_option( 'ghl_sync_publisher_id',  absint( $_POST['ghl_sync_publisher_id'] ?? 0 ) );
		update_option( 'ghl_sync_taxonomy_slug', sanitize_key( wp_unslash( $_POST['ghl_sync_taxonomy_slug'] ?? '' ) ) );
		update_option( 'ghl_sync_delete_missing', isset( $_POST['ghl_sync_delete_missing'] ) ? 1 : 0 );

		$obey = sanitize_key( wp_unslash( $_POST['ghl_sync_obey_changes'] ?? 'launchlocal' ) );
		update_option( 'ghl_sync_obey_changes', in_array( $obey, [ 'launchlocal', 'wordpress' ], true ) ? $obey : 'launchlocal' );
		update_option( 'ghl_sync_exclude_draft',   isset( $_POST['ghl_sync_exclude_draft']   ) ? 1 : 0 );
		update_option( 'ghl_sync_delete_on_draft', isset( $_POST['ghl_sync_delete_on_draft'] ) ? 1 : 0 );

		$schedule = sanitize_text_field( wp_unslash( $_POST['ghl_sync_cron_schedule'] ?? '' ) );
		update_option( 'ghl_sync_cron_schedule', $schedule );
		$schedule ? CronManager::schedule() : CronManager::unschedule();

		// SEO settings.
		update_option( SeoEngine::OPT_SEO_OVERRIDE, isset( $_POST[ SeoEngine::OPT_SEO_OVERRIDE ] ) ? 1 : 0 );
		update_option( SeoEngine::OPT_AUTO_FILL, sanitize_textarea_field( wp_unslash( $_POST[ SeoEngine::OPT_AUTO_FILL ] ?? '' ) ) );

		// SEO pattern settings.
		foreach ( SeoEngine::get_pattern_options() as $opt ) {
			$raw = wp_unslash( $_POST[ $opt['key'] ] ?? '' );
			update_option( $opt['key'], sanitize_text_field( $raw ) ?: $opt['default'] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1&tab=' . $save_tab ) );
		exit;
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( self::MENU_CAP ) ) return;

		$token       = (string) get_option( 'ghl_sync_token', '' );
		$location_id = (string) get_option( 'ghl_sync_location_id', '' );
		$schema_key  = (string) get_option( 'ghl_sync_schema_key', 'custom_objects.showcases' );
		$cron_sched  = (string) get_option( 'ghl_sync_cron_schedule', '' );
		$batch_size  = (int)    get_option( 'ghl_sync_batch_size', 0 );
		$debug_on    = (bool)   get_option( 'ghl_sync_debug', 0 );
		$publisher_id= (int)    get_option( 'ghl_sync_publisher_id', 0 );
		$tax_slug    = (string) get_option( 'ghl_sync_taxonomy_slug', '' );
		$delete_missing  = (bool)   get_option( 'ghl_sync_delete_missing',  0 );
		$obey_changes    = (string) get_option( 'ghl_sync_obey_changes',    'launchlocal' );
		$exclude_draft   = (bool)   get_option( 'ghl_sync_exclude_draft',   1 );
		$delete_on_draft = (bool)   get_option( 'ghl_sync_delete_on_draft', 0 );
		$next_run    = CronManager::get_next_run();
		$last_log    = SyncEngine::get_last_log();
		$back_log    = BackSyncEngine::get_last_log();
		$saved       = isset( $_GET['saved'] ) && '1' === sanitize_key( $_GET['saved'] );
		$has_creds   = ! empty( $token ) && ! empty( $location_id );
		$has_schema  = ! empty( $schema_key );
		$active_tab  = sanitize_key( $_GET['tab'] ?? 'sync' );
		$conn_verified = (bool) get_transient( 'ghl_connection_verified' );

		// Publisher user label.
		$publisher_label = '';
		if ( $publisher_id > 0 ) {
			$pub_user = get_user_by( 'ID', $publisher_id );
			if ( $pub_user ) $publisher_label = $pub_user->display_name . ' (' . $pub_user->user_login . ')';
		}

		// Debug raw response (only if debug mode on AND schema configured).
		$raw_response = null;
		if ( $debug_on && $has_creds && $has_schema ) {
			$raw_response = ApiClient::make()->get_records_raw();
		}
		?>
		<div class="wrap ghl-sync-page">
			<div class="ghl-header">
				<div class="ghl-header__brand">
					<?php
					$_logo = GHL_SYNC_PATH . 'admin/assets/launch-logo.webp';
					if ( file_exists( $_logo ) ) :
					?>
					<img src="<?php echo esc_url( GHL_SYNC_URL . 'admin/assets/launch-logo.webp' ); ?>" alt="Showcase Sync" class="ghl-logo">
					<?php endif; ?>
					<div class="ghl-header__brand-text">
						<h1 class="ghl-header__title"><?php esc_html_e( 'Showcase Sync', 'ghl-showcase-sync' ); ?></h1>
						<span class="ghl-version">v<?php echo esc_html( GHL_SYNC_VERSION ); ?></span>
					</div>
				</div>
				<div class="ghl-header__status">
					<?php
					if ( $has_creds && $conn_verified ) :
						$badge_cls  = 'ghl-badge--connected';
						$badge_text = esc_html__( 'Connected', 'ghl-showcase-sync' );
					elseif ( $has_creds ) :
						$badge_cls  = 'ghl-badge--ready';
						$badge_text = esc_html__( 'Credentials Saved', 'ghl-showcase-sync' );
					else :
						$badge_cls  = 'ghl-badge--warn';
						$badge_text = esc_html__( 'Not Configured', 'ghl-showcase-sync' );
					endif;
					?>
					<span class="ghl-badge <?php echo esc_attr( $badge_cls ); ?>" id="connection-badge">
						<span class="ghl-badge__dot"></span>
						<?php echo $badge_text; ?>
					</span>
				</div>
			</div>

			<?php if ( $saved ) : ?>
			<div class="ghl-notice ghl-notice--success" id="save-notice">
				<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
				<?php esc_html_e( 'Settings saved.', 'ghl-showcase-sync' ); ?>
			</div>
			<?php endif; ?>

			<div class="ghl-tabs">
				<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=sync"      class="ghl-tab <?php echo $active_tab === 'sync'      ? 'is-active' : ''; ?>">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
					<?php esc_html_e( 'Sync', 'ghl-showcase-sync' ); ?>
				</a>
				<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=backsync"  class="ghl-tab <?php echo $active_tab === 'backsync'  ? 'is-active' : ''; ?>">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.504 1.132a1 1 0 01.992 0l1.75 1a1 1 0 11-.992 1.736L10 3.152l-1.254.716a1 1 0 11-.992-1.736l1.75-1zM5.618 4.504a1 1 0 01-.372 1.364L5.016 6l.23.132a1 1 0 11-.992 1.736L4 7.723V8a1 1 0 01-2 0V6a.996.996 0 01.52-.878l1.734-.99a1 1 0 011.364.372zm8.764 0a1 1 0 011.364-.372l1.733.99A1.002 1.002 0 0118 6v2a1 1 0 11-2 0v-.277l-.254.145a1 1 0 11-.992-1.736l.23-.132-.23-.132a1 1 0 01-.372-1.364zm-7 4a1 1 0 011.364-.372L10 8.848l1.254-.716a1 1 0 11.992 1.736L11 10.58V12a1 1 0 11-2 0v-1.42l-1.246-.712a1 1 0 01-.372-1.364zM3 11a1 1 0 011 1v1.42l1.246.712a1 1 0 11-.992 1.736l-1.75-1A1 1 0 012 14v-2a1 1 0 011-1zm14 0a1 1 0 011 1v2a1 1 0 01-.504.868l-1.75 1a1 1 0 11-.992-1.736L16 13.42V12a1 1 0 011-1zm-9.618 5.504a1 1 0 011.364-.372l.254.145V16a1 1 0 112 0v.277l.254-.145a1 1 0 11.992 1.736l-1.735.992a.995.995 0 01-.992 0l-1.735-.992a1 1 0 01-.372-1.364z" clip-rule="evenodd"/></svg>
					<?php esc_html_e( 'Back-Sync', 'ghl-showcase-sync' ); ?>
				</a>
				<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=settings"  class="ghl-tab <?php echo $active_tab === 'settings'  ? 'is-active' : ''; ?>">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
					<?php esc_html_e( 'Settings', 'ghl-showcase-sync' ); ?>
				</a>
				<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=seo"       class="ghl-tab <?php echo $active_tab === 'seo'       ? 'is-active' : ''; ?>">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
					<?php esc_html_e( 'SEO Settings', 'ghl-showcase-sync' ); ?>
				</a>
				<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=mapping"   class="ghl-tab <?php echo $active_tab === 'mapping'   ? 'is-active' : ''; ?>">
					<svg viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM14 11a1 1 0 011 1v1h1a1 1 0 110 2h-1v1a1 1 0 11-2 0v-1h-1a1 1 0 110-2h1v-1a1 1 0 011-1z"/></svg>
					<?php esc_html_e( 'Field Mapping', 'ghl-showcase-sync' ); ?>
				</a>
			</div>

			<?php
			// ═══════════════════════════════════════════════════════════════
			// TAB: SYNC
			// ═══════════════════════════════════════════════════════════════
			if ( $active_tab === 'sync' ) :
			?>
			<div class="ghl-layout">
				<div class="ghl-layout__main">
					<div class="ghl-card" id="status-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 10a8 8 0 1016 0A8 8 0 002 10zm8 0a2 2 0 110-4 2 2 0 010 4z"/></svg>
								<?php esc_html_e( 'Connection Status', 'ghl-showcase-sync' ); ?>
							</h2>
							<button class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-verify">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Test Connection', 'ghl-showcase-sync' ); ?>
							</button>
						</div>
						<div class="ghl-status-grid" id="status-grid">
							<div class="ghl-status-item">
								<span class="ghl-status-item__label"><?php esc_html_e( 'API Token', 'ghl-showcase-sync' ); ?></span>
								<span class="ghl-status-item__value <?php echo $has_creds ? 'ghl-text--green' : 'ghl-text--muted'; ?>">
									<?php echo $has_creds ? esc_html__( 'Saved', 'ghl-showcase-sync' ) : esc_html__( 'Not set', 'ghl-showcase-sync' ); ?>
								</span>
							</div>
							<div class="ghl-status-item">
								<span class="ghl-status-item__label"><?php esc_html_e( 'Location ID', 'ghl-showcase-sync' ); ?></span>
								<span class="ghl-status-item__value <?php echo ! empty( $location_id ) ? 'ghl-text--green' : 'ghl-text--muted'; ?>">
									<?php echo ! empty( $location_id ) ? esc_html( substr( $location_id, 0, 6 ) . '&bull;&bull;&bull;&bull;&bull;&bull;' ) : esc_html__( 'Not set', 'ghl-showcase-sync' ); ?>
								</span>
							</div>
							<div class="ghl-status-item">
								<span class="ghl-status-item__label"><?php esc_html_e( 'Schema Key', 'ghl-showcase-sync' ); ?></span>
								<span class="ghl-status-item__value ghl-text--mono"><?php echo $has_schema ? esc_html( $schema_key ) : '<em class="ghl-text--muted">Not configured — set in Settings</em>'; ?></span>
							</div>
							<div class="ghl-status-item">
								<span class="ghl-status-item__label"><?php esc_html_e( 'Next Cron', 'ghl-showcase-sync' ); ?></span>
								<span class="ghl-status-item__value">
									<?php echo $next_run ? esc_html( wp_date( 'M j, H:i', $next_run ) ) : esc_html__( 'Not scheduled', 'ghl-showcase-sync' ); ?>
								</span>
							</div>
						</div>
						<div id="verify-result" style="display:none;padding:0 20px 16px;"></div>
					</div>

					<?php if ( $has_creds && $has_schema ) : ?>
					<div class="ghl-card" id="pending-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'LaunchLocal Records', 'ghl-showcase-sync' ); ?>
							</h2>
							<button class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-refresh-pending">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Refresh', 'ghl-showcase-sync' ); ?>
							</button>
						</div>
						<div id="pending-loading" style="display:none;" class="ghl-loading-state">
							<div class="ghl-spinner"></div>
							<p><?php esc_html_e( 'Fetching records from LaunchLocal&hellip;', 'ghl-showcase-sync' ); ?></p>
						</div>
						<div id="pending-stats" style="display:none;" class="ghl-stats-row">
							<div class="ghl-stat"><span class="ghl-stat__num" id="stat-total">&mdash;</span><span class="ghl-stat__label">Total in LaunchLocal</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--blue"   id="stat-new">&mdash;</span><span class="ghl-stat__label">New</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--orange" id="stat-needs-update">&mdash;</span><span class="ghl-stat__label">Needs Update</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green"  id="stat-synced">&mdash;</span><span class="ghl-stat__label">Synced</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted"  id="stat-drafted">&mdash;</span><span class="ghl-stat__label">Drafted</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--purple" id="stat-backlog">&mdash;</span><span class="ghl-stat__label">WP Backlog</span></div>
						</div>
						<div id="pending-table-wrap" style="display:none;" class="ghl-table-wrap">
							<table class="ghl-table">
								<thead><tr><th>Title</th><th>LaunchLocal ID</th><th>Status</th><th>Origin</th></tr></thead>
								<tbody id="pending-tbody"></tbody>
							</table>
							<div id="pending-pagination" class="ghl-pagination" style="display:none;"></div>
						</div>
						<div id="pending-empty" style="display:none;" class="ghl-empty-state ghl-empty-state--inline">
							<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
							<p><?php esc_html_e( 'No records found.', 'ghl-showcase-sync' ); ?></p>
						</div>
						<div id="pending-placeholder" class="ghl-empty-state ghl-empty-state--inline">
							<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
							<p><?php esc_html_e( 'Click Refresh to load records from LaunchLocal.', 'ghl-showcase-sync' ); ?></p>
						</div>
					</div>
					<?php elseif ( $has_creds && ! $has_schema ) : ?>
					<div class="ghl-notice ghl-notice--warn">
						<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
						<?php printf(
							wp_kses( __( 'Schema Key not configured. Go to <a href="%s">Settings</a> to configure it, then click Test Connection to see your available object keys.', 'ghl-showcase-sync' ), [ 'a' => [ 'href' => [] ] ] ),
							esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings' ) )
						); ?>
					</div>
					<?php endif; ?>

					<div class="ghl-card" id="sync-result-card" style="display:none;">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Last Sync Result', 'ghl-showcase-sync' ); ?>
							</h2>
						</div>
						<div id="sync-result-content"></div>
					</div>

					<?php $this->render_last_log( $last_log ); ?>
					<?php if ( $debug_on && $has_creds && $has_schema ) : ?>
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Raw API Response', 'ghl-showcase-sync' ); ?>
							</h2>
							<span class="ghl-chip ghl-chip--orange">Debug Mode</span>
						</div>
						<pre class="ghl-raw-response"><?php
							if ( is_wp_error( $raw_response ) ) {
								echo esc_html( 'ERROR (' . $raw_response->get_error_code() . '): ' . $raw_response->get_error_message() );
							} elseif ( is_array( $raw_response ) ) {
								echo esc_html( wp_json_encode( $raw_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
							}
						?></pre>
					</div>
					<?php endif; ?>
				</div>

				<div class="ghl-layout__sidebar">
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Sync Controls', 'ghl-showcase-sync' ); ?>
							</h2>
						</div>
						<div style="padding:0 20px 20px;">
							<?php if ( $batch_size > 0 ) : ?>
							<p class="ghl-field__help" style="margin-bottom:12px;">
								<?php printf( esc_html__( 'Batch mode: %d records per run.', 'ghl-showcase-sync' ), $batch_size ); ?>
								<a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=settings"><?php esc_html_e( 'Change', 'ghl-showcase-sync' ); ?></a>
							</p>
							<?php endif; ?>
							<button class="ghl-btn ghl-btn--primary ghl-btn--full" id="btn-sync"
								data-batch="<?php echo esc_attr( $batch_size ); ?>"
								<?php echo ( ! $has_creds || ! $has_schema ) ? 'disabled' : ''; ?>>
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>
								<?php echo $batch_size > 0
									? esc_html__( 'Run Batch Sync', 'ghl-showcase-sync' )
									: esc_html__( 'Sync All Now', 'ghl-showcase-sync' );
								?>
							</button>
						</div>
					</div>

					<div class="ghl-card ghl-card--subtle">
						<div style="padding:14px 18px;">
							<p class="ghl-field__help" style="margin:0;">Enable <strong>Show Raw API Response</strong> in <a href="?page=ghl-showcase-sync&tab=settings">Settings</a> to dump the API payload on this page for debugging.</p>
						</div>
					</div>
				</div>
			</div>

			<?php
			// ═══════════════════════════════════════════════════════════════
			// TAB: BACK-SYNC
			// ═══════════════════════════════════════════════════════════════
			elseif ( $active_tab === 'backsync' ) :
			$backlog_posts = BackSyncEngine::get_backlog_posts();
			$backlog_count = count( $backlog_posts );
			?>
			<div class="ghl-layout">
				<div class="ghl-layout__main">

					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'WordPress → LaunchLocal Back-Sync', 'ghl-showcase-sync' ); ?>
							</h2>
						</div>
						<div style="padding:0 20px 20px;">
							<p class="ghl-field__help">
								<?php echo wp_kses( __( 'Showcase posts that exist in WordPress but have not been created in LaunchLocal are shown below as <strong>Backlog</strong>. Run the back-sync to push them to LaunchLocal Custom Objects.', 'ghl-showcase-sync' ), [ 'strong' => [] ] ); ?>
							</p>
							<?php if ( ! $has_creds || ! $has_schema ) : ?>
							<div class="ghl-notice ghl-notice--warn" style="margin-top:12px;">
								<?php esc_html_e( 'Please configure your API credentials and Schema Key in Settings before running a back-sync.', 'ghl-showcase-sync' ); ?>
							</div>
							<?php elseif ( $backlog_count === 0 ) : ?>
							<div class="ghl-notice ghl-notice--success" style="margin-top:12px;">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'No backlog posts found — all WordPress showcases are linked to LaunchLocal records.', 'ghl-showcase-sync' ); ?>
							</div>
							<?php else : ?>
							<div class="ghl-stats-row" style="margin:16px 0;">
								<div class="ghl-stat"><span class="ghl-stat__num ghl-text--purple"><?php echo $backlog_count; ?></span><span class="ghl-stat__label">Backlog Posts</span></div>
							</div>
							<?php endif; ?>
						</div>
					</div>

					<?php // WP Posts table with back-sync status. ?>
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'WordPress Showcases', 'ghl-showcase-sync' ); ?>
							</h2>
							<button class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-refresh-wp-posts">
								<?php esc_html_e( 'Refresh', 'ghl-showcase-sync' ); ?>
							</button>
						</div>
						<div id="wp-posts-loading" style="display:none;" class="ghl-loading-state">
							<div class="ghl-spinner"></div>
							<p><?php esc_html_e( 'Loading WordPress showcases&hellip;', 'ghl-showcase-sync' ); ?></p>
						</div>
						<div id="wp-posts-table-wrap" style="display:none;" class="ghl-table-wrap">
							<table class="ghl-table">
								<thead><tr><th>Title</th><th>Post ID</th><th>LaunchLocal ID</th><th>Status</th><th>Origin</th></tr></thead>
								<tbody id="wp-posts-tbody"></tbody>
							</table>
						</div>
						<div id="wp-posts-placeholder" class="ghl-empty-state ghl-empty-state--inline">
							<p><?php esc_html_e( 'Click Refresh to load WordPress showcase posts.', 'ghl-showcase-sync' ); ?></p>
						</div>
					</div>

					<?php if ( $back_log ) :
						$bs = $back_log['summary'] ?? [];
						$bs_ts = wp_date( 'M j, Y \\a\\t H:i:s', strtotime( $back_log['timestamp'] ) );
					?>
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title"><?php esc_html_e( 'Last Back-Sync Log', 'ghl-showcase-sync' ); ?></h2>
							<span class="ghl-text--muted ghl-text--sm"><?php echo esc_html( $bs_ts ); ?></span>
						</div>
						<div class="ghl-stats-row">
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green"><?php echo (int)($bs['created']??0); ?></span><span class="ghl-stat__label">Pushed to GHL</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted"><?php echo (int)($bs['skipped']??0); ?></span><span class="ghl-stat__label">Skipped</span></div>
							<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red"><?php echo count($bs['errors']??[]); ?></span><span class="ghl-stat__label">Errors</span></div>
						</div>
						<?php if ( ! empty( $bs['errors'] ) ) : ?>
						<div class="ghl-log-section ghl-log-section--error" style="padding:0 20px 16px;">
							<ul class="ghl-log-list">
								<?php foreach ( $bs['errors'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?>
							</ul>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<div id="back-sync-result-card" style="display:none;" class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title"><?php esc_html_e( 'Back-Sync Result', 'ghl-showcase-sync' ); ?></h2>
						</div>
						<div id="back-sync-result-content"></div>
					</div>

				</div>

				<div class="ghl-layout__sidebar">
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title"><?php esc_html_e( 'Back-Sync Controls', 'ghl-showcase-sync' ); ?></h2>
						</div>
						<div style="padding:0 20px 20px;">
							<p class="ghl-field__help" style="margin-bottom:14px;">
								<?php esc_html_e( 'This is a one-time operation. All WordPress-only showcase posts will be created as new Custom Object records in LaunchLocal.', 'ghl-showcase-sync' ); ?>
							</p>
							<button class="ghl-btn ghl-btn--primary ghl-btn--full" id="btn-back-sync"
								<?php echo ( ! $has_creds || ! $has_schema ) ? 'disabled' : ''; ?>>
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'Run Backlog Sync', 'ghl-showcase-sync' ); ?>
							</button>
						</div>
					</div>
					<div class="ghl-card ghl-card--subtle">
						<div style="padding:14px 18px;">
							<h3 style="margin:0 0 8px;font-size:13px;font-weight:700;">What gets synced?</h3>
							<p class="ghl-field__help">Only the post title, content, and meta fields that match your field map are pushed back. Images are not pushed to GHL.</p>
						</div>
					</div>
				</div>
			</div>

			<?php
			// ═══════════════════════════════════════════════════════════════
			// TAB: SETTINGS
			// ═══════════════════════════════════════════════════════════════
			elseif ( $active_tab === 'settings' ) :
			?>
			<div class="ghl-layout">
				<div class="ghl-layout__main">
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'API Settings', 'ghl-showcase-sync' ); ?>
							</h2>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ghl_save_settings">
							<input type="hidden" name="_save_tab" value="settings">
							<?php wp_nonce_field( 'ghl_save_settings' ); ?>

							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_token">
									<?php esc_html_e( 'Private Integration Token', 'ghl-showcase-sync' ); ?> <span class="ghl-field__required">*</span>
								</label>
								<div class="ghl-field__input-wrap">
									<input type="password" id="ghl_sync_token" name="ghl_sync_token" value="<?php echo esc_attr( $token ); ?>" class="ghl-input" placeholder="eyJhbGci&hellip;" autocomplete="off">
									<button type="button" class="ghl-input-toggle" data-target="ghl_sync_token" aria-label="Toggle">
										<svg class="icon-eye" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
									</button>
								</div>
								<p class="ghl-field__help"><?php esc_html_e( 'LaunchLocal → Settings → Integrations → Private Integrations. Needs "Custom Objects" scope.', 'ghl-showcase-sync' ); ?></p>
							</div>

							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_location_id"><?php esc_html_e( 'Location ID', 'ghl-showcase-sync' ); ?> <span class="ghl-field__required">*</span></label>
								<input type="text" id="ghl_sync_location_id" name="ghl_sync_location_id" value="<?php echo esc_attr( $location_id ); ?>" class="ghl-input" placeholder="VcDXoVxzJLBkIfxkrpnB" spellcheck="false">
							</div>

							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_schema_key"><?php esc_html_e( 'Schema Key', 'ghl-showcase-sync' ); ?></label>
								<input type="text" id="ghl_sync_schema_key" name="ghl_sync_schema_key" value="<?php echo esc_attr( $schema_key ); ?>" class="ghl-input ghl-input--mono" placeholder="custom_objects.showcases" spellcheck="false" autocomplete="off">
								<p class="ghl-field__help"><?php esc_html_e( 'Format: custom_objects.your_plural_label — use Test Connection to discover available keys.', 'ghl-showcase-sync' ); ?></p>
							</div>

							<div class="ghl-divider"></div>

							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_cron_schedule"><?php esc_html_e( 'Auto-Sync Schedule', 'ghl-showcase-sync' ); ?></label>
								<select id="ghl_sync_cron_schedule" name="ghl_sync_cron_schedule" class="ghl-select">
									<?php foreach ( CronManager::get_interval_options() as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cron_sched, $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_batch_size"><?php esc_html_e( 'Batch Size', 'ghl-showcase-sync' ); ?></label>
								<input type="number" id="ghl_sync_batch_size" name="ghl_sync_batch_size" value="<?php echo esc_attr( $batch_size ); ?>" class="ghl-input" min="0" max="500" placeholder="0">
								<p class="ghl-field__help"><?php esc_html_e( '0 = sync all at once.', 'ghl-showcase-sync' ); ?></p>
							</div>

							<div class="ghl-divider"></div>

							<!-- Publisher -->
							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_publisher_search"><?php esc_html_e( 'Post Publisher / Author', 'ghl-showcase-sync' ); ?></label>
								<p class="ghl-field__help" style="margin-bottom:8px;"><?php esc_html_e( 'Synced showcase posts will be assigned to this WordPress user.', 'ghl-showcase-sync' ); ?></p>
								<div class="ghl-user-picker" id="ghl-user-picker">
									<input type="hidden" id="ghl_sync_publisher_id" name="ghl_sync_publisher_id" value="<?php echo esc_attr( $publisher_id ); ?>">
									<div class="ghl-field__input-wrap">
										<input type="text" id="ghl_publisher_search" class="ghl-input" placeholder="<?php esc_attr_e( 'Search users&hellip;', 'ghl-showcase-sync' ); ?>"
											value="<?php echo esc_attr( $publisher_label ); ?>" autocomplete="off">
										<button type="button" class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-clear-publisher" style="<?php echo ! $publisher_id ? 'display:none' : ''; ?>">✕</button>
									</div>
									<div class="ghl-user-dropdown" id="ghl-user-dropdown" style="display:none;">
										<div id="ghl-user-results"></div>
										<div id="ghl-user-load-more" style="display:none;padding:6px 12px;">
											<button type="button" class="ghl-btn ghl-btn--outline ghl-btn--sm ghl-btn--full" id="btn-load-more-users"><?php esc_html_e( 'Load more…', 'ghl-showcase-sync' ); ?></button>
										</div>
									</div>
								</div>
							</div>

							<!-- Taxonomy mapping -->
							<div class="ghl-field">
								<label class="ghl-field__label" for="ghl_sync_taxonomy_slug"><?php esc_html_e( 'Category Taxonomy Slug', 'ghl-showcase-sync' ); ?></label>
								<input type="text" id="ghl_sync_taxonomy_slug" name="ghl_sync_taxonomy_slug" value="<?php echo esc_attr( $tax_slug ); ?>" class="ghl-input ghl-input--mono" placeholder="category" spellcheck="false">
								<p class="ghl-field__help"><?php esc_html_e( 'The WordPress taxonomy slug used for the "taxonomy" field type. Default: category. Change this if your WP install uses a different taxonomy name.', 'ghl-showcase-sync' ); ?></p>
							</div>

							<div class="ghl-divider"></div>

							<div class="ghl-field">
								<label class="ghl-field__label" style="display:flex;align-items:center;gap:10px;cursor:pointer;">
									<input type="checkbox" id="ghl_sync_debug" name="ghl_sync_debug" value="1" <?php checked( $debug_on ); ?> style="width:18px;height:18px;">
									<?php esc_html_e( 'Show Raw API Response on Sync tab', 'ghl-showcase-sync' ); ?>
								</label>
							</div>

							<div class="ghl-field">
								<label class="ghl-field__label"><?php esc_html_e( 'Obey Changes', 'ghl-showcase-sync' ); ?></label>
								<span class="ghl-field__help" style="display:block;margin-bottom:10px;"><?php esc_html_e( 'When a synced post has been edited on one side, which version wins?', 'ghl-showcase-sync' ); ?></span>
								<div style="display:flex;gap:8px;">
									<label style="flex:1;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border:2px solid <?php echo $obey_changes === 'launchlocal' ? 'var(--ghl-blue)' : 'var(--ghl-gray-200)'; ?>;cursor:pointer;background:<?php echo $obey_changes === 'launchlocal' ? 'var(--ghl-blue-lt)' : '#fff'; ?>;">
										<input type="radio" name="ghl_sync_obey_changes" value="launchlocal" <?php checked( $obey_changes, 'launchlocal' ); ?> style="width:16px;height:16px;flex-shrink:0;accent-color:var(--ghl-blue);">
										<span>
											<strong style="font-size:13px;font-weight:700;"><?php esc_html_e( 'LaunchLocal', 'ghl-showcase-sync' ); ?></strong>
											<span class="ghl-field__help" style="display:block;margin-top:1px;"><?php esc_html_e( 'LaunchLocal data overwrites WordPress on every sync. (Default)', 'ghl-showcase-sync' ); ?></span>
										</span>
									</label>
									<label style="flex:1;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border:2px solid <?php echo $obey_changes === 'wordpress' ? 'var(--ghl-blue)' : 'var(--ghl-gray-200)'; ?>;cursor:pointer;background:<?php echo $obey_changes === 'wordpress' ? 'var(--ghl-blue-lt)' : '#fff'; ?>;">
										<input type="radio" name="ghl_sync_obey_changes" value="wordpress" <?php checked( $obey_changes, 'wordpress' ); ?> style="width:16px;height:16px;flex-shrink:0;accent-color:var(--ghl-blue);">
										<span>
											<strong style="font-size:13px;font-weight:700;"><?php esc_html_e( 'WordPress', 'ghl-showcase-sync' ); ?></strong>
											<span class="ghl-field__help" style="display:block;margin-top:1px;"><?php esc_html_e( 'WordPress edits push back to LaunchLocal instead of being overwritten.', 'ghl-showcase-sync' ); ?></span>
										</span>
									</label>
								</div>
							</div>

							<div class="ghl-divider"></div>

							<div class="ghl-field">
								<label class="ghl-field__label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
									<input type="checkbox" id="ghl_sync_delete_missing" name="ghl_sync_delete_missing" value="1" <?php checked( $delete_missing ); ?> style="width:18px;height:18px;margin-top:1px;flex-shrink:0;">
									<span>
										<?php esc_html_e( 'Delete WordPress post if showcase no longer exists in LaunchLocal', 'ghl-showcase-sync' ); ?>
										<span class="ghl-field__help" style="display:block;margin-top:2px;"><?php esc_html_e( 'Default: OFF. When disabled, the LaunchLocal link is cleared (making it a backlog entry) instead of deleting the post. Enable to permanently delete posts when removed from LaunchLocal.', 'ghl-showcase-sync' ); ?></span>
									</span>
								</label>
							</div>

							<div class="ghl-divider"></div>

							<div class="ghl-field">
								<label class="ghl-field__label"><?php esc_html_e( 'Draft Behaviour', 'ghl-showcase-sync' ); ?></label>
								<span class="ghl-field__help" style="display:block;margin-bottom:10px;"><?php esc_html_e( 'What happens when a LaunchLocal object has showcase_status = draft.', 'ghl-showcase-sync' ); ?></span>
								<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;">
									<input type="checkbox" name="ghl_sync_exclude_draft" value="1" <?php checked( $exclude_draft ); ?> style="width:18px;height:18px;margin-top:1px;flex-shrink:0;">
									<span>
										<strong style="font-size:13px;"><?php esc_html_e( 'Skip new drafts', 'ghl-showcase-sync' ); ?></strong>
										<span class="ghl-field__help" style="display:block;margin-top:2px;"><?php esc_html_e( 'Default: ON. Draft objects are not added to WordPress. If a post already exists, it will be set to draft.', 'ghl-showcase-sync' ); ?></span>
									</span>
								</label>
								<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
									<input type="checkbox" name="ghl_sync_delete_on_draft" value="1" <?php checked( $delete_on_draft ); ?> style="width:18px;height:18px;margin-top:1px;flex-shrink:0;">
									<span>
										<strong style="font-size:13px;"><?php esc_html_e( 'Delete post when drafted', 'ghl-showcase-sync' ); ?></strong>
										<span class="ghl-field__help" style="display:block;margin-top:2px;"><?php esc_html_e( 'Default: OFF. When enabled, setting an object to draft in LaunchLocal permanently deletes the WordPress post.', 'ghl-showcase-sync' ); ?></span>
									</span>
								</label>
							</div>

							<button type="submit" class="ghl-btn ghl-btn--primary ghl-btn--full">
								<svg viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z"/></svg>
								<?php esc_html_e( 'Save Settings', 'ghl-showcase-sync' ); ?>
							</button>
						</form>
					</div>
				</div>
				<div class="ghl-layout__sidebar">
					<div class="ghl-card ghl-card--subtle">
						<div style="padding:16px 18px;">
							<h3 style="margin:0 0 8px;font-size:13.5px;font-weight:700;color:var(--ghl-gray-700);">Batch Sync Tips</h3>
							<p class="ghl-field__help">If your server has a short PHP execution timeout, set Batch Size to 5–20 and run multiple times until all records are processed.</p>
						</div>
					</div>
				</div>
			</div>

			<?php
			// ═══════════════════════════════════════════════════════════════
			// TAB: SEO SETTINGS
			// ═══════════════════════════════════════════════════════════════
			elseif ( $active_tab === 'seo' ) :
			$seo_override_on   = SeoEngine::is_override_enabled();
			$auto_fill_raw     = (string) get_option( SeoEngine::OPT_AUTO_FILL, '' );
			?>
			<div class="ghl-layout">
				<div class="ghl-layout__main">
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
								<?php esc_html_e( 'SEO & Image Settings', 'ghl-showcase-sync' ); ?>
							</h2>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ghl_save_settings">
							<input type="hidden" name="_save_tab" value="seo">
							<?php wp_nonce_field( 'ghl_save_settings' ); ?>
							<!-- Pass-through hidden fields -->
							<input type="hidden" name="ghl_sync_token" value="<?php echo esc_attr( $token ); ?>">
							<input type="hidden" name="ghl_sync_location_id" value="<?php echo esc_attr( $location_id ); ?>">
							<input type="hidden" name="ghl_sync_schema_key" value="<?php echo esc_attr( $schema_key ); ?>">
							<input type="hidden" name="ghl_sync_batch_size" value="<?php echo esc_attr( $batch_size ); ?>">
							<input type="hidden" name="ghl_sync_cron_schedule" value="<?php echo esc_attr( $cron_sched ); ?>">
							<input type="hidden" name="ghl_sync_publisher_id" value="<?php echo esc_attr( $publisher_id ); ?>">
							<input type="hidden" name="ghl_sync_taxonomy_slug" value="<?php echo esc_attr( $tax_slug ); ?>">
							<?php if ( $debug_on ) : ?><input type="hidden" name="ghl_sync_debug" value="1"><?php endif; ?>
							<?php if ( $delete_missing ) : ?><input type="hidden" name="ghl_sync_delete_missing" value="1"><?php endif; ?>
							<input type="hidden" name="ghl_sync_obey_changes" value="<?php echo esc_attr( $obey_changes ); ?>">
							<?php if ( $exclude_draft )   : ?><input type="hidden" name="ghl_sync_exclude_draft" value="1"><?php endif; ?>
							<?php if ( $delete_on_draft ) : ?><input type="hidden" name="ghl_sync_delete_on_draft" value="1"><?php endif; ?>

							<!-- Auto-fill keywords (always active for {auto_fill} token) -->
							<div class="ghl-field">
								<label class="ghl-field__label" for="<?php echo esc_attr( SeoEngine::OPT_AUTO_FILL ); ?>">
									<?php esc_html_e( 'Auto-Fill Keywords', 'ghl-showcase-sync' ); ?>
								</label>
								<textarea
									id="<?php echo esc_attr( SeoEngine::OPT_AUTO_FILL ); ?>"
									name="<?php echo esc_attr( SeoEngine::OPT_AUTO_FILL ); ?>"
									class="ghl-input ghl-textarea"
									rows="5"
									placeholder="Local Experts&#10;Awning Pros&#10;Trusted Installers&#10;Top Rated"
								><?php echo esc_textarea( $auto_fill_raw ); ?></textarea>
								<p class="ghl-field__help">One keyword per line (or comma-separated). The <code>{auto_fill}</code> token picks a random keyword from this list each time a title, description, or image attribute is generated — adding brand variation and uniqueness across all showcases.</p>
							</div>

							<div class="ghl-divider"></div>

							<!-- Image patterns (always active) -->
							<div class="ghl-field ghl-field--section-head">
								<span class="ghl-section-label">Image Optimisation — Always Active</span>
								<p class="ghl-field__help" style="margin-top:4px;">These patterns apply to every image regardless of the SEO override toggle. A URL-based hash is always appended to filenames, guaranteeing uniqueness.</p>
							</div>
							<?php
							$img_opts = array_filter( SeoEngine::get_pattern_options(), fn( $o ) => in_array( $o['key'], [
								SeoEngine::OPT_IMG_NAME_PATTERN,
								SeoEngine::OPT_IMG_ALT_PATTERN,
								SeoEngine::OPT_IMG_TITLE_PATTERN,
							], true ) );
							foreach ( $img_opts as $opt ) :
								$current_val = (string) get_option( $opt['key'], $opt['default'] );
							?>
							<div class="ghl-field">
								<label class="ghl-field__label" for="<?php echo esc_attr( $opt['key'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></label>
								<input type="text" id="<?php echo esc_attr( $opt['key'] ); ?>" name="<?php echo esc_attr( $opt['key'] ); ?>"
									value="<?php echo esc_attr( $current_val ); ?>"
									class="ghl-input" spellcheck="false">
								<p class="ghl-field__help"><?php echo wp_kses( $opt['help'], [ 'code' => [] ] ); ?></p>
							</div>
							<?php endforeach; ?>

							<div class="ghl-divider"></div>

							<!-- SEO override toggle -->
							<div class="ghl-field" style="padding:4px 20px 0;">
								<label class="ghl-field__label ghl-toggle-label" style="display:flex;align-items:center;gap:12px;cursor:pointer;">
									<span class="ghl-toggle-wrap">
										<input type="checkbox" id="ghl_seo_override_toggle" name="<?php echo esc_attr( SeoEngine::OPT_SEO_OVERRIDE ); ?>" value="1"
											<?php checked( $seo_override_on ); ?> class="ghl-toggle-input">
										<span class="ghl-toggle-track"><span class="ghl-toggle-thumb"></span></span>
									</span>
									<span>
										<strong><?php esc_html_e( 'Enable SEO Tag Override', 'ghl-showcase-sync' ); ?></strong><br>
										<span class="ghl-field__help" style="display:block;margin-top:2px;"><?php esc_html_e( 'When ON, enables editing and use of tokens to create custom title tags/descriptions written on every sync.', 'ghl-showcase-sync' ); ?></span>
									</span>
								</label>
							</div>

							<!-- Title/description patterns — only shown when override enabled -->
							<div id="seo-meta-patterns" style="<?php echo $seo_override_on ? '' : 'display:none;'; ?>">
								<div style="padding:16px 20px 0;">
									<p class="ghl-field__help" style="margin:0 0 4px;">These patterns override RankMath every time a showcase is synced.</p>
								</div>
								<?php
								$meta_opts = array_filter( SeoEngine::get_pattern_options(), fn( $o ) => in_array( $o['key'], [
									SeoEngine::OPT_TITLE_PATTERN,
									SeoEngine::OPT_DESC_PATTERN,
								], true ) );
								foreach ( $meta_opts as $opt ) :
									$current_val = (string) get_option( $opt['key'], $opt['default'] );
								?>
								<div class="ghl-field">
									<label class="ghl-field__label" for="<?php echo esc_attr( $opt['key'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></label>
									<input type="text" id="<?php echo esc_attr( $opt['key'] ); ?>" name="<?php echo esc_attr( $opt['key'] ); ?>"
										value="<?php echo esc_attr( $current_val ); ?>"
										class="ghl-input" spellcheck="false">
									<p class="ghl-field__help"><?php echo wp_kses( $opt['help'], [ 'code' => [] ] ); ?></p>
								</div>
								<?php endforeach; ?>
							</div><!-- /#seo-meta-patterns -->

							<div style="padding:12px 20px 20px;">
								<button type="submit" class="ghl-btn ghl-btn--primary ghl-btn--full">
									<?php esc_html_e( 'Save SEO Settings', 'ghl-showcase-sync' ); ?>
								</button>
							</div>
						</form>
					</div>
				</div>
				<div class="ghl-layout__sidebar">
					<div class="ghl-card ghl-card--subtle">
						<div style="padding:16px 18px;">
							<h3 style="margin:0 0 10px;font-size:13.5px;font-weight:700;">Available Tokens</h3>
							<div style="font-size:12px;line-height:2.1;">
								<?php foreach ( SeoEngine::get_tokens_reference() as $token_key => $desc ) : ?>
								<div><code><?php echo esc_html( $token_key ); ?></code> — <?php echo esc_html( $desc ); ?></div>
								<?php endforeach; ?>
							</div>
							<div class="ghl-divider" style="margin:12px 0;"></div>
							<p class="ghl-field__help"><strong>Image names</strong> always include <code>{context}</code> (featured/gallery) and an 8-char URL hash — guaranteeing uniqueness even if two images share the same original filename.</p>
						</div>
					</div>
				</div>
			</div>


			<?php
			// ═══════════════════════════════════════════════════════════════
			// TAB: FIELD MAPPING
			// ═══════════════════════════════════════════════════════════════
			elseif ( $active_tab === 'mapping' ) :
			?>
			<div class="ghl-layout">
				<div class="ghl-layout__main">
					<div class="ghl-card">
						<div class="ghl-card__header">
							<h2 class="ghl-card__title">
								<svg viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM14 11a1 1 0 011 1v1h1a1 1 0 110 2h-1v1a1 1 0 11-2 0v-1h-1a1 1 0 110-2h1v-1a1 1 0 011-1z"/></svg>
								<?php esc_html_e( 'Field Mapping', 'ghl-showcase-sync' ); ?>
							</h2>
							<div style="display:flex;gap:8px;">
								<button class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-map-reset">Reset to defaults</button>
								<button class="ghl-btn ghl-btn--primary ghl-btn--sm" id="btn-map-save">
									<svg viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z"/></svg>
									Save Mapping
								</button>
							</div>
						</div>
						<div style="padding:0 20px 6px;">
							<p class="ghl-field__help">Map LaunchLocal <code>properties.*</code> field keys to WordPress fields. Use type <code>taxonomy</code> for multi-select category fields.</p>
						</div>
						<div style="padding:0 20px 8px;">
							<div class="ghl-map-header">
								<span>LaunchLocal Field Key</span>
								<span>WordPress Field / Meta Key</span>
								<span>Type</span>
								<span></span>
							</div>
							<div id="map-rows"></div>
							<button class="ghl-btn ghl-btn--outline ghl-btn--sm" id="btn-map-add" style="margin-top:10px;">
								<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/></svg>
								Add Row
							</button>
						</div>
						<div id="map-save-result" style="display:none;padding:0 20px 16px;"></div>
					</div>
				</div>
				<div class="ghl-layout__sidebar">
					<div class="ghl-card ghl-card--subtle">
						<div style="padding:16px 18px;">
							<h3 style="margin:0 0 10px;font-size:13.5px;font-weight:700;">Type Reference</h3>
							<div style="font-size:12px;line-height:1.9;">
								<div><code>post</code> &mdash; post_title or post_content</div>
								<div><code>meta</code> &mdash; simple text meta field</div>
								<div><code>image_single</code> &mdash; featured image (post thumbnail)</div>
								<div><code>image_gallery</code> &mdash; MB image_advanced gallery</div>
								<div><code>taxonomy</code> &mdash; WP taxonomy terms (e.g. category)</div>
							</div>
							<div class="ghl-divider" style="margin:12px 0;"></div>
							<h3 style="margin:0 0 10px;font-size:13.5px;font-weight:700;">WP field values</h3>
							<div style="font-size:12px;line-height:1.9;">
								<div><code>post_title</code> &rarr; post title</div>
								<div><code>post_content</code> &rarr; post body</div>
								<div><code>_thumbnail</code> &rarr; featured image</div>
								<div><em>anything else</em> &rarr; meta key name</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<?php endif; ?>

		</div><!-- /ghl-sync-page -->
		<?php
	}

	// ── Shared render helpers ──────────────────────────────────────────────────

	private function render_last_log( ?array $last_log ): void {
		if ( ! $last_log ) return;
		$log_summary = $last_log['summary'] ?? [];
		$log_error   = $last_log['error']   ?? null;
		$log_errors  = $log_summary['errors']  ?? [];
		$log_notices = $log_summary['notices'] ?? [];
		$log_ts      = wp_date( 'M j, Y \\a\\t H:i:s', strtotime( $last_log['timestamp'] ) );
		?>
		<div class="ghl-card">
			<div class="ghl-card__header">
				<h2 class="ghl-card__title">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
					<?php esc_html_e( 'Last Sync Log', 'ghl-showcase-sync' ); ?>
				</h2>
				<span class="ghl-text--muted ghl-text--sm"><?php echo esc_html( $log_ts ); ?></span>
			</div>
			<?php if ( $log_error ) : ?>
			<div class="ghl-notice ghl-notice--error"><strong>Sync failed:</strong> <?php echo esc_html( $log_error ); ?></div>
			<?php else : ?>
			<div class="ghl-stats-row">
				<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green"><?php  echo (int)($log_summary['created']??0); ?></span><span class="ghl-stat__label">Created</span></div>
				<div class="ghl-stat"><span class="ghl-stat__num ghl-text--blue"><?php   echo (int)($log_summary['updated']??0); ?></span><span class="ghl-stat__label">Updated</span></div>
				<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted"><?php  echo (int)($log_summary['skipped']??0); ?></span><span class="ghl-stat__label">Skipped</span></div>
				<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red"><?php    echo count($log_errors);  ?></span><span class="ghl-stat__label">Errors</span></div>
				<div class="ghl-stat"><span class="ghl-stat__num ghl-text--orange"><?php echo count($log_notices); ?></span><span class="ghl-stat__label">Warnings</span></div>
			</div>
			<?php if ( ! empty( $log_errors ) ) : ?>
			<div class="ghl-log-section ghl-log-section--error">
				<div class="ghl-log-section__title">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
					<?php printf( esc_html__( '%d Error(s)', 'ghl-showcase-sync' ), count($log_errors) ); ?>
				</div>
				<ul class="ghl-log-list">
					<?php foreach ( $log_errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php if ( ! empty( $log_notices ) ) : ?>
			<div class="ghl-log-section ghl-log-section--warn">
				<div class="ghl-log-section__title">
					<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
					<?php printf( esc_html__( '%d Warning(s)', 'ghl-showcase-sync' ), count($log_notices) ); ?>
				</div>
				<ul class="ghl-log-list">
					<?php foreach ( $log_notices as $n ) : ?><li><?php echo esc_html( $n ); ?></li><?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
