( function () {
	'use strict';

	var cfg = window.wpvaultDashboard;

	if ( ! cfg ) {
		return;
	}

	function api( path, method ) {
		return fetch( cfg.restUrl + path, {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function poll( jobId ) {
		var card    = document.getElementById( 'wpvault-job-card' );
		var bar     = document.getElementById( 'wpvault-job-bar' );
		var status  = document.getElementById( 'wpvault-job-status' );

		card.hidden = false;

		api( '/jobs/' + jobId + '/step', 'POST' ).then( function ( job ) {
			bar.style.width = job.percent + '%';
			status.textContent = ( job.current_item || job.status ) + ' (' + job.percent + '%)';

			if ( 'completed' === job.status || 'failed' === job.status || 'cancelled' === job.status ) {
				var noun = 'restore' === job.type ? 'Restore' : 'Backup';

				status.textContent = 'completed' === job.status
					? noun + ' complete.'
					: ( job.error_message || noun + ' ' + job.status + '.' );

				setTimeout( function () {
					window.location.href = cfg.backupsUrl;
				}, 1200 );

				return;
			}

			setTimeout( function () {
				poll( jobId );
			}, 1000 );
		} );
	}

	// wp_localize_script casts every value to a string, so a "0" (no active
	// job) arrives here as the truthy string "0" rather than the falsy
	// number 0 -- parseInt it before testing, or this polls a job id that
	// was never created.
	var activeJobId = parseInt( cfg.activeJobId, 10 ) || 0;

	if ( activeJobId ) {
		poll( activeJobId );
	}
} )();
