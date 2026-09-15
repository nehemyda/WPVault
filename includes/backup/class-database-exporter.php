<?php
/**
 * Streams the WordPress database to a gzip-compressed SQL file in bounded
 * row batches (§8), never holding more than one batch in memory.
 *
 * Tables are written as independent gzip members appended to the same
 * file across many chunks/requests -- concatenated gzip members are valid
 * gzip (RFC 1952) and any standard gunzip decompresses the concatenation
 * transparently, which is what lets this export resume with a plain
 * gzopen(..., 'a') instead of holding one long-lived stream handle across
 * separate HTTP requests.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

namespace WPVault\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Exporter {

	const ROW_BATCH_SIZE = 500;

	/**
	 * WPVault's own bookkeeping tables are never part of the backup.
	 * Confirmed by hand why this matters, not just tidiness: a full
	 * backup's database export runs *while its own job row is being
	 * updated* (status ticking through processing/finalizing/verifying),
	 * so including wpvault_jobs would capture that row mid-flight. Restore
	 * that package later and the import overwrites the *live* jobs table
	 * with that stale snapshot -- which is exactly what silently destroyed
	 * a restore job's own tracking row out from under itself during
	 * testing, mid-restore. These three tables are operational state about
	 * backups, not site content, so they're excluded at the source instead
	 * of trying to protect them during import.
	 */
	const EXCLUDED_TABLES = array( 'wpvault_backups', 'wpvault_jobs', 'wpvault_logs' );

	/**
	 * All tables belonging to this WordPress installation -- matched by the
	 * configured prefix, not a hardcoded "wp_" (§8: "must not assume the
	 * table prefix is wp_") -- minus WPVault's own tables, above.
	 */
	public static function discover_tables() {
		global $wpdb;

		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		$excluded = array_map(
			static function ( $suffix ) use ( $wpdb ) {
				return $wpdb->prefix . $suffix;
			},
			self::EXCLUDED_TABLES
		);

		return array_values( array_diff( $tables, $excluded ) );
	}

	/**
	 * Fast approximate row counts (INFORMATION_SCHEMA, not COUNT(*)) purely
	 * for manifest/progress display -- accurate enough for "about how much
	 * work is left", not used to decide when export is actually done.
	 */
	public static function estimate_row_counts( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $tables ), '%s' ) );

		$sql  = "SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $tables ), ARRAY_A );

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ $row['TABLE_NAME'] ] = (int) $row['TABLE_ROWS'];
		}

		return $counts;
	}

	public static function initial_state( array $tables ) {
		return array(
			'tables'       => array_values( $tables ),
			'table_index'  => 0,
			'row_offset'   => 0,
			'bytes_written' => 0,
			'done'         => empty( $tables ),
		);
	}

	/**
	 * Advances the export for up to $time_budget seconds. Returns the
	 * updated state; call again until $state['done'] is true.
	 */
	public static function export_step( array $state, $gz_path, $time_budget = 4 ) {
		global $wpdb;

		if ( $state['done'] ) {
			return $state;
		}

		$start  = microtime( true );
		$handle = gzopen( $gz_path, 'ab9' );

		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open database export file for writing.' );
		}

		if ( 0 === $state['table_index'] && 0 === $state['row_offset'] ) {
			gzwrite( $handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		}

		while ( $state['table_index'] < count( $state['tables'] ) ) {
			if ( microtime( true ) - $start >= $time_budget ) {
				break;
			}

			$table = $state['tables'][ $state['table_index'] ];

			if ( 0 === $state['row_offset'] ) {
				$bytes = self::write_table_header( $handle, $table );
				$state['bytes_written'] += $bytes;
			}

			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, self::ROW_BATCH_SIZE, $state['row_offset'] ), ARRAY_A );

			if ( empty( $rows ) ) {
				gzwrite( $handle, "\n" );
				$state['table_index']++;
				$state['row_offset'] = 0;
				continue;
			}

			$sql = self::rows_to_insert_sql( $table, $rows );
			gzwrite( $handle, $sql );
			$state['bytes_written'] += strlen( $sql );
			$state['row_offset']    += count( $rows );

			if ( count( $rows ) < self::ROW_BATCH_SIZE ) {
				// Last (short) batch for this table -- move on without
				// waiting for an empty SELECT to confirm it.
				gzwrite( $handle, "\n" );
				$state['table_index']++;
				$state['row_offset'] = 0;
			}
		}

		gzclose( $handle );

		$state['done'] = $state['table_index'] >= count( $state['tables'] );

		return $state;
	}

	private static function write_table_header( $handle, $table ) {
		global $wpdb;

		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		$sql    = "-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";

		gzwrite( $handle, $sql );

		return strlen( $sql );
	}

	/**
	 * A restore target's max_allowed_packet is unknown at export time and
	 * is often left at MySQL's conservative historical default (1MB) even
	 * on hosts that could take more. A single INSERT built from a whole
	 * 500-row SELECT batch can exceed that -- confirmed by hand: importing
	 * an early build's dump against a 1MB max_allowed_packet server dropped
	 * the connection ("MySQL server has gone away") on exactly this kind of
	 * statement. So a batch is split into several INSERT statements by
	 * accumulated byte size, independent of the row count used for paging.
	 */
	const MAX_STATEMENT_BYTES = 256 * 1024;

	private static function rows_to_insert_sql( $table, array $rows ) {
		global $wpdb;

		$columns = array_keys( $rows[0] );
		$prefix  = "INSERT INTO `{$table}` (`" . implode( '`, `', $columns ) . "`) VALUES\n";

		$sql          = '';
		$value_lines  = array();
		$current_size = strlen( $prefix );

		foreach ( $rows as $row ) {
			$values = array();

			foreach ( $row as $value ) {
				$values[] = null === $value ? 'NULL' : "'" . $wpdb->_real_escape( $value ) . "'";
			}

			$line = '(' . implode( ', ', $values ) . ')';

			if ( ! empty( $value_lines ) && $current_size + strlen( $line ) > self::MAX_STATEMENT_BYTES ) {
				$sql         .= $prefix . implode( ",\n", $value_lines ) . ";\n";
				$value_lines  = array();
				$current_size = strlen( $prefix );
			}

			$value_lines[]  = $line;
			$current_size  += strlen( $line ) + 2;
		}

		if ( ! empty( $value_lines ) ) {
			$sql .= $prefix . implode( ",\n", $value_lines ) . ";\n";
		}

		return $sql;
	}
}
