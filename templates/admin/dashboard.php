<?php
/**
 * Main dashboard template.
 *
 * @var array  $health
 * @var array  $conflict_summary
 * @var object|null $latest_scan
 * @var array  $active_conflicts
 * @var bool   $has_running
 * @var string $tier
 * @var array  $license_info
 * @var int    $plugin_count
 *
 * @package Jetstrike\ConflictDetector
 */

defined('ABSPATH') || exit;

$total_conflicts = array_sum($conflict_summary);
$grade_colors = [
    'A' => '#28a745',
    'B' => '#5cb85c',
    'C' => '#ffc107',
    'D' => '#fd7e14',
    'F' => '#dc3545',
];
$grade_color = $grade_colors[$health['grade']] ?? '#6c757d';
?>

<div class="wrap jetstrike-cd-wrap">
    <h1 class="jetstrike-cd-header">
        <span class="dashicons dashicons-shield"></span>
        <?php esc_html_e('Jetstrike Conflict Detector', 'jetstrike-cd'); ?>
        <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($tier); ?>">
            <?php echo esc_html(ucfirst($tier)); ?>
        </span>
    </h1>

    <!-- Health Score + Quick Actions Row -->
    <div class="jetstrike-cd-grid jetstrike-cd-grid--3">
        <!-- Health Score Card -->
        <div class="jetstrike-cd-card jetstrike-cd-card--health">
            <div class="jetstrike-cd-health-score">
                <div class="jetstrike-cd-health-circle" style="--score-color: <?php echo esc_attr($grade_color); ?>">
                    <span class="jetstrike-cd-health-grade"><?php echo esc_html($health['grade']); ?></span>
                    <span class="jetstrike-cd-health-number"><?php echo (int) $health['score']; ?>/100</span>
                </div>
                <h3><?php esc_html_e('Site Health Score', 'jetstrike-cd'); ?></h3>
            </div>
            <?php if (! empty($health['factors'])): ?>
                <ul class="jetstrike-cd-health-factors">
                    <?php foreach ($health['factors'] as $factor): ?>
                        <li>
                            <span class="jetstrike-cd-factor-impact"><?php echo (int) $factor['impact']; ?></span>
                            <?php echo esc_html($factor['message']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Conflict Summary Card -->
        <div class="jetstrike-cd-card jetstrike-cd-card--conflicts">
            <h3><?php esc_html_e('Active Conflicts', 'jetstrike-cd'); ?></h3>
            <div class="jetstrike-cd-conflict-counts">
                <div class="jetstrike-cd-severity jetstrike-cd-severity--critical">
                    <span class="jetstrike-cd-severity-count"><?php echo (int) $conflict_summary['critical']; ?></span>
                    <span class="jetstrike-cd-severity-label"><?php esc_html_e('Critical', 'jetstrike-cd'); ?></span>
                </div>
                <div class="jetstrike-cd-severity jetstrike-cd-severity--high">
                    <span class="jetstrike-cd-severity-count"><?php echo (int) $conflict_summary['high']; ?></span>
                    <span class="jetstrike-cd-severity-label"><?php esc_html_e('High', 'jetstrike-cd'); ?></span>
                </div>
                <div class="jetstrike-cd-severity jetstrike-cd-severity--medium">
                    <span class="jetstrike-cd-severity-count"><?php echo (int) $conflict_summary['medium']; ?></span>
                    <span class="jetstrike-cd-severity-label"><?php esc_html_e('Medium', 'jetstrike-cd'); ?></span>
                </div>
                <div class="jetstrike-cd-severity jetstrike-cd-severity--low">
                    <span class="jetstrike-cd-severity-count"><?php echo (int) $conflict_summary['low']; ?></span>
                    <span class="jetstrike-cd-severity-label"><?php esc_html_e('Low', 'jetstrike-cd'); ?></span>
                </div>
            </div>
            <p class="jetstrike-cd-stat-info">
                <?php echo esc_html(sprintf(
                    __('%d active plugin(s) being monitored', 'jetstrike-cd'),
                    $plugin_count
                )); ?>
            </p>
        </div>

        <!-- Quick Actions Card -->
        <div class="jetstrike-cd-card jetstrike-cd-card--actions">
            <h3><?php esc_html_e('Quick Actions', 'jetstrike-cd'); ?></h3>
            <div class="jetstrike-cd-actions">
                <button type="button" class="button button-primary button-hero jetstrike-cd-scan-btn"
                    data-scan-type="quick"
                    <?php echo $has_running ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Quick Scan', 'jetstrike-cd'); ?>
                </button>
                <button type="button" class="button button-secondary jetstrike-cd-scan-btn"
                    data-scan-type="full"
                    <?php echo $has_running ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php esc_html_e('Full Scan', 'jetstrike-cd'); ?>
                </button>
            </div>
            <?php if ($has_running): ?>
                <div class="jetstrike-cd-scan-progress" id="jetstrike-scan-progress">
                    <div class="jetstrike-cd-progress-bar">
                        <div class="jetstrike-cd-progress-fill"></div>
                    </div>
                    <p class="jetstrike-cd-progress-text"><?php esc_html_e('Scan in progress...', 'jetstrike-cd'); ?></p>
                    <button type="button" class="button button-link-delete jetstrike-cd-cancel-scan">
                        <?php esc_html_e('Cancel', 'jetstrike-cd'); ?>
                    </button>
                </div>
            <?php endif; ?>
            <?php if ($latest_scan): ?>
                <p class="jetstrike-cd-last-scan">
                    <?php echo esc_html(sprintf(
                        /* translators: %s: human-readable time diff */
                        __('Last scan: %s ago', 'jetstrike-cd'),
                        human_time_diff(strtotime($latest_scan->completed_at))
                    )); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toolbar: Report, Export, Matrix -->
    <div class="jetstrike-cd-card jetstrike-cd-card--full">
        <div class="jetstrike-cd-toolbar">
            <button type="button" class="button" id="jetstrike-generate-report">
                <span class="dashicons dashicons-media-document"></span>
                <?php esc_html_e('Generate Report', 'jetstrike-cd'); ?>
            </button>
            <button type="button" class="button" id="jetstrike-export-data">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e('Export Data', 'jetstrike-cd'); ?>
            </button>
            <button type="button" class="button" id="jetstrike-toggle-matrix">
                <span class="dashicons dashicons-grid-view"></span>
                <?php esc_html_e('Compatibility Matrix', 'jetstrike-cd'); ?>
            </button>
        </div>

        <!-- Compatibility Matrix (hidden by default, toggled by button) -->
        <div id="jetstrike-matrix-container" style="display: none;">
            <?php
            $matrix = new \Jetstrike\ConflictDetector\Admin\CompatibilityMatrix($this->repository ?? $repository);
            echo $matrix->render_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped internally
            ?>
        </div>
    </div>

    <!-- Active Conflicts Table -->
    <?php if ($total_conflicts > 0 && ! empty($active_conflicts['items'])): ?>
    <div class="jetstrike-cd-card jetstrike-cd-card--full">
        <h3>
            <?php esc_html_e('Active Conflicts', 'jetstrike-cd'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=jetstrike-cd-results')); ?>" class="jetstrike-cd-view-all">
                <?php esc_html_e('View All Scans', 'jetstrike-cd'); ?> &rarr;
            </a>
        </h3>
        <table class="widefat striped jetstrike-cd-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Severity', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Type', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Description', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Plugins', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Auto-Fix', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Actions', 'jetstrike-cd'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_conflicts['items'] as $conflict): ?>
                <tr data-conflict-id="<?php echo (int) $conflict->id; ?>">
                    <td>
                        <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($conflict->severity); ?>">
                            <?php echo esc_html(ucfirst($conflict->severity)); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(str_replace('_', ' ', $conflict->conflict_type)); ?></td>
                    <td>
                        <strong><?php echo esc_html($conflict->description); ?></strong>
                        <?php if ($conflict->recommendation): ?>
                            <br><em class="jetstrike-cd-recommendation"><?php echo esc_html($conflict->recommendation); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code><?php echo esc_html(dirname($conflict->plugin_a)); ?></code>
                        <?php if ($conflict->plugin_b): ?>
                            <br>vs <code><?php echo esc_html(dirname($conflict->plugin_b)); ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="jetstrike-cd-autofix-cell">
                        <?php
                        $can_fix = \Jetstrike\ConflictDetector\Resolver\AutoResolver::can_auto_resolve($conflict->conflict_type);
                        if ($can_fix['can_resolve']): ?>
                            <button type="button"
                                class="button button-small jetstrike-cd-autofix-btn"
                                data-conflict-id="<?php echo (int) $conflict->id; ?>"
                                title="<?php echo esc_attr($can_fix['description']); ?>">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php esc_html_e('Auto-Fix', 'jetstrike-cd'); ?>
                            </button>
                        <?php else: ?>
                            <span class="jetstrike-cd-no-fix" title="<?php echo esc_attr($can_fix['description']); ?>">
                                &mdash;
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select class="jetstrike-cd-conflict-action" data-conflict-id="<?php echo (int) $conflict->id; ?>">
                            <option value="active" <?php selected($conflict->status, 'active'); ?>><?php esc_html_e('Active', 'jetstrike-cd'); ?></option>
                            <option value="resolved"><?php esc_html_e('Mark Resolved', 'jetstrike-cd'); ?></option>
                            <option value="ignored"><?php esc_html_e('Ignore', 'jetstrike-cd'); ?></option>
                            <option value="false_positive"><?php esc_html_e('False Positive', 'jetstrike-cd'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($total_conflicts === 0 && $latest_scan): ?>
    <div class="jetstrike-cd-card jetstrike-cd-card--full jetstrike-cd-card--success">
        <div class="jetstrike-cd-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <h3><?php esc_html_e('All Clear!', 'jetstrike-cd'); ?></h3>
            <p><?php esc_html_e('No plugin conflicts detected. Your site is running cleanly.', 'jetstrike-cd'); ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="jetstrike-cd-card jetstrike-cd-card--full">
        <div class="jetstrike-cd-empty-state">
            <span class="dashicons dashicons-search"></span>
            <h3><?php esc_html_e('Ready to Scan', 'jetstrike-cd'); ?></h3>
            <p><?php esc_html_e('Run your first scan to detect plugin conflicts.', 'jetstrike-cd'); ?></p>
            <button type="button" class="button button-primary button-hero jetstrike-cd-scan-btn" data-scan-type="quick">
                <?php esc_html_e('Run Quick Scan', 'jetstrike-cd'); ?>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Upgrade Banner (Free tier only) -->
    <?php if ($tier === 'free'): ?>
    <div class="jetstrike-cd-card jetstrike-cd-card--upgrade">
        <div class="jetstrike-cd-upgrade-content">
            <h3><?php esc_html_e('Upgrade to Pro', 'jetstrike-cd'); ?></h3>
            <p><?php esc_html_e('Unlock automated background scans, pre-update testing, WooCommerce deep analysis, email alerts, and more.', 'jetstrike-cd'); ?></p>
            <ul>
                <li><?php esc_html_e('One-Click Auto-Fix — automatically resolve conflicts', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('Automated background scanning', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('Pre-update conflict simulation', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('WooCommerce checkout/payment analysis', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('PHP library dependency conflict detection', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('JavaScript deep analysis', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('WP-CLI integration for CI/CD pipelines', 'jetstrike-cd'); ?></li>
                <li><?php esc_html_e('Email conflict alerts', 'jetstrike-cd'); ?></li>
            </ul>
            <a href="https://jetstrike.io/pricing" class="button button-primary" target="_blank" rel="noopener">
                <?php esc_html_e('Upgrade — $79/year', 'jetstrike-cd'); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>
