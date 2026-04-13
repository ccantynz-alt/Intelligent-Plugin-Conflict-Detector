<?php
/**
 * Admin AJAX handlers for scan operations.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Admin;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanQueue;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class AdminAjax {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Register AJAX action hooks.
     */
    public function register(): void {
        add_action('wp_ajax_jetstrike_cd_start_scan', [$this, 'start_scan']);
        add_action('wp_ajax_jetstrike_cd_scan_status', [$this, 'scan_status']);
        add_action('wp_ajax_jetstrike_cd_cancel_scan', [$this, 'cancel_scan']);
        add_action('wp_ajax_jetstrike_cd_update_conflict', [$this, 'update_conflict_status']);
        add_action('wp_ajax_jetstrike_cd_activate_license', [$this, 'activate_license']);
        add_action('wp_ajax_jetstrike_cd_deactivate_license', [$this, 'deactivate_license']);
    }

    /**
     * Start a new scan via AJAX.
     */
    public function start_scan(): void {
        $this->verify_request();

        $scan_type = sanitize_text_field($_POST['scan_type'] ?? 'quick');

        // Feature gating.
        if ($scan_type === 'full' && ! FeatureFlags::can('unlimited_full_scan')) {
            $limit = FeatureFlags::weekly_scan_limit();

            if ($limit > 0) {
                global $wpdb;
                $table = $wpdb->prefix . 'jetstrike_scans';
                $week_ago = gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS);

                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE scan_type = 'full' AND triggered_by = 'manual' AND created_at >= %s",
                    $week_ago
                ));

                if ($count >= $limit) {
                    wp_send_json_error([
                        'message' => sprintf(
                            __('Free plan allows %d full scan per week. Upgrade to Pro for unlimited scans.', 'jetstrike-cd'),
                            $limit
                        ),
                    ]);
                }
            }
        }

        $options = [];

        if ($scan_type === 'targeted') {
            $target = sanitize_text_field($_POST['target_plugin'] ?? '');

            if (empty($target)) {
                wp_send_json_error(['message' => __('No target plugin specified.', 'jetstrike-cd')]);
            }

            $options['target_plugin'] = $target;
        }

        $queue = new ScanQueue($this->repository);
        $scan_id = $queue->enqueue($scan_type, 'manual', $options);

        // Trigger immediate processing.
        $queue->process_batch($scan_id);

        $scan = $this->repository->get_scan($scan_id);

        wp_send_json_success([
            'scan_id' => $scan_id,
            'status'  => $scan->status ?? 'queued',
        ]);
    }

    /**
     * Get scan status for polling.
     */
    public function scan_status(): void {
        $this->verify_request();

        $scan_id = (int) ($_POST['scan_id'] ?? 0);

        if ($scan_id <= 0) {
            wp_send_json_error(['message' => __('Invalid scan ID.', 'jetstrike-cd')]);
        }

        $scan = $this->repository->get_scan($scan_id);

        if ($scan === null) {
            wp_send_json_error(['message' => __('Scan not found.', 'jetstrike-cd')]);
        }

        $data = [
            'scan_id'         => (int) $scan->id,
            'status'          => $scan->status,
            'conflicts_found' => (int) $scan->conflicts_found,
            'started_at'      => $scan->started_at,
            'completed_at'    => $scan->completed_at,
        ];

        if ($scan->status === 'completed') {
            $conflicts = $this->repository->get_conflicts_for_scan($scan_id);
            $data['conflicts'] = array_map(function ($c) {
                return [
                    'id'             => (int) $c->id,
                    'plugin_a'       => $c->plugin_a,
                    'plugin_b'       => $c->plugin_b,
                    'conflict_type'  => $c->conflict_type,
                    'severity'       => $c->severity,
                    'description'    => $c->description,
                    'recommendation' => $c->recommendation,
                ];
            }, $conflicts);
        }

        wp_send_json_success($data);
    }

    /**
     * Cancel a running scan.
     */
    public function cancel_scan(): void {
        $this->verify_request();

        $scan_id = (int) ($_POST['scan_id'] ?? 0);
        $queue = new ScanQueue($this->repository);
        $queue->cancel($scan_id);

        wp_send_json_success(['cancelled' => true]);
    }

    /**
     * Update a conflict's status.
     */
    public function update_conflict_status(): void {
        $this->verify_request();

        $conflict_id = (int) ($_POST['conflict_id'] ?? 0);
        $new_status = sanitize_text_field($_POST['status'] ?? '');

        $allowed = ['active', 'resolved', 'ignored', 'false_positive'];

        if (! in_array($new_status, $allowed, true)) {
            wp_send_json_error(['message' => __('Invalid status.', 'jetstrike-cd')]);
        }

        $update_data = ['status' => $new_status];

        if ($new_status === 'resolved') {
            $update_data['resolved_at'] = current_time('mysql', true);
        }

        $this->repository->update_conflict($conflict_id, $update_data);

        delete_transient('jetstrike_cd_conflict_summary');

        wp_send_json_success(['updated' => true]);
    }

    /**
     * Activate a license key.
     */
    public function activate_license(): void {
        $this->verify_request();

        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        $license = new \Jetstrike\ConflictDetector\Subscription\LicenseManager();
        $result = $license->activate($license_key);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Deactivate the current license.
     */
    public function deactivate_license(): void {
        $this->verify_request();

        $license = new \Jetstrike\ConflictDetector\Subscription\LicenseManager();
        $license->deactivate();

        wp_send_json_success(['deactivated' => true]);
    }

    /**
     * Verify AJAX request (nonce + capability).
     */
    private function verify_request(): void {
        check_ajax_referer('jetstrike_cd_ajax', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'jetstrike-cd')], 403);
        }
    }
}
