<?php
/**
 * Chunked import of the database.sql.gz a backup produced. Reads forward
 * from a persisted decompressed-byte offset (gzseek/gztell work correctly
 * across Database_Exporter's concatenated gzip members -- the same
 * property that let the exporter resume by reopening the file in append
 * mode works symmetrically for reading it back in sequence) and executes
 * complete statements as they're found.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

namespace WPVault\Restore;

use WPVault\Diagnostics\Log_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Importer {

	const READ_BYTES_PER_CHUNK = 2 * MB_IN_BYTES;

	public static function initial_state() {
		return array(
			'read_offset'         => 0,
			'buffer_remainder'    => '',
			'statements_executed' => 0,
			'bytes_processed'     => 0,
			'done'                => false,
		);
	}

	public static function import_step( $gz_path, array $state, $job_id, $time_budget = 4 ) {
		if ( $state['done'] ) {
			return $state;
		}

		$handle = gzopen( $gz_path, 'rb' );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'Could not open the database export for reading.', 'wpvault' ) );
		}

		gzseek( $handle, $state['read_offset'] );

		$start = microtime( true );

		while ( ! gzeof( $handle ) ) {
			if ( microtime( true ) - $start >= $time_budget ) {
				break;
			}

			$chunk = gzread( $handle, self::READ_BYTES_PER_CHUNK );

			if ( '' === $chunk ) {
				break;
			}

			list( $statements, $remainder ) = self::extract_statements( $state['buffer_remainder'] . $chunk );

			foreach ( $statements as $sql ) {
				if ( '' === trim( $sql ) ) {
					continue;
				}

				self::execute( $sql, $job_id );
				$state['statements_executed']++;
			}

			$state['buffer_remainder'] = $remainder;
			$state['read_offset']      = gztell( $handle ) - strlen( $remainder );
		}

		$eof                      = gzeof( $handle );
		$state['bytes_processed'] = $state['read_offset'];

		gzclose( $handle );

		if ( $eof ) {
			if ( '' !== trim( $state['buffer_remainder'] ) ) {
				// The exporter always terminates its final statement with
				// ";\n", so a non-empty remainder at true EOF would mean a
				// truncated export -- run it anyway rather than silently
				// dropping data, since a syntax error here is a clearer
				// signal than data quietly missing.
				self::execute( $state['buffer_remainder'], $job_id );
				$state['statements_executed']++;
				$state['buffer_remainder'] = '';
			}

			$state['done'] = true;
		}

		return $state;
	}

	private static function execute( $sql, $job_id ) {
		global $wpdb;

		$wpdb->query( $sql );

		if ( $wpdb->last_error ) {
			$snippet = substr( trim( $sql ), 0, 120 );
			Log_Store::error( 'SQL error during restore: ' . $wpdb->last_error, 'wpvault_restore_sql_error', $job_id, array( 'statement_start' => $snippet ) );

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: the start of the SQL statement that failed */
					__( 'Database import failed on a statement starting with: %s', 'wpvault' ),
					$snippet
				)
			);
		}
	}

	/**
	 * Splits $buffer into complete, terminator-ended SQL statements plus
	 * whatever incomplete tail is left over. Tracks single-quoted-string
	 * state byte by byte so a semicolon inside a string value (routine in
	 * WordPress post_content) is never mistaken for a statement boundary --
	 * a plain split on ";\n" would truncate exactly that content.
	 *
	 * Matches how Backup\Database_Exporter escapes values on the way out
	 * ($wpdb->_real_escape(): backslash-escapes quotes and backslashes,
	 * does not double single quotes SQL-standard style), so a backslash
	 * inside a string always means "the next byte is literal, not a
	 * string-state transition".
	 *
	 * @return array{0: string[], 1: string} [complete statements, remainder]
	 */
	public static function extract_statements( $buffer ) {
		$statements = array();
		$length     = strlen( $buffer );
		$segment_start = 0;
		$in_string  = false;
		$i          = 0;

		while ( $i < $length ) {
			$char = $buffer[ $i ];

			if ( $in_string ) {
				if ( '\\' === $char ) {
					$i += 2; // Skip the escaped byte entirely; it can't end the string or start anything.
					continue;
				}

				if ( "'" === $char ) {
					$in_string = false;
				}

				$i++;
				continue;
			}

			if ( "'" === $char ) {
				$in_string = true;
				$i++;
				continue;
			}

			if ( ';' === $char ) {
				$statements[]  = substr( $buffer, $segment_start, $i - $segment_start + 1 );
				$segment_start = $i + 1;
			}

			$i++;
		}

		return array( $statements, substr( $buffer, $segment_start ) );
	}
}
