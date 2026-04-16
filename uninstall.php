<?php
/**
 * Uninstall handler — removes all plugin data.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Drop custom tables.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}jetstrike_conflicts");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}jetstrike_scans");

// Remove options.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jetstrike_cd_%'");

// Remove transients.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jetstrike_cd_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_jetstrike_cd_%'");

// Remove scheduled cron events.
$cron_hooks = [
    'jetstrike_cd_background_scan',
    'jetstrike_cd_health_check',
    'jetstrike_cd_cleanup',
];

foreach ($cron_hooks as $hook) {
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
    }
}

// Remove Auto-Fix mu-plugin patches.
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

// Remove the patch loader mu-plugin.
$loader = defined('WPMU_PLUGIN_DIR')
    ? WPMU_PLUGIN_DIR . '/jetstrike-patch-loader.php'
    : WP_CONTENT_DIR . '/mu-plugins/jetstrike-patch-loader.php';

if (file_exists($loader)) {
    wp_delete_file($loader);
}
