( function () {
	'use strict';

	var cfg = window.wpvaultCreateBackup;

	if ( ! cfg ) {
		return;
	}

	var currentJobId = null;
	var cancelled    = false;

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

	function renderPreflight( result ) {
		var el = document.getElementById( 'wpvault-preflight' );

		if ( result.ok ) {
			el.hidden = true;
			return;
		}

		var html = '<h2>' + 'Cannot start a backup yet' + '</h2><ul class="wpvault-checklist">';

		result.checks.forEach( function ( check ) {
			if ( ! check.ok ) {
				html += '<li class="wpvault-check-fail"><strong>' + escapeHtml( check.label ) + ':</strong> ' + escapeHtml( check.message ) + '</li>';
			}
		} );

		html += '</ul>';
		el.innerHTML = html;
		el.hidden = false;

		document.getElementById( 'wpvault-start-backup' ).disabled = true;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	function startBackup() {
		var type = document.querySelector( 'input[name="wpvault-type"]:checked' ).value;
		var excludeCache = document.getElementById( 'wpvault-exclude-cache' ).checked;

		document.getElementById( 'wpvault-start-backup' ).disabled = true;

		api( '/backups', 'POST', { type: type, exclude_cache: excludeCache } ).then( function ( res ) {
			if ( ! res.ok ) {
				showResult( false, res.data.message || cfg.i18n.failed );
				document.getElementById( 'wpvault-start-backup' ).disabled = false;
				return;
			}

			currentJobId = res.data.job_id;
			document.getElementById( 'wpvault-backup-form-card' ).hidden = true;
			document.getElementById( 'wpvault-progress-card' ).hidden = false;
			cancelled = false;
			poll();
		} );
	}

	function poll() {
		if ( cancelled || ! currentJobId ) {
			return;
		}

		api( '/jobs/' + currentJobId + '/step', 'POST' ).then( function ( res ) {
			if ( cancelled ) {
				return;
			}

			var job = res.data;

			document.getElementById( 'wpvault-progress-bar' ).style.width = job.percent + '%';
			document.getElementById( 'wpvault-progress-percent' ).textContent = job.percent + '%';
			document.getElementById( 'wpvault-progress-current' ).textContent = job.current_item || job.status;

			if ( 'completed' === job.status ) {
				document.getElementById( 'wpvault-progress-card' ).hidden = true;
				showResult( true, cfg.i18n.complete, job.backup );
				return;
			}

			if ( 'failed' === job.status || 'cancelled' === job.status ) {
				document.getElementById( 'wpvault-progress-card' ).hidden = true;
				showResult( false, job.error_message || cfg.i18n.failed );
				return;
			}

			setTimeout( poll, 800 );
		} );
	}

	function showResult( success, message, backup ) {
		var el = document.getElementById( 'wpvault-result-card' );
		var html = '<h2>' + ( success ? 'Backup complete' : 'Backup did not finish' ) + '</h2><p>' + escapeHtml( message ) + '</p>';

		if ( success && backup && backup.download_url ) {
			html += '<p><a class="button button-primary" href="' + backup.download_url + '">Download</a> ';
			html += '<a class="button" href="' + cfg.backupsUrl + '">View Backups</a></p>';
		}

		el.innerHTML = html;
		el.hidden = false;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.getElementById( 'wpvault-preflight' ).textContent = cfg.i18n.checking;
		document.getElementById( 'wpvault-preflight' ).hidden = false;

		api( '/preflight', 'GET' ).then( function ( res ) {
			renderPreflight( res.data );
		} );

		document.getElementById( 'wpvault-start-backup' ).addEventListener( 'click', startBackup );

		document.getElementById( 'wpvault-cancel-backup' ).addEventListener( 'click', function () {
			cancelled = true;

			if ( currentJobId ) {
				api( '/jobs/' + currentJobId + '/cancel', 'POST' );
			}

			document.getElementById( 'wpvault-progress-card' ).hidden = true;
			document.getElementById( 'wpvault-backup-form-card' ).hidden = false;
			document.getElementById( 'wpvault-start-backup' ).disabled = false;
		} );
	} );
} )();
