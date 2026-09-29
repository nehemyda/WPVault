( function () {
	'use strict';

	var cfg = window.wpvaultSettings;

	if ( ! cfg ) {
		return;
	}

	function api( path ) {
		return fetch( cfg.restUrl + path, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} );
	}

	/**
	 * Wires up one "Connect" button + device-code UI for a given cloud
	 * storage provider. Google Drive and OneDrive both use the exact same
	 * OAuth Device Flow UI pattern (button -> code + link -> poll -> reload),
	 * differing only in element id prefix and REST path -- this is purely a
	 * UI-wiring helper, not a shared abstraction over the two providers' own
	 * server-side OAuth/upload logic, which stay separate on purpose.
	 */
	function setupCloudConnect( idPrefix, restPrefix ) {
		var connectBtn        = document.getElementById( idPrefix + '-connect-btn' );
		var deviceBox          = document.getElementById( idPrefix + '-device' );
		var userCodeEl         = document.getElementById( idPrefix + '-user-code' );
		var verificationLinkEl = document.getElementById( idPrefix + '-verification-url' );
		var statusEl           = document.getElementById( idPrefix + '-device-status' );

		if ( ! connectBtn ) {
			return;
		}

		var pollTimer = null;

		function poll( interval ) {
			api( restPrefix + '/connect/poll' ).then( function ( result ) {
				if ( ! result.ok ) {
					statusEl.textContent = ( result.data && result.data.message ) || cfg.i18n.error;
					return;
				}

				var status = result.data.status;

				if ( 'connected' === status ) {
					statusEl.textContent = cfg.i18n.connected;
					window.location.reload();
					return;
				}

				if ( 'pending' === status ) {
					statusEl.textContent = cfg.i18n.waiting;

					if ( result.data.slow_down ) {
						interval += 5;
					}

					pollTimer = setTimeout( function () {
						poll( interval );
					}, interval * 1000 );
					return;
				}

				if ( 'expired' === status ) {
					statusEl.textContent = cfg.i18n.expired;
					return;
				}

				if ( 'denied' === status ) {
					statusEl.textContent = cfg.i18n.denied;
					return;
				}

				statusEl.textContent = result.data.message || cfg.i18n.error;
			} ).catch( function () {
				statusEl.textContent = cfg.i18n.error;
			} );
		}

		connectBtn.addEventListener( 'click', function () {
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}

			connectBtn.disabled = true;
			deviceBox.style.display = 'none';
			statusEl.textContent = cfg.i18n.connecting;

			api( restPrefix + '/connect/start' ).then( function ( result ) {
				connectBtn.disabled = false;

				if ( ! result.ok ) {
					statusEl.textContent = ( result.data && result.data.message ) || cfg.i18n.error;
					return;
				}

				userCodeEl.textContent = result.data.user_code;
				verificationLinkEl.textContent = result.data.verification_url;
				verificationLinkEl.href = result.data.verification_url;
				deviceBox.style.display = '';
				statusEl.textContent = cfg.i18n.waiting;

				poll( result.data.interval || 5 );
			} ).catch( function () {
				connectBtn.disabled = false;
				statusEl.textContent = cfg.i18n.error;
			} );
		} );
	}

	setupCloudConnect( 'wpvault-gdrive', '/drive' );
	setupCloudConnect( 'wpvault-onedrive', '/onedrive' );
} )();
