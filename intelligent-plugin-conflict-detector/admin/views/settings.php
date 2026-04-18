<?php
/**
 * Settings view
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = ipcd()->notification_manager->get_settings();
?>
<div class="wrap ipcd-wrap">
	<h1 class="ipcd-page-title">
		<span class="ipcd-logo">⚙️</span>
		<?php esc_html_e( 'Conflict Detector — Settings', 'ipcd' ); ?>
	</h1>

	<div id="ipcd-notice-area"></div>

	<div class="ipcd-card" style="max-width:700px;">
		<form id="ipcd-settings-form">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="ipcd_email_notifications">
								<?php esc_html_e( 'Email Notifications', 'ipcd' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									id="ipcd_email_notifications"
									name="email_notifications"
									value="1"
									<?php checked( $settings['email_notifications'] ); ?> />
								<?php esc_html_e( 'Send email alerts when conflicts are detected', 'ipcd' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ipcd_email_address">
								<?php esc_html_e( 'Alert Email Address', 'ipcd' ); ?>
							</label>
						</th>
						<td>
							<input type="email"
								id="ipcd_email_address"
								name="email_address"
								class="regular-text"
								value="<?php echo esc_attr( $settings['email_address'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Defaults to the WordPress admin email.', 'ipcd' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ipcd_auto_rollback">
								<?php esc_html_e( 'Auto Rollback', 'ipcd' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									id="ipcd_auto_rollback"
									name="auto_rollback"
									value="1"
									<?php checked( $settings['auto_rollback'] ); ?> />
								<?php esc_html_e( 'Automatically roll back when a critical conflict is detected', 'ipcd' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Use with caution. This will restore the previous plugin state immediately after a critical conflict is found.', 'ipcd' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ipcd_scan_interval">
								<?php esc_html_e( 'Scan Interval (hours)', 'ipcd' ); ?>
							</label>
						</th>
						<td>
							<input type="number"
								id="ipcd_scan_interval"
								name="scan_interval_hours"
								class="small-text"
								min="1"
								max="168"
								value="<?php echo esc_attr( $settings['scan_interval_hours'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How often to run scheduled background tests. Minimum 1 hour.', 'ipcd' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button id="ipcd-save-settings-btn" type="submit" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'ipcd' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=ipcd-dashboard' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( '← Back to Dashboard', 'ipcd' ); ?>
				</a>
			</p>
		</form>
	</div>

	<div class="ipcd-card" style="max-width:700px;margin-top:20px;">
		<h2><?php esc_html_e( 'Danger Zone', 'ipcd' ); ?></h2>
		<p><?php esc_html_e( 'These actions cannot be undone.', 'ipcd' ); ?></p>
		<button id="ipcd-clear-conflicts-btn" class="button button-link-delete">
			<?php esc_html_e( 'Clear All Conflict Records', 'ipcd' ); ?>
		</button>
	</div>
</div>
