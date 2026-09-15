<?php
/**
 * Serialization-aware search/replace across the just-imported database
 * (§15.1: "Do not perform a blind text replacement... because plugin data
 * may contain serialized PHP values"). A blind string replace on serialized
 * data corrupts it -- PHP's serialize format encodes each string's byte
 * length (s:5:"hello";), so shortening or lengthening a string inside one
 * without fixing that prefix produces a value unserialize() can't read back.
 *
 * Skipped entirely by Restore_Job when the detected old/new URLs are equal
 * (the ordinary same-site rollback case) -- this class is only reached for
 * an actual URL change.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

namespace WPVault\Restore;

use WPVault\Diagnostics\Log_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Url_Replacer {

	const ROW_BATCH_SIZE = 200;

	public static function discover_tables() {
		return \WPVault\Backup\Database_Exporter::discover_tables();
	}

	public static function initial_state( array $tables ) {
		return array(
			'tables'         => array_values( $tables ),
			'table_index'    => 0,
			'row_offset'     => 0,
			'rows_updated'   => 0,
			'tables_skipped' => array(),
			'current_pk'     => null,
			'current_columns' => array(),
			'done'           => empty( $tables ),
		);
	}

	public static function replace_step( array $state, $old_url, $new_url, $job_id, $time_budget = 4 ) {
		global $wpdb;

		if ( $state['done'] || '' === (string) $old_url || $old_url === $new_url ) {
			$state['done'] = true;
			return $state;
		}

		$start = microtime( true );

		while ( $state['table_index'] < count( $state['tables'] ) ) {
			if ( microtime( true ) - $start >= $time_budget ) {
				break;
			}

			$table = $state['tables'][ $state['table_index'] ];

			if ( 0 === $state['row_offset'] ) {
				$pk = self::primary_key_column( $table );

				if ( ! $pk ) {
					$state['tables_skipped'][] = $table;
					Log_Store::warning(
						sprintf( /* translators: %s: database table name */ __( 'Skipped URL replacement in %s -- no single-column primary key found.', 'wpvault' ), $table ),
						'wpvault_restore_no_primary_key',
						$job_id
					);
					$state['table_index']++;
					continue;
				}

				$state['current_pk']      = $pk;
				$state['current_columns'] = self::text_columns( $table );
			}

			$pk      = $state['current_pk'];
			$columns = $state['current_columns'];

			if ( empty( $columns ) ) {
				$state['table_index']++;
				$state['row_offset'] = 0;
				continue;
			}

			$select_columns = array_merge( array( $pk ), $columns );
			$rows           = $wpdb->get_results(
				$wpdb->prepare( self::select_sql( $select_columns ), $table, self::ROW_BATCH_SIZE, $state['row_offset'] ),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				$state['table_index']++;
				$state['row_offset'] = 0;
				continue;
			}

			foreach ( $rows as $row ) {
				$updates = array();

				foreach ( $columns as $column ) {
					$value = $row[ $column ];

					if ( null === $value || false === strpos( $value, $old_url ) ) {
						continue;
					}

					$replaced = self::replace_value( $value, $old_url, $new_url );

					if ( $replaced !== $value ) {
						$updates[ $column ] = $replaced;
					}
				}

				if ( ! empty( $updates ) ) {
					$wpdb->update( $table, $updates, array( $pk => $row[ $pk ] ) );
					$state['rows_updated']++;
				}
			}

			$state['row_offset'] += count( $rows );

			if ( count( $rows ) < self::ROW_BATCH_SIZE ) {
				$state['table_index']++;
				$state['row_offset'] = 0;
			}
		}

		$state['done'] = $state['table_index'] >= count( $state['tables'] );

		return $state;
	}

	private static function select_sql( array $columns ) {
		$quoted = '`' . implode( '`, `', $columns ) . '`';

		return "SELECT {$quoted} FROM %i LIMIT %d OFFSET %d";
	}

	/**
	 * Only tables with exactly one PRIMARY KEY column are updated row by
	 * row. A table without one (rare, but some third-party plugin tables
	 * lack a PK) is skipped rather than guessed at -- there is no safe
	 * generic WHERE clause to target "this exact row" without one.
	 */
	private static function primary_key_column( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );

		if ( 1 !== count( $keys ) ) {
			return null;
		}

		return $keys[0]['Column_name'];
	}

	/**
	 * URLs only ever live in text-ish columns -- restricting to these
	 * skips every numeric/date/binary column for both speed and safety.
	 */
	private static function text_columns( $table ) {
		global $wpdb;

		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$text    = array();

		foreach ( $columns as $column ) {
			if ( preg_match( '/char|text/i', $column['Type'] ) ) {
				$text[] = $column['Field'];
			}
		}

		return $text;
	}

	public static function replace_value( $value, $old_url, $new_url ) {
		if ( is_serialized( $value ) ) {
			// allowed_classes => false: an object comes back as
			// __PHP_Incomplete_Class rather than being instantiated (no
			// constructor/__wakeup() runs on data pulled from the site's
			// own database) -- and PHP's serialize() has a specific
			// carve-out for these that re-emits the original class name
			// unchanged, so the round-trip is still faithful.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $data && 'b:0;' !== $value ) {
				// Looked serialized but didn't actually unserialize --
				// leave it untouched rather than risk corrupting it further.
				return $value;
			}

			return serialize( self::recursive_replace( $data, $old_url, $new_url ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}

		return str_replace( $old_url, $new_url, $value );
	}

	private static function recursive_replace( $data, $old_url, $new_url ) {
		if ( is_string( $data ) ) {
			return str_replace( $old_url, $new_url, $data );
		}

		if ( is_array( $data ) ) {
			$out = array();

			foreach ( $data as $key => $value ) {
				$out[ $key ] = self::recursive_replace( $value, $old_url, $new_url );
			}

			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = self::recursive_replace( $value, $old_url, $new_url );
			}

			return $data;
		}

		return $data;
	}
}
