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
	var restoreLastStatus = ''; // last job.status seen while polling, so Cancel knows whether the live site was ever actually touched

	var cloudBusy = { drive: false, onedrive: false }; // true from opening a cloud-upload modal until completed/failed

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
		var flags = { create: backupBusy, import: importBusy, restore: restoreBusy, drive: cloudBusy.drive, onedrive: cloudBusy.onedrive };
		delete flags[ name ];
		return flags.create || flags.import || flags.restore || flags.drive || flags.onedrive;
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

			if ( 'restore' === name && restoreBusy ) {
				window.alert( cfg.i18n.restoreCloseBlocked );
				return;
			}

			if ( 'drive' === name && cloudBusy.drive && ! window.confirm( cfg.i18n.closeDriveConfirm ) ) {
				return;
			}

			if ( 'onedrive' === name && cloudBusy.onedrive && ! window.confirm( cfg.i18n.closeOnedriveConfirm ) ) {
				return;
			}
		}

		document.getElementById( 'wpvault-' + name + '-modal' ).hidden = true;
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! backupBusy && ! importBusy && ! restoreBusy && ! cloudBusy.drive && ! cloudBusy.onedrive ) {
			return;
		}

		event.preventDefault();
		event.returnValue = cfg.i18n.leaveWarning; // Most browsers show their own generic text instead of this, by design.
		return cfg.i18n.leaveWarning;
	} );

	// A restore actually overwrites live files and database tables while it
	// runs, unlike backup/import/cloud-upload which only ever touch a temp
	// package -- so once one is in progress, every click anywhere outside
	// its own modal (the wp-admin menu, the admin bar, any other button on
	// this page) is blocked here in the capture phase, before it can reach
	// its normal handler. The only way out is the modal's own "Cancel
	// Restore" button.
	document.addEventListener( 'click', function ( event ) {
		if ( ! restoreBusy ) {
			return;
		}

		if ( event.target.closest( '#wpvault-restore-modal' ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		window.alert( cfg.i18n.restoreBlockedNav );
	}, true );

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
		var type              = document.querySelector( 'input[name="wpvault-type"]:checked' ).value;
		var excludeCache      = document.getElementById( 'wpvault-exclude-cache' ).checked;
		var uploadCheckbox    = document.getElementById( 'wpvault-upload-to-drive' );
		var uploadToDrive     = uploadCheckbox ? uploadCheckbox.checked : false;
		var onedriveCheckbox  = document.getElementById( 'wpvault-upload-to-onedrive' );
		var uploadToOnedrive  = onedriveCheckbox ? onedriveCheckbox.checked : false;

		document.getElementById( 'wpvault-start-backup' ).disabled = true;
		backupBusy = true;

		api( '/backups', 'POST', { type: type, exclude_cache: excludeCache, upload_to_drive: uploadToDrive, upload_to_onedrive: uploadToOnedrive } ).then( function ( res ) {
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

	// --- Import from cloud storage (Google Drive / OneDrive) ---------------
	//
	// A picker inside this same Import modal, listing .wpvault packages this
	// site's own connected account already has (via list_backup_files() on
	// whichever provider). Selecting one starts a server-to-server download
	// job (Drive_Import_Job / Onedrive_Import_Job) -- nothing for the
	// browser to upload -- polled the same generic way every other job in
	// this plugin is, then reloading on completion exactly like a
	// local-file import does once its own backup row exists.

	var cloudImportJobId = null;

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}

		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i     = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );

		return ( bytes / Math.pow( 1024, i ) ).toFixed( 0 === i ? 0 : 1 ) + ' ' + units[ i ];
	}

	function openCloudImportPicker( provider ) {
		if ( anyOtherBusy( 'import' ) ) {
			window.alert( cfg.i18n.busyOpenOther );
			return;
		}

		var title = 'drive' === provider ? cfg.i18n.cloudImportTitleDrive : cfg.i18n.cloudImportTitleOnedrive;

		document.getElementById( 'wpvault-cloud-import-picker-title' ).textContent = title;
		document.getElementById( 'wpvault-cloud-import-list' ).textContent = cfg.i18n.checkingBackup;
		document.getElementById( 'wpvault-cloud-import-error' ).hidden = true;
		document.getElementById( 'wpvault-import-form-card' ).hidden = true;
		document.getElementById( 'wpvault-cloud-import-picker' ).hidden = false;

		api( '/' + provider + '/import-list' ).then( function ( res ) {
			if ( ! res.ok ) {
				showCloudImportError( ( res.data && res.data.message ) || cfg.i18n.cloudImportListFailed );
				return;
			}

			renderCloudImportList( provider, res.data.files || [] );
		} ).catch( function () {
			showCloudImportError( cfg.i18n.cloudImportListFailed );
		} );
	}

	function showCloudImportError( message ) {
		document.getElementById( 'wpvault-cloud-import-list' ).textContent = '';
		document.getElementById( 'wpvault-cloud-import-error' ).hidden = false;
		document.getElementById( 'wpvault-cloud-import-error' ).textContent = message;
	}

	function renderCloudImportList( provider, files ) {
		var el = document.getElementById( 'wpvault-cloud-import-list' );

		if ( ! files.length ) {
			el.textContent = cfg.i18n.cloudImportEmpty;
			return;
		}

		var html = '<table class="wpvault-cloud-import-table">';

		files.forEach( function ( file ) {
			html += '<tr>' +
				'<td>' + escapeHtml( file.name ) + '</td>' +
				'<td>' + escapeHtml( formatBytes( file.size ) ) + '</td>' +
				'<td><button type="button" class="button wpvault-cloud-import-pick" data-provider="' + provider + '" data-file-id="' + escapeHtml( file.id ) + '">' + escapeHtml( cfg.i18n.cloudImportButton ) + '</button></td>' +
				'</tr>';
		} );

		html += '</table>';
		el.innerHTML = html;
	}

	function startCloudImport( provider, fileId ) {
		document.getElementById( 'wpvault-cloud-import-picker' ).hidden = true;
		document.getElementById( 'wpvault-import-progress-card' ).hidden = false;
		document.getElementById( 'wpvault-import-progress-bar' ).style.width = '0%';
		document.getElementById( 'wpvault-import-progress-percent' ).textContent = '0%';

		importBusy = true;

		api( '/backups/import-from-' + provider, 'POST', { file_id: fileId } ).then( function ( res ) {
			if ( ! res.ok ) {
				finishImportFailure( res.data.message || cfg.i18n.importFailed );
				return;
			}

			cloudImportJobId = res.data.job_id;
			pollCloudImport();
		} ).catch( function () {
			finishImportFailure( cfg.i18n.importFailed );
		} );
	}

	function pollCloudImport() {
		api( '/jobs/' + cloudImportJobId + '/step', 'POST' ).then( function ( res ) {
			if ( ! res.ok ) {
				finishImportFailure( ( res.data && res.data.message ) || cfg.i18n.importFailed );
				return;
			}

			var job = res.data;

			document.getElementById( 'wpvault-import-progress-bar' ).style.width = job.percent + '%';
			document.getElementById( 'wpvault-import-progress-percent' ).textContent = job.percent + '%';

			if ( 'completed' === job.status ) {
				importBusy = false;
				window.location.reload();
				return;
			}

			if ( 'failed' === job.status || 'cancelled' === job.status ) {
				finishImportFailure( job.error_message || cfg.i18n.importFailed );
				return;
			}

			setTimeout( pollCloudImport, 800 );
		} ).catch( function () {
			finishImportFailure( cfg.i18n.importFailed );
		} );
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

			restoreJobId     = res.data.job_id;
			restoreCancelled = false;
			restoreLastStatus = 'queued';
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

			restoreLastStatus = job.status;

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

	function cancelRestore() {
		if ( ! window.confirm( cfg.i18n.restoreCancelConfirm ) ) {
			return;
		}

		// Live files/database are only ever touched once the restore moves
		// past its own safety-snapshot phase (advance_to_extracting() in
		// Restore_Job) -- cancelling before that, the site was never
		// touched, and the still-running snapshot backup gets cancelled
		// right along with it (Job_Runner::cancel()), so there is no
		// snapshot to point anyone at.
		var siteWasTouched = 'queued' !== restoreLastStatus && 'snapshot' !== restoreLastStatus;

		restoreCancelled = true;
		restoreBusy      = false;

		if ( restoreJobId ) {
			api( '/jobs/' + restoreJobId + '/cancel', 'POST' );
		}

		document.getElementById( 'wpvault-restore-progress-card' ).hidden = true;
		document.getElementById( 'wpvault-restore-result-card' ).hidden = false;
		document.getElementById( 'wpvault-restore-result-card' ).textContent = siteWasTouched
			? cfg.i18n.restoreCancelledResult
			: cfg.i18n.restoreCancelledSafe;
	}

	// --- Save to cloud storage (Google Drive / OneDrive) ---------------------
	//
	// The backup file already exists locally by the time either of these can
	// be clicked (only verified rows offer it) -- this is a copy, not how the
	// backup was made, so both are driven by the same generic /jobs/{id}/step
	// polling loop as everything else, just uploading server-side instead of
	// doing any local work. The two providers differ only in REST path,
	// dataset key, and i18n strings -- this factory is UI wiring shared for
	// convenience, not a shared abstraction over their separate server-side
	// OAuth/upload logic, which stays deliberately unmirrored.

	function makeCloudUpload( provider, restPath, linkDatasetKey, i18n ) {
		function updateProgress( percent ) {
			document.getElementById( 'wpvault-' + provider + '-progress-bar' ).style.width = percent + '%';
			document.getElementById( 'wpvault-' + provider + '-progress-percent' ).textContent = percent + '%';
		}

		function finishFailure( message ) {
			cloudBusy[ provider ] = false;
			document.getElementById( 'wpvault-' + provider + '-progress-card' ).hidden = true;
			document.getElementById( 'wpvault-' + provider + '-result-card' ).hidden = false;
			document.getElementById( 'wpvault-' + provider + '-result-card' ).textContent = message;
		}

		function finishSuccess( backupId, link ) {
			document.getElementById( 'wpvault-' + provider + '-progress-card' ).hidden = true;

			var resultCard = document.getElementById( 'wpvault-' + provider + '-result-card' );
			resultCard.hidden = false;
			resultCard.innerHTML = '';

			var p = document.createElement( 'p' );
			p.appendChild( document.createTextNode( i18n.saved + ' ' ) );

			if ( link ) {
				var a = document.createElement( 'a' );
				a.href = link;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.textContent = i18n.view;
				p.appendChild( a );
			}

			resultCard.appendChild( p );

			// So the row's own dropdown immediately offers "View on..."
			// instead of "Save to..." again, without a reload.
			var select = document.querySelector( '.wpvault-download-target[data-id="' + backupId + '"]' );

			if ( select && link ) {
				select.dataset[ linkDatasetKey ] = link;
				var option = select.querySelector( 'option[value="' + provider + '"]' );
				if ( option ) {
					option.textContent = i18n.view;
				}
			}
		}

		function poll( jobId, backupId ) {
			api( '/jobs/' + jobId + '/step', 'POST' ).then( function ( res ) {
				if ( ! res.ok ) {
					finishFailure( res.data.message || i18n.failed );
					return;
				}

				var job = res.data;

				updateProgress( job.percent );

				if ( 'completed' === job.status ) {
					cloudBusy[ provider ] = false;
					finishSuccess( backupId, job[ provider ] && job[ provider ].link ? job[ provider ].link : '' );
					return;
				}

				if ( 'failed' === job.status || 'cancelled' === job.status ) {
					finishFailure( job.error_message || i18n.failed );
					return;
				}

				setTimeout( function () {
					poll( jobId, backupId );
				}, 800 );
			} ).catch( function () {
				finishFailure( i18n.failed );
			} );
		}

		return function ( backupId ) {
			if ( anyOtherBusy( provider ) ) {
				window.alert( cfg.i18n.busyOpenOther );
				return;
			}

			cloudBusy[ provider ] = true;
			document.getElementById( 'wpvault-' + provider + '-progress-card' ).hidden = false;
			document.getElementById( 'wpvault-' + provider + '-result-card' ).hidden = true;
			updateProgress( 0 );
			document.getElementById( 'wpvault-' + provider + '-modal' ).hidden = false;

			api( '/backups/' + backupId + '/' + restPath, 'POST' ).then( function ( res ) {
				if ( ! res.ok ) {
					finishFailure( res.data.message || i18n.failed );
					return;
				}

				poll( res.data.job_id, backupId );
			} ).catch( function () {
				finishFailure( i18n.failed );
			} );
		};
	}

	var openDriveModal = makeCloudUpload( 'drive', 'drive-upload', 'driveLink', {
		saved:  cfg.i18n.driveSaved,
		view:   cfg.i18n.viewOnDrive,
		failed: cfg.i18n.driveFailed,
	} );

	var openOnedriveModal = makeCloudUpload( 'onedrive', 'onedrive-upload', 'onedriveLink', {
		saved:  cfg.i18n.onedriveSaved,
		view:   cfg.i18n.viewOnOnedrive,
		failed: cfg.i18n.onedriveFailed,
	} );

	// --- Row actions: verify / delete ------------------------------------

	document.addEventListener( 'click', function ( event ) {
		var verifyBtn      = event.target.closest( '.wpvault-verify' );
		var deleteBtn      = event.target.closest( '.wpvault-delete' );
		var importBtn      = event.target.closest( '#wpvault-import-button' );
		var openCreate     = event.target.closest( '#wpvault-open-create' );
		var openImport     = event.target.closest( '#wpvault-open-import' );
		var openRestore    = event.target.closest( '.wpvault-open-restore' );
		var downloadGo     = event.target.closest( '.wpvault-download-go' );
		var closeModalEl   = event.target.closest( '[data-close-modal]' );
		var overlay        = event.target.classList && event.target.classList.contains( 'wpvault-modal-overlay' ) ? event.target : null;
		var openDriveImport   = event.target.closest( '#wpvault-import-from-drive' );
		var openOnedriveImport = event.target.closest( '#wpvault-import-from-onedrive' );
		var cloudImportPick   = event.target.closest( '.wpvault-cloud-import-pick' );
		var cloudImportBack   = event.target.closest( '#wpvault-cloud-import-back' );

		if ( openCreate ) {
			openModal( 'create' );
		}

		if ( openImport ) {
			openModal( 'import' );

			if ( ! importBusy ) {
				document.getElementById( 'wpvault-cloud-import-picker' ).hidden = true;
				document.getElementById( 'wpvault-import-progress-card' ).hidden = true;
				document.getElementById( 'wpvault-import-form-card' ).hidden = false;
				document.getElementById( 'wpvault-import-result' ).textContent = '';
			}
		}

		if ( openRestore ) {
			openRestoreModal( openRestore.dataset.id, openRestore.dataset.date, openRestore.dataset.size );
		}

		if ( downloadGo ) {
			var row2   = downloadGo.closest( 'tr' );
			var select = row2.querySelector( '.wpvault-download-target' );

			if ( 'local' === select.value ) {
				window.location.href = select.dataset.localUrl;
			} else if ( 'drive' === select.value ) {
				if ( select.dataset.driveLink ) {
					window.open( select.dataset.driveLink, '_blank', 'noopener,noreferrer' );
				} else if ( ! cfg.gdriveConnected ) {
					window.alert( cfg.i18n.gdriveNotConnected );
				} else {
					openDriveModal( downloadGo.dataset.id );
				}
			} else if ( 'onedrive' === select.value ) {
				if ( select.dataset.onedriveLink ) {
					window.open( select.dataset.onedriveLink, '_blank', 'noopener,noreferrer' );
				} else if ( ! cfg.onedriveConnected ) {
					window.alert( cfg.i18n.onedriveNotConnected );
				} else {
					openOnedriveModal( downloadGo.dataset.id );
				}
			}
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
			} else if ( overlay === document.getElementById( 'wpvault-drive-modal' ) ) {
				overlayName = 'drive';
			} else if ( overlay === document.getElementById( 'wpvault-onedrive-modal' ) ) {
				overlayName = 'onedrive';
			}
			closeModal( overlayName, false );
		}

		if ( importBtn ) {
			importFile();
		}

		if ( openDriveImport ) {
			openCloudImportPicker( 'drive' );
		}

		if ( openOnedriveImport ) {
			openCloudImportPicker( 'onedrive' );
		}

		if ( cloudImportPick ) {
			startCloudImport( cloudImportPick.dataset.provider, cloudImportPick.dataset.fileId );
		}

		if ( cloudImportBack ) {
			document.getElementById( 'wpvault-cloud-import-picker' ).hidden = true;
			document.getElementById( 'wpvault-import-form-card' ).hidden = false;
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
		document.getElementById( 'wpvault-cancel-restore' ).addEventListener( 'click', cancelRestore );

		document.getElementById( 'wpvault-start-backup' ).addEventListener( 'click', startBackup );
		document.getElementById( 'wpvault-cancel-backup' ).addEventListener( 'click', cancelBackup );
	} );
} )();
