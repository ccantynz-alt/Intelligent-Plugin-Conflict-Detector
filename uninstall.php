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
