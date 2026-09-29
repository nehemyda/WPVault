<?php
/**
 * Recurring backups (§17/roadmap): a wp-cron tick checks whether the
 * configured schedule is due and, if so, starts a backup job the exact same
 * way the admin UI's "Create Backup" button does -- Backup_Store::create()
 * + Job_Store::create() + one Job_Runner::step() to get it moving. From
 * there the existing wpvault_cron_tick safety net (Cron_Runner) carries it
 * to completion like any other job; this class never drives a job itself
 * beyond that first step.
 *
 * Deliberately its own cron hook/interval rather than piggybacking on
 * Cron_Runner's wpvault_cron_tick -- that hook's whole contract is "resume
 * an already-existing stale job," not "decide whether to create a new one."
 */

namespace WPVault\Jobs;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Preflight;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\One_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduled_Backups {

	const OPTION    = 'wpvault_schedule';
	const CRON_HOOK = 'wpvault_scheduled_backup_tick';
	const INTERVAL  = 'wpvault_quarter_hour';

	const FREQUENCY_DAILY  = 'daily';
	const FREQUENCY_WEEKLY = 'weekly';

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function register_interval( $schedules ) {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (WPVault)', 'wpvault' ),
			);
		}

		return $schedules;
	}

	public static function defaults() {
		return array(
			'enabled'         => false,
			'frequency'       => self::FREQUENCY_DAILY,
			'time'            => '03:00',
			'day_of_week'     => 1, // ISO-8601: 1 = Monday ... 7 = Sunday, matches DateTime's 'N'.
			'type'            => Backup_Store::TYPE_FULL,
			'exclude_cache'      => true,
			'upload_to_drive'    => false, // Also save each scheduled backup to Google Drive once it completes.
			'upload_to_onedrive' => false, // Also save each scheduled backup to OneDrive once it completes.
			'retention'          => 7, // Most recent N *scheduled* backups kept; 0 = keep them all.
			'next_run'        => null, // GMT unix timestamp.
			'last_run_at'     => null, // GMT unix timestamp.
			'last_backup_id'  => null,
			'last_error'      => null,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Merges sanitized input into the stored settings, recomputing next_run
	 * whenever that's needed to keep it honest: newly enabled, or the
	 * frequency/time/day changed underneath an existing schedule.
	 *
	 * @param array $input Already-sanitized fields (see
	 *                      handle_settings_save() for the sanitizing side).
	 * @return array The settings actually saved.
	 */
	public static function update_settings( array $input ) {
		$current = self::get_settings();
		$merged  = array_merge( $current, $input );

		$schedule_changed = $merged['frequency'] !== $current['frequency']
			|| $merged['time'] !== $current['time']
			|| $merged['day_of_week'] !== $current['day_of_week'];

		if ( ! $merged['enabled'] ) {
			$merged['next_run'] = null;
		} elseif ( ! $current['enabled'] || $schedule_changed || empty( $merged['next_run'] ) ) {
			$merged['next_run'] = self::compute_next_run( $merged, time() );
		}

		update_option( self::OPTION, $merged );

		return $merged;
	}

	/**
	 * The next GMT timestamp, strictly after $from, that matches the
	 * configured wall-clock time (and weekday, for weekly) in the site's
	 * own timezone. Built on DateTimeImmutable so the result is a real
	 * instant regardless of which zone it was computed in -- getTimestamp()
	 * always returns true UTC seconds, avoiding the local-vs-GMT mismatch
	 * that bit the job heartbeat logic elsewhere in this plugin.
	 */
	public static function compute_next_run( array $settings, $from ) {
		$tz  = wp_timezone();
		$now = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );

		$parts  = explode( ':', $settings['time'] );
		$hour   = isset( $parts[0] ) ? max( 0, min( 23, (int) $parts[0] ) ) : 3;
		$minute = isset( $parts[1] ) ? max( 0, min( 59, (int) $parts[1] ) ) : 0;

		if ( self::FREQUENCY_WEEKLY === $settings['frequency'] ) {
			$target_dow = max( 1, min( 7, (int) $settings['day_of_week'] ) );
			$candidate  = $now->setTime( $hour, $minute, 0 );
			$day_diff   = $target_dow - (int) $candidate->format( 'N' );

			if ( $day_diff < 0 ) {
				$day_diff += 7;
			}

			$candidate = $candidate->modify( "+{$day_diff} days" );

			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+7 days' );
			}
		} else {
			$candidate = $now->setTime( $hour, $minute, 0 );

			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+1 day' );
			}
		}

		return $candidate->getTimestamp();
	}

	/**
	 * admin_post handler for the Settings page's schedule form -- a plain
	 * POST-and-redirect rather than a REST/JS round trip, since this is a
	 * one-shot settings save with no progress to poll, unlike everything
	 * else in the admin UI.
	 */
	public static function handle_settings_save() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpvault' ) );
		}

		check_admin_referer( 'wpvault_save_schedule' );

		$defaults = self::defaults();

		$frequency = isset( $_POST['wpvault_frequency'] ) && self::FREQUENCY_WEEKLY === $_POST['wpvault_frequency']
			? self::FREQUENCY_WEEKLY
			: self::FREQUENCY_DAILY;

		$time = isset( $_POST['wpvault_time'] ) ? sanitize_text_field( wp_unslash( $_POST['wpvault_time'] ) ) : '';

		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = $defaults['time'];
		}

		$day_of_week = isset( $_POST['wpvault_day_of_week'] ) ? max( 1, min( 7, (int) $_POST['wpvault_day_of_week'] ) ) : $defaults['day_of_week'];

		$type = isset( $_POST['wpvault_type'] ) ? sanitize_key( wp_unslash( $_POST['wpvault_type'] ) ) : $defaults['type'];

		if ( ! in_array( $type, array( Backup_Store::TYPE_FULL, Backup_Store::TYPE_DATABASE, Backup_Store::TYPE_FILES ), true ) ) {
			$type = $defaults['type'];
		}

		$retention = isset( $_POST['wpvault_retention'] ) ? max( 0, min( 90, (int) $_POST['wpvault_retention'] ) ) : $defaults['retention'];

		self::update_settings(
			array(
				'enabled'         => ! empty( $_POST['wpvault_enabled'] ),
				'frequency'       => $frequency,
				'time'            => $time,
				'day_of_week'     => $day_of_week,
				'type'            => $type,
				'exclude_cache'      => ! empty( $_POST['wpvault_exclude_cache'] ),
				'upload_to_drive'    => ! empty( $_POST['wpvault_upload_to_drive'] ),
				'upload_to_onedrive' => ! empty( $_POST['wpvault_upload_to_onedrive'] ),
				'retention'          => $retention,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_schedule_saved=1' ) );
		exit;
	}

	/**
	 * The wp-cron callback. Runs every 15 minutes; does nothing unless a
	 * schedule is enabled and actually due. Skips (without giving up on the
	 * day) while another job is active, so it never stacks a scheduled
	 * backup on top of one already running.
	 */
	public static function run_tick() {
		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) || empty( $settings['next_run'] ) ) {
			return;
		}

		if ( time() < (int) $settings['next_run'] ) {
			return;
		}

		if ( Job_Store::find_latest_active() ) {
			return; // Try again on the next tick rather than skipping this run entirely.
		}

		self::run_now( $settings );
	}

	private static function run_now( array $settings ) {
		$preflight = Preflight::run();

		if ( ! $preflight['ok'] ) {
			$failed = array_values(
				array_filter(
					$preflight['checks'],
					static function ( $check ) {
						return ! $check['ok'];
					}
				)
			);

			self::update_settings(
				array(
					'last_error' => $failed ? $failed[0]['message'] : __( 'Preflight failed.', 'wpvault' ),
					'next_run'   => self::compute_next_run( $settings, time() ),
				)
			);

			return;
		}

		$backup = Backup_Store::create( $settings['type'], Backup_Store::ORIGIN_SCHEDULED );
		$job    = Job_Store::create(
			Job_Store::TYPE_BACKUP,
			$backup->id,
			array(
				'backup_type'        => $settings['type'],
				'exclude_cache'      => (bool) $settings['exclude_cache'],
				'upload_to_drive'    => ! empty( $settings['upload_to_drive'] ) && Google_Drive::is_connected(),
				'upload_to_onedrive' => ! empty( $settings['upload_to_onedrive'] ) && One_Drive::is_connected(),
			)
		);

		Job_Runner::step( $job->id, 25 ); // Get it moving now; the minute-tick safety net finishes it.

		Backup_Store::prune_by_origin( Backup_Store::ORIGIN_SCHEDULED, $settings['retention'], $backup->id );

		self::update_settings(
			array(
				'last_run_at'    => time(),
				'last_backup_id' => $backup->id,
				'last_error'     => null,
				'next_run'       => self::compute_next_run( $settings, time() ),
			)
		);
	}

}
