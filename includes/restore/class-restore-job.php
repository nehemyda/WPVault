<?php
/**
 * The restore job's phase machine, mirroring the role Job_Runner's private
 * backup methods play for a backup job -- dispatched to from
 * Job_Runner::run_phase() when $job->type is Job_Store::TYPE_RESTORE, never
 * called directly.
 *
 *   queued -> [snapshot] -> extracting -> [importing_database] -> [replacing_urls] -> verifying -> completed
 *
 * Bracketed phases are conditional: snapshot only if requested, importing
 * only if the package has a database component, replacing_urls only if the
 * old and new URLs actually differ.
 */

namespace WPVault\Restore;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Log_Store;
use WPVault\Jobs\Job_Runner;
use WPVault\Jobs\Job_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Restore_Job {

	const STATUS_SNAPSHOT            = 'snapshot';
	const STATUS_EXTRACTING          = 'extracting';
	const STATUS_IMPORTING_DATABASE  = 'importing_database';
	const STATUS_REPLACING_URLS      = 'replacing_urls';
	const STATUS_VERIFYING           = 'verifying';

	public static function run_phase( $job, $payload, $temp_dir, $time_budget ) {
		switch ( $job->status ) {
			case Job_Store::STATUS_QUEUED:
				return self::begin( $job, $payload, $temp_dir );

			case self::STATUS_SNAPSHOT:
				return self::do_snapshot( $job, $payload, $temp_dir, $time_budget );

			case self::STATUS_EXTRACTING:
				return self::do_extracting( $job, $payload, $temp_dir, $time_budget );

			case self::STATUS_IMPORTING_DATABASE:
				return self::do_importing_database( $job, $payload, $temp_dir, $time_budget );

			case self::STATUS_REPLACING_URLS:
				return self::do_replacing_urls( $job, $payload, $temp_dir, $time_budget );

			case self::STATUS_VERIFYING:
				return self::do_verifying( $job, $payload, $temp_dir );

			default:
				return $job;
		}
	}

	private static function begin( $job, $payload, $temp_dir ) {
		$backup = Backup_Store::get_by_id( $job->backup_id );

		if ( ! $backup || ! $backup->file_path ) {
			throw new \RuntimeException( __( 'The backup to restore no longer exists.', 'wpvault' ) );
		}

		$payload['zip_path'] = Local_Storage::backups_dir() . $backup->file_path;

		if ( ! empty( $payload['create_snapshot'] ) ) {
			$snapshot_backup = Backup_Store::create( Backup_Store::TYPE_FULL );
			$snapshot_job    = Job_Store::create(
				Job_Store::TYPE_BACKUP,
				$snapshot_backup->id,
				array(
					'backup_type'   => Backup_Store::TYPE_FULL,
					'exclude_cache' => true,
				)
			);

			$payload['snapshot_backup_id'] = $snapshot_backup->id;
			$payload['snapshot_job_id']    = $snapshot_job->id;

			return Job_Store::checkpoint(
				$job->id,
				array(
					'status'       => self::STATUS_SNAPSHOT,
					'phase'        => self::STATUS_SNAPSHOT,
					'current_item' => __( 'Creating safety snapshot…', 'wpvault' ),
					'payload'      => wp_json_encode( $payload ),
				)
			);
		}

		return self::advance_to_extracting( $job, $payload );
	}

	/**
	 * Drives the safety-snapshot backup job to completion by calling the
	 * ordinary backup engine's own step() -- exactly what "Backup Now"
	 * calls, not a parallel implementation. Job_Runner::step()'s own
	 * per-job lock means this can never collide with the cron safety net
	 * also finding the snapshot job independently stale and stepping it.
	 */
	private static function do_snapshot( $job, $payload, $temp_dir, $time_budget ) {
		$snapshot_job = Job_Runner::step( $payload['snapshot_job_id'], $time_budget );

		if ( ! $snapshot_job ) {
			throw new \RuntimeException( __( 'The safety snapshot job disappeared.', 'wpvault' ) );
		}

		if ( in_array( $snapshot_job->status, array( Job_Store::STATUS_FAILED, Job_Store::STATUS_CANCELLED ), true ) ) {
			throw new \RuntimeException( __( 'Could not create a safety snapshot. Restore was not started -- nothing on the site has been changed.', 'wpvault' ) );
		}

		if ( Job_Store::STATUS_COMPLETED !== $snapshot_job->status ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'current_item'    => __( 'Creating safety snapshot…', 'wpvault' ),
					'processed_bytes' => $snapshot_job->processed_bytes,
					'total_bytes'     => $snapshot_job->total_bytes,
				)
			);
		}

		return self::advance_to_extracting( $job, $payload );
	}

	private static function advance_to_extracting( $job, $payload ) {
		$payload['extract_state'] = Extractor::initial_state();

		$reader   = new Package_Reader( $payload['zip_path'] );
		$manifest = $reader->manifest();
		$reader->close();

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'          => self::STATUS_EXTRACTING,
				'phase'           => self::STATUS_EXTRACTING,
				'current_item'    => __( 'Restoring files…', 'wpvault' ),
				'processed_bytes' => 0,
				'total_bytes'     => isset( $manifest['files_size'] ) ? (int) $manifest['files_size'] : 0,
				'processed_items' => 0,
				'total_items'     => isset( $manifest['file_count'] ) ? (int) $manifest['file_count'] : 0,
				'payload'         => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_extracting( $job, $payload, $temp_dir, $time_budget ) {
		$root                     = trailingslashit( ABSPATH );
		$payload['extract_state'] = Extractor::step( $payload['zip_path'], $root, $payload['extract_state'], $job->id, $time_budget );
		$state                    = $payload['extract_state'];

		if ( ! $state['done'] ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_items' => $state['files_extracted'],
					'processed_bytes' => $state['bytes_extracted'],
					/* translators: %d: files restored so far */
					'current_item'    => sprintf( __( 'Restoring files… %d done', 'wpvault' ), $state['files_extracted'] ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		if ( $state['files_skipped'] > 0 ) {
			Log_Store::info(
				sprintf( /* translators: %d: number of skipped files */ __( '%d file(s) were skipped during restore (wp-config.php/.htaccess, or an unsafe path).', 'wpvault' ), $state['files_skipped'] ),
				'wpvault_restore_files_skipped',
				$job->id
			);
		}

		$payload['db_temp_path'] = $temp_dir . 'restore-db.sql.gz';

		$reader = new Package_Reader( $payload['zip_path'] );
		$has_db = $reader->extract_database_to( $payload['db_temp_path'] );
		$reader->close();

		if ( ! $has_db ) {
			return self::advance_to_verifying( $job, $payload );
		}

		$reader_for_manifest = new Package_Reader( $payload['zip_path'] );
		$manifest            = $reader_for_manifest->manifest();
		$reader_for_manifest->close();

		$payload['import_state'] = Database_Importer::initial_state();

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'          => self::STATUS_IMPORTING_DATABASE,
				'phase'           => self::STATUS_IMPORTING_DATABASE,
				'current_item'    => __( 'Restoring database…', 'wpvault' ),
				'processed_bytes' => 0,
				'total_bytes'     => isset( $manifest['database_size'] ) ? (int) $manifest['database_size'] : 0,
				'processed_items' => 0,
				'total_items'     => 0,
				'payload'         => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_importing_database( $job, $payload, $temp_dir, $time_budget ) {
		$payload['import_state'] = Database_Importer::import_step( $payload['db_temp_path'], $payload['import_state'], $job->id, $time_budget );
		$state                   = $payload['import_state'];

		if ( ! $state['done'] ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					'processed_bytes' => $state['bytes_processed'],
					/* translators: %d: number of SQL statements executed so far */
					'current_item'    => sprintf( __( 'Restoring database… %d statements', 'wpvault' ), $state['statements_executed'] ),
					'payload'         => wp_json_encode( $payload ),
				)
			);
		}

		return self::advance_to_url_replacement( $job, $payload );
	}

	private static function advance_to_url_replacement( $job, $payload ) {
		$old_url = isset( $payload['old_url'] ) ? trim( $payload['old_url'] ) : '';
		$new_url = isset( $payload['new_url'] ) ? trim( $payload['new_url'] ) : '';

		if ( '' === $old_url || untrailingslashit( $old_url ) === untrailingslashit( $new_url ) ) {
			return self::advance_to_verifying( $job, $payload );
		}

		$payload['url_state'] = Url_Replacer::initial_state( Url_Replacer::discover_tables() );

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'          => self::STATUS_REPLACING_URLS,
				'phase'           => self::STATUS_REPLACING_URLS,
				'current_item'    => __( 'Updating site URLs…', 'wpvault' ),
				'processed_bytes' => 0,
				'total_bytes'     => 0,
				'payload'         => wp_json_encode( $payload ),
			)
		);
	}

	private static function do_replacing_urls( $job, $payload, $temp_dir, $time_budget ) {
		$old_url = trim( $payload['old_url'] );
		$new_url = trim( $payload['new_url'] );

		$payload['url_state'] = Url_Replacer::replace_step( $payload['url_state'], $old_url, $new_url, $job->id, $time_budget );
		$state                = $payload['url_state'];

		if ( ! $state['done'] ) {
			return Job_Store::checkpoint(
				$job->id,
				array(
					/* translators: %d: number of database rows updated so far */
					'current_item' => sprintf( __( 'Updating site URLs… %d rows updated', 'wpvault' ), $state['rows_updated'] ),
					'payload'      => wp_json_encode( $payload ),
				)
			);
		}

		return self::advance_to_verifying( $job, $payload );
	}

	private static function advance_to_verifying( $job, $payload ) {
		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'       => self::STATUS_VERIFYING,
				'phase'        => self::STATUS_VERIFYING,
				'current_item' => __( 'Verifying restored site…', 'wpvault' ),
				'payload'      => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * A practical stand-in for "confirm WordPress loads" (not possible to
	 * check from inside the request doing the restoring): confirms the
	 * options table that everything else depends on is actually there and
	 * readable post-import.
	 */
	private static function do_verifying( $job, $payload, $temp_dir ) {
		global $wpdb;

		$options_table = $wpdb->prefix . 'options';
		$siteurl       = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options_table, 'siteurl' ) );

		if ( null === $siteurl ) {
			throw new \RuntimeException( __( 'The restored database does not look like a WordPress database -- the siteurl option was not found.', 'wpvault' ) );
		}

		Log_Store::info( __( 'Restore completed.', 'wpvault' ), 'wpvault_restore_completed', $job->id );

		return Job_Store::checkpoint(
			$job->id,
			array(
				'status'           => Job_Store::STATUS_COMPLETED,
				'phase'            => Job_Store::STATUS_COMPLETED,
				'progress_percent' => 100,
				'current_item'     => __( 'Restore complete.', 'wpvault' ),
			)
		);
	}
}
