<?php

namespace WPVault;

use WPVault\Admin\Admin_Menu;
use WPVault\Admin\Download_Handler;
use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Log_Store;
use WPVault\Jobs\Cron_Runner;
use WPVault\Jobs\Job_Store;
use WPVault\Jobs\Pre_Update_Backups;
use WPVault\Jobs\Scheduled_Backups;
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

		add_filter( 'cron_schedules', array( Scheduled_Backups::class, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		Scheduled_Backups::schedule();
		add_action( Scheduled_Backups::CRON_HOOK, array( Scheduled_Backups::class, 'run_tick' ) );
		add_action( 'admin_post_wpvault_save_schedule', array( Scheduled_Backups::class, 'handle_settings_save' ) );

		// Runs synchronously inline with WP's own upgrader (plugin/theme/core,
		// whether triggered from wp-admin, a WP-Cron background auto-update,
		// or WP-CLI) -- see Pre_Update_Backups for why this can't be a cron
		// tick the way Scheduled_Backups is.
		add_filter( 'upgrader_pre_install', array( Pre_Update_Backups::class, 'maybe_backup_before_update' ), 10, 2 );
		add_action( 'admin_post_wpvault_save_pre_update', array( Pre_Update_Backups::class, 'handle_settings_save' ) );

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
