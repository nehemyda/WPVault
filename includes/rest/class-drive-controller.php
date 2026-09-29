<?php
/**
 * REST surface for the Google Drive "Connect" button: starts and polls the
 * OAuth Device Flow handshake (Google_Drive::start_device_flow() /
 * poll_device_flow()) so Settings can show a user code and a live
 * "waiting for you to approve..." status without a page reload or redirect.
 */

namespace WPVault\Rest;

use WPVault\Storage\Google_Drive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drive_Controller {

	public function register_routes() {
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/drive/connect/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/drive/connect/poll',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/drive/import-list',
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
		$result = Google_Drive::start_device_flow();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( $result );
	}

	public function poll() {
		return rest_ensure_response( Google_Drive::poll_device_flow() );
	}

	/**
	 * Backs the Import screen's "Import from Google Drive" file picker.
	 */
	public function import_list() {
		if ( ! Google_Drive::is_connected() ) {
			return new \WP_Error( 'wpvault_gdrive_not_connected', __( 'Google Drive is not connected.', 'wpvault' ), array( 'status' => 409 ) );
		}

		$files = Google_Drive::list_backup_files();

		if ( is_wp_error( $files ) ) {
			return new \WP_Error( $files->get_error_code(), $files->get_error_message(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( array( 'files' => $files ) );
	}
}
