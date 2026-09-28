<?php
/**
 * wpvault_backups -- one row per backup package, per §18.1.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */

namespace WPVault\Backup;

use WPVault\Jobs\Job_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup_Store {

	const DB_VERSION_OPTION = 'wpvault_backups_db_version';
	const DB_VERSION        = '1.2';

	const TYPE_FULL     = 'full';
	const TYPE_DATABASE = 'database';
	const TYPE_FILES    = 'files';

	const STATUS_CREATED  = 'created';
	const STATUS_VERIFIED = 'verified';
	const STATUS_FAILED   = 'failed';

	const ORIGIN_MANUAL     = 'manual';
	const ORIGIN_CLI        = 'cli';
	const ORIGIN_SCHEDULED  = 'scheduled';
	const ORIGIN_IMPORT     = 'import';
	const ORIGIN_PRE_UPDATE = 'pre_update';

	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'wpvault_backups';
	}

	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			backup_uuid VARCHAR(36) NOT NULL,
			type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			file_path VARCHAR(255) NULL DEFAULT NULL,
			package_size BIGINT UNSIGNED NULL DEFAULT NULL,
			origin VARCHAR(20) NOT NULL DEFAULT 'manual',
			source_url VARCHAR(255) NULL DEFAULT NULL,
			manifest_version VARCHAR(20) NULL DEFAULT NULL,
			checksum VARCHAR(64) NULL DEFAULT NULL,
			file_count BIGINT UNSIGNED NULL DEFAULT NULL,
			table_count INT UNSIGNED NULL DEFAULT NULL,
			drive_file_id VARCHAR(64) NULL DEFAULT NULL,
			drive_link VARCHAR(500) NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY backup_uuid (backup_uuid)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create( $type, $origin = self::ORIGIN_MANUAL ) {
		global $wpdb;

		$uuid = wp_generate_uuid4();

		$wpdb->insert(
			self::table_name(),
			array(
				'backup_uuid' => $uuid,
				'type'        => $type,
				'status'      => self::STATUS_CREATED,
				'origin'      => $origin,
				'source_url'  => home_url(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return self::get_by_id( $wpdb->insert_id );
	}

	public static function get_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id ) );
	}

	public static function get_all( $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d', self::table_name(), $limit ) );
	}

	public static function update( $id, array $fields ) {
		global $wpdb;

		$formats = array();

		foreach ( $fields as $key => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->update( self::table_name(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );

		return self::get_by_id( $id );
	}

	public static function mark_finalized( $id, $filename, $package_size, $checksum, $manifest_version, $file_count, $table_count ) {
		return self::update(
			$id,
			array(
				'status'           => self::STATUS_CREATED,
				'file_path'        => $filename,
				'package_size'     => $package_size,
				'checksum'         => $checksum,
				'manifest_version' => $manifest_version,
				'file_count'       => $file_count,
				'table_count'      => $table_count,
				'completed_at'     => current_time( 'mysql' ),
			)
		);
	}

	public static function mark_verified( $id ) {
		return self::update( $id, array( 'status' => self::STATUS_VERIFIED ) );
	}

	public static function mark_failed( $id ) {
		return self::update( $id, array( 'status' => self::STATUS_FAILED ) );
	}

	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Keeps only the $retention most recent backups with a given origin --
	 * shared by the scheduled and pre-update backup engines so that an
	 * automatic backup feature run indefinitely never quietly fills the
	 * disk. Backups from every *other* origin are never touched, regardless
	 * of age, so a manual/CLI/imported backup is never pruned just because
	 * some automatic feature happened to run.
	 *
	 * @param string   $origin         One of the ORIGIN_* constants.
	 * @param int      $retention      Keep this many most-recent matches; 0 = keep them all (no-op).
	 * @param int|null $keep_backup_id Never prune this id even if it would otherwise fall outside
	 *                                 the retention window (the backup a caller just created, which
	 *                                 may still be mid-job).
	 */
	public static function prune_by_origin( $origin, $retention, $keep_backup_id = null ) {
		$retention = (int) $retention;

		if ( $retention <= 0 ) {
			return;
		}

		$matching = array_values(
			array_filter(
				self::get_all( 500 ),
				static function ( $backup ) use ( $origin ) {
					return $origin === $backup->origin;
				}
			)
		);

		if ( count( $matching ) <= $retention ) {
			return;
		}

		foreach ( array_slice( $matching, $retention ) as $backup ) {
			if ( null !== $keep_backup_id && (int) $backup->id === (int) $keep_backup_id ) {
				continue;
			}

			if ( Job_Store::find_active_job_for_backup( $backup->id ) ) {
				continue; // Still in progress somehow -- leave it alone.
			}

			if ( $backup->file_path ) {
				( new Local_Storage() )->delete( $backup->file_path );
			}

			self::delete( $backup->id );
		}
	}
}
