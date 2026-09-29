<?php
/**
 * A thin Google Drive API v3 client plus the OAuth handshake needed to use
 * it. Deliberately NOT a Storage_Adapter -- backups are still always
 * created locally first via the existing engine untouched; this only
 * pushes an already-finished local package to Drive on request (the
 * Backups screen's "Save to Google Drive" choice), the same way
 * Download_Handler streams one to the browser instead.
 *
 * Uses the OAuth 2.0 Device Authorization Grant (RFC 8628) against one
 * Client ID shared by every WPVault install, instead of the Authorization
 * Code flow's per-domain redirect URI -- Device Flow needs no redirect URI
 * at all, so a single Client ID works identically no matter what domain
 * the site is on, and the site owner never touches Google Cloud Console.
 * The requested scope is `drive.file`, not the broader `drive` scope -- it
 * only ever grants access to files this plugin itself creates, never the
 * rest of the user's Drive.
 *
 * Refresh tokens are issued per (Google account, Client ID) pair, not per
 * site: if the same Google account has already granted this shared client
 * a refresh token (e.g. connecting a second WPVault site to the same
 * Drive account), Google may not issue a second one. poll_device_flow()
 * surfaces that case as an error asking the user to revoke WPVault's
 * access at myaccount.google.com/permissions and reconnect.
 */

namespace WPVault\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Drive {

	const OPTION              = 'wpvault_gdrive';
	const DEVICE_TRANSIENT    = 'wpvault_gdrive_device';
	const CLIENT_ID           = '82224326945-d34ca3k71l6af4roa1i3af69f07q5b82.apps.googleusercontent.com';
	const CLIENT_SECRET       = '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';
	const SCOPE               = 'https://www.googleapis.com/auth/drive.file';
	const DEVICE_CODE_ENDPOINT = 'https://oauth2.googleapis.com/device/code';
	const TOKEN_ENDPOINT      = 'https://oauth2.googleapis.com/token';
	const REVOKE_ENDPOINT     = 'https://oauth2.googleapis.com/revoke';
	const API_BASE            = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_BASE         = 'https://www.googleapis.com/upload/drive/v3/files';
	const FOLDER_NAME         = 'WPVault Backups';

	// A safety margin before the token's real expiry, so a slow request
	// started just before expiry never gets a 401 mid-flight.
	const TOKEN_EXPIRY_MARGIN = 60;

