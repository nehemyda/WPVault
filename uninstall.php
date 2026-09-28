<?php
/**
 * Uninstall WPVault.
 *
 * Drops this plugin's own bookkeeping -- its three tables, its DB-version
 * options, and its scheduled cron event. Backup packages under
 * wp-content/wpvault/backups/ are deliberately left on disk: those are the
 * site's actual backups, often the only copy, and a plugin removal (done to
 * troubleshoot something, or by mistake) must not be the thing that deletes
 * someone's safety net. Deleting them is a decision only the site owner
 * should make, from the filesystem or the Backups screen, while the plugin
 * is still there to explain what a file is before it's gone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'wpvault_backups',
	$wpdb->prefix . 'wpvault_jobs',
	$wpdb->prefix . 'wpvault_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

delete_option( 'wpvault_backups_db_version' );
delete_option( 'wpvault_jobs_db_version' );
delete_option( 'wpvault_logs_db_version' );

wp_clear_scheduled_hook( 'wpvault_cron_tick' );
wp_clear_scheduled_hook( 'wpvault_scheduled_backup_tick' );

delete_option( 'wpvault_schedule' );
delete_option( 'wpvault_pre_update' );
delete_transient( 'wpvault_pre_update_running' );

$administrator = get_role( 'administrator' );

if ( $administrator ) {
	$administrator->remove_cap( 'manage_wpvault' );
}
