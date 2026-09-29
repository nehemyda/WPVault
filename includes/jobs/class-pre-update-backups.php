<?php
/**
 * Automatic backup right before a plugin, theme, or core update actually
 * applies (roadmap). Hooks `upgrader_pre_install`, the filter WordPress's
 * own Plugin_Upgrader/Theme_Upgrader/Core_Upgrader all run through just
 * before touching files -- for a plugin/theme update, for a core update,
 * for a browser-driven update, a WP-Cron background auto-update, or a
 * WP-CLI `wp plugin update`/`wp core update`, since all of those go through
 * the exact same WP_Upgrader::run() code path.
 *
 * Unlike Scheduled_Backups, this can't just create a job and let the
 * once-a-minute safety net finish it later -- the whole point is a backup
 * that exists *before* the update. So it drives the job inline for up to
 * MAX_WAIT_SECONDS. If it doesn't finish in that window, the update is
 * allowed to proceed anyway (the safety net picks the job up from there) --
 * this plugin will never hold a security update hostage to its own backup
 * finishing, and never fails or blocks the update over a backup problem.
 */

namespace WPVault\Jobs;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Preflight;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pre_Update_Backups {

	const OPTION = 'wpvault_pre_update';

	// Comfortably under a typical 30s max_execution_time, leaving headroom
	// for the update itself to still run afterward in the same request.
	const MAX_WAIT_SECONDS = 20;

	// A bulk update in the modern wp-admin Plugins list table fires one
	// admin-ajax "update-plugin" request per selected plugin -- separate
	// PHP processes, not a loop within one request -- so an in-memory flag
	// can't dedupe across them (confirmed by hand: it let a 2-plugin bulk
	// update through as two backups). A short-lived transient survives
	// across those requests and collapses one browser bulk-update action
	// into a single backup, while still letting a distinct later update the
	// same day get its own.
	const REQUEST_LOCK_TRANSIENT = 'wpvault_pre_update_running';
	const REQUEST_LOCK_TTL       = 30;

	public static function defaults() {
		return array(
			'enabled'        => false,
			'type'           => Backup_Store::TYPE_DATABASE, // Fast default -- most likely to actually finish before the update proceeds.
			'targets'        => array(
				'plugin' => true,
				'theme'  => true,
				'core'   => true,
			),
			'retention'      => 5,
			'last_run_at'    => null,
			'last_backup_id' => null,
			'last_trigger'   => null,
			'last_error'     => null,
		);
	}

	public static function get_settings() {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$settings['targets'] = wp_parse_args( (array) $settings['targets'], self::defaults()['targets'] );

		return $settings;
	}

	public static function update_settings( array $input ) {
		$merged = array_merge( self::get_settings(), $input );

		update_option( self::OPTION, $merged );

		return $merged;
	}

