<?php
/**
 * Streams a finished .wpvault package to the browser.
 *
 * A plain REST route would work too, but admin-post.php is the simpler fit
 * for a large binary download with WordPress's own nonce + capability
 * checks, and it keeps the file entirely off any predictable public URL
 * (§12.1, §19) -- every request needs both a valid admin session and a
 * nonce minted for that specific backup id.
 */

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Download_Handler {

	const ACTION = 'wpvault_download';

	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public static function url( $backup_id ) {
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'backup' => (int) $backup_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::nonce_action( $backup_id ) );
	}

	private static function nonce_action( $backup_id ) {
		return self::ACTION . '_' . (int) $backup_id;
	}

	public function handle() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to download WPVault backups.', 'wpvault' ), 403 );
		}

		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::nonce_action( $backup_id ) );

		$backup = Backup_Store::get_by_id( $backup_id );

		if ( ! $backup || ! $backup->file_path ) {
			wp_die( esc_html__( 'Backup not found.', 'wpvault' ), 404 );
		}

		$path = Local_Storage::backups_dir() . $backup->file_path;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The backup file is missing from disk.', 'wpvault' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $backup->file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		// A large package is read straight to the output buffer in one
		// stream rather than into a PHP string -- readfile() never holds
		// the whole file in memory, same reasoning as ZipArchive::addFile()
		// on the way in.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
