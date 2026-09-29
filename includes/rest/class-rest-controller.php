<?php

namespace WPVault\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller {

	const NAMESPACE_V1 = 'wpvault/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		( new Backups_Controller() )->register_routes();
		( new Jobs_Controller() )->register_routes();
		( new Restore_Controller() )->register_routes();
		( new Drive_Controller() )->register_routes();
	}
}
