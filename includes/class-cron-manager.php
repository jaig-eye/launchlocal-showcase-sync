<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron scheduling for automated syncs.
 */
class CronManager {

	private const CRON_HOOK     = 'ghl_scheduled_sync';
	private const SCHEDULE_OPT = 'ghl_sync_cron_schedule';

	/** Called on plugin activation. */
	public static function activate(): void {
		if ( get_option( self::SCHEDULE_OPT ) ) {
			self::schedule();
		}
	}

	/** Called on plugin deactivation. */
	public static function deactivate(): void {
		self::unschedule();
	}

	/**
	 * Schedule the cron event with the saved interval.
	 */
	public static function schedule(): void {
		$interval = (string) get_option( self::SCHEDULE_OPT, '' );
		if ( empty( $interval ) ) {
			return;
		}
		self::unschedule(); // clear any previous schedule first
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Remove any scheduled event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Returns the timestamp of the next scheduled run, or null.
	 */
	public static function get_next_run(): ?int {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		return $ts ? (int) $ts : null;
	}

	/**
	 * Returns available cron interval options.
	 *
	 * @return array<string,string>
	 */
	public static function get_interval_options(): array {
		return [
			''         => 'Manual only (no schedule)',
			'hourly'   => 'Every hour',
			'twicedaily' => 'Twice daily',
			'daily'    => 'Once daily',
		];
	}
}
