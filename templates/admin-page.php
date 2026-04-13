<?php
/**
 * Admin page template for the Plugin Conflict Detector.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

// Determine current page.
$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'ipcd-dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>

<div class="wrap ipcd-wrap">
	<h1 class="ipcd-page-title">
		<span class="dashicons dashicons-warning"></span>
		<?php esc_html_e( 'Intelligent Plugin Conflict Detector', 'intelligent-plugin-conflict-detector' ); ?>
	</h1>

	<nav class="ipcd-nav-tabs">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ipcd-dashboard' ) ); ?>"
			class="ipcd-nav-tab <?php echo 'ipcd-dashboard' === $current_page ? 'ipcd-nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-dashboard"></span>
			<?php esc_html_e( 'Dashboard', 'intelligent-plugin-conflict-detector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ipcd-conflicts' ) ); ?>"
			class="ipcd-nav-tab <?php echo 'ipcd-conflicts' === $current_page ? 'ipcd-nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-editor-code"></span>
			<?php esc_html_e( 'Conflicts', 'intelligent-plugin-conflict-detector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ipcd-scans' ) ); ?>"
			class="ipcd-nav-tab <?php echo 'ipcd-scans' === $current_page ? 'ipcd-nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-search"></span>
			<?php esc_html_e( 'Scan History', 'intelligent-plugin-conflict-detector' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ipcd-settings' ) ); ?>"
			class="ipcd-nav-tab <?php echo 'ipcd-settings' === $current_page ? 'ipcd-nav-tab-active' : ''; ?>">
			<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'Settings', 'intelligent-plugin-conflict-detector' ); ?>
		</a>
	</nav>

	<div class="ipcd-content">
		<?php if ( 'ipcd-dashboard' === $current_page ) : ?>

			<!-- Dashboard View -->
			<div class="ipcd-dashboard" id="ipcd-dashboard">
				<div class="ipcd-stats-grid">
					<div class="ipcd-stat-card ipcd-stat-total">
						<div class="ipcd-stat-icon"><span class="dashicons dashicons-shield"></span></div>
						<div class="ipcd-stat-number" id="ipcd-stat-total">—</div>
						<div class="ipcd-stat-label"><?php esc_html_e( 'Total Conflicts', 'intelligent-plugin-conflict-detector' ); ?></div>
					</div>

					<div class="ipcd-stat-card ipcd-stat-active">
						<div class="ipcd-stat-icon"><span class="dashicons dashicons-warning"></span></div>
						<div class="ipcd-stat-number" id="ipcd-stat-active">—</div>
						<div class="ipcd-stat-label"><?php esc_html_e( 'Active Conflicts', 'intelligent-plugin-conflict-detector' ); ?></div>
					</div>

					<div class="ipcd-stat-card ipcd-stat-critical">
						<div class="ipcd-stat-icon"><span class="dashicons dashicons-dismiss"></span></div>
						<div class="ipcd-stat-number" id="ipcd-stat-critical">—</div>
						<div class="ipcd-stat-label"><?php esc_html_e( 'Critical', 'intelligent-plugin-conflict-detector' ); ?></div>
					</div>

					<div class="ipcd-stat-card ipcd-stat-resolved">
						<div class="ipcd-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
						<div class="ipcd-stat-number" id="ipcd-stat-resolved">—</div>
						<div class="ipcd-stat-label"><?php esc_html_e( 'Resolved', 'intelligent-plugin-conflict-detector' ); ?></div>
					</div>
				</div>

				<div class="ipcd-actions-bar">
					<button type="button" class="button button-primary ipcd-btn-scan" id="ipcd-btn-full-scan" data-scan-type="full">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Run Full Scan', 'intelligent-plugin-conflict-detector' ); ?>
					</button>

					<button type="button" class="button ipcd-btn-scan" id="ipcd-btn-quick-scan" data-scan-type="quick">
						<span class="dashicons dashicons-dashboard"></span>
						<?php esc_html_e( 'Quick Health Check', 'intelligent-plugin-conflict-detector' ); ?>
					</button>

					<button type="button" class="button ipcd-btn-scan" id="ipcd-btn-isolation-scan" data-scan-type="isolation">
						<span class="dashicons dashicons-randomize"></span>
						<?php esc_html_e( 'Isolation Test', 'intelligent-plugin-conflict-detector' ); ?>
					</button>

					<span class="ipcd-scan-status" id="ipcd-scan-status"></span>
				</div>

				<div class="ipcd-info-bar">
					<span id="ipcd-last-scan">
						<?php esc_html_e( 'Last scan: Loading...', 'intelligent-plugin-conflict-detector' ); ?>
					</span>
					<span id="ipcd-active-plugins">
						<?php esc_html_e( 'Active plugins: Loading...', 'intelligent-plugin-conflict-detector' ); ?>
					</span>
					<span id="ipcd-monitoring-status">
						<?php esc_html_e( 'Monitoring: Loading...', 'intelligent-plugin-conflict-detector' ); ?>
					</span>
				</div>

				<div class="ipcd-recent-conflicts">
					<h2><?php esc_html_e( 'Recent Conflicts', 'intelligent-plugin-conflict-detector' ); ?></h2>
					<table class="wp-list-table widefat fixed striped" id="ipcd-recent-conflicts-table">
						<thead>
							<tr>
								<th class="ipcd-col-severity"><?php esc_html_e( 'Severity', 'intelligent-plugin-conflict-detector' ); ?></th>
								<th class="ipcd-col-plugins"><?php esc_html_e( 'Plugins', 'intelligent-plugin-conflict-detector' ); ?></th>
								<th class="ipcd-col-type"><?php esc_html_e( 'Type', 'intelligent-plugin-conflict-detector' ); ?></th>
								<th class="ipcd-col-message"><?php esc_html_e( 'Details', 'intelligent-plugin-conflict-detector' ); ?></th>
								<th class="ipcd-col-date"><?php esc_html_e( 'Detected', 'intelligent-plugin-conflict-detector' ); ?></th>
								<th class="ipcd-col-actions"><?php esc_html_e( 'Actions', 'intelligent-plugin-conflict-detector' ); ?></th>
							</tr>
						</thead>
						<tbody id="ipcd-conflicts-body">
							<tr>
								<td colspan="6" class="ipcd-loading">
									<?php esc_html_e( 'Loading conflicts...', 'intelligent-plugin-conflict-detector' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

		<?php elseif ( 'ipcd-conflicts' === $current_page ) : ?>

			<!-- Conflicts List View -->
			<div class="ipcd-conflicts-page" id="ipcd-conflicts-page">
				<div class="ipcd-filters">
					<label for="ipcd-filter-severity"><?php esc_html_e( 'Severity:', 'intelligent-plugin-conflict-detector' ); ?></label>
					<select id="ipcd-filter-severity">
						<option value=""><?php esc_html_e( 'All', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="critical"><?php esc_html_e( 'Critical', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="high"><?php esc_html_e( 'High', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="medium"><?php esc_html_e( 'Medium', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="low"><?php esc_html_e( 'Low', 'intelligent-plugin-conflict-detector' ); ?></option>
					</select>

					<label for="ipcd-filter-status"><?php esc_html_e( 'Status:', 'intelligent-plugin-conflict-detector' ); ?></label>
					<select id="ipcd-filter-status">
						<option value=""><?php esc_html_e( 'All', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="0"><?php esc_html_e( 'Active', 'intelligent-plugin-conflict-detector' ); ?></option>
						<option value="1"><?php esc_html_e( 'Resolved', 'intelligent-plugin-conflict-detector' ); ?></option>
					</select>

					<button type="button" class="button" id="ipcd-filter-apply">
						<?php esc_html_e( 'Filter', 'intelligent-plugin-conflict-detector' ); ?>
					</button>
				</div>

				<table class="wp-list-table widefat fixed striped" id="ipcd-all-conflicts-table">
					<thead>
						<tr>
							<th class="ipcd-col-severity"><?php esc_html_e( 'Severity', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-plugins"><?php esc_html_e( 'Plugin', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-plugins"><?php esc_html_e( 'Conflicting Plugin', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-type"><?php esc_html_e( 'Type', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-message"><?php esc_html_e( 'Details', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-date"><?php esc_html_e( 'Detected', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-status"><?php esc_html_e( 'Status', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th class="ipcd-col-actions"><?php esc_html_e( 'Actions', 'intelligent-plugin-conflict-detector' ); ?></th>
						</tr>
					</thead>
					<tbody id="ipcd-all-conflicts-body">
						<tr>
							<td colspan="8" class="ipcd-loading">
								<?php esc_html_e( 'Loading conflicts...', 'intelligent-plugin-conflict-detector' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="ipcd-pagination" id="ipcd-conflicts-pagination"></div>
			</div>

		<?php elseif ( 'ipcd-scans' === $current_page ) : ?>

			<!-- Scan History View -->
			<div class="ipcd-scans-page" id="ipcd-scans-page">
				<h2><?php esc_html_e( 'Scan History', 'intelligent-plugin-conflict-detector' ); ?></h2>

				<table class="wp-list-table widefat fixed striped" id="ipcd-scans-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Type', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Status', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Plugins Tested', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Combinations', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Conflicts Found', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Started', 'intelligent-plugin-conflict-detector' ); ?></th>
							<th><?php esc_html_e( 'Completed', 'intelligent-plugin-conflict-detector' ); ?></th>
						</tr>
					</thead>
					<tbody id="ipcd-scans-body">
						<tr>
							<td colspan="8" class="ipcd-loading">
								<?php esc_html_e( 'Loading scan history...', 'intelligent-plugin-conflict-detector' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

		<?php elseif ( 'ipcd-settings' === $current_page ) : ?>

			<!-- Settings View -->
			<div class="ipcd-settings-page" id="ipcd-settings-page">
				<h2><?php esc_html_e( 'Settings', 'intelligent-plugin-conflict-detector' ); ?></h2>

				<form id="ipcd-settings-form" class="ipcd-settings-form">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="ipcd-setting-monitoring">
									<?php esc_html_e( 'Background Monitoring', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="ipcd-setting-monitoring" name="monitoring_enabled" value="1">
									<?php esc_html_e( 'Enable automatic background conflict monitoring', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, the plugin will automatically scan for conflicts on a schedule.', 'intelligent-plugin-conflict-detector' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ipcd-setting-frequency">
									<?php esc_html_e( 'Scan Frequency', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</th>
							<td>
								<select id="ipcd-setting-frequency" name="scan_frequency">
									<option value="hourly"><?php esc_html_e( 'Hourly', 'intelligent-plugin-conflict-detector' ); ?></option>
									<option value="twicedaily"><?php esc_html_e( 'Twice Daily', 'intelligent-plugin-conflict-detector' ); ?></option>
									<option value="daily"><?php esc_html_e( 'Daily', 'intelligent-plugin-conflict-detector' ); ?></option>
									<option value="ipcd_weekly"><?php esc_html_e( 'Weekly', 'intelligent-plugin-conflict-detector' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'How often to run automatic conflict detection scans.', 'intelligent-plugin-conflict-detector' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ipcd-setting-threshold">
									<?php esc_html_e( 'Error Threshold', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</th>
							<td>
								<select id="ipcd-setting-threshold" name="error_threshold">
									<option value="notice"><?php esc_html_e( 'Notice (all issues)', 'intelligent-plugin-conflict-detector' ); ?></option>
									<option value="warning"><?php esc_html_e( 'Warning (warnings and above)', 'intelligent-plugin-conflict-detector' ); ?></option>
									<option value="error"><?php esc_html_e( 'Error (errors only)', 'intelligent-plugin-conflict-detector' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Minimum error level to report as a conflict.', 'intelligent-plugin-conflict-detector' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ipcd-setting-email-enabled">
									<?php esc_html_e( 'Email Notifications', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="ipcd-setting-email-enabled" name="email_notifications" value="1">
									<?php esc_html_e( 'Send email notifications when conflicts are detected', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ipcd-setting-email">
									<?php esc_html_e( 'Notification Email', 'intelligent-plugin-conflict-detector' ); ?>
								</label>
							</th>
							<td>
								<input type="email" id="ipcd-setting-email" name="notification_email"
									class="regular-text" value="">
								<p class="description">
									<?php esc_html_e( 'Email address to send conflict notifications to.', 'intelligent-plugin-conflict-detector' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" id="ipcd-save-settings">
							<?php esc_html_e( 'Save Settings', 'intelligent-plugin-conflict-detector' ); ?>
						</button>
						<span class="ipcd-settings-status" id="ipcd-settings-status"></span>
					</p>
				</form>
			</div>

		<?php endif; ?>
	</div>
</div>
