<?php
/**
 * Discovers the backup file set before expensive processing begins (§7).
 *
 * The whole WordPress install root is walked as one tree rather than
 * special-casing wp-content/plugins, /themes, /uploads etc. individually --
 * excluding wp-content/wpvault (this plugin's own storage), caches and VCS
 * directories from a single ABSPATH walk naturally reproduces the file list
 * §6.1 describes, with far less code than enumerating each subfolder.
 *
 * Resumability: this is itself a phase that can be interrupted, so it never
 * holds the whole result in memory or in one DB row. Discovered FILES are
 * appended straight to a flat-file list on disk (which can grow to any
 * size); only the small stack of directories still waiting to be visited
 * lives in the job's JSON state, since a directory count is almost always
 * orders of magnitude smaller than a file count.
 */

namespace WPVault\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_Scanner {

	/**
	 * Directories/files excluded no matter what -- never toggled by the
	 * user, because including them would either explode every future backup
	 * (this plugin's own past packages) or back up things that were never
	 * part of the WordPress install (VCS metadata, JS tooling).
	 */
	public static function always_excluded() {
		return array(
			array( 'type' => 'dir_path', 'match' => 'wp-content/wpvault', 'label' => __( 'WPVault storage (this plugin\'s own backups)', 'wpvault' ) ),
			array( 'type' => 'dir_name', 'match' => '.git', 'label' => __( 'Git metadata', 'wpvault' ) ),
			array( 'type' => 'dir_name', 'match' => 'node_modules', 'label' => __( 'node_modules', 'wpvault' ) ),
			array( 'type' => 'file_name', 'match' => '.DS_Store', 'label' => __( 'macOS folder metadata', 'wpvault' ) ),
			array( 'type' => 'file_name', 'match' => 'Thumbs.db', 'label' => __( 'Windows folder metadata', 'wpvault' ) ),
		);
	}

	/**
	 * Candidate exclusions gated by the "Exclude cache / temporary files"
	 * checkbox (§16.3) -- optional because, unlike the list above, a
	 * restore could plausibly still want them.
	 */
	public static function cache_exclusions() {
		return array(
			array( 'type' => 'dir_path', 'match' => 'wp-content/cache', 'label' => __( 'Cache', 'wpvault' ) ),
			array( 'type' => 'dir_path', 'match' => 'wp-content/upgrade', 'label' => __( 'Temporary upgrade files', 'wpvault' ) ),
			array( 'type' => 'dir_path', 'match' => 'wp-content/upgrade-temp-backup', 'label' => __( 'Temporary upgrade files', 'wpvault' ) ),
			array( 'type' => 'file_path', 'match' => 'wp-content/debug.log', 'label' => __( 'Debug log', 'wpvault' ) ),
		);
	}

	public static function active_exclusions( $exclude_cache ) {
		return $exclude_cache
			? array_merge( self::always_excluded(), self::cache_exclusions() )
			: self::always_excluded();
	}

	private static function is_excluded( $relative_path, $is_dir, array $exclusions ) {
		$basename = basename( $relative_path );

		foreach ( $exclusions as $rule ) {
			if ( 'dir_path' === $rule['type'] && $is_dir && $relative_path === $rule['match'] ) {
				return true;
			}

			if ( 'dir_name' === $rule['type'] && $is_dir && $basename === $rule['match'] ) {
				return true;
			}

			if ( 'file_name' === $rule['type'] && ! $is_dir && $basename === $rule['match'] ) {
				return true;
			}

			if ( 'file_path' === $rule['type'] && ! $is_dir && $relative_path === $rule['match'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fresh state for a new scan. `pending_dirs` starts with the root
	 * itself ('' = ABSPATH).
	 */
	public static function initial_state() {
		return array(
			'pending_dirs'    => array( '' ),
			'scanned_dirs'    => 0,
			'scanned_files'   => 0,
			'total_bytes'     => 0,
			'breakdown'       => array(
				'uploads' => 0,
				'other'   => 0,
			),
			'largest_file'    => array( 'path' => null, 'size' => 0 ),
			'excluded_count'  => 0,
			'done'            => false,
		);
	}

	/**
	 * Advances the scan for up to $time_budget seconds, appending
	 * "relative_path\tsize\n" rows to $filelist_path for every included
	 * file. Returns the updated state; call again with the returned state
	 * until $state['done'] is true.
	 */
	public static function scan_step( $root, array $state, array $exclusions, $filelist_path, $time_budget = 4 ) {
		$start  = microtime( true );
		$handle = null;

		while ( ! empty( $state['pending_dirs'] ) ) {
			if ( microtime( true ) - $start >= $time_budget ) {
				break;
			}

			$relative_dir = array_shift( $state['pending_dirs'] );
			$absolute_dir = '' === $relative_dir ? untrailingslashit( $root ) : $root . $relative_dir;

			$entries = @scandir( $absolute_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $entries ) {
				continue; // Unreadable directory: skip rather than fail the whole backup.
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$entry_relative = '' === $relative_dir ? $entry : $relative_dir . '/' . $entry;
				$entry_absolute = $root . $entry_relative;
				$is_dir         = is_dir( $entry_absolute );

				if ( self::is_excluded( $entry_relative, $is_dir, $exclusions ) ) {
					$state['excluded_count']++;
					continue;
				}

				if ( $is_dir ) {
					if ( is_link( $entry_absolute ) ) {
						// Symlinked directories are not followed -- a link
						// pointing outside the WordPress root (or back on
						// itself) would otherwise turn a bounded walk into
						// an unbounded one.
						continue;
					}

					$state['pending_dirs'][] = $entry_relative;
					$state['scanned_dirs']++;
					continue;
				}

				$size = (int) @filesize( $entry_absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( null === $handle ) {
					$handle = fopen( $filelist_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				}

				fwrite( $handle, $entry_relative . "\t" . $size . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

				$state['scanned_files']++;
				$state['total_bytes'] += $size;

				if ( 0 === strpos( $entry_relative, 'wp-content/uploads/' ) ) {
					$state['breakdown']['uploads'] += $size;
				} else {
					$state['breakdown']['other'] += $size;
				}

				if ( $size > $state['largest_file']['size'] ) {
					$state['largest_file'] = array( 'path' => $entry_relative, 'size' => $size );
				}
			}
		}

		if ( null !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$state['done'] = empty( $state['pending_dirs'] );

		return $state;
	}
}
