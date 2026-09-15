<?php
/**
 * Chunked, path-guarded file restore. Walks the package's own zip index as
 * the resume cursor -- a zip's central directory is already a stable,
 * ordered list, so there's no need to build a separate filelist the way
 * File_Scanner does for a fresh backup.
 */

namespace WPVault\Restore;

use WPVault\Diagnostics\Log_Store;
use WPVault\Security\Path_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Extractor {

	const MAX_FILES_PER_CHUNK = 400;
	const MAX_BYTES_PER_CHUNK = 100 * MB_IN_BYTES;

	// Never restored from a package onto the live site -- see the plan's
	// rationale: wp-config.php reflects the environment it was captured on
	// (DB credentials, salts), and overwriting the current site's own copy
	// is the one failure mode with no recovery path (it can take down the
	// DB connection everything else, including recovery, depends on).
	// Root-level only: a plugin's own "wp-config.php" deep in wp-content is
	// just data, not this file.
	const NEVER_RESTORE = array( 'wp-config.php', '.htaccess' );

	public static function initial_state() {
		return array(
			'entry_index'     => 0,
			'files_extracted' => 0,
			'bytes_extracted' => 0,
			'files_skipped'   => 0,
			'done'            => false,
		);
	}

	public static function step( $zip_path, $root, array $state, $job_id, $time_budget = 4 ) {
		$reader = new Package_Reader( $zip_path );
		$total  = $reader->num_entries();

		$start            = microtime( true );
		$files_this_chunk = 0;
		$bytes_this_chunk = 0;

		while ( $state['entry_index'] < $total ) {
			if ( $files_this_chunk >= self::MAX_FILES_PER_CHUNK || $bytes_this_chunk >= self::MAX_BYTES_PER_CHUNK || microtime( true ) - $start >= $time_budget ) {
				break;
			}

			$entry = $reader->entry_at( $state['entry_index'] );
			$state['entry_index']++;

			if ( null === $entry || $entry['is_dir'] || 0 !== strpos( $entry['name'], 'files/' ) ) {
				continue;
			}

			$relative = substr( $entry['name'], strlen( 'files/' ) );

			if ( in_array( $relative, self::NEVER_RESTORE, true ) ) {
				$state['files_skipped']++;
				continue;
			}

			if ( ! Path_Guard::is_safe_relative_path( $relative ) ) {
				$state['files_skipped']++;
				Log_Store::warning(
					sprintf(
						/* translators: %s: the unsafe path found inside the package */
						__( 'Skipped an unsafe path found inside the package: %s', 'wpvault' ),
						$relative
					),
					'wpvault_restore_unsafe_path',
					$job_id
				);
				continue;
			}

			$destination = $root . $relative;
			$dir         = dirname( $destination );

			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$source = $reader->open_stream( $entry['name'] );
			$target = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( false === $target ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$state['files_skipped']++;
				Log_Store::warning(
					sprintf( /* translators: %s: destination file path */ __( 'Could not write %s -- check filesystem permissions.', 'wpvault' ), $relative ),
					'wpvault_restore_write_failed',
					$job_id
				);
				continue;
			}

			$bytes = stream_copy_to_stream( $source, $target );

			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$state['files_extracted']++;
			$state['bytes_extracted'] += (int) $bytes;
			$files_this_chunk++;
			$bytes_this_chunk += (int) $bytes;
		}

		$reader->close();

		$state['done'] = $state['entry_index'] >= $total;

		return $state;
	}
}
