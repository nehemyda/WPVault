<?php
/**
 * The safety net behind §21's "Browser closed -> Job remains; user can
 * reopen and continue": a job doesn't actually need the user to reopen
 * anything, because this runs every minute regardless and keeps advancing
 * whichever job's heartbeat has gone stale.
 *
 * Deliberately does nothing when a job is being actively polled from the
 * browser -- Job_Store::find_stale_active_job() only returns a job whose
 * heartbeat is already older than Job_Store::STALE_SECONDS, and Job_Runner's
 * own lock file stops this from ever overlapping a foreground request that
 * is mid-step.
 */

namespace WPVault\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron_Runner {

	const CRON_HOOK = 'wpvault_cron_tick';

	// Longer than the foreground poller's budget: nobody is watching a
	// progress bar during an unattended resume, so it's worth using more of
	// this once-a-minute opportunity.
	const TIME_BUDGET = 25;

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wpvault_minute', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function register_interval( $schedules ) {
		if ( ! isset( $schedules['wpvault_minute'] ) ) {
			$schedules['wpvault_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (WPVault)', 'wpvault' ),
			);
		}

		return $schedules;
	}

	public static function run_tick() {
		$job = Job_Store::find_stale_active_job();

		if ( ! $job ) {
			return;
		}

		Job_Runner::step( $job->id, self::TIME_BUDGET );
	}
}
