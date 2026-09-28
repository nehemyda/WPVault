<?php
/**
 * REST surface for backups themselves (§24): list, create (kicks off the
 * job that produces one), inspect, re-verify, delete, and import (§15/§3.1
 * -- bring a package made on a different site onto this one). Starting or
 * stepping a job is the only slow part of creating one, and starting only
 * creates rows -- the actual work happens in Jobs_Controller's step
 * endpoint, called repeatedly by the browser. Import has no job at all: see
 * import_backup()'s docblock for why.
 */

namespace WPVault\Rest;

use WPVault\Admin\Download_Handler;
use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Preflight;
use WPVault\Jobs\Job_Store;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backups_Controller {

	public function register_routes() {
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_backups' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_backup' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'          => array(
							'required' => true,
							'enum'     => array( Backup_Store::TYPE_FULL, Backup_Store::TYPE_DATABASE, Backup_Store::TYPE_FILES ),
						),
						'exclude_cache' => array(
							'default' => true,
							'type'    => 'boolean',
						),
						'upload_to_drive' => array(
							'default' => false,
							'type'    => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_backup' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Uploading to Drive is chunked (Jobs_Controller's generic
		// /jobs/{id}/step drives it) rather than one request, for the same
		// reason import is: a package can be well over what one request
		// should attempt in a single blocking upload.
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/drive-upload',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_drive_upload' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Import is chunked (§15/§3.1 update): a single-request upload would
		// be bounded by this server's own upload_max_filesize/post_max_size,
		// same as every other host WPVault might run on. Splitting it into
		// init/chunk/complete means the only real ceiling left is disk
		// space, checked up front in import_init().
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/import/init',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_init' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/import/chunk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_chunk' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/import/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_complete' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/preflight',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'run_preflight' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission() {
		return wpvault_can_manage();
	}

	public function run_preflight() {
		return rest_ensure_response( Preflight::run() );
	}

	public function list_backups() {
		$backups = Backup_Store::get_all();

		return rest_ensure_response( array_map( array( $this, 'serialize_backup' ), $backups ) );
	}

	public function create_backup( \WP_REST_Request $request ) {
		$preflight = Preflight::run();

		if ( ! $preflight['ok'] ) {
			return new \WP_Error( 'wpvault_preflight_failed', __( 'WPVault cannot start a backup right now -- see the checks below.', 'wpvault' ), array( 'status' => 422, 'checks' => $preflight['checks'] ) );
		}

		$type            = $request->get_param( 'type' );
		$exclude_cache   = (bool) $request->get_param( 'exclude_cache' );
		$upload_to_drive = (bool) $request->get_param( 'upload_to_drive' ) && Google_Drive::is_connected();

		$backup = Backup_Store::create( $type );
		$job    = Job_Store::create(
			Job_Store::TYPE_BACKUP,
			$backup->id,
			array(
				'backup_type'     => $type,
				'exclude_cache'   => $exclude_cache,
				'upload_to_drive' => $upload_to_drive,
			)
		);

		return rest_ensure_response(
			array(
				'backup' => $this->serialize_backup( $backup ),
				'job_id' => $job->id,
			)
		);
	}

	public function get_backup( \WP_REST_Request $request ) {
		$backup = Backup_Store::get_by_id( (int) $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Backup not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->serialize_backup( $backup ) );
	}

	public function verify_backup( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = \WPVault\Backup\Backup_Verifier::verify( $id );

		if ( is_wp_error( $result ) ) {
			$status = 'wpvault_not_found' === $result->get_error_code() ? 404 : 422;
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		return rest_ensure_response( $this->serialize_backup( Backup_Store::get_by_id( $id ) ) );
	}

	public function start_drive_upload( \WP_REST_Request $request ) {
		$backup = Backup_Store::get_by_id( (int) $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Backup not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		if ( Backup_Store::STATUS_VERIFIED !== $backup->status ) {
			return new \WP_Error( 'wpvault_backup_not_verified', __( 'Only a verified backup can be saved to Google Drive.', 'wpvault' ), array( 'status' => 422 ) );
		}

		if ( ! Google_Drive::is_connected() ) {
			return new \WP_Error( 'wpvault_gdrive_not_connected', __( 'Google Drive is not connected. Connect it from Settings first.', 'wpvault' ), array( 'status' => 409 ) );
		}

		if ( Job_Store::find_latest_active() ) {
			return new \WP_Error( 'wpvault_job_in_progress', __( 'Another backup, import, or restore is already running. Wait for it to finish first.', 'wpvault' ), array( 'status' => 409 ) );
		}

		$job = Job_Store::create( Job_Store::TYPE_DRIVE_UPLOAD, $backup->id );

		return rest_ensure_response( array( 'job_id' => $job->id ) );
	}

	/**
	 * §15/§3.1: bring a .wpvault package made on a different site onto this
	 * one, uploaded in chunks so it isn't bounded by this server's own
	 * upload_max_filesize/post_max_size the way a single-request upload
	 * would be. init reserves an upload slot and disk space; chunk appends
	 * one piece at a time (called repeatedly by the browser, same shape as
	 * the job-step endpoints); complete runs the exact same
	 * validate-then-finalize pipeline the old single-shot endpoint used
	 * once the assembled file is on disk.
	 */
	public function import_init( \WP_REST_Request $request ) {
		$preflight = Preflight::run();

		if ( ! $preflight['ok'] ) {
			return new \WP_Error( 'wpvault_preflight_failed', __( 'WPVault cannot import a package right now -- see the checks below.', 'wpvault' ), array( 'status' => 422, 'checks' => $preflight['checks'] ) );
		}

		$total_size = (int) $request->get_param( 'total_size' );

		if ( $total_size <= 0 ) {
			return new \WP_Error( 'wpvault_invalid_upload', __( 'Missing or invalid file size.', 'wpvault' ), array( 'status' => 400 ) );
		}

		$space = Preflight::check_disk_space( $total_size );

		if ( is_wp_error( $space ) ) {
			return $space;
		}

		Local_Storage::ensure_directories();
		Local_Storage::sweep_stale_import_dirs();

		$upload_id = wp_generate_password( 20, false, false );
		$dir       = Local_Storage::new_job_temp_dir( 'import-' . $upload_id );

		file_put_contents( $dir . 'package.wpvault.part', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->write_import_meta(
			$upload_id,
			array(
				'total_size'     => $total_size,
				'received_bytes' => 0,
				'created_at'     => time(),
			)
		);

		return rest_ensure_response(
			array(
				'upload_id'  => $upload_id,
				'chunk_size' => $this->import_chunk_size(),
			)
		);
	}

	public function import_chunk( \WP_REST_Request $request ) {
		$upload_id = (string) $request->get_param( 'upload_id' );
		$offset    = (int) $request->get_param( 'offset' );

		$meta = $this->read_import_meta( $upload_id );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( $offset !== $meta['received_bytes'] ) {
			// Most often a retried request after the client never saw the
			// previous response -- report what the server actually has so
			// the browser can resume from there instead of failing the
			// whole upload over one dropped acknowledgement.
			return new \WP_Error(
				'wpvault_import_offset_mismatch',
				__( 'Upload is out of sync with the server.', 'wpvault' ),
				array(
					'status'         => 409,
					'received_bytes' => $meta['received_bytes'],
				)
			);
		}

		$body = $request->get_body();

		if ( '' === $body ) {
			return new \WP_Error( 'wpvault_import_empty_chunk', __( 'Empty chunk received.', 'wpvault' ), array( 'status' => 400 ) );
		}

		if ( $meta['received_bytes'] + strlen( $body ) > $meta['total_size'] ) {
			return new \WP_Error( 'wpvault_import_too_large', __( 'This chunk would exceed the declared upload size.', 'wpvault' ), array( 'status' => 400 ) );
		}

		$dir       = Local_Storage::new_job_temp_dir( 'import-' . sanitize_file_name( $upload_id ) );
		$part_path = $dir . 'package.wpvault.part';
		$part      = fopen( $part_path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $part ) {
			return new \WP_Error( 'wpvault_import_write_failed', __( 'Could not write the uploaded chunk to disk.', 'wpvault' ), array( 'status' => 500 ) );
		}

		$written = fwrite( $part, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Preflight's free-space check is only an estimate -- disk_free_space()
		// often reports the whole filesystem/volume, not a shared host's
		// actual account quota, so a chunk can still fail here on a host
		// that looked fine at init. A partial write leaves the file longer
		// than what's tracked in received_bytes; truncate it back to that
		// last confirmed offset so file and metadata never disagree, and
		// this exact chunk can simply be retried once space is freed.
		if ( false === $written || $written !== strlen( $body ) ) {
			$fh = fopen( $part_path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( $fh ) {
				ftruncate( $fh, $meta['received_bytes'] );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			return new \WP_Error(
				'wpvault_import_disk_full',
				__( 'Could not write the full chunk to disk -- the server may be out of storage space or over its hosting quota. Free up space and try importing again.', 'wpvault' ),
				array( 'status' => 507 )
			);
		}

		$meta['received_bytes'] += strlen( $body );
		$this->write_import_meta( $upload_id, $meta );

		return rest_ensure_response(
			array(
				'received_bytes' => $meta['received_bytes'],
				'total_size'     => $meta['total_size'],
			)
		);
	}

	public function import_complete( \WP_REST_Request $request ) {
		$upload_id = (string) $request->get_param( 'upload_id' );
		$meta      = $this->read_import_meta( $upload_id );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( $meta['received_bytes'] !== $meta['total_size'] ) {
			return new \WP_Error(
				'wpvault_import_incomplete',
				sprintf(
					/* translators: 1: bytes received so far, 2: total expected bytes */
					__( 'Upload is incomplete -- received %1$s of %2$s.', 'wpvault' ),
					size_format( $meta['received_bytes'] ),
					size_format( $meta['total_size'] )
				),
				array( 'status' => 409 )
			);
		}

		$token        = 'import-' . sanitize_file_name( $upload_id );
		$dir          = Local_Storage::new_job_temp_dir( $token );
		$working_path = $dir . 'package.wpvault.part';

		$manifest = $this->read_and_validate_package( $working_path );

		if ( is_wp_error( $manifest ) ) {
			Local_Storage::remove_job_temp_dir( $token );
			return $manifest;
		}

		$backup   = Backup_Store::create( $manifest['backup_type'], Backup_Store::ORIGIN_IMPORT );
		$filename = sprintf( 'imported-%s-%s.wpvault', gmdate( 'Y-m-d-His' ), (int) $backup->id );
		$moved    = ( new Local_Storage() )->put( $working_path, $filename );

		if ( is_wp_error( $moved ) ) {
			Backup_Store::delete( $backup->id );
			Local_Storage::remove_job_temp_dir( $token );
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

		$backup = Backup_Store::mark_verified( $backup->id );

		Local_Storage::remove_job_temp_dir( $token ); // meta.json only by now -- the .part file was just moved out by put().

		return rest_ensure_response( $this->serialize_backup( $backup ) );
	}

	/**
	 * Picks a chunk size that fits comfortably under this server's own
	 * upload ceiling (whichever of upload_max_filesize/post_max_size binds)
	 * without needing any configuration -- a generous 8MB on a normal host,
	 * smaller wherever the host itself is more restrictive.
	 */
	private function import_chunk_size() {
		$max   = wp_max_upload_size();
		$chunk = (int) floor( $max * 0.8 );
		$chunk = min( $chunk, 8 * MB_IN_BYTES );
		$chunk = max( $chunk, 256 * KB_IN_BYTES );

		return min( $chunk, $max );
	}

	/**
	 * @return array|\WP_Error The decoded meta.json for an in-progress
	 *                         import, or a WP_Error if the upload_id is
	 *                         unknown (never started, already completed, or
	 *                         swept as stale).
	 */
	private function read_import_meta( $upload_id ) {
		$upload_id = sanitize_file_name( (string) $upload_id );

		if ( '' === $upload_id ) {
			return new \WP_Error( 'wpvault_import_not_found', __( 'Unknown upload.', 'wpvault' ), array( 'status' => 404 ) );
		}

		$meta_path = Local_Storage::temp_dir() . 'import-' . $upload_id . '/meta.json';

		if ( ! file_exists( $meta_path ) ) {
			return new \WP_Error( 'wpvault_import_not_found', __( 'This upload was not found -- it may have expired, or the server may have restarted. Please start the import again.', 'wpvault' ), array( 'status' => 404 ) );
		}

		$meta = json_decode( file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( ! is_array( $meta ) ) {
			return new \WP_Error( 'wpvault_import_corrupt', __( 'Upload state is corrupt. Please start the import again.', 'wpvault' ), array( 'status' => 500 ) );
		}

		return $meta;
	}

	private function write_import_meta( $upload_id, $meta ) {
		$dir = Local_Storage::temp_dir() . 'import-' . sanitize_file_name( $upload_id ) . '/';

		file_put_contents( $dir . 'meta.json', wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * @return array|\WP_Error The manifest array, or a WP_Error describing
	 *                         why the package was rejected.
	 */
	private function read_and_validate_package( $path ) {
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

		$structure = \WPVault\Backup\Package_Builder::verify_structure( $path, $type );

		if ( is_wp_error( $structure ) ) {
			return new \WP_Error( 'wpvault_invalid_package', $structure->get_error_message(), array( 'status' => 422 ) );
		}

		return $manifest;
	}

	public function delete_backup( \WP_REST_Request $request ) {
		$backup = Backup_Store::get_by_id( (int) $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Backup not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		if ( Job_Store::find_active_job_for_backup( $backup->id ) ) {
			return new \WP_Error( 'wpvault_backup_in_progress', __( 'This backup is still being created and cannot be deleted yet. Cancel the job first.', 'wpvault' ), array( 'status' => 409 ) );
		}

		if ( $backup->file_path ) {
			( new Local_Storage() )->delete( $backup->file_path );
		}

		Backup_Store::delete( $backup->id );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	private function serialize_backup( $backup ) {
		return array(
			'id'               => (int) $backup->id,
			'uuid'             => $backup->backup_uuid,
			'type'             => $backup->type,
			'status'           => $backup->status,
			'origin'           => $backup->origin,
			'source_url'       => $backup->source_url,
			'package_size'     => null === $backup->package_size ? null : (int) $backup->package_size,
			'file_count'       => null === $backup->file_count ? null : (int) $backup->file_count,
			'table_count'      => null === $backup->table_count ? null : (int) $backup->table_count,
			'created_at'       => $backup->created_at,
			'completed_at'     => $backup->completed_at,
			'download_url'     => $backup->file_path ? Download_Handler::url( $backup->id ) : null,
			'drive_link'       => $backup->drive_link,
		);
	}
}
