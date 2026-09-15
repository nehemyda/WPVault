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

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_backup' ),
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

		$type          = $request->get_param( 'type' );
		$exclude_cache = (bool) $request->get_param( 'exclude_cache' );

		$backup = Backup_Store::create( $type );
		$job    = Job_Store::create(
			Job_Store::TYPE_BACKUP,
			$backup->id,
			array(
				'backup_type'   => $type,
				'exclude_cache' => $exclude_cache,
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

	/**
	 * §15/§3.1: bring a .wpvault package made on a different site onto this
	 * one. Deliberately synchronous, not a job -- validating an upload means
	 * reading a zip's central directory and manifest, not scanning file
	 * contents, and that's fast regardless of package size.
	 */
	public function import_backup( \WP_REST_Request $request ) {
		$preflight = Preflight::run();

		if ( ! $preflight['ok'] ) {
			return new \WP_Error( 'wpvault_preflight_failed', __( 'WPVault cannot import a package right now -- see the checks below.', 'wpvault' ), array( 'status' => 422, 'checks' => $preflight['checks'] ) );
		}

		$files = $request->get_file_params();

		if ( empty( $files['package'] ) || UPLOAD_ERR_OK !== $files['package']['error'] ) {
			return new \WP_Error( 'wpvault_upload_failed', __( 'No file was received, or the upload failed (it may be larger than this server allows).', 'wpvault' ), array( 'status' => 400 ) );
		}

		Local_Storage::ensure_directories();
		$working_path = Local_Storage::temp_dir() . 'import-' . wp_generate_password( 12, false ) . '.wpvault';

		// move_uploaded_file(), not Local_Storage::put()'s rename() -- its
		// built-in is_uploaded_file() check is what stands between this
		// endpoint and a crafted tmp_name naming an arbitrary server file.
		if ( ! move_uploaded_file( $files['package']['tmp_name'], $working_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file, WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'wpvault_upload_failed', __( 'Could not save the uploaded file.', 'wpvault' ), array( 'status' => 500 ) );
		}

		$manifest = $this->read_and_validate_package( $working_path );

		if ( is_wp_error( $manifest ) ) {
			wp_delete_file( $working_path );
			return $manifest;
		}

		$backup = Backup_Store::create( $manifest['backup_type'] );
		$filename = sprintf( 'imported-%s-%s.wpvault', gmdate( 'Y-m-d-His' ), (int) $backup->id );
		$moved    = ( new Local_Storage() )->put( $working_path, $filename );

		if ( is_wp_error( $moved ) ) {
			Backup_Store::delete( $backup->id );
			wp_delete_file( $working_path );
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

		return rest_ensure_response( $this->serialize_backup( $backup ) );
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
			'source_url'       => $backup->source_url,
			'package_size'     => null === $backup->package_size ? null : (int) $backup->package_size,
			'file_count'       => null === $backup->file_count ? null : (int) $backup->file_count,
			'table_count'      => null === $backup->table_count ? null : (int) $backup->table_count,
			'created_at'       => $backup->created_at,
			'completed_at'     => $backup->completed_at,
			'download_url'     => $backup->file_path ? Download_Handler::url( $backup->id ) : null,
		);
	}
}
