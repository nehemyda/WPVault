<?php
/**
 * The validate-then-finalize pipeline every import path shares once a full
 * .wpvault package is sitting on disk in a temp dir, regardless of how it
 * got there: a chunked browser upload (Backups_Controller::import_complete()),
 * or a server-to-server download from Google Drive/OneDrive
 * (Drive_Import_Job / Onedrive_Import_Job). Extracted so none of those three
 * callers re-implement manifest validation, moving the file into permanent
 * storage, or creating the Backup_Store row.
 */

namespace WPVault\Backup;

use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Import_Finalizer {

	/**
	 * @param string $working_path Path to the fully-assembled .wpvault file,
	 *                              still sitting in its own temp dir.
	 * @return object|\WP_Error The finalized, verified Backup_Store row.
	 */
	public static function finalize( $working_path ) {
		$manifest = self::read_and_validate_package( $working_path );

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$backup   = Backup_Store::create( $manifest['backup_type'], Backup_Store::ORIGIN_IMPORT );
		$filename = sprintf( 'imported-%s-%s.wpvault', gmdate( 'Y-m-d-His' ), (int) $backup->id );
		$moved    = ( new Local_Storage() )->put( $working_path, $filename );

		if ( is_wp_error( $moved ) ) {
			Backup_Store::delete( $backup->id );
			return new \WP_Error( 'wpvault_import_failed', $moved->get_error_message(), array( 'status' => 500 ) );
		}

		$final_path   = Local_Storage::backups_dir() . $filename;
		$checksum     = hash_file( 'sha256', $final_path );
		$package_size = filesize( $final_path );

		Backup_Store::mark_finalized(
			$backup->id,
			$filename,
			$package_size,
			$checksum,
			isset( $manifest['format_version'] ) ? $manifest['format_version'] : null,
			isset( $manifest['file_count'] ) ? (int) $manifest['file_count'] : null,
			isset( $manifest['table_count'] ) ? (int) $manifest['table_count'] : null
		);

		// Backup_Store::create() stamps this site's own home_url() as
		// source_url, correct for a backup we produced -- an imported
		// package's source is wherever it actually came from.
		$origin = isset( $manifest['home_url'] ) ? $manifest['home_url'] : ( isset( $manifest['site_url'] ) ? $manifest['site_url'] : '' );
		Backup_Store::update( $backup->id, array( 'source_url' => $origin ) );

		return Backup_Store::mark_verified( $backup->id );
	}

	private static function read_and_validate_package( $path ) {
		try {
			$reader   = new \WPVault\Restore\Package_Reader( $path );
			$manifest = $reader->manifest();
			$reader->close();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpvault_invalid_package', __( 'This does not look like a valid WPVault package (it may not be a zip file at all).', 'wpvault' ), array( 'status' => 422 ) );
		}

		if ( 'wpvault' !== ( $manifest['format'] ?? null ) ) {
			return new \WP_Error( 'wpvault_invalid_package', __( 'This is not a WPVault package.', 'wpvault' ), array( 'status' => 422 ) );
		}

		$type = isset( $manifest['backup_type'] ) ? $manifest['backup_type'] : null;

		if ( ! in_array( $type, array( Backup_Store::TYPE_FULL, Backup_Store::TYPE_DATABASE, Backup_Store::TYPE_FILES ), true ) ) {
			return new \WP_Error( 'wpvault_invalid_package', __( 'The package manifest has an unrecognized backup type.', 'wpvault' ), array( 'status' => 422 ) );
		}

		$structure = Package_Builder::verify_structure( $path, $type );

		if ( is_wp_error( $structure ) ) {
			return new \WP_Error( 'wpvault_invalid_package', $structure->get_error_message(), array( 'status' => 422 ) );
		}

		return $manifest;
	}
}