	/**
	 * admin_post handler for the Settings page's pre-update form -- same
	 * plain POST-and-redirect pattern as Scheduled_Backups::handle_settings_save().
	 */
	public static function handle_settings_save() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpvault' ) );
		}

		check_admin_referer( 'wpvault_save_pre_update' );

		$defaults = self::defaults();

		$type = isset( $_POST['wpvault_pu_type'] ) ? sanitize_key( wp_unslash( $_POST['wpvault_pu_type'] ) ) : $defaults['type'];

		if ( ! in_array( $type, array( Backup_Store::TYPE_FULL, Backup_Store::TYPE_DATABASE, Backup_Store::TYPE_FILES ), true ) ) {
			$type = $defaults['type'];
		}

		$retention = isset( $_POST['wpvault_pu_retention'] ) ? max( 0, min( 90, (int) $_POST['wpvault_pu_retention'] ) ) : $defaults['retention'];

		self::update_settings(
			array(
				'enabled'   => ! empty( $_POST['wpvault_pu_enabled'] ),
				'type'      => $type,
				'targets'   => array(
					'plugin' => ! empty( $_POST['wpvault_pu_target_plugin'] ),
					'theme'  => ! empty( $_POST['wpvault_pu_target_theme'] ),
					'core'   => ! empty( $_POST['wpvault_pu_target_core'] ),
				),
				'retention' => $retention,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_pre_update_saved=1#pre-update' ) );
		exit;
	}

	/**
	 * The upgrader_pre_install filter callback. Always returns $response
	 * unchanged -- this never blocks or fails an update, only rides along
	 * with one.
	 *
	 * @param bool|\WP_Error $response   Whatever the upgrader already decided; passed through untouched.
	 * @param array          $hook_extra Identifies what's being updated -- see trigger_from_hook_extra().
	 */
	public static function maybe_backup_before_update( $response, $hook_extra = array() ) {
		if ( is_wp_error( $response ) || get_transient( self::REQUEST_LOCK_TRANSIENT ) ) {
			return $response;
		}

		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return $response;
		}

		$trigger = self::trigger_from_hook_extra( (array) $hook_extra );

		if ( ! $trigger || empty( $settings['targets'][ $trigger ] ) ) {
			return $response;
		}

		if ( Job_Store::find_latest_active() ) {
			return $response; // Something else is already running -- don't pile on or delay the update further.
		}

		// A bulk update fires this filter once per item -- one pre-update
		// backup per browser action is the right amount of protection, not
		// one per plugin.
		set_transient( self::REQUEST_LOCK_TRANSIENT, time(), self::REQUEST_LOCK_TTL );

		self::run_now( $settings, $trigger );

		return $response;
	}

	/**
	 * @return string|null 'plugin', 'theme', or 'core', or null if this
	 *                     upgrader run doesn't look like one of those (a
	 *                     fresh install rather than an update, for
	 *                     instance) -- pre-update backups only ever fire
	 *                     for an actual update.
	 */
	private static function trigger_from_hook_extra( array $hook_extra ) {
		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return null;
		}

		if ( isset( $hook_extra['type'] ) && in_array( $hook_extra['type'], array( 'plugin', 'theme', 'core' ), true ) ) {
			return $hook_extra['type'];
		}

		if ( isset( $hook_extra['plugin'] ) ) {
			return 'plugin';
		}

		if ( isset( $hook_extra['theme'] ) ) {
			return 'theme';
		}

		return null;
	}

	private static function run_now( array $settings, $trigger ) {
		$preflight = Preflight::run();

		if ( ! $preflight['ok'] ) {
			$failed = array_values(
				array_filter(
					$preflight['checks'],
					static function ( $check ) {
						return ! $check['ok'];
					}
				)
			);

			self::update_settings(
				array(
					'last_trigger' => $trigger,
					'last_error'   => $failed ? $failed[0]['message'] : __( 'Preflight failed.', 'wpvault' ),
				)
			);

			return;
		}

		$backup = Backup_Store::create( $settings['type'], Backup_Store::ORIGIN_PRE_UPDATE );
		$job    = Job_Store::create(
			Job_Store::TYPE_BACKUP,
			$backup->id,
			array(
				'backup_type'   => $settings['type'],
				'exclude_cache' => true,
			)
		);

		$start = microtime( true );

		while ( microtime( true ) - $start < self::MAX_WAIT_SECONDS ) {
			$job = Job_Runner::step( $job->id, 10 );

			if ( Job_Store::is_terminal( $job->status ) ) {
				break;
			}
		}

		// Still running past the wait window? The wpvault_cron_tick safety
		// net resumes it the same way it resumes any other abandoned job --
		// the update itself is never held up any further than this.
		Backup_Store::prune_by_origin( Backup_Store::ORIGIN_PRE_UPDATE, $settings['retention'], $backup->id );

		self::update_settings(
			array(
				'last_run_at'    => time(),
				'last_backup_id' => $backup->id,
				'last_trigger'   => $trigger,
				'last_error'     => Job_Store::STATUS_FAILED === $job->status ? $job->error_message : null,
			)
		);
	}
}
