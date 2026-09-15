<?php
/**
 * On-demand re-verification of an existing package (§13), shared by the
 * REST "Verify" action and `wp wpvault verify` -- one place that knows how
 * to check a backup is still intact, called from both.
 */

namespace WPVault\Backup;

use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backup_Verifier {

	/**
	 * @return true|\WP_Error
	 */
	public static function verify( $backup_id ) {
		$backup = Backup_Store::get_by_id( $backup_id );

		if ( ! $backup || ! $backup->file_path ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Backup not found.', 'wpvault' ) );
		}

		$path = Local_Storage::backups_dir() . $backup->file_path;

		$structure = Package_Builder::verify_structure( $path, $backup->type );

		if ( is_wp_error( $structure ) ) {
			Backup_Store::mark_failed( $backup->id );
			return new \WP_Error( 'wpvault_verify_failed', $structure->get_error_message() );
		}

		$recomputed = file_exists( $path ) ? hash_file( 'sha256', $path ) : null;

		if ( $recomputed !== $backup->checksum ) {
			Backup_Store::mark_failed( $backup->id );
			return new \WP_Error( 'wpvault_checksum_mismatch', __( 'Checksum mismatch -- this package may be corrupt.', 'wpvault' ) );
		}

		Backup_Store::mark_verified( $backup->id );

		return true;
	}
}
