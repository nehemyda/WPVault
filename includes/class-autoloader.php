<?php
/**
 * PSR-ish autoloader for the WPVault namespace.
 *
 * WPVault\Foo\Bar_Baz -> includes/foo/class-bar-baz.php
 *
 * Interfaces live in a "class-*.php" file too (not "interface-*.php") --
 * one naming rule for every autoloadable symbol means the autoloader never
 * has to guess which kind of file to look for.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'WPVault\\' ) ) {
			return;
		}

		$relative   = substr( $class, strlen( 'WPVault\\' ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );

		$path = array_map(
			static function ( $part ) {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);

		$slug = strtolower( str_replace( '_', '-', $class_name ) );
		$path[] = 'class-' . $slug . '.php';

		$full_path = WPVAULT_PLUGIN_DIR . 'includes/' . implode( '/', $path );

		if ( file_exists( $full_path ) ) {
			require $full_path;
		}
	}
);
