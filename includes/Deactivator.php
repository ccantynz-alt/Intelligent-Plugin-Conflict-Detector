<?php
/**
 * Plugin deactivation handler.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector;

final class Deactivator {

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void {
        // Clear all scheduled cron events.
        $hooks = [
            'jetstrike_cd_background_scan',
            'jetstrike_cd_health_check',
            'jetstrike_cd_cleanup',
        ];

        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }

        // Clear transients.
        delete_transient('jetstrike_cd_scan_results');
        delete_transient('jetstrike_cd_conflict_summary');
        delete_transient('jetstrike_cd_plugin_scores');

        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
