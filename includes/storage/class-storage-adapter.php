<?php
/**
 * The seam future storage backends (S3, Drive, Dropbox...) plug into.
 *
 * MVP ships exactly one implementation, Local_Storage. Nothing in the backup
 * or job engine is allowed to know it is talking to a local filesystem --
 * everything goes through this interface, so adding a cloud adapter later is
 * a new class, not a rewrite of BackupManager/JobRunner.
 */

namespace WPVault\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Storage_Adapter {

	/**
	 * Confirms the destination is reachable and usable (writable directory,
	 * reachable bucket, valid credentials...). Returns true, or a WP_Error
	 * describing what failed.
	 *
	 * @return true|\WP_Error
	 */
	public function test_connection();

	/**
	 * Moves a finished local file into permanent storage under $identifier.
	 * Must be atomic from the caller's point of view: readers should never
	 * observe a partially-written destination.
	 *
	 * @param string $source_path Local temp file to move in.
	 * @param string $identifier  Destination-relative name (e.g. a backup filename).
	 * @return true|\WP_Error
	 */
	public function put( $source_path, $identifier );

	/**
	 * Absolute local filesystem path a stored item can currently be read
	 * from. Cloud adapters would download to a temp path here instead.
	 *
	 * @return string|\WP_Error
	 */
	public function get_path( $identifier );

	/**
	 * @return true|\WP_Error
	 */
	public function delete( $identifier );

	/**
	 * @return array List of identifiers currently in storage.
	 */
	public function list_items();

	/**
	 * @return true|\WP_Error
	 */
	public function verify( $identifier );
}
