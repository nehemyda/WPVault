<?php

namespace WPVault\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu() {
		// One instance each, reused for both add_menu_page() and its
		// matching add_submenu_page() below -- passing two different
		// instances (even of the same class/method) would give WordPress
		// two distinct callback identities on what resolves to the same
		// hookname, and the dashboard would render twice.
		$dashboard     = new Dashboard_Page();
		$create_backup = new Create_Backup_Page();
		$backups       = new Backups_Page();
		$restore       = new Restore_Page();
		$settings      = new Settings_Page();

		add_menu_page(
			__( 'WPVault', 'wpvault' ),
			__( 'WPVault', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			WPVAULT_ADMIN_SLUG,
			array( $dashboard, 'render' ),
			'dashicons-database-export',
			75
		);

		add_submenu_page(
			WPVAULT_ADMIN_SLUG,
			__( 'Dashboard', 'wpvault' ),
			__( 'Dashboard', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			WPVAULT_ADMIN_SLUG,
			array( $dashboard, 'render' )
		);

		add_submenu_page(
			WPVAULT_ADMIN_SLUG,
			__( 'Create Backup', 'wpvault' ),
			__( 'Create Backup', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			'wpvault-create-backup',
			array( $create_backup, 'render' )
		);

		add_submenu_page(
			WPVAULT_ADMIN_SLUG,
			__( 'Backups', 'wpvault' ),
			__( 'Backups', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			'wpvault-backups',
			array( $backups, 'render' )
		);

		add_submenu_page(
			WPVAULT_ADMIN_SLUG,
			__( 'Restore', 'wpvault' ),
			__( 'Restore', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			'wpvault-restore',
			array( $restore, 'render' )
		);

		add_submenu_page(
			WPVAULT_ADMIN_SLUG,
			__( 'Settings', 'wpvault' ),
			__( 'Settings', 'wpvault' ),
			WPVAULT_CAP_MANAGE,
			'wpvault-settings',
			array( $settings, 'render' )
		);
	}
}
