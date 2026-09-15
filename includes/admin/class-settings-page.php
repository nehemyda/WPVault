<?php
/**
 * Deliberately small for this pass: every control here reflects something
 * the engine actually does. The spec's fuller Settings layout (§17)
 * includes chunk size / retry tuning that isn't wired to anything yet in
 * this build -- adding those inputs now would just be decoration.
 */

namespace WPVault\Admin;

use WPVault\Diagnostics\Preflight;
use WPVault\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {

	const HOOK = 'wpvault_page_wpvault-settings';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpvault-admin', WPVAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WPVAULT_VERSION );
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$preflight = Preflight::run();
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Settings', 'wpvault' ); ?></h1>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'General', 'wpvault' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Version', 'wpvault' ); ?></th>
						<td><?php echo esc_html( WPVAULT_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Storage directory', 'wpvault' ); ?></th>
						<td><code><?php echo esc_html( Local_Storage::backups_dir() ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Disk usage', 'wpvault' ); ?></th>
						<td>
							<?php
							$free = Local_Storage::free_space();
							printf(
								/* translators: 1: bytes used by backups, 2: bytes available on disk */
								esc_html__( '%1$s used by backups %2$s available', 'wpvault' ),
								esc_html( size_format( Local_Storage::used_space() ) ),
								esc_html( null === $free ? __( '(unknown)', 'wpvault' ) : size_format( $free ) )
							);
							?>
						</td>
					</tr>
				</table>
			</div>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'Diagnostics', 'wpvault' ); ?></h2>
				<ul class="wpvault-checklist">
					<?php foreach ( $preflight['checks'] as $check ) : ?>
						<li class="<?php echo $check['ok'] ? 'wpvault-check-ok' : 'wpvault-check-fail'; ?>">
							<strong><?php echo esc_html( $check['label'] ); ?>:</strong>
							<?php echo esc_html( $check['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}
}
