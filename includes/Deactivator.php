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

        // Remove Auto-Fix mu-plugin patches so they don't run
        // while the plugin is deactivated.
        $patch_dir = defined('WPMU_PLUGIN_DIR')
            ? WPMU_PLUGIN_DIR . '/jetstrike-patches'
            : WP_CONTENT_DIR . '/mu-plugins/jetstrike-patches';

        if (is_dir($patch_dir)) {
            $files = glob($patch_dir . '/*.php');
            if (is_array($files)) {
                foreach ($files as $file) {
                    wp_delete_file($file);
                }
            }
            @rmdir($patch_dir);
        }

        $loader = defined('WPMU_PLUGIN_DIR')
            ? WPMU_PLUGIN_DIR . '/jetstrike-patch-loader.php'
            : WP_CONTENT_DIR . '/mu-plugins/jetstrike-patch-loader.php';

        if (file_exists($loader)) {
            wp_delete_file($loader);
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }
}
