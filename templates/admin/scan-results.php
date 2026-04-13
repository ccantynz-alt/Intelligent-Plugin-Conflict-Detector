<?php
/**
 * Scan results template.
 *
 * @var array       $scans          Paginated scan list.
 * @var int         $page           Current page.
 * @var string      $tier           Current license tier.
 * @var object|null $current_scan   Currently viewed scan.
 * @var array       $scan_conflicts Conflicts for current scan.
 *
 * @package Jetstrike\ConflictDetector
 */

defined('ABSPATH') || exit;
?>

<div class="wrap jetstrike-cd-wrap">
    <h1>
        <span class="dashicons dashicons-shield"></span>
        <?php esc_html_e('Scan Results', 'jetstrike-cd'); ?>
    </h1>

    <?php if ($current_scan): ?>
    <!-- Single Scan Detail View -->
    <div class="jetstrike-cd-card">
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=jetstrike-cd-results')); ?>">&larr; <?php esc_html_e('Back to all scans', 'jetstrike-cd'); ?></a>
        </p>

        <div class="jetstrike-cd-scan-header">
            <h2>
                <?php echo esc_html(sprintf(
                    __('Scan #%d — %s', 'jetstrike-cd'),
                    $current_scan->id,
                    ucfirst($current_scan->scan_type)
                )); ?>
            </h2>
            <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($current_scan->status); ?>">
                <?php echo esc_html(ucfirst($current_scan->status)); ?>
            </span>
        </div>

        <div class="jetstrike-cd-scan-meta">
            <span><strong><?php esc_html_e('Started:', 'jetstrike-cd'); ?></strong> <?php echo esc_html($current_scan->started_at ?? '—'); ?></span>
            <span><strong><?php esc_html_e('Completed:', 'jetstrike-cd'); ?></strong> <?php echo esc_html($current_scan->completed_at ?? '—'); ?></span>
            <span><strong><?php esc_html_e('Triggered by:', 'jetstrike-cd'); ?></strong> <?php echo esc_html(ucfirst($current_scan->triggered_by)); ?></span>
            <span><strong><?php esc_html_e('Conflicts:', 'jetstrike-cd'); ?></strong> <?php echo (int) $current_scan->conflicts_found; ?></span>
        </div>

        <?php if (! empty($scan_conflicts)): ?>
        <table class="widefat striped jetstrike-cd-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Severity', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Type', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Description', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Plugins', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Recommendation', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Status', 'jetstrike-cd'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scan_conflicts as $conflict): ?>
                <tr data-conflict-id="<?php echo (int) $conflict->id; ?>">
                    <td>
                        <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($conflict->severity); ?>">
                            <?php echo esc_html(ucfirst($conflict->severity)); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(str_replace('_', ' ', $conflict->conflict_type)); ?></td>
                    <td><?php echo esc_html($conflict->description); ?></td>
                    <td>
                        <code><?php echo esc_html(dirname($conflict->plugin_a)); ?></code>
                        <?php if ($conflict->plugin_b): ?>
                            <br>vs <code><?php echo esc_html(dirname($conflict->plugin_b)); ?></code>
                        <?php endif; ?>
                    </td>
                    <td><em><?php echo esc_html($conflict->recommendation ?? '—'); ?></em></td>
                    <td>
                        <select class="jetstrike-cd-conflict-action" data-conflict-id="<?php echo (int) $conflict->id; ?>">
                            <option value="active" <?php selected($conflict->status, 'active'); ?>><?php esc_html_e('Active', 'jetstrike-cd'); ?></option>
                            <option value="resolved" <?php selected($conflict->status, 'resolved'); ?>><?php esc_html_e('Resolved', 'jetstrike-cd'); ?></option>
                            <option value="ignored" <?php selected($conflict->status, 'ignored'); ?>><?php esc_html_e('Ignored', 'jetstrike-cd'); ?></option>
                            <option value="false_positive" <?php selected($conflict->status, 'false_positive'); ?>><?php esc_html_e('False Positive', 'jetstrike-cd'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="jetstrike-cd-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <p><?php esc_html_e('No conflicts found in this scan.', 'jetstrike-cd'); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Scan History List -->
    <div class="jetstrike-cd-card">
        <?php if (! empty($scans['items'])): ?>
        <table class="widefat striped jetstrike-cd-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Type', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Status', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Conflicts', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Triggered By', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Date', 'jetstrike-cd'); ?></th>
                    <th><?php esc_html_e('Actions', 'jetstrike-cd'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scans['items'] as $scan): ?>
                <tr>
                    <td>#<?php echo (int) $scan->id; ?></td>
                    <td><?php echo esc_html(ucfirst($scan->scan_type)); ?></td>
                    <td>
                        <span class="jetstrike-cd-badge jetstrike-cd-badge--<?php echo esc_attr($scan->status); ?>">
                            <?php echo esc_html(ucfirst($scan->status)); ?>
                        </span>
                    </td>
                    <td><?php echo (int) $scan->conflicts_found; ?></td>
                    <td><?php echo esc_html(ucfirst($scan->triggered_by)); ?></td>
                    <td><?php echo esc_html($scan->created_at); ?></td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=jetstrike-cd-results&scan_id=' . $scan->id)); ?>" class="button button-small">
                            <?php esc_html_e('View', 'jetstrike-cd'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Pagination.
        $total_pages = (int) ceil($scans['total'] / 20);

        if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe HTML.
                echo paginate_links([
                    'base'    => esc_url(add_query_arg('paged', '%#%')),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="jetstrike-cd-empty-state">
            <span class="dashicons dashicons-search"></span>
            <h3><?php esc_html_e('No Scans Yet', 'jetstrike-cd'); ?></h3>
            <p><?php esc_html_e('Run your first scan from the dashboard to see results here.', 'jetstrike-cd'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=jetstrike-cd')); ?>" class="button button-primary">
                <?php esc_html_e('Go to Dashboard', 'jetstrike-cd'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
