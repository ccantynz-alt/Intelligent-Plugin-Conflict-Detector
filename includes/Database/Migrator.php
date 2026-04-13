<?php
/**
 * Database schema migrations.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Database;

final class Migrator {

    /**
     * Run database migrations.
     */
    public static function migrate(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $scans_table = $wpdb->prefix . 'jetstrike_scans';
        $conflicts_table = $wpdb->prefix . 'jetstrike_conflicts';

        $sql_scans = "CREATE TABLE {$scans_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_type VARCHAR(50) NOT NULL DEFAULT 'full',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            plugins_tested LONGTEXT DEFAULT NULL,
            conflicts_found INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            results LONGTEXT DEFAULT NULL,
            triggered_by VARCHAR(50) NOT NULL DEFAULT 'manual',
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_scan_type (scan_type),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        $sql_conflicts = "CREATE TABLE {$conflicts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_id BIGINT UNSIGNED NOT NULL,
            plugin_a VARCHAR(255) NOT NULL,
            plugin_b VARCHAR(255) NOT NULL,
            conflict_type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'medium',
            description TEXT NOT NULL,
            technical_details LONGTEXT DEFAULT NULL,
            recommendation TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_scan_id (scan_id),
            KEY idx_severity (severity),
            KEY idx_status (status),
            KEY idx_plugin_a (plugin_a(191)),
            KEY idx_plugin_b (plugin_b(191)),
            KEY idx_conflict_type (conflict_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($sql_scans);
        dbDelta($sql_conflicts);

        update_option('jetstrike_cd_db_version', JETSTRIKE_CD_DB_VERSION);
    }

    /**
     * Check if migration is needed.
     */
    public static function needs_migration(): bool {
        $installed_version = get_option('jetstrike_cd_db_version', '0.0.0');
        return version_compare($installed_version, JETSTRIKE_CD_DB_VERSION, '<');
    }
}
