<?php
/**
 * wp wpvault ... (§23). Every command here is a thin wrapper over the same
 * service layer the admin UI calls -- WP-CLI never gets its own copy of the
 * backup or restore engine, only a different front end for driving it.
 *
 * Registered only under WP-CLI (see the WP_CLI guard around
 * WP_CLI::add_command() in Plugin::init()), so none of this loads on a
 * normal web request.
 */

namespace WPVault\Cli;

use WPVault\Backup\Backup_Store;
use WPVault\Backup\Backup_Verifier;
use WPVault\Diagnostics\Preflight;
use WPVault\Jobs\Job_Runner;
use WPVault\Jobs\Job_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli_Commands {

	// A CLI invocation blocks naturally (no browser to keep responsive with
	// frequent small updates), so each step() call can claim more time per
	// call than the REST endpoint's 4s -- fewer round trips for the same job.
	const TIME_BUDGET = 10;

	/**
	 * Creates a backup and waits for it to finish.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : What to back up.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - database
	 *   - files
	 * ---
	 *
	 * [--exclude-cache=<bool>]
	 * : Exclude cache/temporary files.
	 * ---
	 * default: "true"
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault backup
	 *     wp wpvault backup --type=database
	 *
	 * @when after_wp_load
	 */
	public function backup( $args, $assoc_args ) {
		$type          = \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', Backup_Store::TYPE_FULL );
		$exclude_cache = filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'exclude-cache', true ), FILTER_VALIDATE_BOOLEAN );

		$this->run_preflight_or_die( Preflight::run() );

		$backup = Backup_Store::create( $type, Backup_Store::ORIGIN_CLI );
		$job    = Job_Store::create(
			Job_Store::TYPE_BACKUP,
			$backup->id,
			array(
				'backup_type'   => $type,
				'exclude_cache' => $exclude_cache,
			)
		);

		\WP_CLI::log( sprintf( 'Starting %s backup (backup #%d, job #%d)...', $type, $backup->id, $job->id ) );

		$job    = $this->drive_job( $job->id, 'Backing up' );
		$backup = Backup_Store::get_by_id( $backup->id );

		if ( Backup_Store::STATUS_VERIFIED === $backup->status ) {
			\WP_CLI::success(
				sprintf(
					'Backup #%d complete and verified: %s (%s)',
					$backup->id,
					$backup->file_path,
					size_format( $backup->package_size )
				)
			);
			return;
		}

		\WP_CLI::error( $job->error_message ? $job->error_message : sprintf( 'Backup did not complete successfully (status: %s).', $backup->status ) );
	}

	/**
	 * Lists backups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault backups
	 *     wp wpvault backups --format=json
	 *
	 * @when after_wp_load
	 */
	public function backups( $args, $assoc_args ) {
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$backups = Backup_Store::get_all( 100 );

		$items = array_map(
			static function ( $backup ) {
				return array(
					'id'         => (int) $backup->id,
					'type'       => $backup->type,
					'status'     => $backup->status,
					'size'       => $backup->package_size ? size_format( $backup->package_size ) : '',
					'created_at' => $backup->created_at,
					'source_url' => $backup->source_url,
				);
			},
			$backups
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'type', 'status', 'size', 'created_at', 'source_url' ) );
	}

	/**
	 * Re-verifies an existing backup package.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>
	 * : The backup to verify.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault verify 12
	 *
	 * @when after_wp_load
	 */
	public function verify( $args, $assoc_args ) {
		list( $backup_id ) = $args;
		$result             = Backup_Verifier::verify( (int) $backup_id );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Backup #%d verified.', (int) $backup_id ) );
	}

	/**
	 * Restores a backup onto this site. Destructive -- prompts for
	 * confirmation unless --yes is passed.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>
	 * : The backup to restore.
	 *
	 * [--snapshot=<bool>]
	 * : Take a safety snapshot of the current state first. Pass --no-snapshot to skip -- not recommended.
	 * ---
	 * default: "true"
	 * ---
	 *
	 * [--old-url=<url>]
	 * : URL to replace. Defaults to the backup's own recorded source URL.
	 *
	 * [--new-url=<url>]
	 * : Replacement URL. Defaults to this site's current home_url(). Leave
	 * matching --old-url (the default when restoring back onto the same
	 * site) to skip URL replacement entirely.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault restore 12
	 *     wp wpvault restore 12 --yes --old-url=https://old.example --new-url=https://new.example
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc_args ) {
		list( $backup_id ) = $args;
		$backup             = Backup_Store::get_by_id( (int) $backup_id );

		if ( ! $backup ) {
			\WP_CLI::error( 'Backup not found.' );
		}

		$this->run_preflight_or_die( Preflight::check_restore( $backup ) );

		if ( Job_Store::find_latest_active() ) {
			\WP_CLI::error( 'Another backup or restore job is already running. Wait for it to finish first.' );
		}

		$create_snapshot = filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'snapshot', true ), FILTER_VALIDATE_BOOLEAN );
		$old_url         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'old-url', (string) $backup->source_url );
		$new_url         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'new-url', home_url() );

		\WP_CLI::log( sprintf( 'Backup #%d: %s, %s', $backup->id, $backup->type, size_format( (int) $backup->package_size ) ) );
		\WP_CLI::log( 'This will replace this site\'s database and files (wp-config.php and .htaccess excepted).' );
		\WP_CLI::log( $create_snapshot ? 'A safety snapshot of the current state will be taken first.' : 'NO safety snapshot will be taken (--no-snapshot).' );

		if ( $old_url !== $new_url ) {
			\WP_CLI::log( sprintf( 'URLs will be replaced: %s -> %s', $old_url, $new_url ) );
		}

		\WP_CLI::confirm( 'Continue?', $assoc_args );

		$job = Job_Store::create(
			Job_Store::TYPE_RESTORE,
			$backup->id,
			array(
				'create_snapshot' => $create_snapshot,
				'old_url'         => $old_url,
				'new_url'         => $new_url,
			)
		);

		$job = $this->drive_job( $job->id, 'Restoring' );

		if ( Job_Store::STATUS_COMPLETED === $job->status ) {
			\WP_CLI::success( 'Restore complete.' );
			return;
		}

		\WP_CLI::error( $job->error_message ? $job->error_message : sprintf( 'Restore did not complete (status: %s).', $job->status ) );
	}

	/**
	 * Removes orphaned temporary job directories -- left behind only when a
	 * PHP process died outside Job_Runner::step()'s own cleanup (a fatal
	 * error, an OOM kill, a killed process), since a job that finishes
	 * normally (however it ends) already cleans up after itself.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault cleanup
	 *
	 * @when after_wp_load
	 */
	public function cleanup( $args, $assoc_args ) {
		$removed = 0;

		foreach ( (array) glob( Local_Storage::temp_dir() . 'job-*' ) as $dir ) {
			if ( ! is_dir( $dir ) || ! preg_match( '/^job-(\d+)$/', basename( $dir ), $matches ) ) {
				continue;
			}

			$job = Job_Store::get_by_id( (int) $matches[1] );

			if ( $job && ! Job_Store::is_terminal( $job->status ) ) {
				continue; // Still active -- leave it alone.
			}

			Local_Storage::remove_job_temp_dir( basename( $dir ) );
			$removed++;
		}

		$removed += Local_Storage::sweep_stale_import_dirs();

		\WP_CLI::success( sprintf( 'Removed %d orphaned temporary director%s.', $removed, 1 === $removed ? 'y' : 'ies' ) );
	}

	/**
	 * Shows a job's current status and progress.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The job to inspect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault status 4
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		list( $job_id ) = $args;
		$job             = Job_Store::get_by_id( (int) $job_id );

		if ( ! $job ) {
			\WP_CLI::error( 'Job not found.' );
		}

		\WP_CLI::log( sprintf( 'Job #%d (%s)', $job->id, $job->type ) );
		\WP_CLI::log( sprintf( 'Status:   %s', $job->status ) );
		\WP_CLI::log( sprintf( 'Progress: %d%%', $this->percent_for( $job ) ) );

		if ( $job->current_item ) {
			\WP_CLI::log( sprintf( 'Current:  %s', $job->current_item ) );
		}

		if ( $job->error_message ) {
			\WP_CLI::log( sprintf( 'Error:    %s', $job->error_message ) );
		}
	}

	/**
	 * Shows the current scheduled-backup configuration -- read-only; change
	 * it from the Settings screen in wp-admin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpvault schedule
	 *
	 * @when after_wp_load
	 */
	public function schedule( $args, $assoc_args ) {
		$schedule = \WPVault\Jobs\Scheduled_Backups::get_settings();

		if ( empty( $schedule['enabled'] ) ) {
			\WP_CLI::log( 'Scheduled backups are turned off.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Enabled:   yes (%s, %s)', $schedule['frequency'], $schedule['type'] ) );
		\WP_CLI::log( sprintf( 'Time:      %s (%s)', $schedule['time'], wp_timezone_string() ) );

		if ( 'weekly' === $schedule['frequency'] ) {
			$days = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
			\WP_CLI::log( sprintf( 'Day:       %s', $days[ (int) $schedule['day_of_week'] ] ?? $schedule['day_of_week'] ) );
		}

		\WP_CLI::log( sprintf( 'Retention: %s', $schedule['retention'] > 0 ? "last {$schedule['retention']} scheduled backups" : 'unlimited' ) );

		if ( $schedule['next_run'] ) {
			\WP_CLI::log( sprintf( 'Next run:  %s', wp_date( 'Y-m-d H:i:s', (int) $schedule['next_run'] ) ) );
		}

		if ( $schedule['last_run_at'] ) {
			\WP_CLI::log( sprintf( 'Last run:  %s (backup #%d)', wp_date( 'Y-m-d H:i:s', (int) $schedule['last_run_at'] ), (int) $schedule['last_backup_id'] ) );
		}

		if ( $schedule['last_error'] ) {
			\WP_CLI::log( sprintf( 'Last error: %s', $schedule['last_error'] ) );
		}
	}

	private function run_preflight_or_die( array $preflight ) {
		if ( $preflight['ok'] ) {
			return;
		}

		foreach ( $preflight['checks'] as $check ) {
			if ( ! $check['ok'] ) {
				\WP_CLI::warning( $check['label'] . ': ' . $check['message'] );
			}
		}

		\WP_CLI::error( 'Preflight checks failed.' );
	}

	/**
	 * Drives a job to completion, rendering a progress bar ticked by the
	 * same processed_bytes/total_bytes percentage Jobs_Controller uses for
	 * the browser's progress bar -- one definition of "how far along", used
	 * by both front ends.
	 */
	private function drive_job( $job_id, $label ) {
		$progress     = \WP_CLI\Utils\make_progress_bar( $label, 100 );
		$last_percent = 0;

		while ( true ) {
			$job = Job_Runner::step( $job_id, self::TIME_BUDGET );

			if ( ! $job ) {
				$progress->finish();
				\WP_CLI::error( 'The job disappeared unexpectedly.' );
			}

			$percent = $this->percent_for( $job );

			if ( $percent > $last_percent ) {
				$progress->tick( $percent - $last_percent );
				$last_percent = $percent;
			}

			if ( Job_Store::is_terminal( $job->status ) ) {
				$progress->finish();
				return $job;
			}
		}
	}

	private function percent_for( $job ) {
		if ( Job_Store::STATUS_COMPLETED === $job->status ) {
			return 100;
		}

		if ( (int) $job->total_bytes > 0 ) {
			return (int) min( 99, round( ( $job->processed_bytes / $job->total_bytes ) * 100 ) );
		}

		return 0;
	}
}
