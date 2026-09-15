<?php
/**
 * Builds manifest.json -- the fields listed in §10.1, plus the exclusions
 * that were applied, so a restore (or a curious admin) never has to guess
 * what a package actually contains.
 */

namespace WPVault\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manifest {

	public static function build( $backup, array $stats, array $exclusion_labels ) {
		global $wpdb;

		return array(
			'format'            => 'wpvault',
			'format_version'    => WPVAULT_PACKAGE_FORMAT_VERSION,
			'backup_id'         => $backup->backup_uuid,
			'backup_type'       => $backup->type,
			'created_at'        => gmdate( 'c' ),
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'database_engine'   => stripos( $wpdb->db_server_info(), 'mariadb' ) !== false ? 'MariaDB' : 'MySQL',
			'table_prefix'      => $wpdb->prefix,
			'table_count'       => isset( $stats['table_count'] ) ? (int) $stats['table_count'] : 0,
			'file_count'        => isset( $stats['file_count'] ) ? (int) $stats['file_count'] : 0,
			'database_size'     => isset( $stats['database_size'] ) ? (int) $stats['database_size'] : 0,
			'files_size'        => isset( $stats['files_size'] ) ? (int) $stats['files_size'] : 0,
			'checksum_algorithm' => 'sha256',
			'excluded_items'    => array_values( $exclusion_labels ),
		);
	}

	public static function build_site_metadata() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'admin_email' => get_bloginfo( 'admin_email' ),
			'language'    => get_bloginfo( 'language' ),
			'multisite'   => is_multisite(),
			'theme'       => wp_get_theme()->get( 'Name' ),
		);
	}

	public static function build_environment_metadata() {
		global $wpdb;

		return array(
			'php_version'      => PHP_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'mysql_version'    => $wpdb->db_version(),
			'os'               => PHP_OS,
			'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}
}
