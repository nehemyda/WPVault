<?php

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;

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
				'restUrl' => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Delete this backup permanently? This cannot be undone.', 'wpvault' ),
					'verifying'     => __( 'Verifying…', 'wpvault' ),
					'importing'     => __( 'Importing…', 'wpvault' ),
					'chooseFile'    => __( 'Choose a .wpvault file first.', 'wpvault' ),
				),
			)
		);
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$backups = Backup_Store::get_all( 100 );
		?>
		<div class="wrap wpvault-wrap">
			<h1>
				<?php esc_html_e( 'Backups', 'wpvault' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-create-backup' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Create Backup', 'wpvault' ); ?>
				</a>
			</h1>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'Import a Backup', 'wpvault' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the maximum file size this server accepts in one upload */
						esc_html__( 'Bring in a .wpvault package made on another site. Maximum upload size: %s.', 'wpvault' ),
						esc_html( size_format( wp_max_upload_size() ) )
					);
					?>
				</p>
				<p>
					<input type="file" id="wpvault-import-file" accept=".wpvault">
					<button type="button" id="wpvault-import-button" class="button">
						<?php esc_html_e( 'Import', 'wpvault' ); ?>
					</button>
				</p>
				<div id="wpvault-import-result"></div>
			</div>

			<?php if ( empty( $backups ) ) : ?>
				<div class="wpvault-card">
					<p><?php esc_html_e( 'No backups yet. Create your first local backup to protect this WordPress site.', 'wpvault' ); ?></p>
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
										<a href="<?php echo esc_url( Download_Handler::url( $backup->id ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Download', 'wpvault' ); ?>
										</a>
										<button type="button" class="button button-small wpvault-verify" data-id="<?php echo esc_attr( $backup->id ); ?>">
											<?php esc_html_e( 'Verify', 'wpvault' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( Backup_Store::STATUS_VERIFIED === $backup->status ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-restore&backup=' . $backup->id ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Restore', 'wpvault' ); ?>
										</a>
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
