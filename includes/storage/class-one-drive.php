<?php
/**
 * A thin Microsoft Graph API client plus the OAuth handshake needed to use
 * it. Sibling to Google_Drive, not a shared abstraction over it -- the two
 * vendors' upload protocols and OAuth quirks differ enough (chunk size
 * multiples, refresh token rotation, error codes) that forcing one interface
 * over both would cost more than it saves. Deliberately NOT a
 * Storage_Adapter for the same reason Google_Drive isn't: backups are always
 * created locally first; this only pushes an already-finished local package
 * to OneDrive on request.
 *
 * Uses the OAuth 2.0 Device Authorization Grant against one app registration
 * shared by every WPVault install -- same one-click "Connect" pattern as
 * Google Drive, no redirect URI, no per-site setup. Unlike Google's device
 * flow, Microsoft's public client here has no client secret at all: the
 * Azure app is registered with "Allow public client flows" enabled and no
 * secret generated, so the client_secret parameter is simply omitted from
 * every token request.
 *
 * The requested scope is `Files.ReadWrite.AppFolder`, OneDrive's equivalent
 * of Google's `drive.file`: access is limited to the app's own special
 * folder (auto-created under /Apps/<app name> on first use), never the rest
 * of the user's OneDrive.
 *
 * Microsoft's refresh tokens rotate on every use (unlike Google's, which
 * stays valid until revoked) -- get_valid_access_token() always persists
 * whatever refresh_token comes back from a refresh call, not just the
 * access token.
 */

namespace WPVault\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class One_Drive {

	const OPTION            = 'wpvault_onedrive';
	const DEVICE_TRANSIENT  = 'wpvault_onedrive_device';
	const CLIENT_ID         = '52c0b898-80cd-4985-ae4c-5401ec506c46';
	const SCOPE             = 'Files.ReadWrite.AppFolder offline_access User.Read';
	const DEVICE_CODE_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/devicecode';
	const TOKEN_ENDPOINT    = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	const GRAPH_BASE        = 'https://graph.microsoft.com/v1.0';
	const APP_FOLDER        = '/me/drive/special/approot';

	// A safety margin before the token's real expiry, so a slow request
	// started just before expiry never gets a 401 mid-flight.
	const TOKEN_EXPIRY_MARGIN = 60;

	public static function defaults() {
		return array(
			'access_token'    => '',
			'refresh_token'   => '',
			'expires_at'      => 0,
			'email'           => '',
			'needs_reconnect' => false,
		);
	}

	public static function get_settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	private static function save( array $fields ) {
		$merged = array_merge( self::get_settings(), $fields );

		update_option( self::OPTION, $merged );

		return $merged;
	}

	public static function is_connected() {
		return '' !== self::get_settings()['refresh_token'];
	}

	public static function get_connected_email() {
		return self::get_settings()['email'];
	}

	/**
	 * True once get_valid_access_token() has seen Microsoft reject the
	 * stored refresh token outright -- distinct from never having connected
	 * at all, so Settings can say "reconnect" instead of just "connect".
	 */
	public static function needs_reconnect() {
		return ! empty( self::get_settings()['needs_reconnect'] );
	}

