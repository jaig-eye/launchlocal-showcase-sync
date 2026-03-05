<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP Admin → Plugins → Delete.
 *
 * Removes all plugin options, transients, and scheduled events.
 * Showcase posts in WordPress are intentionally preserved.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = [
	'ghl_sync_token',
	'ghl_sync_location_id',
	'ghl_sync_schema_key',
	'ghl_sync_cron_schedule',
	'ghl_sync_batch_size',
	'ghl_sync_debug',
	'ghl_sync_publisher_id',
	'ghl_sync_taxonomy_slug',
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

foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'ghl_connection_verified' );
delete_transient( 'ghl_sync_github_release' );

wp_clear_scheduled_hook( 'ghl_scheduled_sync' );
