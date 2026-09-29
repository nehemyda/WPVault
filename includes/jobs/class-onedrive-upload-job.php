<?php
/**
 * The onedrive_upload job's phase machine -- sibling to Drive_Upload_Job,
 * dispatched to from Job_Runner::run_phase() when $job->type is
 * Job_Store::TYPE_ONEDRIVE_UPLOAD, never called directly.
 *
 *   queued -> uploading_onedrive -> completed
 *
 * Uploads an already-finished local .wpvault package to OneDrive using a
 * resumable upload session, chunk by chunk, the same time-budgeted way
 * every other job phase in this plugin works.
 */

namespace WPVault\Jobs;

use WPVault\Backup\Backup_Store;
use WPVault\Storage\Local_Storage;
use WPVault\Storage\One_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Onedrive_Upload_Job {

	// Distinct from Drive_Upload_Job::STATUS_UPLOADING ('uploading') so
	// Job_Runner's phase switch can tell "mid-Drive-upload" apart from
	// "mid-OneDrive-upload" on a backup job that piggybacks one of these
	// as its own final phase.
	const STATUS_UPLOADING = 'uploading_onedrive';

	// Must be a multiple of 320 KiB per Graph's resumable upload protocol
	// (except the final chunk, which may be shorter). 10 MiB is 32 * 320 KiB.
	const CHUNK_SIZE = 32 * 320 * 1024;

	public static function run_phase( $job, $payload, $temp_dir, $time_budget ) {
		switch ( $job->status ) {
			case Job_Store::STATUS_QUEUED:
				return self::begin( $job, $payload );

			case self::STATUS_UPLOADING:
				return self::do_uploading( $job, $payload, $time_budget );

			default:
				return $job;
		}
	}

	/**
	 * Public (not just called from run_phase() above) so a backup job can
	 * also enter this same phase directly as its own optional final step --
	 * see Job_Runner::do_verifying()'s upload_to_onedrive branch.
	 */
	public static function begin( $job, $payload ) {
		$backup = Backup_Store::get_by_id( $job->backup_id );

		if ( ! $backup || ! $backup->file_path ) {
			throw new \RuntimeException( __( 'The backup to upload no longer exists.', 'wpvault' ) );
		}

		$path = Local_Storage::backups_dir() . $backup->file_path;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( __( 'The backup file is missing from disk.', 'wpvault' ) );
		}

		$total_size  = filesize( $path );
		$session_url = One_Drive::start_resumable_upload( basename( $backup->file_path ), $total_size );

		if ( is_wp_error( $session_url ) ) {
			throw new \RuntimeException( $session_url->get_error_message() );
		}

		$payload['file_path']      = $path;
		$payload['session_url']    = $session_url;
		$payload['total_size']     = $total_size;
		$payload['uploaded_bytes'] = 0;

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => self::STATUS_UPLOADING,
				'phase'        => self::STATUS_UPLOADING,
				'total_bytes'  => $total_size,
				'current_item' => __( 'Uploading to OneDrive…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Public for the same reason begin() is -- reused directly by a backup
	 * job's own tacked-on upload phase.
	 */
	public static function do_uploading( $job, $payload, $time_budget ) {
		$handle = fopen( $payload['file_path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			throw new \RuntimeException( __( 'Could not open the backup file to upload it.', 'wpvault' ) );
		}

		fseek( $handle, $payload['uploaded_bytes'] );

		$start  = microtime( true );
		$result = null;

		while ( microtime( true ) - $start < $time_budget ) {
			$chunk     = fread( $handle, self::CHUNK_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fread
			$chunk_len = strlen( $chunk );

			if ( 0 === $chunk_len ) {
				break;
			}

			$result = One_Drive::upload_chunk( $payload['session_url'], $chunk, $payload['uploaded_bytes'], $payload['total_size'] );

			if ( is_wp_error( $result ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new \RuntimeException( $result->get_error_message() );
			}

			$payload['uploaded_bytes'] += $chunk_len;

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $result && ! empty( $result['done'] ) ) {
			Backup_Store::update(
				$job->backup_id,
				array(
					'onedrive_file_id' => $result['file_id'],
					'onedrive_link'    => $result['web_view_link'],
				)
			);

			// Every upload counts toward the same cap regardless of what
			// triggered it (scheduled, "Backup Now", on-demand dropdown --
			// they all run through this same job class), so this belongs
			// right after a successful upload, not gated to one trigger.
			Backup_Store::prune_cloud_copies( 'onedrive', One_Drive::get_retention() );

			return Job_Store::checkpoint(
				$job->id,
				array(
					'status'           => Job_Store::STATUS_COMPLETED,
					'phase'            => Job_Store::STATUS_COMPLETED,
					'progress_percent' => 100,
					'processed_bytes'  => $payload['total_size'],
					'current_item'     => __( 'Saved to OneDrive.', 'wpvault' ),
					'payload'          => wp_json_encode( $payload ),
				)
			);
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'processed_bytes' => $payload['uploaded_bytes'],
				/* translators: %s: bytes uploaded so far, formatted (e.g. "12 MB") */
				'current_item'    => sprintf( __( 'Uploading to OneDrive… %s sent', 'wpvault' ), size_format( $payload['uploaded_bytes'] ) ),
				'payload'         => wp_json_encode( $payload ),
			)
		);
	}
}
