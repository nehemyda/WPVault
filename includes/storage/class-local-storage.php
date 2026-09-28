<?php
/**
 * The only Storage_Adapter implementation in the MVP. Everything lives
 * under wp-content/wpvault/ -- outside uploads, so WordPress never treats a
 * backup package as media, and behind directory-listing/execution
 * protection so a guessed URL can't read or run it.
 */

namespace WPVault\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_Storage implements Storage_Adapter {

	public static function base_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'wpvault/';
	}

	public static function backups_dir() {
		return self::base_dir() . 'backups/';
	}

	public static function temp_dir() {
		return self::base_dir() . 'temp/';
	}

	public static function logs_dir() {
		return self::base_dir() . 'logs/';
	}

	/**
	 * Creates the storage tree and drops the files that keep a web server
	 * from ever serving its contents directly. Safe to call on every
	 * activation/init -- each piece is only written if missing.
	 */
	public static function ensure_directories() {
		$dirs = array( self::base_dir(), self::backups_dir(), self::temp_dir(), self::logs_dir() );

		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			self::write_if_missing( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}

		// Apache only -- there is no plugin-writable equivalent for Nginx,
		// which must be blocked with a server-block rule instead (documented
		// in readme.txt). Both directories get it: temp holds in-progress
		// packages and raw SQL dumps that are just as sensitive as finished
		// backups.
		$htaccess = "Require all denied\n\nOrder deny,allow\nDeny from all\n";
		self::write_if_missing( self::backups_dir() . '.htaccess', $htaccess );
		self::write_if_missing( self::temp_dir() . '.htaccess', $htaccess );
	}

	private static function write_if_missing( $path, $contents ) {
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * A fresh working path under temp/ for one job -- callers build whatever
	 * they need under it (the in-progress .wpvault, the raw SQL stream...).
	 */
	public static function new_job_temp_dir( $job_token ) {
		$dir = self::temp_dir() . sanitize_file_name( $job_token ) . '/';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	public static function remove_job_temp_dir( $job_token ) {
		$dir = self::temp_dir() . sanitize_file_name( $job_token ) . '/';

		if ( ! file_exists( $dir ) ) {
			return;
		}

		$files = glob( $dir . '*' );

		foreach ( (array) $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function test_connection() {
		self::ensure_directories();

		if ( ! wp_is_writable( self::backups_dir() ) || ! wp_is_writable( self::temp_dir() ) ) {
			return new \WP_Error(
				'wpvault_storage_not_writable',
				__( 'WPVault cannot write to its storage directory (wp-content/wpvault/). Check filesystem permissions.', 'wpvault' )
			);
		}

		return true;
	}

	public function put( $source_path, $identifier ) {
		$destination = self::backups_dir() . $identifier;

		// rename() is atomic when source and destination are on the same
		// filesystem, which they always are here (both under wp-content) --
		// a reader either sees no file yet or the whole finished file, never
		// a partial one.
		if ( ! @rename( $source_path, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'wpvault_storage_move_failed', __( 'Could not move the finished package into storage.', 'wpvault' ) );
		}

		return true;
	}

	public function get_path( $identifier ) {
		$path = self::backups_dir() . $identifier;

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'wpvault_storage_missing', __( 'That backup package no longer exists on disk.', 'wpvault' ) );
		}

		return $path;
	}

	public function delete( $identifier ) {
		$path = self::backups_dir() . $identifier;

		if ( file_exists( $path ) && ! wp_delete_file( $path ) ) {
			return new \WP_Error( 'wpvault_storage_delete_failed', __( 'Could not delete the backup file.', 'wpvault' ) );
		}

		return true;
	}

	public function list_items() {
		$files = glob( self::backups_dir() . '*.wpvault' );

		return $files ? array_map( 'basename', $files ) : array();
	}

	public function verify( $identifier ) {
		$path = self::backups_dir() . $identifier;

		if ( ! file_exists( $path ) || ! is_readable( $path ) || 0 === (int) filesize( $path ) ) {
			return new \WP_Error( 'wpvault_storage_invalid', __( 'The backup package is missing or empty.', 'wpvault' ) );
		}

		return true;
	}

	/**
	 * Bytes free on the volume backing storage -- used by preflight/disk
	 * space checks before a backup or restore begins.
	 */
	public static function free_space() {
		$free = disk_free_space( self::base_dir() );

		return false === $free ? null : (int) $free;
	}

	/**
	 * Total bytes currently used by finished backup packages.
	 */
	public static function used_space() {
		$total = 0;

		foreach ( glob( self::backups_dir() . '*.wpvault' ) ?: array() as $file ) {
			$total += (int) filesize( $file );
		}

		return $total;
	}

	/**
	 * Removes import-* temp directories whose upload never finished within
	 * a reasonable window -- an abandoned browser tab or a page reload
	 * mid-upload otherwise leaves a partial package sitting in temp/
	 * forever. Used both by the cron-free "sweep before starting a new
	 * import" path and by `wp wpvault cleanup`.
	 *
	 * @return int Number of directories removed.
	 */
	public static function sweep_stale_import_dirs( $max_age_seconds = null ) {
		if ( null === $max_age_seconds ) {
			$max_age_seconds = 6 * HOUR_IN_SECONDS;
		}

		$removed = 0;

		foreach ( (array) glob( self::temp_dir() . 'import-*', GLOB_ONLYDIR ) as $dir ) {
			$meta_path = $dir . '/meta.json';
			$created   = 0;

			if ( file_exists( $meta_path ) ) {
				$meta    = json_decode( file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				$created = is_array( $meta ) && isset( $meta['created_at'] ) ? (int) $meta['created_at'] : 0;
			}

			if ( $created && ( time() - $created ) < $max_age_seconds ) {
				continue; // Still within its window -- an upload in progress.
			}

			self::remove_job_temp_dir( basename( $dir ) );
			$removed++;
		}

		return $removed;
	}
}
