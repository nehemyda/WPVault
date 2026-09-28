<?php
/**
 * Plugin Name:       WPVault
 * Plugin URI:        https://wpvault.dev
 * Description:       Local WordPress backup, restore and migration. Chunked, resumable, and verified -- built to actually finish and actually restore.
 * Version:           0.7.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WPVault
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpvault
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPVAULT_VERSION', '0.7.0' );
define( 'WPVAULT_PLUGIN_FILE', __FILE__ );
define( 'WPVAULT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVAULT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPVAULT_ADMIN_SLUG', 'wpvault' );

/**
 * format_version stored in every manifest.json -- bumped only when the
 * .wpvault package layout itself changes in a way a future reader needs to
 * branch on, never on ordinary plugin releases.
 */
define( 'WPVAULT_PACKAGE_FORMAT_VERSION', '1' );

require WPVAULT_PLUGIN_DIR . 'includes/class-autoloader.php';
require WPVAULT_PLUGIN_DIR . 'includes/capabilities.php';

register_activation_hook( __FILE__, array( 'WPVault\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPVault\\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		WPVault\Plugin::instance()->init();
	}
);
