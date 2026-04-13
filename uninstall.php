<?php
/**
 * Uninstall handler — runs when the plugin is deleted via the WordPress admin.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
$options = array(
	'ipcd_monitoring_enabled',
	'ipcd_scan_frequency',
	'ipcd_error_threshold',
	'ipcd_email_notifications',
	'ipcd_notification_email',
	'ipcd_db_version',
	'ipcd_last_health_check',
	'ipcd_last_health_check_time',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Drop custom database tables.
global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ipcd_conflicts" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ipcd_scans" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching

// Clear any leftover transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ipcd_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ipcd_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'ipcd_cron_full_scan' );
wp_clear_scheduled_hook( 'ipcd_cron_quick_check' );
wp_clear_scheduled_hook( 'ipcd_cron_cleanup' );
