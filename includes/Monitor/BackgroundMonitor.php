<?php
/**
 * Background monitor — schedules and runs automated scans via WP-Cron.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Monitor;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Notification\NotificationManager;
use Jetstrike\ConflictDetector\Scanner\ScanQueue;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class BackgroundMonitor {

    /** @var Repository */
    private Repository $repository;

    /** @var NotificationManager */
    private NotificationManager $notifications;

    public function __construct(Repository $repository, NotificationManager $notifications) {
        $this->repository = $repository;
        $this->notifications = $notifications;
    }

    /**
     * Register WP-Cron hooks.
     */
    public function register(): void {
        // Background scan cron handler.
        add_action('jetstrike_cd_background_scan', [$this, 'run_scheduled_scan']);

        // Scan processing handler.
        add_action('jetstrike_cd_process_scan', [$this, 'process_scan']);

        // Cleanup old data.
        add_action('jetstrike_cd_cleanup', [$this, 'cleanup']);
    }

    /**
     * Run a scheduled background scan.
     */
    public function run_scheduled_scan(): void {
        // Only Pro+ users get automated background scans.
        if (! FeatureFlags::can('auto_background_scan')) {
            return;
        }

        // Check if auto-scan is enabled in settings.
        $settings = get_option('jetstrike_cd_settings', []);

        if (empty($settings['auto_scan_enabled'])) {
            return;
        }

        // Don't run if a scan is already in progress.
        if ($this->repository->has_running_scan()) {
            return;
        }

        // Enqueue a quick scan (static analysis — fast and safe for background).
        $queue = new ScanQueue($this->repository);
        $scan_id = $queue->enqueue('quick', 'scheduled');

        if ($scan_id > 0) {
            // Log that we started an automated scan.
            update_option('jetstrike_cd_last_auto_scan', [
                'scan_id'    => $scan_id,
                'started_at' => current_time('mysql', true),
            ]);
        }
    }

    /**
     * Process a queued scan batch.
     *
     * @param int $scan_id Scan to process.
     */
    public function process_scan(int $scan_id): void {
        $queue = new ScanQueue($this->repository);
        $is_complete = $queue->process_batch($scan_id);

        if ($is_complete) {
            $this->on_scan_complete($scan_id);
        }
    }

    /**
     * Handle scan completion — send notifications if conflicts found.
     *
     * @param int $scan_id Completed scan ID.
     */
    private function on_scan_complete(int $scan_id): void {
        $scan = $this->repository->get_scan($scan_id);

        if ($scan === null || $scan->status !== 'completed') {
            return;
        }

        $conflict_count = (int) $scan->conflicts_found;

        if ($conflict_count === 0) {
            return;
        }

        // Get the conflicts for the notification.
        $conflicts = $this->repository->get_conflicts_for_scan($scan_id);
        $critical_count = 0;

        foreach ($conflicts as $conflict) {
            if ($conflict->severity === 'critical') {
                $critical_count++;
            }
        }

        // Send notification.
        $this->notifications->notify_scan_complete($scan_id, $conflict_count, $critical_count, $conflicts);
    }

    /**
     * Clean up old scan data.
     */
    public function cleanup(): void {
        $limit = FeatureFlags::scan_history_limit();

        if ($limit > 0) {
            $this->repository->purge_old_scans($limit);
        }
    }
}
