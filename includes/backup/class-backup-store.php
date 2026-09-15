<?php
/**
 * wpvault_backups -- one row per backup package, per §18.1.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */

namespace WPVault\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup_Store {

	const DB_VERSION_OPTION = 'wpvault_backups_db_version';
	const DB_VERSION        = '1.0';

	const TYPE_FULL     = 'full';
	const TYPE_DATABASE = 'database';
	const TYPE_FILES    = 'files';

	const STATUS_CREATED  = 'created';
	const STATUS_VERIFIED = 'verified';
	const STATUS_FAILED   = 'failed';

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
			source_url VARCHAR(255) NULL DEFAULT NULL,
			manifest_version VARCHAR(20) NULL DEFAULT NULL,
			checksum VARCHAR(64) NULL DEFAULT NULL,
			file_count BIGINT UNSIGNED NULL DEFAULT NULL,
			table_count INT UNSIGNED NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY backup_uuid (backup_uuid)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create( $type ) {
		global $wpdb;

		$uuid = wp_generate_uuid4();

		$wpdb->insert(
			self::table_name(),
			array(
				'backup_uuid' => $uuid,
				'type'        => $type,
				'status'      => self::STATUS_CREATED,
				'source_url'  => home_url(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
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
}
