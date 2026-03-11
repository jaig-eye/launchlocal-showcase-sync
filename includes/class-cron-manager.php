<?php
declare(strict_types=1);

namespace GHL\ShowcaseSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron scheduling for automated syncs.
 *
 * Two hooks share the same user-configured interval:
 *   ghl_scheduled_sync       — forward sync  (GHL → WP), batched with stored offset
 *   ghl_scheduled_back_sync  — back-sync     (WP → GHL), processes one batch per fire;
 *                              the backlog naturally shrinks as posts are pushed, so no
 *                              offset tracking is needed.
 */
class CronManager {

	private const CRON_HOOK          = 'ghl_scheduled_sync';
	private const BACK_SYNC_HOOK     = 'ghl_scheduled_back_sync';
	private const SCHEDULE_OPT      = 'ghl_sync_cron_schedule';

	/** Called on plugin activation. */
	public static function activate(): void {
		if ( get_option( self::SCHEDULE_OPT ) ) {
			self::schedule();
		}
	}

	/**
	 * Self-healing: if a cron schedule is configured but either event is missing
	 * (cleared by WP updates, site migrations, etc.), reschedule automatically.
	 * Hooked to 'init' so it fires on every request without user interaction.
	 * Cost: two wp_next_scheduled() calls — both read the autoloaded 'cron' option
	 * already in PHP memory, so there is no additional DB query.
	 */
	public static function maybe_reschedule(): void {
		$interval = (string) get_option( self::SCHEDULE_OPT, '' );
		if ( empty( $interval ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $interval, self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::BACK_SYNC_HOOK ) ) {
			wp_schedule_event( time() + 30, $interval, self::BACK_SYNC_HOOK );
		}
	}

	/** Called on plugin deactivation. */
	public static function deactivate(): void {
		self::unschedule();
	}

	/**
	 * Schedule both cron events with the saved interval.
	 * Back-sync fires 30 s after the forward sync to avoid API contention.
	 */
	public static function schedule(): void {
		$interval = (string) get_option( self::SCHEDULE_OPT, '' );
		if ( empty( $interval ) ) {
			return;
		}
		self::unschedule();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $interval, self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::BACK_SYNC_HOOK ) ) {
			wp_schedule_event( time() + 30, $interval, self::BACK_SYNC_HOOK );
		}
	}

	/**
	 * Remove all scheduled events managed by this plugin.
	 */
	public static function unschedule(): void {
		foreach ( [ self::CRON_HOOK, self::BACK_SYNC_HOOK ] as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Returns the timestamp of the next forward-sync run, or null.
	 */
	public static function get_next_run(): ?int {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		return $ts ? (int) $ts : null;
	}

	/**
	 * Returns the name of the back-sync cron hook (used for hook registration).
	 */
	public static function back_sync_hook(): string {
		return self::BACK_SYNC_HOOK;
	}

	/**
	 * Returns available cron interval options.
	 *
	 * @return array<string,string>
	 */
	public static function get_interval_options(): array {
		return [
			''           => 'Manual only (no schedule)',
			'hourly'     => 'Every hour',
			'twicedaily' => 'Twice daily',
			'daily'      => 'Once daily',
		];
	}
}
