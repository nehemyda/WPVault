<?php

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;
use WPVault\Jobs\Job_Store;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Page {

	const HOOK = 'toplevel_page_wpvault';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpvault-admin', WPVAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WPVAULT_VERSION );
		wp_enqueue_script( 'wpvault-dashboard', WPVAULT_PLUGIN_URL . 'assets/js/dashboard.js', array(), WPVAULT_VERSION, true );

		$active_job = Job_Store::find_latest_active();

		wp_localize_script(
			'wpvault-dashboard',
			'wpvaultDashboard',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'createUrl'    => admin_url( 'admin.php?page=wpvault-create-backup' ),
				'backupsUrl'   => admin_url( 'admin.php?page=wpvault-backups' ),
				'activeJobId'  => $active_job ? (int) $active_job->id : 0,
			)
		);
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$latest  = Backup_Store::get_all( 1 );
		$latest  = $latest ? $latest[0] : null;
		$used    = Local_Storage::used_space();
		$free    = Local_Storage::free_space();
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'WPVault', 'wpvault' ); ?></h1>

			<div class="wpvault-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-create-backup' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Backup Now', 'wpvault' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-restore' ) ); ?>" class="button">
					<?php esc_html_e( 'Restore', 'wpvault' ); ?>
				</a>
			</div>

			<div id="wpvault-job-card" class="wpvault-card" hidden>
				<h2><?php esc_html_e( 'Current Job', 'wpvault' ); ?></h2>
				<div class="wpvault-progress">
					<div class="wpvault-progress-bar" id="wpvault-job-bar" style="width:0%"></div>
				</div>
				<p><span id="wpvault-job-status"></span></p>
			</div>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'Latest Backup', 'wpvault' ); ?></h2>
				<?php if ( $latest ) : ?>
					<p>
						<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $latest->created_at ) ); ?>
						&middot;
						<?php echo esc_html( $latest->package_size ? size_format( $latest->package_size ) : '—' ); ?>
						&middot;
						<?php if ( Backup_Store::STATUS_VERIFIED === $latest->status ) : ?>
							<span class="wpvault-badge wpvault-badge-ok"><?php esc_html_e( 'Verified', 'wpvault' ); ?> ✓</span>
						<?php elseif ( Backup_Store::STATUS_FAILED === $latest->status ) : ?>
							<span class="wpvault-badge wpvault-badge-error"><?php esc_html_e( 'Failed', 'wpvault' ); ?></span>
						<?php else : ?>
							<span class="wpvault-badge"><?php echo esc_html( ucfirst( $latest->status ) ); ?></span>
						<?php endif; ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-backups' ) ); ?>" class="button">
						<?php esc_html_e( 'View Backups', 'wpvault' ); ?>
					</a>
				<?php else : ?>
					<p><?php esc_html_e( 'No backups yet. Create your first local backup to protect this WordPress site.', 'wpvault' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'Local Storage', 'wpvault' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: bytes used, 2: bytes available */
						esc_html__( '%1$s used %2$s available', 'wpvault' ),
						esc_html( size_format( $used ) ),
						esc_html( null === $free ? __( '(unknown)', 'wpvault' ) : size_format( $free ) )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
