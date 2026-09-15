<?php
/**
 * wpvault_logs -- human-readable diagnostics per job, so a failure can be
 * explained on-screen (§22) without anyone opening the PHP error log.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */

namespace WPVault\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Log_Store {

	const DB_VERSION_OPTION = 'wpvault_logs_db_version';
	const DB_VERSION        = '1.0';

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';
	const LEVEL_DEBUG   = 'debug';

	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'wpvault_logs';
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
			job_id BIGINT UNSIGNED NULL DEFAULT NULL,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			code VARCHAR(100) NULL DEFAULT NULL,
			message TEXT NOT NULL,
			context LONGTEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * @param string     $level   One of the LEVEL_* constants.
	 * @param string     $message Plain-language message, safe to show a user.
	 * @param string     $code    Machine-readable event/error code.
	 * @param int|null   $job_id  Associated job, if any.
	 * @param array|null $context Structured diagnostic data. Never put
	 *                            database passwords or credentials here --
	 *                            this table is intended to be shown, not
	 *                            just logged (§19: never log secrets).
	 */
	public static function log( $level, $message, $code = null, $job_id = null, $context = null ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'job_id'     => $job_id,
				'level'      => $level,
				'code'       => $code,
				'message'    => $message,
				'context'    => null === $context ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function info( $message, $code = null, $job_id = null, $context = null ) {
		self::log( self::LEVEL_INFO, $message, $code, $job_id, $context );
	}

	public static function warning( $message, $code = null, $job_id = null, $context = null ) {
		self::log( self::LEVEL_WARNING, $message, $code, $job_id, $context );
	}

	public static function error( $message, $code = null, $job_id = null, $context = null ) {
		self::log( self::LEVEL_ERROR, $message, $code, $job_id, $context );
	}

	public static function for_job( $job_id, $limit = 200 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %d ORDER BY id DESC LIMIT %d',
				self::table_name(),
				$job_id,
				$limit
			)
		);
	}
}
