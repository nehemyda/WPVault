<?php
/**
 * The generic chunked-job engine (§11.1): locking, the time-budgeted step
 * loop, failure handling, and cron/foreground reuse are all job-type
 * agnostic and live here. The actual phase sequence is dispatched by
 * job->type -- the backup phases (queued -> scanning -> preparing ->
 * processing -> finalizing -> verifying -> completed) are the private
 * methods below; restore's own phase sequence lives in
 * WPVault\Restore\Restore_Job and is reached via one branch in run_phase().
 *
 * step() is the only public entry point. It is called both by the REST
 * "run next chunk" endpoint (short time budget, so a browser polling it
 * gets frequent progress updates) and by the cron safety net (a longer
 * budget, since nobody is watching a progress bar during an unattended
 * resume). Each call does as much bounded work as fits in its time budget,
 * persists a checkpoint, and returns -- it never assumes it will be the
 * call that finishes the job.
 *
 * A per-job lock file (flock, non-blocking) stops the foreground poller and
 * the cron safety net from ever stepping the same job at the same time --
 * without it, two processes racing to reopen/close the same in-progress
 * zip could corrupt it.
 */

namespace WPVault\Jobs;

use WPVault\Backup\Backup_Store;
use WPVault\Backup\Database_Exporter;
use WPVault\Backup\File_Scanner;
use WPVault\Backup\Manifest;
use WPVault\Backup\Package_Builder;
use WPVault\Diagnostics\Log_Store;
use WPVault\Diagnostics\Preflight;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\Local_Storage;
use WPVault\Storage\One_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Runner {

	private static function job_token( $job_id ) {
		return 'job-' . (int) $job_id;
	}

	/**
	 * @return object|null The job row after stepping (unchanged if it was
	 *                      already terminal, or if another request holds
	 *                      the lock).
	 */
	public static function step( $job_id, $time_budget = 4 ) {
		$job = Job_Store::get_by_id( $job_id );

		if ( ! $job || Job_Store::is_terminal( $job->status ) ) {
			return $job;
		}

		$temp_dir  = Local_Storage::new_job_temp_dir( self::job_token( $job_id ) );
		$lock_path = $temp_dir . 'job.lock';
		$lock      = fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( $lock ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			return $job;
		}

		$start = microtime( true );

		try {
			while ( microtime( true ) - $start < $time_budget ) {
				if ( Job_Store::is_terminal( $job->status ) ) {
					break;
				}

				$remaining = $time_budget - ( microtime( true ) - $start );

				if ( $remaining <= 0.2 ) {
					break;
				}

				$job = self::run_phase( $job, $temp_dir, $remaining );
			}
		} catch ( \Throwable $e ) {
			Log_Store::error( $e->getMessage(), 'wpvault_job_failed', $job_id );
			$job = Job_Store::update(
				$job_id,
				array(
					'status'        => Job_Store::STATUS_FAILED,
					'phase'         => Job_Store::STATUS_FAILED,
					'error_message' => $e->getMessage(),
				)
			);
			self::mark_backup_failed_if_producing( $job );
		}

		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( Job_Store::is_terminal( $job->status ) ) {
			Local_Storage::remove_job_temp_dir( self::job_token( $job_id ) );
		}

		return $job;
	}

	public static function cancel( $job_id ) {
		$job = Job_Store::get_by_id( $job_id );

		if ( ! $job || Job_Store::is_terminal( $job->status ) ) {
			return $job;
		}

		$job = Job_Store::update( $job_id, array( 'status' => Job_Store::STATUS_CANCELLED, 'phase' => Job_Store::STATUS_CANCELLED ) );
		self::mark_backup_failed_if_producing( $job );
		self::cancel_snapshot_if_restoring( $job );
		Local_Storage::remove_job_temp_dir( self::job_token( $job_id ) );

		return $job;
	}

	/**
	 * A restore's own safety-snapshot backup (Restore_Job::begin()) is a
	 * separate, independently-stepped job, not a sub-step of the restore job
	 * itself -- cancelling the restore while that snapshot is still running
	 * would otherwise leave it stepping on unattended via cron/Dashboard
	 * polling with no visible link back to the restore the user just
	 * cancelled.
	 */
	private static function cancel_snapshot_if_restoring( $job ) {
		if ( Job_Store::TYPE_RESTORE !== $job->type ) {
			return;
		}

		$payload      = Job_Store::get_payload( $job );
		$snapshot_id  = isset( $payload['snapshot_job_id'] ) ? (int) $payload['snapshot_job_id'] : 0;

		if ( $snapshot_id ) {
			self::cancel( $snapshot_id );
		}
	}

	/**
	 * job->backup_id means something different depending on job->type: for
	 * a backup job it's the package this job is producing (failing the job
	 * should fail that backup too); for a restore job it's the existing,
	 * already-good package being restored FROM (failing the restore must
	 * never mark that source backup failed -- it did nothing wrong).
	 */
	private static function mark_backup_failed_if_producing( $job ) {
		if ( Job_Store::TYPE_BACKUP === $job->type ) {
			Backup_Store::mark_failed( $job->backup_id );
		}
	}

	private static function run_phase( $job, $temp_dir, $time_budget ) {
		$payload = Job_Store::get_payload( $job );

		if ( Job_Store::TYPE_RESTORE === $job->type ) {
			return \WPVault\Restore\Restore_Job::run_phase( $job, $payload, $temp_dir, $time_budget );
		}

		if ( Job_Store::TYPE_DRIVE_UPLOAD === $job->type ) {
			return Drive_Upload_Job::run_phase( $job, $payload, $temp_dir, $time_budget );
		}

		if ( Job_Store::TYPE_ONEDRIVE_UPLOAD === $job->type ) {
			return Onedrive_Upload_Job::run_phase( $job, $payload, $temp_dir, $time_budget );
		}

		if ( Job_Store::TYPE_DRIVE_IMPORT === $job->type ) {
			return Drive_Import_Job::run_phase( $job, $payload, $temp_dir, $time_budget );
		}

		if ( Job_Store::TYPE_ONEDRIVE_IMPORT === $job->type ) {
			return Onedrive_Import_Job::run_phase( $job, $payload, $temp_dir, $time_budget );
		}

		switch ( $job->status ) {
			case Job_Store::STATUS_QUEUED:
				return self::begin_scanning( $job, $payload, $temp_dir );

			case Job_Store::STATUS_SCANNING:
				return self::do_scanning( $job, $payload, $temp_dir, $time_budget );

			case Job_Store::STATUS_PREPARING:
				return self::do_preparing( $job, $payload, $temp_dir );

			case Job_Store::STATUS_PROCESSING:
				return self::do_processing( $job, $payload, $temp_dir, $time_budget );

			case Job_Store::STATUS_FINALIZING:
				return self::do_finalizing( $job, $payload, $temp_dir );

			case Job_Store::STATUS_VERIFYING:
				return self::do_verifying( $job, $payload, $temp_dir );

			// Reuses Drive_Upload_Job's/Onedrive_Upload_Job's own phase
			// methods directly -- an optional final step (or two, chained)
			// tacked onto a backup job when the caller (Scheduled_Backups,
			// or the browser's "Also save to..." checkboxes) asked for it,
			// entered from do_verifying() below.
			case Drive_Upload_Job::STATUS_UPLOADING:
				return self::do_drive_uploading( $job, $payload, $time_budget );

			case Onedrive_Upload_Job::STATUS_UPLOADING:
				return self::do_onedrive_uploading( $job, $payload, $time_budget );

			default:
				return $job;
		}
	}

	/**
	 * A Drive upload failure must never fail the backup job it's tacked
	 * onto -- the local backup already succeeded and stays exactly as
	 * verified either way. Unlike Drive_Upload_Job's own standalone jobs
	 * (where an upload failure correctly IS the whole job failing), this
	 * catches it and completes the backup job anyway, just without a
	 * Drive copy. On success (or failure), also checks whether a OneDrive
	 * upload was requested too and chains into that before finishing --
	 * a backup job can only occupy one phase at a time, so two requested
	 * cloud copies run one after the other rather than concurrently.
	 */
	private static function do_drive_uploading( $job, $payload, $time_budget ) {
		try {
			$result = Drive_Upload_Job::do_uploading( $job, $payload, $time_budget );
		} catch ( \Throwable $e ) {
			Log_Store::error( $e->getMessage(), 'wpvault_drive_upload_failed', $job->id );

			return self::begin_onedrive_or_complete( $job, $payload, true );
		}

		if ( Job_Store::STATUS_COMPLETED === $result->status ) {
			return self::begin_onedrive_or_complete( $job, Job_Store::get_payload( $result ), false );
		}

		return $result;
	}

	/**
	 * Same reasoning as do_drive_uploading() -- a OneDrive upload failure
	 * must never fail the backup job it's tacked onto.
	 */
	private static function do_onedrive_uploading( $job, $payload, $time_budget ) {
		try {
			return Onedrive_Upload_Job::do_uploading( $job, $payload, $time_budget );
		} catch ( \Throwable $e ) {
			Log_Store::error( $e->getMessage(), 'wpvault_onedrive_upload_failed', $job->id );

			return Job_Store::checkpoint(
				$job->id,
				array(
					'status'           => Job_Store::STATUS_COMPLETED,
					'phase'            => Job_Store::STATUS_COMPLETED,
					'progress_percent' => 100,
					'current_item'     => __( 'Backup complete (OneDrive upload failed).', 'wpvault' ),
				)
			);
		}
	}

	/**
	 * The hand-off point between the two optional cloud-upload phases: after
	 * Drive's phase finishes (or is skipped because it wasn't requested),
	 * either begin OneDrive's phase or mark the backup job complete.
	 */
	private static function begin_onedrive_or_complete( $job, $payload, $earlier_upload_failed ) {
		if ( ! empty( $payload['upload_to_onedrive'] ) && One_Drive::is_connected() ) {
			try {
				return Onedrive_Upload_Job::begin( $job, $payload );
			} catch ( \Throwable $e ) {
				Log_Store::error( $e->getMessage(), 'wpvault_onedrive_upload_failed', $job->id );
				$earlier_upload_failed = true;
			}
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'           => Job_Store::STATUS_COMPLETED,
				'phase'            => Job_Store::STATUS_COMPLETED,
				'progress_percent' => 100,
				'current_item'     => $earlier_upload_failed
					? __( 'Backup complete (a cloud upload failed).', 'wpvault' )
					: __( 'Backup complete.', 'wpvault' ),
			)
		);
	}

	private static function needs_files( $payload ) {
		return in_array( $payload['backup_type'], array( 'full', 'files' ), true );
	}

	private static function needs_database( $payload ) {
		return in_array( $payload['backup_type'], array( 'full', 'database' ), true );
	}

	private static function begin_scanning( $job, $payload, $temp_dir ) {
		if ( ! self::needs_files( $payload ) ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'status'  => Job_Store::STATUS_PREPARING,
					'phase'   => Job_Store::STATUS_PREPARING,
					'payload' => wp_json_encode( $payload ),
				)
			);
		}

		$payload['scan_state']    = File_Scanner::initial_state();
		$payload['filelist_path'] = $temp_dir . 'filelist.txt';

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => Job_Store::STATUS_SCANNING,
				'phase'        => Job_Store::STATUS_SCANNING,
				'current_item' => __( 'Scanning files…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_scanning( $job, $payload, $temp_dir, $time_budget ) {
		$exclusions = File_Scanner::active_exclusions( ! empty( $payload['exclude_cache'] ) );
		$root       = trailingslashit( ABSPATH );

		$payload['scan_state'] = File_Scanner::scan_step( $root, $payload['scan_state'], $exclusions, $payload['filelist_path'], $time_budget );
		$scan                  = $payload['scan_state'];

		if ( ! $scan['done'] ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_items' => $scan['scanned_files'],
					/* translators: %d: number of files found so far */
					'current_item'    => sprintf( __( 'Scanning… %d files found', 'wpvault' ), $scan['scanned_files'] ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => Job_Store::STATUS_PREPARING,
				'phase'        => Job_Store::STATUS_PREPARING,
				'total_items'  => $scan['scanned_files'],
				'total_bytes'  => $scan['total_bytes'],
				'current_item' => __( 'Preparing…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_preparing( $job, $payload, $temp_dir ) {
		$files_bytes = isset( $payload['scan_state']['total_bytes'] ) ? (int) $payload['scan_state']['total_bytes'] : 0;
		$db_estimate = 0;

		if ( self::needs_database( $payload ) ) {
			$tables                 = Database_Exporter::discover_tables();
			$row_counts              = Database_Exporter::estimate_row_counts( $tables );
			$payload['db_state']    = Database_Exporter::initial_state( $tables );
			$payload['table_count'] = count( $tables );
			// A rough per-row size assumption purely to size the disk-space
			// check -- real progress tracks actual bytes written as the
			// export proceeds, not this estimate.
			$db_estimate = array_sum( $row_counts ) * 1024;
		} else {
			$payload['table_count'] = 0;
		}

		$space_check = Preflight::check_disk_space( $files_bytes + $db_estimate );

		if ( is_wp_error( $space_check ) ) {
			throw new \RuntimeException( $space_check->get_error_message() );
		}

		$payload['db_export_path']   = $temp_dir . 'db-export.sql.gz';
		$payload['zip_path']         = $temp_dir . 'package.wpvault.part';
		$payload['files_state']      = Package_Builder::initial_files_state();
		$payload['processing_stage'] = self::needs_database( $payload ) ? 'database' : 'files';

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => Job_Store::STATUS_PROCESSING,
				'phase'        => Job_Store::STATUS_PROCESSING,
				'total_bytes'  => $files_bytes + $db_estimate,
				'current_item' => __( 'Starting backup…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_processing( $job, $payload, $temp_dir, $time_budget ) {
		if ( 'database' === $payload['processing_stage'] ) {
			$payload['db_state'] = Database_Exporter::export_step( $payload['db_state'], $payload['db_export_path'], $time_budget );

			if ( $payload['db_state']['done'] ) {
				$payload['processing_stage'] = self::needs_files( $payload ) ? 'files' : 'done';
			}

			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_bytes' => $payload['db_state']['bytes_written'],
					'current_item'    => __( 'Exporting database…', 'wpvault' ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		if ( 'files' === $payload['processing_stage'] ) {
			$root                    = trailingslashit( ABSPATH );
			$payload['files_state']  = Package_Builder::add_files_step( $payload['zip_path'], $payload['filelist_path'], $root, $payload['files_state'], $time_budget );

			if ( $payload['files_state']['done'] ) {
				$payload['processing_stage'] = 'done';
			}

			$db_bytes = isset( $payload['db_state']['bytes_written'] ) ? $payload['db_state']['bytes_written'] : 0;

			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_bytes' => $db_bytes + $payload['files_state']['bytes_added'],
					'processed_items' => $payload['files_state']['files_added'],
					/* translators: 1: files added so far, 2: total files */
					'current_item'    => sprintf( __( 'Adding files… %1$d of %2$d', 'wpvault' ), $payload['files_state']['files_added'], $job->total_items ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => Job_Store::STATUS_FINALIZING,
				'phase'        => Job_Store::STATUS_FINALIZING,
				'current_item' => __( 'Finalizing package…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_finalizing( $job, $payload, $temp_dir ) {
		$backup = Backup_Store::get_by_id( $job->backup_id );

		if ( ! $backup ) {
			throw new \RuntimeException( __( 'The backup record no longer exists -- it may have been deleted while this job was running.', 'wpvault' ) );
		}

		$manifest_stats = array(
			'table_count'   => isset( $payload['table_count'] ) ? $payload['table_count'] : 0,
			'file_count'    => isset( $payload['scan_state']['scanned_files'] ) ? $payload['scan_state']['scanned_files'] : 0,
			'database_size' => isset( $payload['db_state']['bytes_written'] ) ? $payload['db_state']['bytes_written'] : 0,
			'files_size'    => isset( $payload['scan_state']['total_bytes'] ) ? $payload['scan_state']['total_bytes'] : 0,
		);

		$exclusions       = File_Scanner::active_exclusions( ! empty( $payload['exclude_cache'] ) );
		$exclusion_labels = wp_list_pluck( $exclusions, 'label' );
		$manifest         = Manifest::build( $backup, $manifest_stats, $exclusion_labels );

		Package_Builder::finalize(
			$payload['zip_path'],
			$manifest,
			self::needs_database( $payload ) ? $payload['db_export_path'] : null
		);

		$filename = self::build_filename( $backup );
		$storage  = new Local_Storage();
		$moved    = $storage->put( $payload['zip_path'], $filename );

		if ( is_wp_error( $moved ) ) {
			throw new \RuntimeException( $moved->get_error_message() );
		}

		$final_path   = Local_Storage::backups_dir() . $filename;
		$checksum     = hash_file( 'sha256', $final_path );
		$package_size = filesize( $final_path );

		Backup_Store::mark_finalized(
			$backup->id,
			$filename,
			$package_size,
			$checksum,
			WPVAULT_PACKAGE_FORMAT_VERSION,
			$manifest_stats['file_count'],
			$manifest_stats['table_count']
		);

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => Job_Store::STATUS_VERIFYING,
				'phase'        => Job_Store::STATUS_VERIFYING,
				'current_item' => __( 'Verifying package…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_verifying( $job, $payload, $temp_dir ) {
		$backup = Backup_Store::get_by_id( $job->backup_id );

		if ( ! $backup ) {
			throw new \RuntimeException( __( 'The backup record no longer exists -- it may have been deleted while this job was running.', 'wpvault' ) );
		}

		$path = Local_Storage::backups_dir() . $backup->file_path;

		$structure = Package_Builder::verify_structure( $path, $backup->type );

		if ( is_wp_error( $structure ) ) {
			throw new \RuntimeException( $structure->get_error_message() );
		}

		$recomputed = hash_file( 'sha256', $path );

		if ( $recomputed !== $backup->checksum ) {
			throw new \RuntimeException( __( 'Checksum mismatch after finalizing -- the package may be corrupt.', 'wpvault' ) );
		}

		Backup_Store::mark_verified( $backup->id );
		Log_Store::info( __( 'Backup completed and verified.', 'wpvault' ), 'wpvault_backup_verified', $job->id );

		if ( ! empty( $payload['upload_to_drive'] ) && Google_Drive::is_connected() ) {
			try {
				return Drive_Upload_Job::begin( $job, $payload );
			} catch ( \Throwable $e ) {
				// Same reasoning as do_drive_uploading() -- a failure here
				// must not fail the backup job; just skip the Drive copy,
				// and still try a requested OneDrive copy before finishing.
				Log_Store::error( $e->getMessage(), 'wpvault_drive_upload_failed', $job->id );

				return self::begin_onedrive_or_complete( $job, $payload, true );
			}
		}

		return self::begin_onedrive_or_complete( $job, $payload, false );
	}

	private static function build_filename( $backup ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = sanitize_file_name( $host ? $host : 'site' );

		return sprintf( '%s-%s-%s.wpvault', $slug, gmdate( 'Y-m-d-His' ), (int) $backup->id );
	}
}
