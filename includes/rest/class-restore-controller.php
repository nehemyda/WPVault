<?php
/**
 * REST surface for starting a restore. Stepping/cancelling the resulting
 * job reuses the existing /jobs/{id} routes in Jobs_Controller unchanged --
 * restore is just a different job `type`, and that controller already only
 * cares about the job id.
 */

namespace WPVault\Rest;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Preflight;
use WPVault\Jobs\Job_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Restore_Controller {

	public function register_routes() {
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/restore-preflight',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preflight' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/backups/(?P<id>\d+)/restore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_restore' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'create_snapshot' => array(
						'default' => true,
						'type'    => 'boolean',
					),
					'old_url'         => array( 'type' => 'string' ),
					'new_url'         => array( 'type' => 'string' ),
				),
			)
		);
	}

	public function check_permission() {
		return wpvault_can_manage();
	}

	private function get_backup_or_404( \WP_REST_Request $request ) {
		$backup = Backup_Store::get_by_id( (int) $request->get_param( 'id' ) );

		if ( ! $backup ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Backup not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		return $backup;
	}

	public function preflight( \WP_REST_Request $request ) {
		$backup = $this->get_backup_or_404( $request );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$result             = Preflight::check_restore( $backup );
		$result['old_url']  = $backup->source_url;
		$result['new_url']  = home_url();

		return rest_ensure_response( $result );
	}

	public function start_restore( \WP_REST_Request $request ) {
		$backup = $this->get_backup_or_404( $request );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$preflight = Preflight::check_restore( $backup );

		if ( ! $preflight['ok'] ) {
			return new \WP_Error( 'wpvault_preflight_failed', __( 'WPVault cannot start this restore -- see the checks below.', 'wpvault' ), array( 'status' => 422, 'checks' => $preflight['checks'] ) );
		}

		$busy = Job_Store::find_latest_active();

		if ( $busy ) {
			return new \WP_Error( 'wpvault_job_in_progress', __( 'Another backup or restore job is already running. Wait for it to finish first.', 'wpvault' ), array( 'status' => 409 ) );
		}

		$job = Job_Store::create(
			Job_Store::TYPE_RESTORE,
			$backup->id,
			array(
				'create_snapshot' => (bool) $request->get_param( 'create_snapshot' ),
				'old_url'         => (string) $request->get_param( 'old_url' ),
				'new_url'         => (string) $request->get_param( 'new_url' ),
			)
		);

		return rest_ensure_response( array( 'job_id' => $job->id ) );
	}
}
