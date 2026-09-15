<?php
/**
 * Owns the .wpvault package's ZipArchive across many chunked requests.
 *
 * ZipArchive::addFile() streams straight from disk -- it never loads a
 * whole file into PHP memory, which is what §9.1 ("never load an entire
 * large media file into memory") actually requires; a zip is reopened and
 * closed once per chunk rather than kept open across separate PHP
 * processes, since a ZipArchive handle cannot survive past the request
 * that created it.
 */

namespace WPVault\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Package_Builder {

	const MAX_FILES_PER_CHUNK = 400;
	const MAX_BYTES_PER_CHUNK = 100 * MB_IN_BYTES;

	public static function initial_files_state() {
		return array(
			'read_offset' => 0,
			'files_added' => 0,
			'bytes_added' => 0,
			'done'        => false,
		);
	}

	/**
	 * Adds files listed in $filelist_path (one "relative\tsize" per line,
	 * written by File_Scanner) into $zip_path under files/, resuming from
	 * $state['read_offset'] -- a byte offset into the file list, not a line
	 * number, so seeking back in doesn't require re-reading lines already
	 * processed.
	 */
	public static function add_files_step( $zip_path, $filelist_path, $root, array $state, $time_budget = 4 ) {
		if ( $state['done'] || ! file_exists( $filelist_path ) ) {
			$state['done'] = true;
			return $state;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE ) ) {
			throw new \RuntimeException( 'Could not open the package for writing.' );
		}

		$handle = fopen( $filelist_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $handle, $state['read_offset'] );

		$start           = microtime( true );
		$files_this_chunk = 0;
		$bytes_this_chunk = 0;

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			if ( false === $line || '' === trim( $line ) ) {
				continue;
			}

			list( $relative, $size ) = array_pad( explode( "\t", rtrim( $line, "\n" ), 2 ), 2, 0 );
			$size                    = (int) $size;
			$absolute                = $root . $relative;

			if ( is_readable( $absolute ) ) {
				$zip->addFile( $absolute, 'files/' . $relative );
			}

			$state['files_added']++;
			$state['bytes_added'] += $size;
			$files_this_chunk++;
			$bytes_this_chunk += $size;
			$state['read_offset'] = ftell( $handle );

			if ( $files_this_chunk >= self::MAX_FILES_PER_CHUNK || $bytes_this_chunk >= self::MAX_BYTES_PER_CHUNK || microtime( true ) - $start >= $time_budget ) {
				break;
			}
		}

		$state['done'] = feof( $handle );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();

		return $state;
	}

	/**
	 * Adds the database export, manifest, and metadata/integrity entries,
	 * then finalizes. Called once, after files (if any) and the database
	 * export (if any) are both fully written.
	 *
	 * @return array{database_checksum: ?string} so the caller can record it.
	 */
	public static function finalize( $zip_path, array $manifest, $db_export_path = null ) {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE ) ) {
			throw new \RuntimeException( 'Could not open the package to finalize it.' );
		}

		$database_checksum = null;

		if ( $db_export_path && file_exists( $db_export_path ) ) {
			$database_checksum = hash_file( 'sha256', $db_export_path );
			$zip->addFile( $db_export_path, 'database/database.sql.gz' );
		}

		$zip->addFromString( 'metadata/site.json', wp_json_encode( Manifest::build_site_metadata(), JSON_PRETTY_PRINT ) );
		$zip->addFromString( 'metadata/environment.json', wp_json_encode( Manifest::build_environment_metadata(), JSON_PRETTY_PRINT ) );

		$checksums = array(
			'algorithm' => 'sha256',
			'database'  => $database_checksum,
		);
		$zip->addFromString( 'integrity/checksums.json', wp_json_encode( $checksums, JSON_PRETTY_PRINT ) );

		// manifest.json last -- every other entry's final shape (in
		// particular whether a database component exists) is already
		// decided by this point, so nothing here can go stale.
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		$zip->close();

		return array( 'database_checksum' => $database_checksum );
	}

	/**
	 * Opens a package read-only and confirms it is structurally sound:
	 * a valid zip, a readable manifest, and the components its own
	 * manifest claims to have (§13).
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_structure( $zip_path, $expected_type ) {
		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path, \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'wpvault_corrupt_package', __( 'The package is not a valid zip archive.', 'wpvault' ) );
		}

		$manifest_json = $zip->getFromName( 'manifest.json' );

		if ( false === $manifest_json ) {
			$zip->close();
			return new \WP_Error( 'wpvault_missing_manifest', __( 'The package has no manifest.json.', 'wpvault' ) );
		}

		$manifest = json_decode( $manifest_json, true );

		if ( ! is_array( $manifest ) || 'wpvault' !== ( $manifest['format'] ?? null ) ) {
			$zip->close();
			return new \WP_Error( 'wpvault_invalid_manifest', __( 'The package manifest could not be read.', 'wpvault' ) );
		}

		$needs_database = in_array( $expected_type, array( 'full', 'database' ), true );
		$needs_files    = in_array( $expected_type, array( 'full', 'files' ), true );

		if ( $needs_database && false === $zip->locateName( 'database/database.sql.gz' ) ) {
			$zip->close();
			return new \WP_Error( 'wpvault_missing_database', __( 'The database component is missing from the package.', 'wpvault' ) );
		}

		if ( $needs_files ) {
			$has_files = false;

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				if ( 0 === strpos( $zip->getNameIndex( $i ), 'files/' ) && '/' !== substr( $zip->getNameIndex( $i ), -1 ) ) {
					$has_files = true;
					break;
				}
			}

			if ( ! $has_files ) {
				$zip->close();
				return new \WP_Error( 'wpvault_missing_files', __( 'The file component is missing from the package.', 'wpvault' ) );
			}
		}

		$zip->close();

		return true;
	}
}
