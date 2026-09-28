( function () {
	'use strict';

	var cfg = window.wpvaultBackups;

	if ( ! cfg ) {
		return;
	}

	var currentJobId    = null;
	var backupCancelled = false;
	var backupBusy      = false; // true from "Start Backup" click until completed/failed/cancelled
	var importBusy      = false; // true only while the upload request is actually in flight

	var restoreJobId     = null;
	var restoreCancelled = false;
	var restoreBackupId  = null;
	var restoreBusy      = false; // true from "Start Restore" click until completed/failed

	function api( path, method, body ) {
		var headers = { 'X-WP-Nonce': cfg.nonce };

		if ( body ) {
			headers['Content-Type'] = 'application/json';
		}

		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			headers: headers,
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

	// --- Modals -----------------------------------------------------------
	//
	// Only one reason either modal ever needs to warn before closing: once
	// "Start Backup" or "Import" has been clicked, walking away is either
	// lossy (the import upload has no resume -- closing mid-upload means
	// starting over) or just loses the progress view (a backup job itself
	// keeps running server-side either way, cron picks it up if the tab
	// really does go away). beforeunload covers every way of *leaving the
	// page* -- closing the tab, refreshing, typing a new URL, or clicking
	// any other wp-admin menu item, since those are all normal navigations
	// here, not an SPA route change. The modal's own close button and
	// switching to the other modal are handled separately since
	// beforeunload never fires for those (the page itself never unloads).

	function anyOtherBusy( name ) {
		var flags = { create: backupBusy, import: importBusy, restore: restoreBusy };
		delete flags[ name ];
		return flags.create || flags.import || flags.restore;
	}

	function openModal( name ) {
		if ( anyOtherBusy( name ) ) {
			window.alert( cfg.i18n.busyOpenOther );
			return;
		}

		document.getElementById( 'wpvault-' + name + '-modal' ).hidden = false;
	}

	function closeModal( name, force ) {
		if ( ! force ) {
			if ( 'create' === name && backupBusy && ! window.confirm( cfg.i18n.closeBackupConfirm ) ) {
				return;
			}

			if ( 'import' === name && importBusy && ! window.confirm( cfg.i18n.closeImportConfirm ) ) {
				return;
			}

			if ( 'restore' === name && restoreBusy && ! window.confirm( cfg.i18n.closeRestoreConfirm ) ) {
				return;
			}
		}

		document.getElementById( 'wpvault-' + name + '-modal' ).hidden = true;
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! backupBusy && ! importBusy && ! restoreBusy ) {
			return;
		}

		event.preventDefault();
		event.returnValue = cfg.i18n.leaveWarning; // Most browsers show their own generic text instead of this, by design.
		return cfg.i18n.leaveWarning;
	} );

	// --- Create backup ------------------------------------------------

	function loadPreflight() {
		var el = document.getElementById( 'wpvault-preflight' );

		el.textContent = cfg.i18n.checkingBackup;
		el.hidden = false;

		api( '/preflight', 'GET' ).then( function ( res ) {
			renderPreflight( res.data );
		} );
	}

	function renderPreflight( result ) {
		var el = document.getElementById( 'wpvault-preflight' );

		if ( result.ok ) {
			el.hidden = true;
			return;
		}

		var html = '<h2>Cannot start a backup yet</h2><ul class="wpvault-checklist">';

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

	function startBackup() {
		var type         = document.querySelector( 'input[name="wpvault-type"]:checked' ).value;
		var excludeCache = document.getElementById( 'wpvault-exclude-cache' ).checked;

		document.getElementById( 'wpvault-start-backup' ).disabled = true;
		backupBusy = true;

		api( '/backups', 'POST', { type: type, exclude_cache: excludeCache } ).then( function ( res ) {
			if ( ! res.ok ) {
				backupBusy = false;
				window.alert( res.data.message || cfg.i18n.backupFailed );
				document.getElementById( 'wpvault-start-backup' ).disabled = false;
				return;
			}

			currentJobId    = res.data.job_id;
			backupCancelled = false;
			document.getElementById( 'wpvault-backup-form-card' ).hidden = true;
			document.getElementById( 'wpvault-progress-card' ).hidden = false;
			pollBackup();
		} );
	}

	function pollBackup() {
		if ( backupCancelled || ! currentJobId ) {
			return;
		}

		api( '/jobs/' + currentJobId + '/step', 'POST' ).then( function ( res ) {
			if ( backupCancelled ) {
				return;
			}

			var job = res.data;

			document.getElementById( 'wpvault-progress-bar' ).style.width = job.percent + '%';
			document.getElementById( 'wpvault-progress-percent' ).textContent = job.percent + '%';
			document.getElementById( 'wpvault-progress-current' ).textContent = job.current_item || job.status;

			if ( 'completed' === job.status ) {
				backupBusy = false;
				// The new backup belongs in the history table right below --
				// reloading is simpler than splicing a row in by hand.
				window.location.reload();
				return;
			}

			if ( 'failed' === job.status || 'cancelled' === job.status ) {
				backupBusy = false;
				document.getElementById( 'wpvault-progress-card' ).hidden = true;
				document.getElementById( 'wpvault-backup-form-card' ).hidden = false;
				document.getElementById( 'wpvault-start-backup' ).disabled = false;
				window.alert( job.error_message || cfg.i18n.backupFailed );
				return;
			}

			setTimeout( pollBackup, 800 );
		} );
	}

	function cancelBackup() {
		backupCancelled = true;
		backupBusy      = false;

		if ( currentJobId ) {
			api( '/jobs/' + currentJobId + '/cancel', 'POST' );
		}

		document.getElementById( 'wpvault-progress-card' ).hidden = true;
		document.getElementById( 'wpvault-backup-form-card' ).hidden = false;
		document.getElementById( 'wpvault-start-backup' ).disabled = false;
	}

	// --- Import backup ---------------------------------------------------
	//
	// Uploaded in chunks rather than one multipart POST -- a single-request
	// upload would be bounded by this server's own upload_max_filesize /
	// post_max_size, same limitation every host imposes somewhere. init
	// reserves an upload slot sized to the whole file (checked against free
	// disk space server-side); chunk is called repeatedly, each time
	// sending the next slice as a raw body; complete runs validation and
	// finalizing once every byte has arrived.

	function importFile() {
		var input = document.getElementById( 'wpvault-import-file' );

		if ( ! input.files || ! input.files[0] ) {
			document.getElementById( 'wpvault-import-result' ).textContent = cfg.i18n.chooseFile;
			return;
		}

		var file = input.files[0];

		document.getElementById( 'wpvault-import-button' ).disabled = true;
		importBusy = true;
		document.getElementById( 'wpvault-import-form-card' ).hidden = true;
		document.getElementById( 'wpvault-import-progress-card' ).hidden = false;
		updateImportProgress( 0, file.size );

		api( '/backups/import/init', 'POST', { total_size: file.size } ).then( function ( res ) {
			if ( ! res.ok ) {
				finishImportFailure( res.data.message || cfg.i18n.importFailed );
				return;
			}

			uploadImportChunks( res.data.upload_id, file, res.data.chunk_size, 0, 0 );
		} ).catch( function () {
			finishImportFailure( cfg.i18n.importFailed );
		} );
	}

	function uploadImportChunks( uploadId, file, chunkSize, offset, retries ) {
		if ( offset >= file.size ) {
			completeImport( uploadId );
			return;
		}

		var chunk = file.slice( offset, offset + chunkSize );

		fetch( cfg.restUrl + '/backups/import/chunk?upload_id=' + encodeURIComponent( uploadId ) + '&offset=' + offset, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/octet-stream' },
			body: chunk,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} ).then( function ( res ) {
			if ( res.ok ) {
				updateImportProgress( res.data.received_bytes, file.size );
				uploadImportChunks( uploadId, file, chunkSize, res.data.received_bytes, 0 );
				return;
			}

			// The server reports how many bytes it actually has whenever
			// this happens -- most often because our previous response was
			// lost even though the chunk was applied. Resume from the real
			// position instead of failing the whole upload over that.
			if ( res.data && 'number' === typeof res.data.received_bytes && retries < 5 ) {
				updateImportProgress( res.data.received_bytes, file.size );
				uploadImportChunks( uploadId, file, chunkSize, res.data.received_bytes, retries + 1 );
				return;
			}

			finishImportFailure( res.data.message || cfg.i18n.importFailed );
		} ).catch( function () {
			if ( retries < 3 ) {
				setTimeout( function () {
					uploadImportChunks( uploadId, file, chunkSize, offset, retries + 1 );
				}, 1000 );
				return;
			}

			finishImportFailure( cfg.i18n.importFailed );
		} );
	}

	function completeImport( uploadId ) {
		api( '/backups/import/complete', 'POST', { upload_id: uploadId } ).then( function ( res ) {
			if ( ! res.ok ) {
				finishImportFailure( res.data.message || cfg.i18n.importFailed );
				return;
			}

			importBusy = false;
			// The new backup belongs in the history table right below --
			// reloading is simpler than splicing a row in by hand.
			window.location.reload();
		} ).catch( function () {
			finishImportFailure( cfg.i18n.importFailed );
		} );
	}

	function updateImportProgress( receivedBytes, totalBytes ) {
		var percent = totalBytes > 0 ? Math.min( 100, Math.round( ( receivedBytes / totalBytes ) * 100 ) ) : 0;

		document.getElementById( 'wpvault-import-progress-bar' ).style.width = percent + '%';
		document.getElementById( 'wpvault-import-progress-percent' ).textContent = percent + '%';
	}

	function finishImportFailure( message ) {
		importBusy = false;
		document.getElementById( 'wpvault-import-progress-card' ).hidden = true;
		document.getElementById( 'wpvault-import-form-card' ).hidden = false;
		document.getElementById( 'wpvault-import-button' ).disabled = false;
		document.getElementById( 'wpvault-import-result' ).textContent = message;
	}

	// --- Restore -----------------------------------------------------------

	function openRestoreModal( backupId, date, size ) {
		if ( anyOtherBusy( 'restore' ) ) {
			window.alert( cfg.i18n.busyOpenOther );
			return;
		}

		restoreBackupId = backupId;

		document.getElementById( 'wpvault-restore-summary' ).textContent = date + ' · ' + size;
		document.getElementById( 'wpvault-restore-confirm-card' ).hidden = false;
		document.getElementById( 'wpvault-restore-progress-card' ).hidden = true;
		document.getElementById( 'wpvault-restore-result-card' ).hidden = true;
		document.getElementById( 'wpvault-create-snapshot' ).checked = true;
		document.getElementById( 'wpvault-old-url' ).value = '';
		document.getElementById( 'wpvault-new-url' ).value = '';
		document.getElementById( 'wpvault-start-restore' ).disabled = false;

		var preflightEl = document.getElementById( 'wpvault-restore-preflight' );
		preflightEl.textContent = cfg.i18n.checkingRestore;
		preflightEl.hidden = false;

		document.getElementById( 'wpvault-restore-modal' ).hidden = false;

		api( '/backups/' + backupId + '/restore-preflight', 'GET' ).then( function ( res ) {
			if ( restoreBackupId !== backupId ) {
				return; // The modal was closed/reopened for a different backup while this was in flight.
			}

			if ( ! res.ok ) {
				preflightEl.textContent = res.data.message || cfg.i18n.restoreNotFound;
				document.getElementById( 'wpvault-start-restore' ).disabled = true;
				return;
			}

			renderRestorePreflight( res.data );
		} );
	}

	function renderRestorePreflight( result ) {
		var el = document.getElementById( 'wpvault-restore-preflight' );

		document.getElementById( 'wpvault-old-url' ).value = result.old_url || '';
		document.getElementById( 'wpvault-new-url' ).value = result.new_url || '';

		if ( result.ok ) {
			el.hidden = true;
			return;
		}

		var html = '<h2>Cannot start this restore yet</h2><ul class="wpvault-checklist">';

		result.checks.forEach( function ( check ) {
			if ( ! check.ok ) {
				html += '<li class="wpvault-check-fail"><strong>' + escapeHtml( check.label ) + ':</strong> ' + escapeHtml( check.message ) + '</li>';
			}
		} );

		html += '</ul>';
		el.innerHTML = html;
		el.hidden = false;

		document.getElementById( 'wpvault-start-restore' ).disabled = true;
	}

	function startRestore() {
		var createSnapshot = document.getElementById( 'wpvault-create-snapshot' ).checked;
		var oldUrl          = document.getElementById( 'wpvault-old-url' ).value;
		var newUrl          = document.getElementById( 'wpvault-new-url' ).value;

		document.getElementById( 'wpvault-start-restore' ).disabled = true;
		restoreBusy = true;

		api( '/backups/' + restoreBackupId + '/restore', 'POST', {
			create_snapshot: createSnapshot,
			old_url: oldUrl,
			new_url: newUrl,
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				restoreBusy = false;
				window.alert( res.data.message || cfg.i18n.restoreFailed );
				document.getElementById( 'wpvault-start-restore' ).disabled = false;
				return;
			}

			restoreJobId    = res.data.job_id;
			restoreCancelled = false;
			document.getElementById( 'wpvault-restore-confirm-card' ).hidden = true;
			document.getElementById( 'wpvault-restore-progress-card' ).hidden = false;
			pollRestore();
		} );
	}

	function pollRestore() {
		if ( restoreCancelled || ! restoreJobId ) {
			return;
		}

		api( '/jobs/' + restoreJobId + '/step', 'POST' ).then( function ( res ) {
			if ( restoreCancelled ) {
				return;
			}

			var job = res.data;

			document.getElementById( 'wpvault-restore-progress-bar' ).style.width = job.percent + '%';
			document.getElementById( 'wpvault-restore-progress-percent' ).textContent = job.percent + '%';
			document.getElementById( 'wpvault-restore-progress-current' ).textContent = job.current_item || job.status;

			if ( 'completed' === job.status ) {
				restoreBusy = false;
				// The restore may have changed this site's own URL -- reload
				// straight into the (possibly new) admin URL rather than
				// trusting the old one still resolves here.
				window.location.href = ( job.restore && job.restore.site_url ? job.restore.site_url : window.location.origin ) + '/wp-admin/admin.php?page=wpvault-backups';
				return;
			}

			if ( 'failed' === job.status || 'cancelled' === job.status ) {
				restoreBusy = false;
				document.getElementById( 'wpvault-restore-progress-card' ).hidden = true;
				document.getElementById( 'wpvault-restore-result-card' ).hidden = false;
				document.getElementById( 'wpvault-restore-result-card' ).textContent = job.error_message || cfg.i18n.restoreFailed;
				return;
			}

			setTimeout( pollRestore, 800 );
		} );
	}

	// --- Row actions: verify / delete ------------------------------------

	document.addEventListener( 'click', function ( event ) {
		var verifyBtn    = event.target.closest( '.wpvault-verify' );
		var deleteBtn    = event.target.closest( '.wpvault-delete' );
		var importBtn    = event.target.closest( '#wpvault-import-button' );
		var openCreate   = event.target.closest( '#wpvault-open-create' );
		var openImport   = event.target.closest( '#wpvault-open-import' );
		var openRestore  = event.target.closest( '.wpvault-open-restore' );
		var closeModalEl = event.target.closest( '[data-close-modal]' );
		var overlay      = event.target.classList && event.target.classList.contains( 'wpvault-modal-overlay' ) ? event.target : null;

		if ( openCreate ) {
			openModal( 'create' );
		}

		if ( openImport ) {
			openModal( 'import' );
		}

		if ( openRestore ) {
			openRestoreModal( openRestore.dataset.id, openRestore.dataset.date, openRestore.dataset.size );
		}

		if ( closeModalEl ) {
			closeModal( closeModalEl.dataset.closeModal, false );
		}

		if ( overlay ) {
			// Clicking the dimmed backdrop itself (not the modal box) is the
			// same intent as the explicit close button.
			var overlayName = 'create';
			if ( overlay === document.getElementById( 'wpvault-import-modal' ) ) {
				overlayName = 'import';
			} else if ( overlay === document.getElementById( 'wpvault-restore-modal' ) ) {
				overlayName = 'restore';
			}
			closeModal( overlayName, false );
		}

		if ( importBtn ) {
			importFile();
		}

		if ( verifyBtn ) {
			var id   = verifyBtn.dataset.id;
			var cell = verifyBtn.closest( 'tr' ).querySelector( '.wpvault-status-cell' );

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

	// --- Deep-linked open (from the Dashboard's "Backup Now" / "Restore") --
	//
	// Backups_Page reads ?action=... off the request that landed here and
	// hands it back as cfg.autoOpen, so a Dashboard link opens straight into
	// the matching popup instead of just landing on this page and requiring
	// a second click. The query args are stripped from the URL afterward so
	// refreshing or coming back here later doesn't reopen it.
	function openAutoOpen() {
		var autoOpen = cfg.autoOpen;

		if ( ! autoOpen || ! autoOpen.action ) {
			return;
		}

		if ( 'create' === autoOpen.action ) {
			openModal( 'create' );
		} else if ( 'import' === autoOpen.action ) {
			openModal( 'import' );
		} else if ( 'restore' === autoOpen.action && autoOpen.backupId ) {
			openRestoreModal( autoOpen.backupId, autoOpen.date, autoOpen.size );
		}

		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + '?page=wpvault-backups' );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadPreflight();
		openAutoOpen();

		document.getElementById( 'wpvault-start-restore' ).addEventListener( 'click', startRestore );

		document.getElementById( 'wpvault-start-backup' ).addEventListener( 'click', startBackup );
		document.getElementById( 'wpvault-cancel-backup' ).addEventListener( 'click', cancelBackup );
	} );
} )();
