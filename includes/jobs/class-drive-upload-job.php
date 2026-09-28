<?php
/**
 * The drive_upload job's phase machine, mirroring the role Restore_Job
 * plays for a restore job -- dispatched to from Job_Runner::run_phase()
 * when $job->type is Job_Store::TYPE_DRIVE_UPLOAD, never called directly.
 *
 *   queued -> uploading -> completed
 *
 * Uploads an already-finished local .wpvault package to Google Drive using
 * a resumable upload session, chunk by chunk, the same time-budgeted way
 * every other job phase in this plugin works -- so a large package
 * uploading over a slow connection survives a closed browser tab exactly
 * like a backup or restore does, picked up again by the wpvault_cron_tick
 * safety net.
 */

namespace WPVault\Jobs;

use WPVault\Backup\Backup_Store;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drive_Upload_Job {

	const STATUS_UPLOADING = 'uploading';

	// Must be a multiple of 256 KiB per Drive's resumable upload protocol
	// (except the final chunk, which may be shorter). 8 MiB is 32 * 256 KiB.
	const CHUNK_SIZE = 8 * 1024 * 1024;

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
	 * see Job_Runner::do_verifying()'s upload_to_drive branch.
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
		$session_url = Google_Drive::start_resumable_upload( basename( $backup->file_path ), $total_size );

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
				'current_item' => __( 'Uploading to Google Drive…', 'wpvault' ),
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

			$result = Google_Drive::upload_chunk( $payload['session_url'], $chunk, $payload['uploaded_bytes'], $payload['total_size'] );

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
					'drive_file_id' => $result['file_id'],
					'drive_link'    => $result['web_view_link'],
				)
			);

			return Job_Store::checkpoint(
				$job->id,
				array(
					'status'           => Job_Store::STATUS_COMPLETED,
					'phase'            => Job_Store::STATUS_COMPLETED,
					'progress_percent' => 100,
					'processed_bytes'  => $payload['total_size'],
					'current_item'     => __( 'Saved to Google Drive.', 'wpvault' ),
					'payload'          => wp_json_encode( $payload ),
				)
			);
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'processed_bytes' => $payload['uploaded_bytes'],
				/* translators: %s: bytes uploaded so far, formatted (e.g. "12 MB") */
				'current_item'    => sprintf( __( 'Uploading to Google Drive… %s sent', 'wpvault' ), size_format( $payload['uploaded_bytes'] ) ),
				'payload'         => wp_json_encode( $payload ),
			)
		);
	}
}
