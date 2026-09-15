<?php

namespace WPVault;

use WPVault\Jobs\Cron_Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * Stops the cron safety net only. Tables, options, caps and -- most
	 * importantly -- backup files are left untouched: deactivation is
	 * reversible, and a user's backups must not depend on the plugin
	 * staying active to remain safe.
	 */
	public static function deactivate() {
		Cron_Runner::unschedule();
	}
}
