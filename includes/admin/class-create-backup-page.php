<?php

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Create_Backup_Page {

	const HOOK = 'wpvault_page_wpvault-create-backup';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpvault-admin', WPVAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WPVAULT_VERSION );
		wp_enqueue_script( 'wpvault-create-backup', WPVAULT_PLUGIN_URL . 'assets/js/create-backup.js', array(), WPVAULT_VERSION, true );

		wp_localize_script(
			'wpvault-create-backup',
			'wpvaultCreateBackup',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'backupsUrl' => admin_url( 'admin.php?page=wpvault-backups' ),
				'i18n'       => array(
					'checking'  => __( 'Running preflight checks…', 'wpvault' ),
					'starting'  => __( 'Starting backup…', 'wpvault' ),
					'failed'    => __( 'Backup failed.', 'wpvault' ),
					'complete'  => __( 'Backup complete.', 'wpvault' ),
				),
			)
		);
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Create Backup', 'wpvault' ); ?></h1>

			<div id="wpvault-preflight" class="wpvault-card"></div>

			<div id="wpvault-backup-form-card" class="wpvault-card">
				<h2><?php esc_html_e( 'What do you want to back up?', 'wpvault' ); ?></h2>

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

				<p>
					<button type="button" id="wpvault-start-backup" class="button button-primary button-hero">
						<?php esc_html_e( 'Start Backup', 'wpvault' ); ?>
					</button>
				</p>
			</div>

			<div id="wpvault-progress-card" class="wpvault-card" hidden>
				<h2 id="wpvault-progress-title"><?php esc_html_e( 'Creating backup', 'wpvault' ); ?></h2>
				<div class="wpvault-progress">
					<div class="wpvault-progress-bar" id="wpvault-progress-bar" style="width:0%"></div>
				</div>
				<p>
					<span id="wpvault-progress-percent">0%</span>
					&middot;
					<span id="wpvault-progress-current"></span>
				</p>
				<p class="description"><?php esc_html_e( 'You can leave this page -- the backup keeps running and will pick back up on its own if it gets interrupted.', 'wpvault' ); ?></p>
				<p>
					<button type="button" id="wpvault-cancel-backup" class="button">
						<?php esc_html_e( 'Cancel', 'wpvault' ); ?>
					</button>
				</p>
			</div>

			<div id="wpvault-result-card" class="wpvault-card" hidden></div>
		</div>
		<?php
	}
}
