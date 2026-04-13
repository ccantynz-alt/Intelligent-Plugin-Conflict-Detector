<?php
/**
 * WordPress admin notices for scan results and alerts.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Admin;

use Jetstrike\ConflictDetector\Database\Repository;

final class AdminNotices {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action('admin_notices', [$this, 'display_notices']);
        add_action('wp_ajax_jetstrike_cd_dismiss_notice', [$this, 'dismiss_notice']);
    }

    /**
     * Display pending admin notices.
     */
    public function display_notices(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Show critical conflict warning on all admin pages.
        $this->show_critical_warning();

        // Show queued notices.
        $this->show_pending_notices();
    }

    /**
     * Show persistent warning if critical conflicts exist.
     */
    private function show_critical_warning(): void {
        $summary = $this->repository->get_conflict_summary();

        if ($summary['critical'] > 0) {
            $dashboard_url = admin_url('admin.php?page=jetstrike-cd');

            printf(
                '<div class="notice notice-error jetstrike-cd-notice" data-notice-id="critical">
                    <p><strong>%s</strong> %s <a href="%s">%s</a></p>
                </div>',
                esc_html__('Jetstrike Conflict Detector:', 'jetstrike-cd'),
                esc_html(sprintf(
                    /* translators: %d: number of critical conflicts */
                    _n(
                        '%d critical plugin conflict detected that may cause fatal errors.',
                        '%d critical plugin conflicts detected that may cause fatal errors.',
                        $summary['critical'],
                        'jetstrike-cd'
                    ),
                    $summary['critical']
                )),
                esc_url($dashboard_url),
                esc_html__('View Details', 'jetstrike-cd')
            );
        }
    }

    /**
     * Show one-time queued notices (scan complete, etc.).
     */
    private function show_pending_notices(): void {
        $notices = get_option('jetstrike_cd_pending_notices', []);

        if (empty($notices)) {
            return;
        }

        foreach ($notices as $notice) {
            if ($notice['type'] === 'scan_complete') {
                $type = $notice['critical_count'] > 0 ? 'error' : ($notice['conflict_count'] > 0 ? 'warning' : 'success');

                printf(
                    '<div class="notice notice-%s is-dismissible jetstrike-cd-notice" data-notice-id="scan-%d">
                        <p><strong>%s</strong> %s <a href="%s">%s</a></p>
                    </div>',
                    esc_attr($type),
                    (int) $notice['scan_id'],
                    esc_html__('Jetstrike Scan Complete:', 'jetstrike-cd'),
                    esc_html(sprintf(
                        /* translators: %d: number of conflicts */
                        _n(
                            '%d plugin conflict found.',
                            '%d plugin conflicts found.',
                            $notice['conflict_count'],
                            'jetstrike-cd'
                        ),
                        $notice['conflict_count']
                    )),
                    esc_url(admin_url('admin.php?page=jetstrike-cd-results&scan_id=' . $notice['scan_id'])),
                    esc_html__('View Results', 'jetstrike-cd')
                );
            }
        }

        // Clear notices after display.
        delete_option('jetstrike_cd_pending_notices');
    }

    /**
     * Handle notice dismissal.
     */
    public function dismiss_notice(): void {
        check_ajax_referer('jetstrike_cd_ajax', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $notice_id = sanitize_text_field($_POST['notice_id'] ?? '');

        if ($notice_id === 'critical') {
            // Store dismissal timestamp — re-show after 24 hours.
            update_option('jetstrike_cd_critical_dismissed', time());
        }

        wp_send_json_success();
    }
}
