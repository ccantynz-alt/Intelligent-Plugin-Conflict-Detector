<?php
/**
 * Update watcher — detects plugin updates and triggers pre-update scans.
 *
 * Monitors the WordPress update system and runs a conflict scan
 * when plugins are about to be updated, catching issues before
 * they break the live site.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Monitor;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanQueue;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class UpdateWatcher {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Register hooks for watching plugin updates.
     */
    public function register(): void {
        // After a plugin is updated.
        add_action('upgrader_process_complete', [$this, 'on_update_complete'], 10, 2);

        // When a new plugin is activated.
        add_action('activated_plugin', [$this, 'on_plugin_activated'], 10, 2);

        // When a plugin is deactivated.
        add_action('deactivated_plugin', [$this, 'on_plugin_deactivated'], 10, 2);

        // Track available updates for the dashboard.
        add_action('set_site_transient_update_plugins', [$this, 'on_update_check']);
    }

    /**
     * Handle plugin update completion.
     *
     * @param \WP_Upgrader $upgrader Upgrader instance.
     * @param array        $options  Update options.
     */
    public function on_update_complete(\WP_Upgrader $upgrader, array $options): void {
        if (! FeatureFlags::can('pre_update_scan')) {
            return;
        }

        if (($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'update') {
            return;
        }

        $updated_plugins = $options['plugins'] ?? [];

        if (empty($updated_plugins)) {
            return;
        }

        // Log which plugins were updated.
        $update_log = get_option('jetstrike_cd_update_log', []);
        $update_log[] = [
            'plugins'    => $updated_plugins,
            'updated_at' => current_time('mysql', true),
        ];

        // Keep only last 50 entries.
        $update_log = array_slice($update_log, -50);
        update_option('jetstrike_cd_update_log', $update_log);

        // Trigger a targeted scan for each updated plugin.
        if (! $this->repository->has_running_scan()) {
            $queue = new ScanQueue($this->repository);

            foreach ($updated_plugins as $plugin_file) {
                if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                    continue;
                }

                $queue->enqueue('targeted', 'update_watcher', [
                    'target_plugin' => $plugin_file,
                    'trigger'       => 'plugin_updated',
                ]);

                break; // One scan at a time.
            }
        }
    }

    /**
     * Handle new plugin activation.
     *
     * @param string $plugin Plugin file path.
     * @param bool   $network_wide Whether network-wide activation.
     */
    public function on_plugin_activated(string $plugin, bool $network_wide): void {
        if ($plugin === JETSTRIKE_CD_BASENAME) {
            return;
        }

        if (! FeatureFlags::can('auto_background_scan')) {
            return;
        }

        // Don't scan during bulk activations.
        if (defined('JETSTRIKE_CD_BULK_OPERATION')) {
            return;
        }

        // Schedule a targeted scan for the newly activated plugin.
        if (! $this->repository->has_running_scan()) {
            $queue = new ScanQueue($this->repository);
            $queue->enqueue('targeted', 'update_watcher', [
                'target_plugin' => $plugin,
                'trigger'       => 'plugin_activated',
            ]);
        }

        // Log activation event.
        $this->log_event('activated', $plugin);
    }

    /**
     * Handle plugin deactivation — clear any active conflicts involving this plugin.
     *
     * @param string $plugin Plugin file path.
     * @param bool   $network_wide Whether network-wide deactivation.
     */
    public function on_plugin_deactivated(string $plugin, bool $network_wide): void {
        if ($plugin === JETSTRIKE_CD_BASENAME) {
            return;
        }

        // Mark conflicts involving this plugin as resolved.
        global $wpdb;
        $table = $wpdb->prefix . 'jetstrike_conflicts';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, resolved_at = %s
                WHERE status = %s AND (plugin_a = %s OR plugin_b = %s)",
                'resolved',
                current_time('mysql', true),
                'active',
                $plugin,
                $plugin
            )
        );

        // Clear cached conflict data.
        delete_transient('jetstrike_cd_conflict_summary');

        $this->log_event('deactivated', $plugin);
    }

    /**
     * Track available updates for dashboard display.
     *
     * @param object $transient Update transient data.
     */
    public function on_update_check(object $transient): void {
        if (empty($transient->response)) {
            return;
        }

        $pending_updates = [];

        foreach ($transient->response as $plugin_file => $update_data) {
            $pending_updates[$plugin_file] = [
                'new_version' => $update_data->new_version ?? 'unknown',
                'slug'        => $update_data->slug ?? '',
            ];
        }

        update_option('jetstrike_cd_pending_updates', $pending_updates);
    }

    /**
     * Log a plugin event.
     */
    private function log_event(string $event, string $plugin): void {
        $log = get_option('jetstrike_cd_event_log', []);
        $log[] = [
            'event'   => $event,
            'plugin'  => $plugin,
            'time'    => current_time('mysql', true),
        ];

        // Keep last 100 events.
        $log = array_slice($log, -100);
        update_option('jetstrike_cd_event_log', $log);
    }
}
