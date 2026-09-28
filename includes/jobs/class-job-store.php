<?php
/**
 * wpvault_jobs -- one row per backup or restore job, per §18.2.
 *
 * `payload` is this table's one addition beyond the spec's column list: a
 * JSON blob holding whatever a specific phase needs to resume exactly where
 * it left off (remaining file list + cursor, which DB table + row offset,
 * chosen backup type/exclusions...). Without it, resuming would mean
 * re-deriving that state from scratch on every chunk.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 */

namespace WPVault\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Store {

	const DB_VERSION_OPTION = 'wpvault_jobs_db_version';
	const DB_VERSION        = '1.0';

	const TYPE_BACKUP       = 'backup';
	const TYPE_RESTORE      = 'restore';
	const TYPE_DRIVE_UPLOAD = 'drive_upload';

	// Order matters: this is also the sequence Job_Runner advances through.
	const STATUS_QUEUED     = 'queued';
	const STATUS_SCANNING   = 'scanning';
	const STATUS_PREPARING  = 'preparing';
	const STATUS_PROCESSING = 'processing';
	const STATUS_FINALIZING = 'finalizing';
	const STATUS_VERIFYING  = 'verifying';
	const STATUS_COMPLETED  = 'completed';

	const STATUS_PAUSED    = 'paused';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	// A heartbeat older than this is treated as abandoned (browser closed,
	// request killed) -- both the foreground poller (~1s between polls) and
	// the cron safety net use this to tell "still being actively driven"
	// from "nobody is stepping this right now".
	//
	// Must stay BELOW Cron_Runner's schedule interval (60s), not above it.
	// The cron safety net refreshes the heartbeat every time it advances a
	// job, so if this threshold were longer than the cron interval, each
	// pickup would leave the job looking "fresh" for longer than the gap
	// until the next tick -- the tick after a pickup would find nothing
	// stale and skip it, and the job would only actually progress on every
	// *other* tick. Confirmed by hand: at 90s (above the 60s interval) a
	// backdated job advanced on the first tick and then sat untouched
	// through 20 more ticks in a row.
	const STALE_SECONDS = 45;

	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'wpvault_jobs';
	}

	public static function maybe_create_table() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			backup_id BIGINT UNSIGNED NULL DEFAULT NULL,
			type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			phase VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			processed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			processed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			current_item VARCHAR(500) NULL DEFAULT NULL,
			retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			error_message TEXT NULL DEFAULT NULL,
			payload LONGTEXT NULL DEFAULT NULL,
			last_heartbeat DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY backup_id (backup_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function create( $type, $backup_id, array $payload = array() ) {
		global $wpdb;

		// GMT, not current_time( 'mysql' ) -- last_heartbeat is compared
		// against gmdate()-based cutoffs (find_stale_active_job(),
		// find_latest_active() doesn't need it but checkpoint() does), and
		// mixing a site-local timestamp with a UTC cutoff is exactly the
		// kind of thing that only breaks on sites whose timezone isn't
		// UTC+0. Confirmed by hand on a site running several hours ahead of
		// UTC: after the cron safety net touched a job's heartbeat once
		// using current_time( 'mysql' ), the job's heartbeat sat hours in
		// the "future" relative to the UTC cutoff and never looked stale
		// again for the rest of that offset -- silently disabling the
		// resume-after-browser-closed guarantee for exactly as long as the
		// site's UTC offset. created_at/updated_at stay GMT too, for the
		// same table, for the same reason.
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			self::table_name(),
			array(
				'backup_id'      => $backup_id,
				'type'           => $type,
				'status'         => self::STATUS_QUEUED,
				'phase'          => self::STATUS_QUEUED,
				'payload'        => wp_json_encode( $payload ),
				'last_heartbeat' => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return self::get_by_id( $wpdb->insert_id );
	}

	public static function get_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id ) );
	}

	public static function get_payload( $job ) {
		if ( empty( $job->payload ) ) {
			return array();
		}

		$decoded = json_decode( $job->payload, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * "Not finished yet" defined as "not one of the three terminal
	 * statuses", rather than an allowlist of in-progress phase names.
	 * Backup and restore jobs use entirely different phase vocabularies
	 * (scanning/processing/... vs preflight/snapshot/extracting/...), and
	 * an allowlist keyed to one of them silently stops seeing jobs of the
	 * other kind -- which is exactly what happened here before this was a
	 * NOT IN clause: restore jobs weren't picked up by the cron safety net,
	 * didn't show on the Dashboard's current-job card, and didn't block
	 * deleting the backup they were reading from.
	 */
	private static function terminal_statuses() {
		return array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED );
	}

	/**
	 * Finds a job that looks abandoned: active but its heartbeat has gone
	 * stale. Used by the cron safety net so a closed browser tab doesn't
	 * leave a backup or restore stuck.
	 */
	public static function find_stale_active_job() {
		global $wpdb;

		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );
		$terminal     = self::terminal_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $terminal ), '%s' ) );

		$sql  = "SELECT * FROM %i WHERE status NOT IN ({$placeholders}) AND last_heartbeat < %s ORDER BY id ASC LIMIT 1";
		$args = array_merge( array( self::table_name() ), $terminal, array( $cutoff ) );

		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * The most recent job that hasn't reached a terminal state -- used by
	 * the Dashboard to resume showing progress for a job that's still
	 * running (whether the browser started it or the cron safety net is
	 * carrying it along).
	 */
	public static function find_latest_active() {
		global $wpdb;

		$terminal     = self::terminal_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $terminal ), '%s' ) );

		$sql  = "SELECT * FROM %i WHERE status NOT IN ({$placeholders}) ORDER BY id DESC LIMIT 1";
		$args = array_merge( array( self::table_name() ), $terminal );

		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Guards against deleting a backup out from under a job that's still
	 * building it OR restoring from it -- confirmed by hand as a real gap,
	 * not a hypothetical one: deleting an in-progress backup's row left its
	 * job to discover a missing Backup_Store row only once it reached
	 * finalizing/verifying, several chunks later.
	 */
	public static function find_active_job_for_backup( $backup_id ) {
		global $wpdb;

		$terminal     = self::terminal_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $terminal ), '%s' ) );

		$sql  = "SELECT * FROM %i WHERE backup_id = %d AND status NOT IN ({$placeholders}) LIMIT 1";
		$args = array_merge( array( self::table_name(), $backup_id ), $terminal );

		return $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
	}

	public static function update( $id, array $fields ) {
		global $wpdb;

		// GMT -- see the note in create() on last_heartbeat; updated_at gets
		// compared against nothing today, but this table's timestamps stay
		// one consistent clock rather than being GMT in some rows/columns
		// and site-local in others.
		$fields['updated_at'] = current_time( 'mysql', true );

		$formats = array();

		foreach ( $fields as $key => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->update( self::table_name(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );

		return self::get_by_id( $id );
	}

	/**
	 * Advances phase/progress and, critically, refreshes the heartbeat --
	 * every call from a running step must touch this so §11.3's "never mark
	 * a stale job as still running" check works.
	 */
	public static function checkpoint( $id, array $fields ) {
		$fields['last_heartbeat'] = current_time( 'mysql', true ); // GMT -- see create().

		return self::update( $id, $fields );
	}

	public static function is_terminal( $status ) {
		return in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ), true );
	}
}
