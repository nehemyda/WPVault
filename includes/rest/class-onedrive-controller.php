<?php
/**
 * REST surface for the OneDrive "Connect" button: starts and polls the
 * OAuth Device Flow handshake (One_Drive::start_device_flow() /
 * poll_device_flow()), the same shape as Drive_Controller for Google Drive.
 */

namespace WPVault\Rest;

use WPVault\Storage\One_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Onedrive_Controller {

	public function register_routes() {
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/onedrive/connect/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/onedrive/connect/poll',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/onedrive/import-list',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission() {
		return wpvault_can_manage();
	}

	public function start() {
		$result = One_Drive::start_device_flow();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( $result );
	}

	public function poll() {
		return rest_ensure_response( One_Drive::poll_device_flow() );
	}

	/**
	 * Backs the Import screen's "Import from OneDrive" file picker.
	 */
	public function import_list() {
		if ( ! One_Drive::is_connected() ) {
			return new \WP_Error( 'wpvault_onedrive_not_connected', __( 'OneDrive is not connected.', 'wpvault' ), array( 'status' => 409 ) );
		}

		$files = One_Drive::list_backup_files();

		if ( is_wp_error( $files ) ) {
			return new \WP_Error( $files->get_error_code(), $files->get_error_message(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( array( 'files' => $files ) );
	}
}
