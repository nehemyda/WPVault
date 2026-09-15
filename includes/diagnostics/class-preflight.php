<?php
/**
 * Environment checks run before a backup starts, so a shared-hosting
 * limitation shows up as a plain-language reason instead of a PHP fatal
 * halfway through processing (§4.1, §5.1, §20).
 */

namespace WPVault\Diagnostics;

use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Preflight {

	const MIN_PHP_VERSION = '7.4';

	/**
	 * @return array{ok: bool, checks: array<array{id:string,label:string,ok:bool,message:string}>}
	 */
	public static function run() {
		$checks = array(
			self::check_php_version(),
			self::check_zip_extension(),
			self::check_database(),
			self::check_storage_writable(),
		);

		$ok = true;

		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$ok = false;
			}
		}

		return array(
			'ok'     => $ok,
			'checks' => $checks,
		);
	}

	private static function check_php_version() {
		$ok = version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '>=' );

		return array(
			'id'      => 'php_version',
			'label'   => __( 'PHP version', 'wpvault' ),
			'ok'      => $ok,
			'message' => $ok
				? sprintf( /* translators: %s: PHP version */ __( 'PHP %s', 'wpvault' ), PHP_VERSION )
				: sprintf(
					/* translators: 1: minimum PHP version required, 2: current PHP version */
					__( 'WPVault requires PHP %1$s or newer. This server runs PHP %2$s.', 'wpvault' ),
					self::MIN_PHP_VERSION,
					PHP_VERSION
				),
		);
	}

	private static function check_zip_extension() {
		$ok = class_exists( 'ZipArchive' );

		return array(
			'id'      => 'zip_extension',
			'label'   => __( 'Zip support', 'wpvault' ),
			'ok'      => $ok,
			'message' => $ok
				? __( 'The PHP zip extension is available.', 'wpvault' )
				: __( 'The PHP zip extension is not installed. WPVault needs it to build backup packages -- ask your host to enable it.', 'wpvault' ),
		);
	}

	private static function check_database() {
		global $wpdb;

		$ok = null === $wpdb->get_var( 'SELECT 1' ) ? false : true;

		return array(
			'id'      => 'database_connection',
			'label'   => __( 'Database connection', 'wpvault' ),
			'ok'      => $ok,
			'message' => $ok
				? __( 'Connected.', 'wpvault' )
				: __( 'Could not query the WordPress database.', 'wpvault' ),
		);
	}

	private static function check_storage_writable() {
		$result = ( new \WPVault\Storage\Local_Storage() )->test_connection();
		$ok     = true !== $result ? false : true;

		return array(
			'id'      => 'storage_writable',
			'label'   => __( 'Backup storage', 'wpvault' ),
			'ok'      => $ok,
			'message' => $ok
				? __( 'wp-content/wpvault/ is writable.', 'wpvault' )
				: $result->get_error_message(),
		);
	}

	/**
	 * Checked separately from run() because it needs a size estimate the
	 * scanner produces after preflight's system checks already passed.
	 *
	 * @param int $required_bytes Estimated space the job will need (temp + final package).
	 * @return true|\WP_Error
	 */
	public static function check_disk_space( $required_bytes ) {
		$free = Local_Storage::free_space();

		if ( null === $free ) {
			// Some hosts disable disk_free_space(); do not block the backup
			// over a check we simply can't run.
			return true;
		}

		// A flat safety margin on top of the estimate -- the exact margin is
		// left simple for MVP (§20.1 flags it as configurable later).
		$margin   = max( 100 * MB_IN_BYTES, (int) ( $required_bytes * 0.1 ) );
		$required = $required_bytes + $margin;

		if ( $free < $required ) {
			return new \WP_Error(
				'wpvault_low_disk_space',
				sprintf(
					/* translators: 1: required space, 2: available space */
					__( 'Not enough disk space. Required: %1$s. Available: %2$s.', 'wpvault' ),
					size_format( $required ),
					size_format( $free )
				)
			);
		}

		return true;
	}

	/**
	 * Restore preflight (§14.2): the general checks above, plus confirming
	 * the specific package about to be restored is actually intact. A bad
	 * package must never get past this point -- everything downstream
	 * touches the live site.
	 *
	 * @param object $backup A wpvault_backups row.
	 * @return array{ok: bool, checks: array}
	 */
	public static function check_restore( $backup ) {
		$checks   = self::run()['checks'];
		$checks[] = self::check_package_intact( $backup );

		$ok = true;

		foreach ( $checks as $check ) {
			if ( ! $check['ok'] ) {
				$ok = false;
			}
		}

		return array(
			'ok'     => $ok,
			'checks' => $checks,
		);
	}

	private static function check_package_intact( $backup ) {
		$id = 'package_intact';
		$label = __( 'Backup package', 'wpvault' );

		if ( ! $backup || ! $backup->file_path ) {
			return array( 'id' => $id, 'label' => $label, 'ok' => false, 'message' => __( 'This backup has no package on disk.', 'wpvault' ) );
		}

		$path = Local_Storage::backups_dir() . $backup->file_path;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array( 'id' => $id, 'label' => $label, 'ok' => false, 'message' => __( 'The package file is missing from disk.', 'wpvault' ) );
		}

		$structure = \WPVault\Backup\Package_Builder::verify_structure( $path, $backup->type );

		if ( is_wp_error( $structure ) ) {
			return array( 'id' => $id, 'label' => $label, 'ok' => false, 'message' => $structure->get_error_message() );
		}

		if ( $backup->checksum && hash_file( 'sha256', $path ) !== $backup->checksum ) {
			return array( 'id' => $id, 'label' => $label, 'ok' => false, 'message' => __( 'The package checksum does not match -- it may be corrupt.', 'wpvault' ) );
		}

		return array( 'id' => $id, 'label' => $label, 'ok' => true, 'message' => __( 'Package is intact and verified.', 'wpvault' ) );
	}
}
