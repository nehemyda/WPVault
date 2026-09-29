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
		var spinnerEl          = document.getElementById( idPrefix + '-spinner' );

		if ( ! connectBtn ) {
			return;
		}

		// The spinner stays active for the whole stretch between clicking
		// Connect and either landing on "connected" (the page reloads right
		// after, so there's nothing left to un-spin) or a terminal failure
		// (expired/denied/error) -- not just the initial "Connecting..."
		// request, since "waiting for you to approve" can run for minutes.
		function setSpinning( spinning ) {
			if ( spinnerEl ) {
				spinnerEl.classList.toggle( 'is-active', spinning );
			}
		}

		var pollTimer = null;

		function poll( interval ) {
			api( restPrefix + '/connect/poll' ).then( function ( result ) {
				if ( ! result.ok ) {
					setSpinning( false );
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

				setSpinning( false );

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
				setSpinning( false );
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
			setSpinning( true );

			api( restPrefix + '/connect/start' ).then( function ( result ) {
				connectBtn.disabled = false;

				if ( ! result.ok ) {
					setSpinning( false );
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
				setSpinning( false );
				statusEl.textContent = cfg.i18n.error;
			} );
		} );
	}

	setupCloudConnect( 'wpvault-gdrive', '/drive' );
	setupCloudConnect( 'wpvault-onedrive', '/onedrive' );

	// --- Sidebar-driven sections -------------------------------------------
	//
	// Every section already exists in the page as its own card; this only
	// ever toggles which one is visible, so nothing above (schedule forms,
	// cloud connect wiring) needs to know sections exist at all. The active
	// section is kept in both the URL hash (so a save's own redirect back to
	// this page, or a bookmark/shared link, lands on the right one) and
	// localStorage (so simply reopening Settings later remembers where you
	// left off even without a hash).
	function setupSettingsSections() {
		var navItems = document.querySelectorAll( '.wpvault-settings-nav-item' );
		var panels   = document.querySelectorAll( '.wpvault-settings-panel' );

		if ( ! navItems.length || ! panels.length ) {
			return;
		}

		function activate( slug ) {
			var matched = false;

			navItems.forEach( function ( item ) {
				var isMatch = item.dataset.panel === slug;
				item.classList.toggle( 'is-active', isMatch );

				if ( isMatch ) {
					matched = true;
				}
			} );

			panels.forEach( function ( panel ) {
				panel.classList.toggle( 'is-active', panel.id === 'wpvault-panel-' + slug );
			} );

			return matched;
		}

		function rememberSlug( slug ) {
			try {
				window.localStorage.setItem( 'wpvaultSettingsSection', slug );
			} catch ( e ) {
				// Private browsing or a blocked store -- the hash still works.
			}
		}

		var initial = window.location.hash ? window.location.hash.replace( '#', '' ) : '';

		if ( ! initial || ! activate( initial ) ) {
			try {
				initial = window.localStorage.getItem( 'wpvaultSettingsSection' ) || '';
			} catch ( e ) {
				initial = '';
			}

			if ( ! initial || ! activate( initial ) ) {
				activate( navItems[ 0 ].dataset.panel );
			}
		}

		navItems.forEach( function ( item ) {
			item.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var slug = item.dataset.panel;

				activate( slug );
				rememberSlug( slug );
				window.location.hash = slug;
			} );
		} );

		var searchInput = document.getElementById( 'wpvault-settings-search' );

		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				var query = searchInput.value.trim().toLowerCase();

				navItems.forEach( function ( item ) {
					var matches = ! query || item.textContent.trim().toLowerCase().indexOf( query ) !== -1;
					item.closest( 'li' ).classList.toggle( 'is-hidden-by-search', ! matches );
				} );
			} );
		}
	}

	setupSettingsSections();
} )();
