<?php
/**
 * Database handler for storing conflict detection results.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Database
 *
 * Manages the custom database tables for conflict logs and scan results.
 */
class IPCD_Database {

	/**
	 * Conflict log table name (without prefix).
	 *
	 * @var string
	 */
	const CONFLICTS_TABLE = 'ipcd_conflicts';

	/**
	 * Scan history table name (without prefix).
	 *
	 * @var string
	 */
	const SCANS_TABLE = 'ipcd_scans';

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @param string $table Short table name constant.
	 * @return string
	 */
	public function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Create the plugin database tables.
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$conflicts_table = $this->get_table_name( self::CONFLICTS_TABLE );
		$scans_table     = $this->get_table_name( self::SCANS_TABLE );

		$sql_conflicts = "CREATE TABLE {$conflicts_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plugin_slug varchar(255) NOT NULL,
			conflicting_plugin_slug varchar(255) NOT NULL,
			conflict_type varchar(50) NOT NULL DEFAULT 'error',
			severity varchar(20) NOT NULL DEFAULT 'medium',
			error_message text NOT NULL,
			error_code varchar(50) DEFAULT NULL,
			file_reference varchar(500) DEFAULT NULL,
			line_number int(11) DEFAULT NULL,
			scan_id bigint(20) unsigned DEFAULT NULL,
			resolved tinyint(1) NOT NULL DEFAULT 0,
			resolved_at datetime DEFAULT NULL,
			detected_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY plugin_slug (plugin_slug),
			KEY conflicting_plugin_slug (conflicting_plugin_slug),
			KEY severity (severity),
			KEY resolved (resolved),
			KEY scan_id (scan_id),
			KEY detected_at (detected_at)
		) {$charset_collate};";

		$sql_scans = "CREATE TABLE {$scans_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_type varchar(50) NOT NULL DEFAULT 'full',
			status varchar(20) NOT NULL DEFAULT 'pending',
			total_plugins int(11) NOT NULL DEFAULT 0,
			tested_combinations int(11) NOT NULL DEFAULT 0,
			conflicts_found int(11) NOT NULL DEFAULT 0,
			errors_log longtext DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY scan_type (scan_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_conflicts );
		dbDelta( $sql_scans );

		update_option( 'ipcd_db_version', IPCD_VERSION );
	}

	/**
	 * Insert a new conflict record.
	 *
	 * @param array $data Conflict data.
	 * @return int|false The conflict ID or false on failure.
	 */
	public function insert_conflict( $data ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		$defaults = array(
			'conflict_type' => 'error',
			'severity'      => 'medium',
			'resolved'      => 0,
			'detected_at'   => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$table,
			$data,
			$this->get_column_formats( $data )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Insert a new scan record.
	 *
	 * @param array $data Scan data.
	 * @return int|false The scan ID or false on failure.
	 */
	public function insert_scan( $data ) {
		global $wpdb;

		$table = $this->get_table_name( self::SCANS_TABLE );

		$defaults = array(
			'scan_type'  => 'full',
			'status'     => 'pending',
			'created_at' => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$table,
			$data,
			$this->get_column_formats( $data )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a scan record.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $data    Data to update.
	 * @return bool
	 */
	public function update_scan( $scan_id, $data ) {
		global $wpdb;

		$table = $this->get_table_name( self::SCANS_TABLE );

		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => $scan_id ),
			$this->get_column_formats( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get conflicts with optional filtering.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_conflicts( $args = array() ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		$defaults = array(
			'per_page'    => 20,
			'page'        => 1,
			'severity'    => '',
			'resolved'    => '',
			'plugin_slug' => '',
			'orderby'     => 'detected_at',
			'order'       => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where_clauses = array();
		$where_values  = array();

		if ( '' !== $args['severity'] ) {
			$where_clauses[] = 'severity = %s';
			$where_values[]  = $args['severity'];
		}

		if ( '' !== $args['resolved'] ) {
			$where_clauses[] = 'resolved = %d';
			$where_values[]  = (int) $args['resolved'];
		}

		if ( '' !== $args['plugin_slug'] ) {
			$where_clauses[] = '(plugin_slug = %s OR conflicting_plugin_slug = %s)';
			$where_values[]  = $args['plugin_slug'];
			$where_values[]  = $args['plugin_slug'];
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$allowed_orderby = array( 'detected_at', 'severity', 'plugin_slug', 'conflict_type' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'detected_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$query_values   = array_merge( $where_values, array( $per_page, $offset ) );
		$prepared_query = $wpdb->prepare( $query, $query_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_results( $prepared_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a single conflict by ID.
	 *
	 * @param int $conflict_id Conflict ID.
	 * @return object|null
	 */
	public function get_conflict( $conflict_id ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $conflict_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Resolve a conflict.
	 *
	 * @param int $conflict_id Conflict ID.
	 * @return bool
	 */
	public function resolve_conflict( $conflict_id ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		$result = $wpdb->update(
			$table,
			array(
				'resolved'    => 1,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $conflict_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get scans with optional filtering.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_scans( $args = array() ) {
		global $wpdb;

		$table = $this->get_table_name( self::SCANS_TABLE );

		$defaults = array(
			'per_page' => 10,
			'page'     => 1,
			'status'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where_sql    = '';
		$where_values = array();

		if ( '' !== $args['status'] ) {
			$where_sql    = 'WHERE status = %s';
			$where_values = array( $args['status'] );
		}

		$allowed_orderby = array( 'created_at', 'status', 'conflicts_found' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$query = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$query_values   = array_merge( $where_values, array( $per_page, $offset ) );
		$prepared_query = $wpdb->prepare( $query, $query_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_results( $prepared_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a scan by ID.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	public function get_scan( $scan_id ) {
		global $wpdb;

		$table = $this->get_table_name( self::SCANS_TABLE );

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get conflict statistics for the dashboard.
	 *
	 * @return array
	 */
	public function get_conflict_stats() {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$resolved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$critical = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE severity = 'critical' AND resolved = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$high     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE severity = 'high' AND resolved = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'    => $total,
			'active'   => $active,
			'resolved' => $resolved,
			'critical' => $critical,
			'high'     => $high,
		);
	}

	/**
	 * Delete old resolved conflicts (cleanup).
	 *
	 * @param int $days_old Number of days to keep resolved conflicts.
	 * @return int Number of rows deleted.
	 */
	public function cleanup_old_conflicts( $days_old = 90 ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE resolved = 1 AND resolved_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days_old
			)
		);
	}

	/**
	 * Check if a conflict already exists (to prevent duplicates).
	 *
	 * @param string $plugin_slug             Plugin slug.
	 * @param string $conflicting_plugin_slug Conflicting plugin slug.
	 * @param string $error_message           Error message.
	 * @return bool
	 */
	public function conflict_exists( $plugin_slug, $conflicting_plugin_slug, $error_message ) {
		global $wpdb;

		$table = $this->get_table_name( self::CONFLICTS_TABLE );

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE plugin_slug = %s AND conflicting_plugin_slug = %s AND error_message = %s AND resolved = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$plugin_slug,
				$conflicting_plugin_slug,
				$error_message
			)
		);

		return (int) $result > 0;
	}

	/**
	 * Drop plugin tables on uninstall.
	 */
	public static function drop_tables() {
		global $wpdb;

		$prefix = $wpdb->prefix;

		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}" . self::CONFLICTS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}" . self::SCANS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		delete_option( 'ipcd_db_version' );
	}

	/**
	 * Get column format strings for wpdb insert/update.
	 *
	 * @param array $data Data array.
	 * @return array Format strings.
	 */
	private function get_column_formats( $data ) {
		$formats = array();

		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