	/**
	 * Starts a new OAuth Device Flow handshake. See Google_Drive's own
	 * start_device_flow() for the shared rationale; the wire format here
	 * differs only in field names (Microsoft returns `verification_uri`,
	 * Google returns `verification_url`) and in needing no client secret.
	 *
	 * @return array|\WP_Error {user_code, verification_url, interval, expires_in}
	 */
	public static function start_device_flow() {
		$response = wp_remote_post(
			self::DEVICE_CODE_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id' => self::CLIENT_ID,
					'scope'     => self::SCOPE,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['device_code'] ) || empty( $data['user_code'] ) ) {
			return new \WP_Error( 'wpvault_onedrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 900;
		$interval   = isset( $data['interval'] ) ? (int) $data['interval'] : 5;

		set_transient(
			self::DEVICE_TRANSIENT,
			array(
				'device_code' => $data['device_code'],
				'interval'    => $interval,
			),
			$expires_in
		);

		return array(
			'user_code'        => $data['user_code'],
			'verification_url' => isset( $data['verification_uri'] ) ? $data['verification_uri'] : 'https://microsoft.com/devicelogin',
			'interval'         => $interval,
			'expires_in'       => $expires_in,
		);
	}

	/**
	 * Polls the token endpoint for the device code start_device_flow()
	 * stashed. See Google_Drive::poll_device_flow() for the shared shape;
	 * Microsoft's error codes differ (`authorization_declined` rather than
	 * `access_denied`), and no refresh-token-already-issued edge case is
	 * expected here -- Microsoft issues a fresh one on every grant.
	 *
	 * @return array {status: pending|connected|expired|denied|error, message?, email?, slow_down?}
	 */
	public static function poll_device_flow() {
		$pending = get_transient( self::DEVICE_TRANSIENT );

		if ( ! $pending ) {
			return array( 'status' => 'expired' );
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'   => self::CLIENT_ID,
					'device_code' => $pending['device_code'],
					'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'error',
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['access_token'] ) && ! empty( $data['refresh_token'] ) ) {
			delete_transient( self::DEVICE_TRANSIENT );

			self::save(
				array(
					'access_token'    => $data['access_token'],
					'refresh_token'   => $data['refresh_token'],
					'expires_at'      => time() + (int) $data['expires_in'],
					'needs_reconnect' => false,
				)
			);

			$email = self::fetch_connected_email();

			if ( ! is_wp_error( $email ) ) {
				self::save( array( 'email' => $email ) );
			}

			return array(
				'status' => 'connected',
				'email'  => is_wp_error( $email ) ? '' : $email,
			);
		}

		$error = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : '';

		switch ( $error ) {
			case 'authorization_pending':
				return array( 'status' => 'pending' );

			case 'slow_down':
				return array(
					'status'    => 'pending',
					'slow_down' => true,
				);

			case 'expired_token':
				delete_transient( self::DEVICE_TRANSIENT );
				return array( 'status' => 'expired' );

			case 'authorization_declined':
				delete_transient( self::DEVICE_TRANSIENT );
				return array( 'status' => 'denied' );

			default:
				delete_transient( self::DEVICE_TRANSIENT );
				return array(
					'status'  => 'error',
					'message' => self::error_message_from_response( $data, $code ),
				);
		}
	}

