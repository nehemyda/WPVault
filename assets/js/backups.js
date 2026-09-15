( function () {
	'use strict';

	var cfg = window.wpvaultBackups;

	if ( ! cfg ) {
		return;
	}

	function api( path, method ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} );
	}

	function importFile() {
		var input  = document.getElementById( 'wpvault-import-file' );
		var button = document.getElementById( 'wpvault-import-button' );
		var result = document.getElementById( 'wpvault-import-result' );

		if ( ! input.files || ! input.files[0] ) {
			result.textContent = cfg.i18n.chooseFile;
			return;
		}

		var body = new FormData();
		body.append( 'package', input.files[0] );

		button.disabled = true;
		result.textContent = cfg.i18n.importing;

		// No Content-Type header here -- the browser sets the multipart
		// boundary itself when the body is a FormData; setting it manually
		// breaks the upload.
		fetch( cfg.restUrl + '/backups/import', {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: body,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} ).then( function ( res ) {
			button.disabled = false;

			if ( res.ok ) {
				result.textContent = 'Imported.';
				window.location.reload();
				return;
			}

			result.textContent = res.data.message || 'Import failed.';
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var verifyBtn = event.target.closest( '.wpvault-verify' );
		var deleteBtn = event.target.closest( '.wpvault-delete' );
		var importBtn = event.target.closest( '#wpvault-import-button' );

		if ( importBtn ) {
			importFile();
		}

		if ( verifyBtn ) {
			var id = verifyBtn.dataset.id;
			var cell = verifyBtn.closest( 'tr' ).querySelector( '.wpvault-status-cell' );
			var previous = cell.innerHTML;

			cell.textContent = cfg.i18n.verifying;

			api( '/backups/' + id + '/verify', 'POST' ).then( function ( res ) {
				if ( res.ok ) {
					cell.innerHTML = '<span class="wpvault-badge wpvault-badge-ok">Verified ✓</span>';
				} else {
					cell.innerHTML = '<span class="wpvault-badge wpvault-badge-error">Failed</span>';
					window.alert( res.data.message || 'Verification failed.' );
				}
			} );
		}

		if ( deleteBtn ) {
			if ( ! window.confirm( cfg.i18n.confirmDelete ) ) {
				return;
			}

			var id2 = deleteBtn.dataset.id;
			var row = deleteBtn.closest( 'tr' );

			api( '/backups/' + id2, 'DELETE' ).then( function ( res ) {
				if ( res.ok ) {
					row.remove();
				} else {
					window.alert( res.data.message || 'Could not delete backup.' );
				}
			} );
		}
	} );
} )();
