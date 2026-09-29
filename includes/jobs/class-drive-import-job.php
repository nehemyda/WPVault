<?php
/**
 * The drive_import job's phase machine, mirroring the role Drive_Upload_Job
 * plays in the opposite direction -- dispatched to from
 * Job_Runner::run_phase() when $job->type is Job_Store::TYPE_DRIVE_IMPORT,
 * never called directly.
 *
 *   queued -> downloading_from_drive -> completed
 *
 * Downloads an existing .wpvault package from this site's own connected
 * Google Drive in chunks via HTTP Range requests, the same time-budgeted way
 * every other job phase in this plugin works, then runs it through the same
 * validate-then-finalize pipeline a browser-uploaded import uses
 * (Import_Finalizer). Unlike a browser upload, this is a server-to-server
 * transfer with nothing for the browser to relay, so a closed tab is picked
 * back up by the wpvault_cron_tick safety net exactly like a backup or
 * restore.
 */

namespace WPVault\Jobs;

use WPVault\Backup\Import_Finalizer;
use WPVault\Diagnostics\Preflight;
use WPVault\Storage\Google_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drive_Import_Job {

	// wpvault_jobs.status/phase are VARCHAR(20) -- must fit within that,
	// confirmed the hard way: a longer string here doesn't throw, it just
	// makes every checkpoint() silently fail $wpdb->update() and the job
	// sits at "queued" forever with no error anywhere.
	const STATUS_DOWNLOADING = 'downloading_drive';

	// No protocol-mandated multiple here (unlike the upload side's chunk
	// sizes) -- Drive's media download Range support has no alignment
	// requirement, so this is just a reasonable per-request size.
	const CHUNK_SIZE = 8 * 1024 * 1024;

	public static function run_phase( $job, $payload, $temp_dir, $time_budget ) {
		switch ( $job->status ) {
			case Job_Store::STATUS_QUEUED:
				return self::begin( $job, $payload, $temp_dir );

			case self::STATUS_DOWNLOADING:
				return self::do_downloading( $job, $payload, $temp_dir, $time_budget );

			default:
				return $job;
		}
	}

	private static function begin( $job, $payload, $temp_dir ) {
		$info = Google_Drive::get_file_info( $payload['drive_file_id'] );

		if ( is_wp_error( $info ) ) {
			throw new \RuntimeException( $info->get_error_message() );
		}

		$space_check = Preflight::check_disk_space( $info['size'] );

		if ( is_wp_error( $space_check ) ) {
			throw new \RuntimeException( $space_check->get_error_message() );
		}

		$working_path = $temp_dir . 'package.wpvault.part';
		file_put_contents( $working_path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$payload['total_size']       = $info['size'];
		$payload['downloaded_bytes'] = 0;
		$payload['working_path']     = $working_path;

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => self::STATUS_DOWNLOADING,
				'phase'        => self::STATUS_DOWNLOADING,
				'total_bytes'  => $info['size'],
				'current_item' => __( 'Downloading from Google Drive…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_downloading( $job, $payload, $temp_dir, $time_budget ) {
		$start = microtime( true );

		while ( $payload['downloaded_bytes'] < $payload['total_size'] && microtime( true ) - $start < $time_budget ) {
			$length = min( self::CHUNK_SIZE, $payload['total_size'] - $payload['downloaded_bytes'] );
			$chunk  = Google_Drive::download_chunk( $payload['drive_file_id'], $payload['downloaded_bytes'], $length );

			if ( is_wp_error( $chunk ) ) {
				throw new \RuntimeException( $chunk->get_error_message() );
			}

			$handle = fopen( $payload['working_path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( ! $handle ) {
				throw new \RuntimeException( __( 'Could not write the downloaded chunk to disk.', 'wpvault' ) );
			}

			fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$payload['downloaded_bytes'] += strlen( $chunk );
		}

		if ( $payload['downloaded_bytes'] < $payload['total_size'] ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_bytes' => $payload['downloaded_bytes'],
					/* translators: 1: bytes downloaded so far, 2: total package size */
					'current_item'    => sprintf( __( 'Downloading from Google Drive… %1$s of %2$s', 'wpvault' ), size_format( $payload['downloaded_bytes'] ), size_format( $payload['total_size'] ) ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		$backup = Import_Finalizer::finalize( $payload['working_path'] );

		if ( is_wp_error( $backup ) ) {
			throw new \RuntimeException( $backup->get_error_message() );
		}

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'           => Job_Store::STATUS_COMPLETED,
				'phase'            => Job_Store::STATUS_COMPLETED,
				'backup_id'        => $backup->id,
				'progress_percent' => 100,
				'processed_bytes'  => $payload['total_size'],
				'current_item'     => __( 'Import complete.', 'wpvault' ),
				'payload'          => wp_json_encode( $payload ),
			)
		);
	}
}
