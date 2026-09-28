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
use WPVault\Jobs\Scheduled_Backups;
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
		$schedule  = Scheduled_Backups::get_settings();

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

			<div class="wpvault-card">
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
