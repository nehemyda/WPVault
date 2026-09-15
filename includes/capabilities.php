<?php
/**
 * Capability for WPVault administrative actions.
 *
 * A single tier: creating, downloading, and deleting backups all reach the
 * filesystem and the database wholesale, so there is no useful split between
 * "can view" and "can act" the way SEO Autopilot Pro splits edit/manage/AI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPVAULT_CAP_MANAGE', 'manage_wpvault' );

function wpvault_can_manage() {
	return current_user_can( WPVAULT_CAP_MANAGE );
}
