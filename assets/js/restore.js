( function () {
	'use strict';

	var cfg = window.wpvaultRestore;

	if ( ! cfg ) {
		return;
	}

	var currentJobId = null;

	function api( path, method, body ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/json',
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	function loadPreflight() {
		document.getElementById( 'wpvault-preflight' ).textContent = cfg.i18n.checking;
		document.getElementById( 'wpvault-preflight' ).hidden = false;

		api( '/backups/' + cfg.backupId + '/restore-preflight', 'GET' ).then( function ( res ) {
			var result = res.data;
			var el     = document.getElementById( 'wpvault-preflight' );

			document.getElementById( 'wpvault-old-url' ).value = result.old_url || '';
			document.getElementById( 'wpvault-new-url' ).value = result.new_url || cfg.homeUrl;

			if ( result.ok ) {
				el.hidden = true;
			} else {
				var html = '<h2>Cannot restore this backup</h2><ul class="wpvault-checklist">';

				( result.checks || [] ).forEach( function ( check ) {
					if ( ! check.ok ) {
						html += '<li class="wpvault-check-fail"><strong>' + escapeHtml( check.label ) + ':</strong> ' + escapeHtml( check.message ) + '</li>';
					}
				} );

				html += '</ul>';
				el.innerHTML = html;
				el.hidden = false;
			}

			document.getElementById( 'wpvault-restore-confirm-card' ).hidden = false;
			document.getElementById( 'wpvault-start-restore' ).disabled = ! result.ok;
		} );
	}

	function startRestore() {
		var createSnapshot = document.getElementById( 'wpvault-create-snapshot' ).checked;
		var oldUrl          = document.getElementById( 'wpvault-old-url' ).value;
		var newUrl          = document.getElementById( 'wpvault-new-url' ).value;

		document.getElementById( 'wpvault-start-restore' ).disabled = true;

		api( '/backups/' + cfg.backupId + '/restore', 'POST', {
			create_snapshot: createSnapshot,
			old_url: oldUrl,
			new_url: newUrl,
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				showResult( false, res.data.message || cfg.i18n.failed );
				document.getElementById( 'wpvault-start-restore' ).disabled = false;
				return;
			}

			currentJobId = res.data.job_id;
			document.getElementById( 'wpvault-restore-confirm-card' ).hidden = true;
			document.getElementById( 'wpvault-restore-progress-card' ).hidden = false;
			poll();
		} );
	}

	function poll() {
		api( '/jobs/' + currentJobId + '/step', 'POST' ).then( function ( res ) {
			var job = res.data;

			document.getElementById( 'wpvault-restore-progress-bar' ).style.width = job.percent + '%';
			document.getElementById( 'wpvault-restore-progress-percent' ).textContent = job.percent + '%';
			document.getElementById( 'wpvault-restore-progress-current' ).textContent = job.current_item || job.status;

			if ( 'completed' === job.status ) {
				document.getElementById( 'wpvault-restore-progress-card' ).hidden = true;
				showResult( true, cfg.i18n.complete, job.restore );
				return;
			}

			if ( 'failed' === job.status || 'cancelled' === job.status ) {
				document.getElementById( 'wpvault-restore-progress-card' ).hidden = true;
				showResult( false, job.error_message || cfg.i18n.failed );
				return;
			}

			setTimeout( poll, 800 );
		} );
	}

	function showResult( success, message, restore ) {
		var el   = document.getElementById( 'wpvault-restore-result-card' );
		var html = '<h2>' + ( success ? 'Restore complete' : 'Restore did not finish' ) + '</h2><p>' + escapeHtml( message ) + '</p>';

		if ( success && restore && restore.site_url ) {
			html += '<p><a class="button button-primary" href="' + restore.site_url + '">Visit Site</a> ';
			html += '<a class="button" href="' + cfg.backupsUrl + '">View Backups</a></p>';
		}

		if ( ! success ) {
			html += '<p>' + escapeHtml( 'A safety snapshot may have been created before this failure -- check Backups.' ) + '</p>';
			html += '<p><a class="button" href="' + cfg.backupsUrl + '">View Backups</a></p>';
		}

		el.innerHTML = html;
		el.hidden = false;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadPreflight();
		document.getElementById( 'wpvault-start-restore' ).addEventListener( 'click', startRestore );
	} );
} )();
