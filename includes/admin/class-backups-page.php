<?php
/**
 * The hub page: create a backup, import one, restore one, and see history --
 * all on one screen and all three as popups opened from their own trigger
 * (two buttons up top, one per verified row) rather than separate admin
 * pages.
 */

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Backups_Page {

	const HOOK = 'wpvault_page_wpvault-backups';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpvault-admin', WPVAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WPVAULT_VERSION );
		wp_enqueue_script( 'wpvault-backups', WPVAULT_PLUGIN_URL . 'assets/js/backups.js', array(), WPVAULT_VERSION, true );

		wp_localize_script(
			'wpvault-backups',
			'wpvaultBackups',
			array(
				'restUrl'         => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'autoOpen'        => $this->auto_open_from_request(),
				'gdriveConnected' => Google_Drive::is_connected(),
				'settingsUrl'     => admin_url( 'admin.php?page=wpvault-settings' ),
				'i18n'            => array(
					'confirmDelete'       => __( 'Delete this backup permanently? This cannot be undone.', 'wpvault' ),
					'verifying'           => __( 'Verifying…', 'wpvault' ),
					'chooseFile'          => __( 'Choose a .wpvault file first.', 'wpvault' ),
					'checkingBackup'      => __( 'Running preflight checks…', 'wpvault' ),
					'checkingRestore'     => __( 'Checking backup…', 'wpvault' ),
					'backupFailed'        => __( 'Backup failed.', 'wpvault' ),
					'importFailed'        => __( 'Import failed.', 'wpvault' ),
					'restoreFailed'       => __( 'Restore failed.', 'wpvault' ),
					'restoreNotFound'     => __( 'Backup not found.', 'wpvault' ),
					'driveFailed'         => __( 'Could not save to Google Drive.', 'wpvault' ),
					'driveSaved'          => __( 'Saved to Google Drive.', 'wpvault' ),
					'viewOnDrive'         => __( 'View on Google Drive', 'wpvault' ),
					'gdriveNotConnected'  => __( 'Google Drive is not connected. Go to WPVault → Settings to connect it.', 'wpvault' ),
					'leaveWarning'        => __( 'A backup, import, restore, or Google Drive upload is in progress. Leaving now may interrupt it.', 'wpvault' ),
					'closeBackupConfirm'  => __( 'A backup is in progress. Close this window anyway? The backup itself keeps running in the background, but you will lose this progress view.', 'wpvault' ),
					'closeImportConfirm'  => __( 'An import is uploading. Close this window anyway? The upload will be interrupted and will need to be started over.', 'wpvault' ),
					'closeRestoreConfirm' => __( 'A restore is in progress. Close this window anyway? The restore itself keeps running in the background, but you will lose this progress view.', 'wpvault' ),
					'closeDriveConfirm'   => __( 'A Google Drive upload is in progress. Close this window anyway? The upload itself keeps running in the background, but you will lose this progress view.', 'wpvault' ),
					'busyOpenOther'       => __( 'Please wait for the current backup, import, restore, or Google Drive upload to finish first.', 'wpvault' ),
				),
			)
		);
	}

	/**
	 * Reads ?action=create|import|restore(&backup=ID) off the request so
	 * this page can pop the matching modal open on load -- Dashboard's
	 * "Backup Now" and "Restore" links land here with one of these rather
	 * than requiring a second click once you arrive. A restore target has
	 * to actually exist and be verified, since the id came in over a URL.
	 */
	private function auto_open_from_request() {
		$auto_open = array(
			'action'   => '',
			'backupId' => 0,
			'date'     => '',
			'size'     => '',
		);

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( in_array( $action, array( 'create', 'import' ), true ) ) {
			$auto_open['action'] = $action;
			return $auto_open;
		}

		if ( 'restore' !== $action || empty( $_GET['backup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $auto_open;
		}

		$backup_id = absint( $_GET['backup'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$backup    = Backup_Store::get_by_id( $backup_id );

		if ( ! $backup || Backup_Store::STATUS_VERIFIED !== $backup->status ) {
			return $auto_open;
		}

		$auto_open['action']   = 'restore';
		$auto_open['backupId'] = $backup_id;
		$auto_open['date']     = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup->created_at );
		$auto_open['size']     = $backup->package_size ? size_format( $backup->package_size ) : '—';

		return $auto_open;
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$backups    = Backup_Store::get_all( 100 );
		$free_space = Local_Storage::free_space();
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Backups', 'wpvault' ); ?></h1>

			<div class="wpvault-actions">
				<button type="button" id="wpvault-open-create" class="button button-primary button-hero">
					<?php esc_html_e( 'Create Backup', 'wpvault' ); ?>
				</button>
				<button type="button" id="wpvault-open-import" class="button button-hero">
					<?php esc_html_e( 'Import Backup', 'wpvault' ); ?>
				</button>
			</div>

			<div id="wpvault-create-modal" class="wpvault-modal-overlay" hidden>
				<div class="wpvault-modal" role="dialog" aria-modal="true" aria-labelledby="wpvault-create-modal-title">
					<button type="button" class="wpvault-modal-close" data-close-modal="create" aria-label="<?php esc_attr_e( 'Close', 'wpvault' ); ?>">&times;</button>

					<div id="wpvault-preflight"></div>

					<div id="wpvault-backup-form-card">
						<h2 id="wpvault-create-modal-title"><?php esc_html_e( 'Create a Backup', 'wpvault' ); ?></h2>

						<p>
							<label>
								<input type="radio" name="wpvault-type" value="<?php echo esc_attr( Backup_Store::TYPE_FULL ); ?>" checked>
								<?php esc_html_e( 'Entire website (database + files)', 'wpvault' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="wpvault-type" value="<?php echo esc_attr( Backup_Store::TYPE_DATABASE ); ?>">
								<?php esc_html_e( 'Database only', 'wpvault' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="wpvault-type" value="<?php echo esc_attr( Backup_Store::TYPE_FILES ); ?>">
								<?php esc_html_e( 'Files only', 'wpvault' ); ?>
							</label>
						</p>

						<hr>

						<p>
							<label>
								<input type="checkbox" id="wpvault-exclude-cache" checked>
								<?php esc_html_e( 'Exclude cache / temporary files (recommended)', 'wpvault' ); ?>
							</label>
						</p>

						<?php if ( Google_Drive::is_connected() ) : ?>
							<p>
								<label>
									<input type="checkbox" id="wpvault-upload-to-drive">
									<?php esc_html_e( 'Also save this backup to Google Drive', 'wpvault' ); ?>
								</label>
							</p>
						<?php endif; ?>

						<p>
							<button type="button" id="wpvault-start-backup" class="button button-primary button-hero">
								<?php esc_html_e( 'Start Backup', 'wpvault' ); ?>
							</button>
						</p>
					</div>

					<div id="wpvault-progress-card" hidden>
						<h2><?php esc_html_e( 'Creating backup', 'wpvault' ); ?></h2>
						<div class="wpvault-progress">
							<div class="wpvault-progress-bar" id="wpvault-progress-bar" style="width:0%"></div>
						</div>
						<p>
							<span id="wpvault-progress-percent">0%</span>
							&middot;
							<span id="wpvault-progress-current"></span>
						</p>
						<p class="description"><?php esc_html_e( 'Please keep this tab open and avoid navigating away until the backup finishes.', 'wpvault' ); ?></p>
						<p>
							<button type="button" id="wpvault-cancel-backup" class="button">
								<?php esc_html_e( 'Cancel', 'wpvault' ); ?>
							</button>
						</p>
					</div>
				</div>
			</div>

			<div id="wpvault-import-modal" class="wpvault-modal-overlay" hidden>
				<div class="wpvault-modal" role="dialog" aria-modal="true" aria-labelledby="wpvault-import-modal-title">
					<button type="button" class="wpvault-modal-close" data-close-modal="import" aria-label="<?php esc_attr_e( 'Close', 'wpvault' ); ?>">&times;</button>

					<div id="wpvault-import-form-card">
						<h2 id="wpvault-import-modal-title"><?php esc_html_e( 'Import a Backup', 'wpvault' ); ?></h2>
						<p class="description">
							<?php
							printf(
								/* translators: %s: disk space currently free in WPVault's storage directory */
								esc_html__( 'Bring in a .wpvault package made on another site. Uploaded in chunks, so it isn\'t limited by this server\'s upload size setting -- only by free disk space (%s available).', 'wpvault' ),
								esc_html( null === $free_space ? __( 'unknown', 'wpvault' ) : size_format( $free_space ) )
							);
							?>
						</p>
						<p>
							<input type="file" id="wpvault-import-file" accept=".wpvault">
							<button type="button" id="wpvault-import-button" class="button button-primary">
								<?php esc_html_e( 'Import', 'wpvault' ); ?>
							</button>
						</p>
						<p class="description"><?php esc_html_e( 'Please keep this tab open and avoid navigating away until the import finishes.', 'wpvault' ); ?></p>
						<div id="wpvault-import-result"></div>
					</div>

					<div id="wpvault-import-progress-card" hidden>
						<h2><?php esc_html_e( 'Importing…', 'wpvault' ); ?></h2>
						<div class="wpvault-progress">
							<div class="wpvault-progress-bar" id="wpvault-import-progress-bar" style="width:0%"></div>
						</div>
						<p><span id="wpvault-import-progress-percent">0%</span></p>
						<p class="description"><?php esc_html_e( 'Please keep this tab open and avoid navigating away until the import finishes.', 'wpvault' ); ?></p>
						<div id="wpvault-import-progress-result"></div>
					</div>
				</div>
			</div>

			<div id="wpvault-restore-modal" class="wpvault-modal-overlay" hidden>
				<div class="wpvault-modal" role="dialog" aria-modal="true" aria-labelledby="wpvault-restore-modal-title">
					<button type="button" class="wpvault-modal-close" data-close-modal="restore" aria-label="<?php esc_attr_e( 'Close', 'wpvault' ); ?>">&times;</button>

					<div id="wpvault-restore-preflight"></div>

					<div id="wpvault-restore-confirm-card">
						<h2 id="wpvault-restore-modal-title"><?php esc_html_e( 'Restore website?', 'wpvault' ); ?></h2>

						<p id="wpvault-restore-summary"></p>

						<p class="description">
							<?php esc_html_e( 'This will replace the current site\'s database and files with the contents of this backup. wp-config.php and .htaccess are never overwritten.', 'wpvault' ); ?>
						</p>

						<p>
							<label>
								<input type="checkbox" id="wpvault-create-snapshot" checked>
								<?php esc_html_e( 'Create a safety snapshot first (recommended)', 'wpvault' ); ?>
							</label>
						</p>

						<details>
							<summary><?php esc_html_e( 'Advanced: update site URLs', 'wpvault' ); ?></summary>
							<p>
								<label for="wpvault-old-url"><?php esc_html_e( 'Old URL', 'wpvault' ); ?></label><br>
								<input type="text" id="wpvault-old-url" class="regular-text">
							</p>
							<p>
								<label for="wpvault-new-url"><?php esc_html_e( 'New URL', 'wpvault' ); ?></label><br>
								<input type="text" id="wpvault-new-url" class="regular-text">
							</p>
							<p class="description"><?php esc_html_e( 'Leave these matching if you are restoring this backup back onto the same site.', 'wpvault' ); ?></p>
						</details>

						<p>
							<button type="button" class="button" data-close-modal="restore">
								<?php esc_html_e( 'Cancel', 'wpvault' ); ?>
							</button>
							<button type="button" id="wpvault-start-restore" class="button button-primary">
								<?php esc_html_e( 'Create Safety Snapshot & Restore', 'wpvault' ); ?>
							</button>
						</p>
					</div>

					<div id="wpvault-restore-progress-card" hidden>
						<h2><?php esc_html_e( 'Restoring…', 'wpvault' ); ?></h2>
						<div class="wpvault-progress">
							<div class="wpvault-progress-bar" id="wpvault-restore-progress-bar" style="width:0%"></div>
						</div>
						<p>
							<span id="wpvault-restore-progress-percent">0%</span>
							&middot;
							<span id="wpvault-restore-progress-current"></span>
						</p>
						<p class="description"><?php esc_html_e( 'You can leave this page -- the restore keeps running and will pick back up on its own if it gets interrupted.', 'wpvault' ); ?></p>
					</div>

					<div id="wpvault-restore-result-card" hidden></div>
				</div>
			</div>

			<div id="wpvault-drive-modal" class="wpvault-modal-overlay" hidden>
				<div class="wpvault-modal" role="dialog" aria-modal="true" aria-labelledby="wpvault-drive-modal-title">
					<button type="button" class="wpvault-modal-close" data-close-modal="drive" aria-label="<?php esc_attr_e( 'Close', 'wpvault' ); ?>">&times;</button>

					<div id="wpvault-drive-progress-card">
						<h2 id="wpvault-drive-modal-title"><?php esc_html_e( 'Saving to Google Drive…', 'wpvault' ); ?></h2>
						<div class="wpvault-progress">
							<div class="wpvault-progress-bar" id="wpvault-drive-progress-bar" style="width:0%"></div>
						</div>
						<p><span id="wpvault-drive-progress-percent">0%</span></p>
						<p class="description"><?php esc_html_e( 'Please keep this tab open and avoid navigating away until the upload finishes.', 'wpvault' ); ?></p>
					</div>

					<div id="wpvault-drive-result-card" hidden></div>
				</div>
			</div>

			<?php if ( empty( $backups ) ) : ?>
				<div class="wpvault-card">
					<p><?php esc_html_e( 'No backups yet. Click "Create Backup" above to protect this WordPress site.', 'wpvault' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'wpvault' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpvault' ); ?></th>
							<th><?php esc_html_e( 'Size', 'wpvault' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wpvault' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wpvault' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<?php
							$source_host  = $backup->source_url ? wp_parse_url( $backup->source_url, PHP_URL_HOST ) : null;
							$current_host = wp_parse_url( home_url(), PHP_URL_HOST );
							$is_imported  = $source_host && $source_host !== $current_host;
							?>
							<tr data-backup-id="<?php echo esc_attr( $backup->id ); ?>">
								<td>
									<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup->created_at ) ); ?>
									<?php if ( $is_imported ) : ?>
										<br><span class="description">
											<?php
											printf(
												/* translators: %s: the source site's hostname */
												esc_html__( 'from %s', 'wpvault' ),
												esc_html( $source_host )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ucfirst( $backup->type ) ); ?></td>
								<td><?php echo esc_html( $backup->package_size ? size_format( $backup->package_size ) : '—' ); ?></td>
								<td class="wpvault-status-cell">
									<?php if ( Backup_Store::STATUS_VERIFIED === $backup->status ) : ?>
										<span class="wpvault-badge wpvault-badge-ok"><?php esc_html_e( 'Verified', 'wpvault' ); ?> ✓</span>
									<?php elseif ( Backup_Store::STATUS_FAILED === $backup->status ) : ?>
										<span class="wpvault-badge wpvault-badge-error"><?php esc_html_e( 'Failed', 'wpvault' ); ?></span>
									<?php else : ?>
										<span class="wpvault-badge"><?php echo esc_html( ucfirst( $backup->status ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $backup->file_path ) : ?>
										<select
											class="wpvault-download-target"
											data-id="<?php echo esc_attr( $backup->id ); ?>"
											data-local-url="<?php echo esc_url( Download_Handler::url( $backup->id ) ); ?>"
											data-drive-link="<?php echo esc_url( $backup->drive_link ? $backup->drive_link : '' ); ?>"
										>
											<option value="local"><?php esc_html_e( 'Download to Local', 'wpvault' ); ?></option>
											<option value="drive">
												<?php echo $backup->drive_link ? esc_html__( 'View on Google Drive', 'wpvault' ) : esc_html__( 'Save to Google Drive', 'wpvault' ); ?>
											</option>
										</select>
										<button type="button" class="button button-small wpvault-download-go" data-id="<?php echo esc_attr( $backup->id ); ?>">
											<?php esc_html_e( 'Go', 'wpvault' ); ?>
										</button>
										<button type="button" class="button button-small wpvault-verify" data-id="<?php echo esc_attr( $backup->id ); ?>">
											<?php esc_html_e( 'Verify', 'wpvault' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( Backup_Store::STATUS_VERIFIED === $backup->status ) : ?>
										<button
											type="button"
											class="button button-small wpvault-open-restore"
											data-id="<?php echo esc_attr( $backup->id ); ?>"
											data-date="<?php echo esc_attr( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup->created_at ) ); ?>"
											data-size="<?php echo esc_attr( $backup->package_size ? size_format( $backup->package_size ) : '—' ); ?>"
										>
											<?php esc_html_e( 'Restore', 'wpvault' ); ?>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small wpvault-delete" data-id="<?php echo esc_attr( $backup->id ); ?>">
										<?php esc_html_e( 'Delete', 'wpvault' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
