<?php

namespace WPVault;

use WPVault\Admin\Admin_Menu;
use WPVault\Admin\Download_Handler;
use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Log_Store;
use WPVault\Jobs\Cron_Runner;
use WPVault\Jobs\Job_Store;
use WPVault\Rest\Rest_Controller;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init() {
		Backup_Store::maybe_create_table();
		Job_Store::maybe_create_table();
		Log_Store::maybe_create_table();

		Local_Storage::ensure_directories();

		// Registered on every load, not only on activation: a site updated
		// from a version before the cron safety net existed never re-runs
		// the activation hook, so without this its minute-tick would
		// silently never start. The interval itself must also be
		// re-registered every load -- WP re-reads cron_schedules to decide
		// when a recurring event fires again, and an unknown slug silently
		// turns it into a one-off.
		add_filter( 'cron_schedules', array( Cron_Runner::class, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		Cron_Runner::schedule();
		add_action( Cron_Runner::CRON_HOOK, array( Cron_Runner::class, 'run_tick' ) );

		( new Rest_Controller() )->register();

		if ( is_admin() ) {
			( new Admin_Menu() )->register();
			( new Download_Handler() )->register();
		}

		// Never loaded on a normal web request -- the reference to
		// Cli_Commands (which triggers the autoloader) only happens inside
		// this guard.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wpvault', \WPVault\Cli\Cli_Commands::class );
		}
	}
}