	public static function defaults() {
		return array(
			'access_token'    => '',
			'refresh_token'   => '',
			'expires_at'      => 0,
			'email'           => '',
			'folder_id'       => '',
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
	 * True once get_valid_access_token() has seen Google reject the stored
	 * refresh token outright (revoked from the user's Google account,
	 * expired, or the OAuth app's own access removed) -- distinct from
	 * never having connected at all, so Settings can say "reconnect"
	 * instead of just "connect".
	 */
	public static function needs_reconnect() {
		return ! empty( self::get_settings()['needs_reconnect'] );
	}

	/**
	 * Starts a new OAuth Device Flow handshake: asks Google for a user_code
	 * the site owner enters at a Google-hosted URL on any device, with no
	 * redirect URI needed -- the one thing that lets every WPVault install
	 * share this single Client ID despite running on a different domain
	 * each. The returned device_code is stashed server-side (never sent to
	 * the browser) for poll_device_flow() to redeem.
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
			return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 1800;
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
			'verification_url' => isset( $data['verification_url'] ) ? $data['verification_url'] : 'https://www.google.com/device',
			'interval'         => $interval,
			'expires_in'       => $expires_in,
		);
	}

	/**
	 * Polls the token endpoint for the device code start_device_flow()
	 * stashed. Meant to be called repeatedly (per the interval that call
	 * returned) until it reports something other than "pending".
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
					'client_id'     => self::CLIENT_ID,
					'client_secret' => self::CLIENT_SECRET,
					'device_code'   => $pending['device_code'],
					'grant_type'    => 'urn:ietf:params:oauth:grant-type:device_code',
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

		if ( $code >= 200 && $code < 300 && ! empty( $data['access_token'] ) ) {
			delete_transient( self::DEVICE_TRANSIENT );

			// A refresh token is only issued the FIRST time this Google
			// account grants this shared Client ID access -- keep any
			// already-stored one if this authorization didn't get a new
			// one (e.g. reconnecting the same account after a token
			// refresh failure, rather than a first-time connect).
			$current_refresh = self::get_settings()['refresh_token'];
			$refresh_token   = ! empty( $data['refresh_token'] ) ? $data['refresh_token'] : $current_refresh;

			if ( '' === $refresh_token ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Google did not grant a long-lived connection, likely because this Google account already authorized WPVault for another site. Remove WPVault\'s access at https://myaccount.google.com/permissions, then try connecting again.', 'wpvault' ),
				);
			}

			self::save(
				array(
					'access_token'    => $data['access_token'],
					'refresh_token'   => $refresh_token,
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

			case 'access_denied':
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

		check_admin_referer( 'wpvault_gdrive_disconnect' );

		$settings = self::get_settings();

		if ( '' !== $settings['refresh_token'] ) {
			// Best-effort -- Google may already consider the token invalid,
			// and either way the connection is being cleared locally below.
			wp_remote_post(
				self::REVOKE_ENDPOINT,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => $settings['refresh_token'] ),
				)
			);
		}

		self::save(
			array(
				'access_token'    => '',
				'refresh_token'   => '',
				'expires_at'      => 0,
				'email'           => '',
				'folder_id'       => '',
				'needs_reconnect' => false,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_disconnected=1' ) );
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
				? __( 'Your Google Drive connection expired or was revoked. Reconnect it from Settings.', 'wpvault' )
				: __( 'Google Drive is not connected. Connect it from Settings first.', 'wpvault' );

			return new \WP_Error( 'wpvault_gdrive_not_connected', $message );
		}

		if ( '' !== $settings['access_token'] && time() < ( $settings['expires_at'] - self::TOKEN_EXPIRY_MARGIN ) ) {
			return $settings['access_token'];
		}

		$tokens = self::request_token_endpoint(
			array(
				'refresh_token' => $settings['refresh_token'],
				'client_id'     => self::CLIENT_ID,
				'client_secret' => self::CLIENT_SECRET,
				'grant_type'    => 'refresh_token',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			$error_data   = $tokens->get_error_data();
			$google_error = is_array( $error_data ) && isset( $error_data['google_error'] ) ? $error_data['google_error'] : null;

			if ( 'invalid_grant' === $google_error ) {
				// Google itself rejected the refresh token -- revoked from
				// the user's Google account, or the OAuth app's access
				// removed. Retrying later won't help; clear the connection
				// so every surface (Settings, the Backups dropdown,
				// scheduled/manual auto-upload) treats it as needing a
				// fresh Connect, not a transient hiccup.
				self::save(
					array(
						'access_token'    => '',
						'refresh_token'   => '',
						'expires_at'      => 0,
						'needs_reconnect' => true,
					)
				);

				return new \WP_Error( 'wpvault_gdrive_not_connected', __( 'Your Google Drive connection expired or was revoked. Reconnect it from Settings.', 'wpvault' ) );
			}

			return $tokens;
		}

		self::save(
			array(
				'access_token' => $tokens['access_token'],
				'expires_at'   => time() + (int) $tokens['expires_in'],
			)
		);

		return $tokens['access_token'];
	}

	private static function request_token_endpoint( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 30,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$message = is_array( $data ) && ! empty( $data['error_description'] )
				? $data['error_description']
				: sprintf(
					/* translators: %d: HTTP status code Google returned */
					__( 'Google returned an unexpected response (HTTP %d).', 'wpvault' ),
					$code
				);

			// Google's own error code (carried in the WP_Error's data, not
			// just its message) is what get_valid_access_token() uses to
			// tell "the refresh token itself was rejected -- reconnect"
			// apart from a transient failure worth just retrying later.
			return new \WP_Error(
				'wpvault_gdrive_auth_failed',
				$message,
				array( 'google_error' => is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : null )
			);
		}

		return $data;
	}

