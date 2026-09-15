<?php
/**
 * The one thing every archive-extraction path in this plugin must run
 * every entry name through before it touches the filesystem (§19.1).
 *
 * Deliberately a string check, not a realpath()-based one: the destination
 * usually doesn't exist yet (that's the point of extracting it), so
 * realpath() can't resolve it to compare against ABSPATH. Rejecting on the
 * entry name's own structure -- no parent-traversal segments, no absolute
 * path, no null bytes -- is what actually works before the file exists.
 */

namespace WPVault\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Path_Guard {

	public static function is_safe_relative_path( $relative_path ) {
		if ( ! is_string( $relative_path ) || '' === $relative_path ) {
			return false;
		}

		if ( false !== strpos( $relative_path, "\0" ) ) {
			return false;
		}

		// Absolute (unix or drive-letter/UNC) paths have no business in an
		// entry that is supposed to be relative to the site root.
		if ( '/' === $relative_path[0] || '\\' === $relative_path[0] || preg_match( '#^[A-Za-z]:#', $relative_path ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $relative_path );

		foreach ( explode( '/', $normalized ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}
}
