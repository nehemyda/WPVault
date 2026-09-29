<?php
/**
 * REST surface for stepping a job (§24: GET /jobs/{id}). The browser polls
 * the step endpoint in a loop while a job is active; each call does one
 * bounded chunk of work server-side (Job_Runner::step) and returns the
 * resulting progress, so the client never needs to know anything about
 * phases, chunk sizes, or locking.
 */

namespace WPVault\Rest;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Log_Store;
use WPVault\Jobs\Job_Runner;
use WPVault\Jobs\Job_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jobs_Controller {

	// Short on purpose -- this is the budget used while a browser tab is
	// actively polling, so the progress bar keeps moving smoothly instead
	// of freezing for a long single request. Job_Cron_Runner uses a much
	// longer budget for the same underlying step() when nobody is watching.
	const FOREGROUND_TIME_BUDGET = 4;

	public function register_routes() {
		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'step_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Rest_Controller::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission() {
		return wpvault_can_manage();
	}

	public function get_job( \WP_REST_Request $request ) {
		$job = Job_Store::get_by_id( (int) $request->get_param( 'id' ) );

		if ( ! $job ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Job not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->serialize_job( $job ) );
	}

	public function step_job( \WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );
		$job    = Job_Runner::step( $job_id, self::FOREGROUND_TIME_BUDGET );

		if ( ! $job ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Job not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->serialize_job( $job ) );
	}

	public function cancel_job( \WP_REST_Request $request ) {
		$job = Job_Runner::cancel( (int) $request->get_param( 'id' ) );

		if ( ! $job ) {
			return new \WP_Error( 'wpvault_not_found', __( 'Job not found.', 'wpvault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->serialize_job( $job ) );
	}

	private function serialize_job( $job ) {
		$percent = 0;

		if ( Job_Store::STATUS_COMPLETED === $job->status ) {
			$percent = 100;
		} elseif ( $job->total_bytes > 0 ) {
			$percent = (int) min( 99, round( ( $job->processed_bytes / $job->total_bytes ) * 100 ) );
		}

		$data = array(
			'id'               => (int) $job->id,
			'type'             => $job->type,
			'backup_id'        => (int) $job->backup_id,
			'status'           => $job->status,
			'phase'            => $job->phase,
			'percent'          => $percent,
			'processed_bytes'  => (int) $job->processed_bytes,
			'total_bytes'      => (int) $job->total_bytes,
			'processed_items'  => (int) $job->processed_items,
			'total_items'      => (int) $job->total_items,
			'current_item'     => $job->current_item,
			'error_message'    => $job->error_message,
		);

		if ( Job_Store::STATUS_COMPLETED === $job->status && Job_Store::TYPE_BACKUP === $job->type ) {
			$backup           = Backup_Store::get_by_id( $job->backup_id );
			$data['backup']   = $backup ? array(
				'id'           => (int) $backup->id,
				'status'       => $backup->status,
				'package_size' => (int) $backup->package_size,
				'download_url' => \WPVault\Admin\Download_Handler::url( $backup->id ),
			) : null;
		}

		if ( Job_Store::STATUS_COMPLETED === $job->status && Job_Store::TYPE_RESTORE === $job->type ) {
			$payload            = Job_Store::get_payload( $job );
			$data['restore']    = array(
				'site_url'           => home_url(),
				'snapshot_backup_id' => isset( $payload['snapshot_backup_id'] ) ? (int) $payload['snapshot_backup_id'] : null,
			);
		}

		if ( Job_Store::STATUS_COMPLETED === $job->status && Job_Store::TYPE_DRIVE_UPLOAD === $job->type ) {
			$backup       = Backup_Store::get_by_id( $job->backup_id );
			$data['drive'] = $backup ? array( 'link' => $backup->drive_link ) : null;
		}

		if ( Job_Store::STATUS_COMPLETED === $job->status && Job_Store::TYPE_ONEDRIVE_UPLOAD === $job->type ) {
			$backup          = Backup_Store::get_by_id( $job->backup_id );
			$data['onedrive'] = $backup ? array( 'link' => $backup->onedrive_link ) : null;
		}

		if ( Job_Store::STATUS_FAILED === $job->status ) {
			$data['logs'] = array_map(
				static function ( $log ) {
					return array(
						'level'      => $log->level,
						'message'    => $log->message,
						'created_at' => $log->created_at,
					);
				},
				Log_Store::for_job( $job->id, 10 )
			);
		}

		return $data;
	}
}
