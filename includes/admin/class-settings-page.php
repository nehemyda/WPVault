<?php
/**
 * Deliberately small for this pass: every control here reflects something
 * the engine actually does. The spec's fuller Settings layout (§17)
 * includes chunk size / retry tuning that isn't wired to anything yet in
 * this build -- adding those inputs now would just be decoration.
 */

namespace WPVault\Admin;

use WPVault\Backup\Backup_Store;
use WPVault\Diagnostics\Preflight;
use WPVault\Jobs\Pre_Update_Backups;
use WPVault\Jobs\Scheduled_Backups;
use WPVault\Storage\Google_Drive;
use WPVault\Storage\Local_Storage;
use WPVault\Storage\One_Drive;

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
		wp_enqueue_script( 'wpvault-settings', WPVAULT_PLUGIN_URL . 'assets/js/settings.js', array(), WPVAULT_VERSION, true );

		wp_localize_script(
			'wpvault-settings',
			'wpvaultSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wpvault/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'connecting' => __( 'Starting connection…', 'wpvault' ),
					'waiting'    => __( 'Waiting for you to approve access…', 'wpvault' ),
					'connected'  => __( 'Connected! Reloading…', 'wpvault' ),
					'expired'    => __( 'This code expired. Click Connect to get a new one.', 'wpvault' ),
					'denied'     => __( 'Access was denied. Click Connect to try again.', 'wpvault' ),
					'error'      => __( 'Something went wrong connecting.', 'wpvault' ),
				),
			)
		);
	}

	public function render() {
		if ( ! wpvault_can_manage() ) {
			return;
		}

		$preflight  = Preflight::run();
		$schedule   = Scheduled_Backups::get_settings();
		$pre_update = Pre_Update_Backups::get_settings();

		global $wp_locale;
		$day_names = array();

		for ( $iso = 1; $iso <= 7; $iso++ ) {
			$day_names[ $iso ] = $wp_locale->get_weekday( $iso % 7 );
		}

		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap wpvault-wrap">
			<h1><?php esc_html_e( 'Settings', 'wpvault' ); ?></h1>

			<?php if ( isset( $_GET['wpvault_schedule_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Schedule saved.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpvault_pre_update_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pre-update backup settings saved.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpvault_gdrive_disconnected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Drive disconnected.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpvault_onedrive_disconnected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'OneDrive disconnected.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpvault_gdrive_retention_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Drive retention saved.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['wpvault_onedrive_retention_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'OneDrive retention saved.', 'wpvault' ); ?></p></div>
			<?php endif; ?>

			<div class="wpvault-settings-layout">
				<nav class="wpvault-settings-sidebar">
					<input type="search" id="wpvault-settings-search" class="wpvault-settings-search" placeholder="<?php esc_attr_e( 'Search settings…', 'wpvault' ); ?>">
					<ul class="wpvault-settings-nav">
						<li><a href="#scheduled" class="wpvault-settings-nav-item" data-panel="scheduled"><?php esc_html_e( 'Scheduled Backups', 'wpvault' ); ?></a></li>
						<li><a href="#pre-update" class="wpvault-settings-nav-item" data-panel="pre-update"><?php esc_html_e( 'Pre-Update Backups', 'wpvault' ); ?></a></li>
						<li><a href="#cloud-storage" class="wpvault-settings-nav-item" data-panel="cloud-storage"><?php esc_html_e( 'Cloud Storage', 'wpvault' ); ?></a></li>
						<li><a href="#general" class="wpvault-settings-nav-item" data-panel="general"><?php esc_html_e( 'General', 'wpvault' ); ?></a></li>
						<li><a href="#diagnostics" class="wpvault-settings-nav-item" data-panel="diagnostics"><?php esc_html_e( 'Diagnostics', 'wpvault' ); ?></a></li>
					</ul>
				</nav>

				<div class="wpvault-settings-content">

			<div class="wpvault-card wpvault-settings-panel" id="wpvault-panel-scheduled">
				<h2><?php esc_html_e( 'Scheduled Backups', 'wpvault' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wpvault_save_schedule">
					<?php wp_nonce_field( 'wpvault_save_schedule' ); ?>

					<table class="form-table">
						<tr>
							<th><label for="wpvault-schedule-enabled"><?php esc_html_e( 'Enabled', 'wpvault' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="wpvault-schedule-enabled" name="wpvault_enabled" value="1" <?php checked( $schedule['enabled'] ); ?>>
									<?php esc_html_e( 'Automatically create backups on a schedule', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><label for="wpvault-schedule-frequency"><?php esc_html_e( 'Frequency', 'wpvault' ); ?></label></th>
							<td>
								<select id="wpvault-schedule-frequency" name="wpvault_frequency">
									<option value="daily" <?php selected( $schedule['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wpvault' ); ?></option>
									<option value="weekly" <?php selected( $schedule['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wpvault' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="wpvault-schedule-day"><?php esc_html_e( 'Day of week', 'wpvault' ); ?></label></th>
							<td>
								<select id="wpvault-schedule-day" name="wpvault_day_of_week">
									<?php foreach ( $day_names as $iso => $label ) : ?>
										<option value="<?php echo esc_attr( $iso ); ?>" <?php selected( (int) $schedule['day_of_week'], $iso ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only used when frequency is Weekly.', 'wpvault' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="wpvault-schedule-time"><?php esc_html_e( 'Time', 'wpvault' ); ?></label></th>
							<td>
								<input type="time" id="wpvault-schedule-time" name="wpvault_time" value="<?php echo esc_attr( $schedule['time'] ); ?>">
								<p class="description">
									<?php
									printf(
										/* translators: %s: the site's configured timezone */
										esc_html__( 'In this site\'s timezone (%s).', 'wpvault' ),
										esc_html( wp_timezone_string() )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Backup type', 'wpvault' ); ?></th>
							<td>
								<label>
									<input type="radio" name="wpvault_type" value="<?php echo esc_attr( Backup_Store::TYPE_FULL ); ?>" <?php checked( $schedule['type'], Backup_Store::TYPE_FULL ); ?>>
									<?php esc_html_e( 'Entire website (database + files)', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="radio" name="wpvault_type" value="<?php echo esc_attr( Backup_Store::TYPE_DATABASE ); ?>" <?php checked( $schedule['type'], Backup_Store::TYPE_DATABASE ); ?>>
									<?php esc_html_e( 'Database only', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="radio" name="wpvault_type" value="<?php echo esc_attr( Backup_Store::TYPE_FILES ); ?>" <?php checked( $schedule['type'], Backup_Store::TYPE_FILES ); ?>>
									<?php esc_html_e( 'Files only', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><label for="wpvault-schedule-exclude-cache"><?php esc_html_e( 'Exclude cache', 'wpvault' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="wpvault-schedule-exclude-cache" name="wpvault_exclude_cache" value="1" <?php checked( $schedule['exclude_cache'] ); ?>>
									<?php esc_html_e( 'Exclude cache / temporary files (recommended)', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<?php if ( Google_Drive::is_connected() ) : ?>
							<tr>
								<th><label for="wpvault-schedule-upload-to-drive"><?php esc_html_e( 'Google Drive', 'wpvault' ); ?></label></th>
								<td>
									<label>
										<input type="checkbox" id="wpvault-schedule-upload-to-drive" name="wpvault_upload_to_drive" value="1" <?php checked( ! empty( $schedule['upload_to_drive'] ) ); ?>>
										<?php esc_html_e( 'Also save each scheduled backup to Google Drive', 'wpvault' ); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>
						<?php if ( One_Drive::is_connected() ) : ?>
							<tr>
								<th><label for="wpvault-schedule-upload-to-onedrive"><?php esc_html_e( 'OneDrive', 'wpvault' ); ?></label></th>
								<td>
									<label>
										<input type="checkbox" id="wpvault-schedule-upload-to-onedrive" name="wpvault_upload_to_onedrive" value="1" <?php checked( ! empty( $schedule['upload_to_onedrive'] ) ); ?>>
										<?php esc_html_e( 'Also save each scheduled backup to OneDrive', 'wpvault' ); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th><label for="wpvault-schedule-retention"><?php esc_html_e( 'Keep', 'wpvault' ); ?></label></th>
							<td>
								<input type="number" id="wpvault-schedule-retention" name="wpvault_retention" min="0" max="90" value="<?php echo esc_attr( $schedule['retention'] ); ?>" class="small-text">
								<?php esc_html_e( 'most recent scheduled backups', 'wpvault' ); ?>
								<p class="description"><?php esc_html_e( 'Older scheduled backups beyond this count are deleted automatically. Manually created, imported, and WP-CLI backups are never affected. 0 keeps every scheduled backup.', 'wpvault' ); ?></p>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'wpvault' ); ?></button>
					</p>
				</form>

				<hr>

				<p>
					<?php if ( $schedule['enabled'] && $schedule['next_run'] ) : ?>
						<?php
						printf(
							/* translators: %s: formatted date/time of the next scheduled backup */
							esc_html__( 'Next scheduled backup: %s', 'wpvault' ),
							esc_html( wp_date( $datetime_format, (int) $schedule['next_run'] ) )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'Scheduled backups are turned off.', 'wpvault' ); ?>
					<?php endif; ?>
				</p>

				<?php if ( $schedule['last_run_at'] ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: formatted date/time of the last scheduled backup attempt */
							esc_html__( 'Last run: %s', 'wpvault' ),
							esc_html( wp_date( $datetime_format, (int) $schedule['last_run_at'] ) )
						);
						?>
						<?php if ( $schedule['last_backup_id'] ) : ?>
							&middot;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-backups' ) ); ?>"><?php esc_html_e( 'View in Backups', 'wpvault' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $schedule['last_error'] ) : ?>
					<p class="wpvault-check-fail">
						<?php
						printf(
							/* translators: %s: the error message from the last failed scheduled backup attempt */
							esc_html__( 'Last attempt did not start a backup: %s', 'wpvault' ),
							esc_html( $schedule['last_error'] )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="wpvault-card wpvault-settings-panel" id="wpvault-panel-pre-update">
				<h2><?php esc_html_e( 'Pre-Update Backups', 'wpvault' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Automatically back up right before a plugin, theme, or core update is applied -- including background auto-updates. If the backup can\'t finish within a short window, the update proceeds anyway; it is never blocked or delayed for long over this.', 'wpvault' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wpvault_save_pre_update">
					<?php wp_nonce_field( 'wpvault_save_pre_update' ); ?>

					<table class="form-table">
						<tr>
							<th><label for="wpvault-pu-enabled"><?php esc_html_e( 'Enabled', 'wpvault' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" id="wpvault-pu-enabled" name="wpvault_pu_enabled" value="1" <?php checked( $pre_update['enabled'] ); ?>>
									<?php esc_html_e( 'Back up automatically before updates', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Before which updates', 'wpvault' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wpvault_pu_target_plugin" value="1" <?php checked( ! empty( $pre_update['targets']['plugin'] ) ); ?>>
									<?php esc_html_e( 'Plugin updates', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="wpvault_pu_target_theme" value="1" <?php checked( ! empty( $pre_update['targets']['theme'] ) ); ?>>
									<?php esc_html_e( 'Theme updates', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="wpvault_pu_target_core" value="1" <?php checked( ! empty( $pre_update['targets']['core'] ) ); ?>>
									<?php esc_html_e( 'WordPress core updates', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Backup type', 'wpvault' ); ?></th>
							<td>
								<label>
									<input type="radio" name="wpvault_pu_type" value="<?php echo esc_attr( Backup_Store::TYPE_DATABASE ); ?>" <?php checked( $pre_update['type'], Backup_Store::TYPE_DATABASE ); ?>>
									<?php esc_html_e( 'Database only (fast -- most likely to finish before the update proceeds)', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="radio" name="wpvault_pu_type" value="<?php echo esc_attr( Backup_Store::TYPE_FULL ); ?>" <?php checked( $pre_update['type'], Backup_Store::TYPE_FULL ); ?>>
									<?php esc_html_e( 'Entire website (database + files -- more protection, more likely to still be running when the update proceeds on a large site)', 'wpvault' ); ?>
								</label><br>
								<label>
									<input type="radio" name="wpvault_pu_type" value="<?php echo esc_attr( Backup_Store::TYPE_FILES ); ?>" <?php checked( $pre_update['type'], Backup_Store::TYPE_FILES ); ?>>
									<?php esc_html_e( 'Files only', 'wpvault' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><label for="wpvault-pu-retention"><?php esc_html_e( 'Keep', 'wpvault' ); ?></label></th>
							<td>
								<input type="number" id="wpvault-pu-retention" name="wpvault_pu_retention" min="0" max="90" value="<?php echo esc_attr( $pre_update['retention'] ); ?>" class="small-text">
								<?php esc_html_e( 'most recent pre-update backups', 'wpvault' ); ?>
								<p class="description"><?php esc_html_e( 'Older pre-update backups beyond this count are deleted automatically. Manually created, scheduled, imported, and WP-CLI backups are never affected. 0 keeps every pre-update backup.', 'wpvault' ); ?></p>
							</td>
						</tr>
					</table>

					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wpvault' ); ?></button>
					</p>
				</form>

				<?php if ( $pre_update['last_run_at'] ) : ?>
					<hr>
					<p>
						<?php
						printf(
							/* translators: 1: formatted date/time of the last pre-update backup, 2: what triggered it (a plugin, theme, or core update) */
							esc_html__( 'Last run: %1$s, before a %2$s update', 'wpvault' ),
							esc_html( wp_date( $datetime_format, (int) $pre_update['last_run_at'] ) ),
							esc_html( $pre_update['last_trigger'] )
						);
						?>
						<?php if ( $pre_update['last_backup_id'] ) : ?>
							&middot;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvault-backups' ) ); ?>"><?php esc_html_e( 'View in Backups', 'wpvault' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( $pre_update['last_error'] ) : ?>
					<p class="wpvault-check-fail">
						<?php
						printf(
							/* translators: %s: the error message from the last failed pre-update backup attempt */
							esc_html__( 'Last attempt did not start a backup: %s', 'wpvault' ),
							esc_html( $pre_update['last_error'] )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="wpvault-settings-panel" id="wpvault-panel-cloud-storage">

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'Google Drive', 'wpvault' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Save a copy of any verified backup to Google Drive on demand, from the Backups screen. Backups are always created locally first -- Drive is just an extra copy you choose to send, never where backups are created.', 'wpvault' ); ?>
				</p>

				<?php if ( Google_Drive::needs_reconnect() ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Your Google Drive connection expired or was revoked. Reconnect it below to keep saving backups there.', 'wpvault' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! Google_Drive::is_connected() ) : ?>
					<p>
						<button type="button" id="wpvault-gdrive-connect-btn" class="button button-primary"><?php esc_html_e( 'Connect Google Drive', 'wpvault' ); ?></button>
					</p>

					<div id="wpvault-gdrive-device" style="display:none">
						<p><?php esc_html_e( 'Go to the link below on any device and enter this code to finish connecting:', 'wpvault' ); ?></p>
						<p><code id="wpvault-gdrive-user-code" style="font-size:1.4em"></code></p>
						<p><a id="wpvault-gdrive-verification-url" href="#" target="_blank" rel="noopener noreferrer"></a></p>
					</div>
					<p class="description">
						<span id="wpvault-gdrive-spinner" class="spinner" style="float:none;vertical-align:middle"></span>
						<span id="wpvault-gdrive-device-status"></span>
					</p>

					<p class="description">
						<?php esc_html_e( 'WPVault only ever requests the drive.file scope: access to files this plugin itself creates in your Drive, never your existing files.', 'wpvault' ); ?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: the connected Google account's email address */
							esc_html__( 'Connected as: %s', 'wpvault' ),
							esc_html( Google_Drive::get_connected_email() )
						);
						?>
					</p>
					<p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<label for="wpvault-gdrive-retention"><?php esc_html_e( 'Keep at most this many backups in Google Drive (0 = unlimited):', 'wpvault' ); ?></label>
							<input type="hidden" name="action" value="wpvault_gdrive_save_retention">
							<?php wp_nonce_field( 'wpvault_gdrive_save_retention' ); ?>
							<input type="number" min="0" max="90" name="retention" id="wpvault-gdrive-retention" value="<?php echo esc_attr( Google_Drive::get_retention() ); ?>" style="width:80px">
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'wpvault' ); ?></button>
						</form>
					</p>
					<p class="description">
						<?php esc_html_e( 'Applies to every upload to Google Drive, whatever started it -- scheduled, "Backup Now", or the on-demand dropdown. Older copies beyond this count are deleted from Drive only; the local backup they came from is never affected.', 'wpvault' ); ?>
					</p>
					<p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="wpvault_gdrive_disconnect">
							<?php wp_nonce_field( 'wpvault_gdrive_disconnect' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'wpvault' ); ?></button>
						</form>
					</p>
				<?php endif; ?>
			</div>

			<div class="wpvault-card">
				<h2><?php esc_html_e( 'OneDrive', 'wpvault' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Save a copy of any verified backup to OneDrive on demand, from the Backups screen. Backups are always created locally first -- OneDrive is just an extra copy you choose to send, never where backups are created.', 'wpvault' ); ?>
				</p>

				<?php if ( One_Drive::needs_reconnect() ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Your OneDrive connection expired or was revoked. Reconnect it below to keep saving backups there.', 'wpvault' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! One_Drive::is_connected() ) : ?>
					<p>
						<button type="button" id="wpvault-onedrive-connect-btn" class="button button-primary"><?php esc_html_e( 'Connect OneDrive', 'wpvault' ); ?></button>
					</p>

					<div id="wpvault-onedrive-device" style="display:none">
						<p><?php esc_html_e( 'Go to the link below on any device and enter this code to finish connecting:', 'wpvault' ); ?></p>
						<p><code id="wpvault-onedrive-user-code" style="font-size:1.4em"></code></p>
						<p><a id="wpvault-onedrive-verification-url" href="#" target="_blank" rel="noopener noreferrer"></a></p>
					</div>
					<p class="description">
						<span id="wpvault-onedrive-spinner" class="spinner" style="float:none;vertical-align:middle"></span>
						<span id="wpvault-onedrive-device-status"></span>
					</p>

					<p class="description">
						<?php esc_html_e( 'WPVault only ever requests the Files.ReadWrite.AppFolder scope: access to a single app-only folder in your OneDrive, never your existing files.', 'wpvault' ); ?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: the connected Microsoft account's email address */
							esc_html__( 'Connected as: %s', 'wpvault' ),
							esc_html( One_Drive::get_connected_email() )
						);
						?>
					</p>
					<p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<label for="wpvault-onedrive-retention"><?php esc_html_e( 'Keep at most this many backups in OneDrive (0 = unlimited):', 'wpvault' ); ?></label>
							<input type="hidden" name="action" value="wpvault_onedrive_save_retention">
							<?php wp_nonce_field( 'wpvault_onedrive_save_retention' ); ?>
							<input type="number" min="0" max="90" name="retention" id="wpvault-onedrive-retention" value="<?php echo esc_attr( One_Drive::get_retention() ); ?>" style="width:80px">
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'wpvault' ); ?></button>
						</form>
					</p>
					<p class="description">
						<?php esc_html_e( 'Applies to every upload to OneDrive, whatever started it -- scheduled, "Backup Now", or the on-demand dropdown. Older copies beyond this count are deleted from OneDrive only; the local backup they came from is never affected.', 'wpvault' ); ?>
					</p>
					<p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="wpvault_onedrive_disconnect">
							<?php wp_nonce_field( 'wpvault_onedrive_disconnect' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'wpvault' ); ?></button>
						</form>
					</p>
				<?php endif; ?>
			</div>

			</div>

			<div class="wpvault-card wpvault-settings-panel" id="wpvault-panel-general">
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

			<div class="wpvault-card wpvault-settings-panel" id="wpvault-panel-diagnostics">
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
			</div>
		</div>
		<?php
	}
}
