<?php
/**
 * Dashboard view
 *
 * @package IPCD
 * @var IPCD_Conflict_Detector    $detector
 * @var IPCD_Rollback_Manager     $rollback
 * @var IPCD_Background_Tester    $tester
 * @var IPCD_Plugin_State_Manager $state_manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Make objects available inside the view.
$detector      = ipcd()->conflict_detector;
$rollback      = ipcd()->rollback_manager;
$tester        = ipcd()->background_tester;
$state_manager = ipcd()->state_manager;

$active_conflicts = $detector->get_active_conflicts();
$all_conflicts    = $detector->get_conflicts();
$snapshots        = $state_manager->get_snapshots();
$rollback_history = $rollback->get_history();
$next_run         = $tester->get_next_run();
$queue            = $tester->get_queue();

$severity_icons = array(
	'critical' => '🔴',
	'warning'  => '🟡',
	'info'     => '🔵',
);
?>
<div class="wrap ipcd-wrap">
	<h1 class="ipcd-page-title">
		<span class="ipcd-logo">🔍</span>
		<?php esc_html_e( 'Intelligent Plugin Conflict Detector', 'ipcd' ); ?>
		<span class="ipcd-version">v<?php echo esc_html( IPCD_VERSION ); ?></span>
	</h1>

	<nav class="ipcd-tabs">
		<a href="#tab-conflicts" class="ipcd-tab active"><?php esc_html_e( 'Conflicts', 'ipcd' ); ?>
			<?php if ( ! empty( $active_conflicts ) ) : ?>
				<span class="ipcd-badge ipcd-badge--error"><?php echo esc_html( count( $active_conflicts ) ); ?></span>
			<?php endif; ?>
		</a>
		<a href="#tab-snapshots" class="ipcd-tab"><?php esc_html_e( 'Snapshots', 'ipcd' ); ?>
			<span class="ipcd-badge"><?php echo esc_html( count( $snapshots ) ); ?></span>
		</a>
		<a href="#tab-history" class="ipcd-tab"><?php esc_html_e( 'Rollback History', 'ipcd' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ipcd-settings' ) ); ?>" class="ipcd-tab"><?php esc_html_e( 'Settings', 'ipcd' ); ?></a>
	</nav>

	<!-- STATUS BAR -->
	<div class="ipcd-status-bar">
		<div class="ipcd-status-item">
			<strong><?php esc_html_e( 'Background Testing:', 'ipcd' ); ?></strong>
			<span class="ipcd-pill ipcd-pill--green"><?php esc_html_e( 'Active', 'ipcd' ); ?></span>
		</div>
		<div class="ipcd-status-item">
			<strong><?php esc_html_e( 'Next Scan:', 'ipcd' ); ?></strong>
			<?php echo $next_run ? esc_html( human_time_diff( time(), $next_run ) . ' ' . __( 'from now', 'ipcd' ) ) : esc_html__( 'Not scheduled', 'ipcd' ); ?>
		</div>
		<div class="ipcd-status-item">
			<strong><?php esc_html_e( 'Queue:', 'ipcd' ); ?></strong>
			<?php
			$queue_count = count( $queue );
			/* translators: %d: number of pending tests */
			echo esc_html( sprintf( _n( '%d pending test', '%d pending tests', $queue_count, 'ipcd' ), $queue_count ) );
			?>
		</div>
		<div class="ipcd-status-actions">
			<button id="ipcd-run-test-btn" class="button button-secondary" data-plugin="" data-event="manual">
				<?php esc_html_e( 'Run Test Now', 'ipcd' ); ?>
			</button>
			<button id="ipcd-create-snapshot-btn" class="button button-secondary">
				<?php esc_html_e( 'Create Snapshot', 'ipcd' ); ?>
			</button>
		</div>
	</div>

	<div id="ipcd-notice-area"></div>

	<!-- TAB: CONFLICTS -->
	<div id="tab-conflicts" class="ipcd-tab-content active">
		<div class="ipcd-card">
			<div class="ipcd-card-header">
				<h2><?php esc_html_e( 'Active Conflicts', 'ipcd' ); ?></h2>
				<?php if ( ! empty( $active_conflicts ) ) : ?>
					<button id="ipcd-clear-conflicts-btn" class="button button-link-delete">
						<?php esc_html_e( 'Clear All', 'ipcd' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( empty( $active_conflicts ) ) : ?>
				<div class="ipcd-empty-state">
					<span class="ipcd-empty-icon">✅</span>
					<p><?php esc_html_e( 'No active conflicts detected. Your site looks healthy!', 'ipcd' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped ipcd-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Severity', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Plugin', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Message', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Detected', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ipcd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $active_conflicts as $conflict ) : ?>
							<tr class="ipcd-conflict-row ipcd-severity-<?php echo esc_attr( $conflict['severity'] ); ?>" data-conflict-id="<?php echo esc_attr( $conflict['id'] ); ?>">
								<td>
									<?php echo esc_html( $severity_icons[ $conflict['severity'] ] ?? '⚪' ); ?>
									<strong><?php echo esc_html( ucfirst( $conflict['severity'] ) ); ?></strong>
								</td>
								<td><code><?php echo esc_html( $conflict['plugin'] ); ?></code></td>
								<td><code><?php echo esc_html( $conflict['type'] ); ?></code></td>
								<td><?php echo esc_html( $conflict['message'] ); ?></td>
								<td><?php echo esc_html( human_time_diff( $conflict['time'], time() ) . ' ago' ); ?></td>
								<td>
									<button class="button button-small ipcd-resolve-btn" data-conflict-id="<?php echo esc_attr( $conflict['id'] ); ?>">
										<?php esc_html_e( 'Resolve', 'ipcd' ); ?>
									</button>
								</td>
							</tr>
							<?php if ( ! empty( $conflict['context'] ) ) : ?>
								<tr class="ipcd-context-row">
									<td colspan="6">
										<details>
											<summary><?php esc_html_e( 'Context', 'ipcd' ); ?></summary>
											<pre class="ipcd-context-pre"><?php echo esc_html( wp_json_encode( $conflict['context'], JSON_PRETTY_PRINT ) ); ?></pre>
										</details>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( count( $all_conflicts ) > count( $active_conflicts ) ) : ?>
			<div class="ipcd-card">
				<div class="ipcd-card-header">
					<h2><?php esc_html_e( 'Resolved Conflicts', 'ipcd' ); ?></h2>
				</div>
				<table class="wp-list-table widefat fixed striped ipcd-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Message', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Detected', 'ipcd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_conflicts as $conflict ) : ?>
							<?php if ( ! empty( $conflict['resolved'] ) ) : ?>
								<tr class="ipcd-resolved-row">
									<td><code><?php echo esc_html( $conflict['plugin'] ); ?></code></td>
									<td><code><?php echo esc_html( $conflict['type'] ); ?></code></td>
									<td><?php echo esc_html( $conflict['message'] ); ?></td>
									<td><?php echo esc_html( human_time_diff( $conflict['time'], time() ) . ' ago' ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- TAB: SNAPSHOTS -->
	<div id="tab-snapshots" class="ipcd-tab-content">
		<div class="ipcd-card">
			<div class="ipcd-card-header">
				<h2><?php esc_html_e( 'Plugin State Snapshots', 'ipcd' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Snapshots capture the active plugin list at a point in time. Use them to roll back if a plugin causes issues.', 'ipcd' ); ?></p>
			</div>

			<?php if ( empty( $snapshots ) ) : ?>
				<div class="ipcd-empty-state">
					<span class="ipcd-empty-icon">📷</span>
					<p><?php esc_html_e( 'No snapshots yet. Snapshots are created automatically when plugins are activated or updated, or you can create one manually.', 'ipcd' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped ipcd-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Snapshot ID', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Created', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ipcd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $snapshots as $snapshot ) : ?>
							<tr>
								<td><?php echo esc_html( $snapshot['label'] ?: __( '(manual)', 'ipcd' ) ); ?></td>
								<td><code><?php echo esc_html( $snapshot['snapshot_id'] ); ?></code></td>
								<td><?php echo esc_html( $snapshot['created_at'] ); ?></td>
								<td class="ipcd-actions-cell">
									<button class="button button-primary ipcd-rollback-btn"
											data-snapshot-id="<?php echo esc_attr( $snapshot['snapshot_id'] ); ?>">
										⏪ <?php esc_html_e( 'Rollback', 'ipcd' ); ?>
									</button>
									<button class="button button-link-delete ipcd-delete-snapshot-btn"
											data-snapshot-id="<?php echo esc_attr( $snapshot['snapshot_id'] ); ?>">
										<?php esc_html_e( 'Delete', 'ipcd' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<!-- TAB: ROLLBACK HISTORY -->
	<div id="tab-history" class="ipcd-tab-content">
		<div class="ipcd-card">
			<div class="ipcd-card-header">
				<h2><?php esc_html_e( 'Rollback History', 'ipcd' ); ?></h2>
			</div>

			<?php if ( empty( $rollback_history ) ) : ?>
				<div class="ipcd-empty-state">
					<span class="ipcd-empty-icon">📋</span>
					<p><?php esc_html_e( 'No rollbacks have been performed yet.', 'ipcd' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped ipcd-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Restored Snapshot', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Safety Snapshot', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Status', 'ipcd' ); ?></th>
							<th><?php esc_html_e( 'Time', 'ipcd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rollback_history as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( $entry['snapshot_id'] ); ?></code></td>
								<td><code><?php echo esc_html( $entry['pre_rollback_id'] ); ?></code></td>
								<td>
									<?php if ( 'success' === $entry['status'] ) : ?>
										<span class="ipcd-pill ipcd-pill--green"><?php esc_html_e( 'Success', 'ipcd' ); ?></span>
									<?php else : ?>
										<span class="ipcd-pill ipcd-pill--red"><?php esc_html_e( 'Failed', 'ipcd' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( human_time_diff( $entry['time'], time() ) . ' ago' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