	/**
	 * admin_post handler for the Settings screen's "Disconnect" button.
	 */
	public static function handle_disconnect() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpvault' ) );
		}

		check_admin_referer( 'wpvault_onedrive_disconnect' );

		self::save(
			array(
				'access_token'    => '',
				'refresh_token'   => '',
				'expires_at'      => 0,
				'email'           => '',
				'needs_reconnect' => false,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_onedrive_disconnected=1' ) );
		exit;
	}

	/**
	 * @return string|\WP_Error A currently-valid access token, refreshing
	 *                          first if the stored one has expired.
	 */
	public static function get_valid_access_token() {
		$settings = self::get_settings();

		if ( '' === $settings['refresh_token'] ) {
			$message = ! empty( $settings['needs_reconnect'] )
				? __( 'Your OneDrive connection expired or was revoked. Reconnect it from Settings.', 'wpvault' )
				: __( 'OneDrive is not connected. Connect it from Settings first.', 'wpvault' );

			return new \WP_Error( 'wpvault_onedrive_not_connected', $message );
		}

		if ( '' !== $settings['access_token'] && time() < ( $settings['expires_at'] - self::TOKEN_EXPIRY_MARGIN ) ) {
			return $settings['access_token'];
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => self::CLIENT_ID,
					'refresh_token' => $settings['refresh_token'],
					'grant_type'    => 'refresh_token',
					'scope'         => self::SCOPE,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : null;

			if ( 'invalid_grant' === $error ) {
				// Microsoft itself rejected the refresh token -- revoked
				// from the user's account, or the app's access removed.
				// Retrying later won't help; clear the connection so every
				// surface treats it as needing a fresh Connect.
				self::save(
					array(
						'access_token'    => '',
						'refresh_token'   => '',
						'expires_at'      => 0,
						'needs_reconnect' => true,
					)
				);

				return new \WP_Error( 'wpvault_onedrive_not_connected', __( 'Your OneDrive connection expired or was revoked. Reconnect it from Settings.', 'wpvault' ) );
			}

			return new \WP_Error( 'wpvault_onedrive_auth_failed', self::error_message_from_response( $data, $code ) );
		}

		// Microsoft rotates refresh tokens on every use -- always persist
		// whatever came back, falling back to the existing one only if this
		// response omitted it (shouldn't happen, but don't strand the
		// connection over a missing field).
		self::save(
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => ! empty( $data['refresh_token'] ) ? $data['refresh_token'] : $settings['refresh_token'],
				'expires_at'    => time() + (int) $data['expires_in'],
			)
		);

		return $data['access_token'];
	}

	private static function fetch_connected_email() {
		$response = self::api_get( self::GRAPH_BASE . '/me?$select=mail,userPrincipalName' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['mail'] ) ) {
			return $response['mail'];
		}

		return isset( $response['userPrincipalName'] ) ? $response['userPrincipalName'] : '';
	}

	private static function api_get( $url ) {
		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'wpvault_onedrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	private static function error_message_from_response( $data, $code ) {
		if ( is_array( $data ) && ! empty( $data['error_description'] ) ) {
			return $data['error_description'];
		}

		if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		return sprintf(
			/* translators: %d: HTTP status code OneDrive returned */
			__( 'OneDrive returned an unexpected error (HTTP %d).', 'wpvault' ),
			$code
		);
	}

	/**
	 * Opens a resumable upload session for a new file inside the app's
	 * special OneDrive folder (auto-created under /Apps/<app name> the
	 * first time anything is written to it -- no separate "ensure folder
	 * exists" step needed, unlike Drive).
	 *
	 * @return string|\WP_Error The uploadUrl to PUT chunks to.
	 */
	public static function start_resumable_upload( $filename, $total_size ) {
		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::GRAPH_BASE . self::APP_FOLDER . ':/' . rawurlencode( $filename ) . ':/createUploadSession',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'item' => array(
							'@microsoft.graph.conflictBehavior' => 'rename',
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['uploadUrl'] ) ) {
			return new \WP_Error( 'wpvault_onedrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		return $data['uploadUrl'];
	}

	/**
	 * Uploads one chunk of an in-progress resumable session.
	 *
	 * @param string $session_url The uploadUrl start_resumable_upload() returned.
	 * @param string $chunk       Raw bytes for this chunk. Every chunk but
	 *                            the last must be a multiple of 320 KiB, per
	 *                            Graph's resumable upload protocol.
	 * @param int    $offset      Byte offset this chunk starts at.
	 * @param int    $total_size  The full upload's total size in bytes.
	 * @return array|\WP_Error {done:false} once more chunks are expected,
	 *                          or {done:true, file_id, web_view_link} once
	 *                          OneDrive confirms the file is complete.
	 */
	public static function upload_chunk( $session_url, $chunk, $offset, $total_size ) {
		$chunk_len = strlen( $chunk );
		$last_byte = $offset + $chunk_len - 1;

		// The uploadUrl is itself a pre-authorized, short-lived URL -- an
		// Authorization header is deliberately not sent, matching Graph's
		// documented resumable upload examples.
		$response = wp_remote_request(
			$session_url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array(
					'Content-Length' => (string) $chunk_len,
					'Content-Range'  => "bytes {$offset}-{$last_byte}/{$total_size}",
				),
				'body'    => $chunk,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 202 === $code ) {
			return array( 'done' => false );
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['id'] ) ) {
				return new \WP_Error( 'wpvault_onedrive_api_error', __( 'OneDrive confirmed the upload but did not return a file id.', 'wpvault' ) );
			}

			return array(
				'done'          => true,
				'file_id'       => $data['id'],
				'web_view_link' => isset( $data['webUrl'] ) ? $data['webUrl'] : '',
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return new \WP_Error( 'wpvault_onedrive_api_error', self::error_message_from_response( $data, $code ) );
	}
}
