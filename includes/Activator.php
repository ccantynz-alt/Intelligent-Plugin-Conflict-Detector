<?php
/**
 * Plugin activation handler.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector;

use Jetstrike\ConflictDetector\Database\Migrator;

final class Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        // Check minimum requirements.
        self::check_requirements();

        // Create/update database tables.
        Migrator::migrate();

        // Set default options.
        self::set_defaults();

        // Schedule cron events.
        self::schedule_cron();

        // Flush rewrite rules for REST API.
        flush_rewrite_rules();
    }

    /**
     * Check minimum PHP and WordPress versions.
     */
    private static function check_requirements(): void {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(JETSTRIKE_CD_BASENAME);
            wp_die(
                esc_html__('Jetstrike Conflict Detector requires PHP 7.4 or higher.', 'jetstrike-cd'),
                esc_html__('Plugin Activation Error', 'jetstrike-cd'),
                ['back_link' => true]
            );
        }

        global $wp_version;
        if (version_compare($wp_version, '6.0', '<')) {
            deactivate_plugins(JETSTRIKE_CD_BASENAME);
            wp_die(
                esc_html__('Jetstrike Conflict Detector requires WordPress 6.0 or higher.', 'jetstrike-cd'),
                esc_html__('Plugin Activation Error', 'jetstrike-cd'),
                ['back_link' => true]
            );
        }
    }

    /**
     * Set default plugin options.
     */
    private static function set_defaults(): void {
        $defaults = [
            'jetstrike_cd_settings' => [
                'auto_scan_enabled'    => false,
                'scan_frequency'       => 'twice_daily',
                'email_alerts'         => true,
                'alert_email'          => get_option('admin_email'),
                'slack_webhook_url'    => '',
                'scan_timeout'         => 30,
                'max_pairs_per_batch'  => 5,
                'performance_threshold' => 3.0,
                'excluded_plugins'     => [],
            ],
            'jetstrike_cd_license_key'            => '',
            'jetstrike_cd_license_tier'           => 'free',
            'jetstrike_cd_autofix_beta_enabled'   => 'no',
            'jetstrike_cd_activated_at'           => current_time('mysql', true),
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Schedule WP-Cron events.
     */
    private static function schedule_cron(): void {
        if (! wp_next_scheduled('jetstrike_cd_background_scan')) {
            wp_schedule_event(time(), 'twicedaily', 'jetstrike_cd_background_scan');
        }

        if (! wp_next_scheduled('jetstrike_cd_health_check')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'jetstrike_cd_health_check');
        }

        if (! wp_next_scheduled('jetstrike_cd_cleanup')) {
            wp_schedule_event(time() + (2 * HOUR_IN_SECONDS), 'weekly', 'jetstrike_cd_cleanup');
        }
    }
}
