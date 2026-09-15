<?php

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Restore_Page {

	const HOOK = 'wpvault_page_wpvault-restore';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpvault-admin', WPVAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WPVAULT_VERSION );

		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $backup_id ) {
			return;
		}

		wp_enqueue_script( 'wpvault-restore', WPVAULT_PLUGIN_URL . 'assets/js/restore.js', array(), WPVAULT_VERSION, true );

		wp_localize_script(
			'wpvault-restore',
			'wpvaultRestore',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'backupId'  => $backup_id,
				'homeUrl'   => home_url(),
				'backupsUrl' => admin_url( 'admin.php?page=wpvault-backups' ),
				'i18n'      => array(
					'checking' => __( 'Checking backup…', 'wpvault' ),
					'failed'   => __( 'Restore failed.', 'wpvault' ),
					'complete' => __( 'Restore complete.', 'wpvault' ),
				),
			)
		);
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $backup_id ) {
			$this->render_confirm( $backup_id );
			return;
		}

		$this->render_picker();
	}

	private function render_picker() {
		$backups = array_filter(
			Backup_Store::get_all( 100 ),
			static function ( $backup ) {
				return Backup_Store::STATUS_VERIFIED === $backup->status;
			}
		);
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Restore', 'wpvault' ); ?></h1>

			<?php if ( empty( $backups ) ) : ?>
				<div class="wpvault-card">
					<p><?php esc_html_e( 'No verified backups available to restore yet.', 'wpvault' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-create-backup' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Create Backup', 'wpvault' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="wpvault-card">
					<p><?php esc_html_e( 'Choose a backup to restore.', 'wpvault' ); ?></p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'wpvault' ); ?></th>
								<th><?php esc_html_e( 'Type', 'wpvault' ); ?></th>
								<th><?php esc_html_e( 'Size', 'wpvault' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $backups as $backup ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup->created_at ) ); ?></td>
									<td><?php echo esc_html( ucfirst( $backup->type ) ); ?></td>
									<td><?php echo esc_html( $backup->package_size ? size_format( $backup->package_size ) : '—' ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-restore&backup=' . $backup->id ) ); ?>">
											<?php esc_html_e( 'Restore', 'wpvault' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_confirm( $backup_id ) {
		$backup = Backup_Store::get_by_id( $backup_id );

		if ( ! $backup ) {
			echo '<div class="wrap wpvault-wrap"><div class="wpvault-card"><p>' . esc_html__( 'Backup not found.', 'wpvault' ) . '</p></div></div>';
			return;
		}
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Restore website?', 'wpvault' ); ?></h1>

			<div id="wpvault-preflight" class="wpvault-card"></div>

			<div id="wpvault-restore-confirm-card" class="wpvault-card" hidden>
				<p>
					<strong><?php esc_html_e( 'Backup:', 'wpvault' ); ?></strong>
					<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup->created_at ) ); ?>
					&middot;
					<?php echo esc_html( $backup->package_size ? size_format( $backup->package_size ) : '—' ); ?>
				</p>
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
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-backups' ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'wpvault' ); ?>
					</a>
					<button type="button" id="wpvault-start-restore" class="button button-primary">
						<?php esc_html_e( 'Create Safety Snapshot & Restore', 'wpvault' ); ?>
					</button>
				</p>
			</div>

			<div id="wpvault-restore-progress-card" class="wpvault-card" hidden>
				<h2 id="wpvault-restore-progress-title"><?php esc_html_e( 'Restoring…', 'wpvault' ); ?></h2>
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

			<div id="wpvault-restore-result-card" class="wpvault-card" hidden></div>
		</div>
		<?php
	}
}
