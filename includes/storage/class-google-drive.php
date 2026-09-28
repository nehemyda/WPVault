<?php
/**
 * A thin Google Drive API v3 client plus the OAuth handshake needed to use
 * it. Deliberately NOT a Storage_Adapter -- backups are still always
 * created locally first via the existing engine untouched; this only
 * pushes an already-finished local package to Drive on request (the
 * Backups screen's "Save to Google Drive" choice), the same way
 * Download_Handler streams one to the browser instead.
 *
 * There is no shared/shipped OAuth credential: a self-hosted, open-source
 * plugin has no backend of its own to broker one safely, so the site owner
 * creates their own Google Cloud OAuth app (Client ID + Secret) and pastes
 * it into Settings, the same as most self-hosted plugins with Drive
 * support. The requested scope is `drive.file`, not the broader `drive`
 * scope -- it only ever grants access to files this plugin itself creates,
 * never the rest of the user's Drive, which also keeps a self-hosted OAuth
 * app out of Google's stricter "sensitive scope" verification review.
 */

namespace WPVault\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Drive {

	const OPTION                = 'wpvault_gdrive';
	const OAUTH_CALLBACK_ACTION = 'wpvault_gdrive_oauth_callback';
	const SCOPE                 = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_ENDPOINT         = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT        = 'https://oauth2.googleapis.com/token';
	const REVOKE_ENDPOINT       = 'https://oauth2.googleapis.com/revoke';
	const API_BASE              = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_BASE           = 'https://www.googleapis.com/upload/drive/v3/files';
	const FOLDER_NAME           = 'WPVault Backups';

	// A safety margin before the token's real expiry, so a slow request
	// started just before expiry never gets a 401 mid-flight.
	const TOKEN_EXPIRY_MARGIN = 60;

	public static function defaults() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'access_token'  => '',
			'refresh_token' => '',
			'expires_at'    => 0,
			'email'         => '',
			'folder_id'     => '',
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

	public static function has_credentials() {
		$settings = self::get_settings();

		return '' !== $settings['client_id'] && '' !== $settings['client_secret'];
	}

	public static function is_connected() {
		return '' !== self::get_settings()['refresh_token'];
	}

	public static function get_connected_email() {
		return self::get_settings()['email'];
	}

	public static function redirect_uri() {
		return add_query_arg( array( 'action' => self::OAUTH_CALLBACK_ACTION ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * admin_post handler saving the site owner's own Google Cloud OAuth app
	 * credentials. Changing them invalidates any existing connection --
	 * a refresh token only works with the client_id/secret that issued it.
	 */
	public static function handle_save_credentials() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpvault' ) );
		}

		check_admin_referer( 'wpvault_save_gdrive_credentials' );

		$client_id     = isset( $_POST['wpvault_gdrive_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wpvault_gdrive_client_id'] ) ) : '';
		$client_secret = isset( $_POST['wpvault_gdrive_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['wpvault_gdrive_client_secret'] ) ) : '';

		$current = self::get_settings();

		if ( $client_id !== $current['client_id'] || $client_secret !== $current['client_secret'] ) {
			self::save(
				array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'access_token'  => '',
					'refresh_token' => '',
					'expires_at'    => 0,
					'email'         => '',
					'folder_id'     => '',
				)
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_credentials_saved=1' ) );
		exit;
	}

	/**
	 * The URL Settings' "Connect Google Drive" link points to. access_type=
	 * offline + prompt=consent are both required to reliably get a
	 * refresh_token back -- Google only issues one on first consent unless
	 * consent is forced again, and without offline access it wouldn't be
	 * issued at all.
	 */
	public static function get_authorize_url() {
		$settings = self::get_settings();

		return add_query_arg(
			array(
				'client_id'              => rawurlencode( $settings['client_id'] ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => wp_create_nonce( 'wpvault_gdrive_oauth' ),
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * admin_post handler for Google's redirect back after consent.
	 */
	public static function handle_oauth_callback() {
		if ( ! wpvault_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpvault' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $state, 'wpvault_gdrive_oauth' ) ) {
			wp_die( esc_html__( 'This Google Drive connection link has expired. Please try connecting again from Settings.', 'wpvault' ) );
		}

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_error=' . rawurlencode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $code ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_error=missing_code' ) );
			exit;
		}

		$tokens = self::request_token_endpoint(
			array(
				'code'          => $code,
				'client_id'     => self::get_settings()['client_id'],
				'client_secret' => self::get_settings()['client_secret'],
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_error=' . rawurlencode( $tokens->get_error_message() ) ) );
			exit;
		}

		if ( empty( $tokens['refresh_token'] ) ) {
			// Google omits this if the user had already granted consent
			// before without prompt=consent -- shouldn't happen given we
			// always pass it, but fail clearly rather than "connect"
			// without anything that can actually stay connected.
			wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_error=no_refresh_token' ) );
			exit;
		}

		self::save(
			array(
				'access_token'  => $tokens['access_token'],
				'refresh_token' => $tokens['refresh_token'],
				'expires_at'    => time() + (int) $tokens['expires_in'],
			)
		);

		$email = self::fetch_connected_email();

		if ( ! is_wp_error( $email ) ) {
			self::save( array( 'email' => $email ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpvault-settings&wpvault_gdrive_connected=1' ) );
		exit;
	}

	/**
	 * admin_post handler for the Settings screen's "Disconnect" button.
	 * Credentials (client_id/secret) are kept -- only the connection itself
	 * is torn down, so reconnecting doesn't require re-entering them.
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
				'access_token'  => '',
				'refresh_token' => '',
				'expires_at'    => 0,
				'email'         => '',
				'folder_id'     => '',
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
			return new \WP_Error( 'wpvault_gdrive_not_connected', __( 'Google Drive is not connected. Connect it from Settings first.', 'wpvault' ) );
		}

		if ( '' !== $settings['access_token'] && time() < ( $settings['expires_at'] - self::TOKEN_EXPIRY_MARGIN ) ) {
			return $settings['access_token'];
		}

		$tokens = self::request_token_endpoint(
			array(
				'refresh_token' => $settings['refresh_token'],
				'client_id'     => $settings['client_id'],
				'client_secret' => $settings['client_secret'],
				'grant_type'    => 'refresh_token',
			)
		);

		if ( is_wp_error( $tokens ) ) {
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

			return new \WP_Error( 'wpvault_gdrive_auth_failed', $message );
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
}