	private static function fetch_connected_email() {
		$response = self::api_get( self::API_BASE . '/about?fields=user(emailAddress)' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['user']['emailAddress'] ) ? $response['user']['emailAddress'] : '';
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
			return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	private static function error_message_from_response( $data, $code ) {
		if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		return sprintf(
			/* translators: %d: HTTP status code Google Drive returned */
			__( 'Google Drive returned an unexpected error (HTTP %d).', 'wpvault' ),
			$code
		);
	}

	/**
	 * Finds (or creates, on first use) the single folder every backup this
	 * plugin uploads goes into. Cached in settings after the first call --
	 * with the drive.file scope, files.list only ever sees items this app
	 * created or the user opened with it, so searching only ever matters
	 * once, before that folder id is known locally.
	 *
	 * @return string|\WP_Error
	 */
	public static function ensure_backups_folder() {
		$settings = self::get_settings();

		if ( '' !== $settings['folder_id'] ) {
			return $settings['folder_id'];
		}

		$query    = sprintf(
			"mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
			str_replace( "'", "\\'", self::FOLDER_NAME )
		);
		$existing = self::api_get( self::API_BASE . '/files?' . http_build_query( array( 'q' => $query, 'fields' => 'files(id)' ) ) );

		if ( ! is_wp_error( $existing ) && ! empty( $existing['files'][0]['id'] ) ) {
			self::save( array( 'folder_id' => $existing['files'][0]['id'] ) );
			return $existing['files'][0]['id'];
		}

		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::API_BASE . '/files?fields=id',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'name'     => self::FOLDER_NAME,
						'mimeType' => 'application/vnd.google-apps.folder',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		self::save( array( 'folder_id' => $data['id'] ) );

		return $data['id'];
	}

	/**
	 * Opens a resumable upload session for a new file. Chunk uploads that
	 * follow use this exact session URL and need no Content-Type of their
	 * own -- Drive already knows it from X-Upload-Content-Type here.
	 *
	 * @return string|\WP_Error The session URL to PUT chunks to.
	 */
	public static function start_resumable_upload( $filename, $total_size ) {
		$folder_id = self::ensure_backups_folder();

		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::UPLOAD_BASE . '?uploadType=resumable&fields=id,webViewLink',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'          => 'Bearer ' . $token,
					'Content-Type'           => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'  => 'application/octet-stream',
					'X-Upload-Content-Length' => (string) $total_size,
				),
				'body'    => wp_json_encode(
					array(
						'name'    => $filename,
						'parents' => array( $folder_id ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( $code < 200 || $code >= 300 || ! $location ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		return $location;
	}

	/**
	 * Uploads one chunk of an in-progress resumable session.
	 *
	 * @param string $session_url The URL start_resumable_upload() returned.
	 * @param string $chunk       Raw bytes for this chunk. Every chunk but
	 *                            the last must be a multiple of 256 KiB,
	 *                            per Drive's resumable upload protocol.
	 * @param int    $offset      Byte offset this chunk starts at.
	 * @param int    $total_size  The full upload's total size in bytes.
	 * @return array|\WP_Error {done:false} once more chunks are expected,
	 *                          or {done:true, file_id, web_view_link} once
	 *                          Drive confirms the file is complete.
	 */
	public static function upload_chunk( $session_url, $chunk, $offset, $total_size ) {
		$chunk_len = strlen( $chunk );
		$last_byte = $offset + $chunk_len - 1;

		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_request(
			$session_url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
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

		if ( 308 === $code ) {
			return array( 'done' => false );
		}

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['id'] ) ) {
				return new \WP_Error( 'wpvault_gdrive_api_error', __( 'Google Drive confirmed the upload but did not return a file id.', 'wpvault' ) );
			}

			return array(
				'done'          => true,
				'file_id'       => $data['id'],
				'web_view_link' => isset( $data['webViewLink'] ) ? $data['webViewLink'] : '',
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
	}

	/**
	 * Lists .wpvault packages sitting in this app's own Drive folder, for
	 * the Import screen's "Import from Google Drive" picker -- the
	 * drive.file scope means files.list only ever sees items this app
	 * itself created, so this can only ever surface WPVault's own uploads,
	 * never arbitrary Drive content.
	 *
	 * @return array|\WP_Error List of {id, name, size, modified_time}.
	 */
	public static function list_backup_files() {
		$folder_id = self::ensure_backups_folder();

		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$query  = sprintf( "'%s' in parents and trashed=false and name contains '.wpvault'", $folder_id );
		$result = self::api_get(
			self::API_BASE . '/files?' . http_build_query(
				array(
					'q'        => $query,
					'fields'   => 'files(id,name,size,modifiedTime)',
					'orderBy'  => 'modifiedTime desc',
					'pageSize' => 100,
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$files = isset( $result['files'] ) && is_array( $result['files'] ) ? $result['files'] : array();

		return array_map(
			static function ( $file ) {
				return array(
					'id'            => $file['id'],
					'name'          => $file['name'],
					'size'          => isset( $file['size'] ) ? (int) $file['size'] : 0,
					'modified_time' => isset( $file['modifiedTime'] ) ? $file['modifiedTime'] : '',
				);
			},
			$files
		);
	}

	/**
	 * @return array|\WP_Error {size, name} for a file this app has access to.
	 */
	public static function get_file_info( $file_id ) {
		$result = self::api_get( self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?fields=size,name' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'size' => isset( $result['size'] ) ? (int) $result['size'] : 0,
			'name' => isset( $result['name'] ) ? $result['name'] : '',
		);
	}

	/**
	 * Downloads one byte range of an existing file's raw contents -- Drive's
	 * media download endpoint supports HTTP Range requests, so a large
	 * package is pulled the same chunk-at-a-time way everything else in this
	 * plugin handles large files, instead of one unbounded request.
	 *
	 * @return string|\WP_Error Raw bytes for this range.
	 */
	public static function download_chunk( $file_id, $offset, $length ) {
		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$last_byte = $offset + $length - 1;

		$response = wp_remote_get(
			self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?alt=media',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Range'         => "bytes={$offset}-{$last_byte}",
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 206 !== $code && 200 !== $code ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'wpvault_gdrive_api_error', self::error_message_from_response( $data, $code ) );
		}

		return wp_remote_retrieve_body( $response );
	}
}
