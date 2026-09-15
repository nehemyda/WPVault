<?php

namespace WPVault;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Log_Store;
use WPVault\Jobs\Cron_Runner;
use WPVault\Jobs\Job_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		Backup_Store::create_table();
		Job_Store::create_table();
		Log_Store::create_table();

		Local_Storage::ensure_directories();

		add_filter( 'cron_schedules', array( Cron_Runner::class, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		Cron_Runner::schedule();

		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			$administrator->add_cap( WPVAULT_CAP_MANAGE );
		}
	}
}
