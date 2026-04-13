<?php
/**
 * Notification orchestrator — routes alerts to email, Slack, and other channels.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Notification;

use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class NotificationManager {

    /**
     * Send notification when a scan completes with conflicts.
     *
     * @param int   $scan_id        Scan ID.
     * @param int   $conflict_count Total conflicts found.
     * @param int   $critical_count Critical conflicts found.
     * @param array $conflicts      Conflict records.
     */
    public function notify_scan_complete(int $scan_id, int $conflict_count, int $critical_count, array $conflicts): void {
        $settings = get_option('jetstrike_cd_settings', []);

        // Email notifications (Pro+).
        if (FeatureFlags::can('email_alerts') && ! empty($settings['email_alerts'])) {
            $email = new EmailNotifier();
            $email->send_scan_results(
                $settings['alert_email'] ?? get_option('admin_email'),
                $scan_id,
                $conflict_count,
                $critical_count,
                $conflicts
            );
        }

        // Slack notifications (Agency).
        if (FeatureFlags::can('slack_integration') && ! empty($settings['slack_webhook_url'])) {
            $slack = new SlackNotifier($settings['slack_webhook_url']);
            $slack->send_scan_results($scan_id, $conflict_count, $critical_count, $conflicts);
        }

        // Store notification in the admin notice queue.
        $this->queue_admin_notice($conflict_count, $critical_count, $scan_id);
    }

    /**
     * Send notification for a new critical conflict.
     *
     * @param object $conflict Conflict record.
     */
    public function notify_critical_conflict(object $conflict): void {
        $settings = get_option('jetstrike_cd_settings', []);

        if (FeatureFlags::can('email_alerts') && ! empty($settings['email_alerts'])) {
            $email = new EmailNotifier();
            $email->send_critical_alert(
                $settings['alert_email'] ?? get_option('admin_email'),
                $conflict
            );
        }

        if (FeatureFlags::can('slack_integration') && ! empty($settings['slack_webhook_url'])) {
            $slack = new SlackNotifier($settings['slack_webhook_url']);
            $slack->send_critical_alert($conflict);
        }
    }

    /**
     * Queue an admin notice for display on next page load.
     */
    private function queue_admin_notice(int $conflict_count, int $critical_count, int $scan_id): void {
        $notices = get_option('jetstrike_cd_pending_notices', []);

        $notices[] = [
            'type'           => 'scan_complete',
            'conflict_count' => $conflict_count,
            'critical_count' => $critical_count,
            'scan_id'        => $scan_id,
            'created_at'     => current_time('mysql', true),
        ];

        // Keep only last 5 notices.
        $notices = array_slice($notices, -5);

        update_option('jetstrike_cd_pending_notices', $notices);
    }
}
