<?php
/**
 * Plugin State Manager
 *
 * Captures and restores snapshots of the active-plugin list and relevant
 * WordPress/WooCommerce option values so the Rollback Manager can restore
 * any saved state.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Plugin_State_Manager
 */
class IPCD_Plugin_State_Manager {

	/** Database table name (without prefix). */
	const TABLE_NAME = 'ipcd_snapshots';

	/**
	 * Create the custom DB table on activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_id VARCHAR(191)        NOT NULL,
			label       VARCHAR(255)        NOT NULL DEFAULT '',
			active_plugins LONGTEXT         NOT NULL,
			options_data   LONGTEXT         NOT NULL,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY snapshot_id (snapshot_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Capture a snapshot of the current plugin/option state.
	 *
	 * @param string $label   Human-readable label (e.g. "before_activate_woocommerce").
	 * @param array  $extra_option_keys  Additional option keys to snapshot beyond the defaults.
	 *
	 * @return string  The unique snapshot ID.
	 */
	public function capture_snapshot( string $label = '', array $extra_option_keys = array() ): string {
		global $wpdb;

		$snapshot_id    = uniqid( 'ipcd_', true );
		$active_plugins = get_option( 'active_plugins', array() );

		$default_option_keys = array(
			'siteurl',
			'blogname',
			'active_plugins',
			'template',
			'stylesheet',
			'woocommerce_version',
			'db_version',
		);

		$options_data = array();
		foreach ( array_unique( array_merge( $default_option_keys, $extra_option_keys ) ) as $key ) {
			$options_data[ $key ] = get_option( $key );
		}

		$table = $wpdb->prefix . self::TABLE_NAME;

		$wpdb->insert(
			$table,
			array(
				'snapshot_id'    => $snapshot_id,
				'label'          => sanitize_text_field( $label ),
				'active_plugins' => wp_json_encode( $active_plugins ),
				'options_data'   => wp_json_encode( $options_data ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return $snapshot_id;
	}

	/**
	 * Return all stored snapshots, newest first.
	 *
	 * @return array
	 */
	public function get_snapshots(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

		return $rows ?: array();
	}

	/**
	 * Return a single snapshot by its ID.
	 *
	 * @param string $snapshot_id  Snapshot ID.
	 *
	 * @return array|null
	 */
	public function get_snapshot( string $snapshot_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE snapshot_id = %s", $snapshot_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Restore the active_plugins list from a snapshot.
	 * Does NOT update other options – only the plugin list.
	 *
	 * @param string $snapshot_id  Snapshot ID to restore.
	 *
	 * @return bool  True on success, false if snapshot not found.
	 */
	public function restore_snapshot( string $snapshot_id ): bool {
		$snapshot = $this->get_snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			return false;
		}

		$active_plugins = json_decode( $snapshot['active_plugins'], true );
		if ( ! is_array( $active_plugins ) ) {
			return false;
		}

		// Only keep plugins that still exist on disk.
		$valid_plugins = array_filter( $active_plugins, static function ( $plugin ) {
			return file_exists( WP_PLUGIN_DIR . '/' . $plugin );
		} );

		update_option( 'active_plugins', array_values( $valid_plugins ) );

		return true;
	}

	/**
	 * Delete a snapshot by ID.
	 *
	 * @param string $snapshot_id  Snapshot ID to delete.
	 *
	 * @return bool
	 */
	public function delete_snapshot( string $snapshot_id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_NAME;
		$result = $wpdb->delete( $table, array( 'snapshot_id' => $snapshot_id ), array( '%s' ) );

		return (bool) $result;
	}

	/**
	 * Prune snapshots older than a given number of days.
	 *
	 * @param int $days  Number of days to keep.
	 *
	 * @return int  Number of rows deleted.
	 */
	public function prune_old_snapshots( int $days = 30 ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
