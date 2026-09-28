<?php

namespace WPVault;

use WPVault\Jobs\Cron_Runner;
use WPVault\Jobs\Scheduled_Backups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * Stops both cron hooks only. Tables, options (including the schedule
	 * config itself), caps and -- most importantly -- backup files are left
	 * untouched: deactivation is reversible, and a user's backups (and
	 * their schedule settings) must not depend on the plugin staying active
	 * to remain safe or to survive a reactivation.
	 */
	public static function deactivate() {
		Cron_Runner::unschedule();
		Scheduled_Backups::unschedule();
	}
}
