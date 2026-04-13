<?php
/**
 * Background monitor using WP-Cron for scheduled scanning.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Background_Monitor
 *
 * Schedules and manages background conflict detection tasks.
 */
class IPCD_Background_Monitor {

	/**
	 * Conflict detector engine.
	 *
	 * @var IPCD_Conflict_Detector
	 */
	private $detector;

	/**
	 * Sandbox tester.
	 *
	 * @var IPCD_Sandbox_Tester
	 */
	private $sandbox;

	/**
	 * Cron hook names.
	 */
	const CRON_FULL_SCAN    = 'ipcd_cron_full_scan';
	const CRON_QUICK_CHECK  = 'ipcd_cron_quick_check';
	const CRON_CLEANUP      = 'ipcd_cron_cleanup';

	/**
	 * Constructor.
	 *
	 * @param IPCD_Conflict_Detector $detector Conflict detector.
	 * @param IPCD_Sandbox_Tester    $sandbox  Sandbox tester.
	 */
	public function __construct( IPCD_Conflict_Detector $detector, IPCD_Sandbox_Tester $sandbox ) {
		$this->detector = $detector;
		$this->sandbox  = $sandbox;

		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		// Register cron callbacks.
		add_action( self::CRON_FULL_SCAN, array( $this, 'run_scheduled_full_scan' ) );
		add_action( self::CRON_QUICK_CHECK, array( $this, 'run_scheduled_quick_check' ) );
		add_action( self::CRON_CLEANUP, array( $this, 'run_scheduled_cleanup' ) );

		// Register custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Monitor plugin activation / deactivation.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );

		// Monitor plugin updates.
		add_action( 'upgrader_process_complete', array( $this, 'on_plugins_updated' ), 10, 2 );

		// AJAX handler for sandbox health checks.
		add_action( 'wp_ajax_nopriv_ipcd_sandbox_health_check', array( $this, 'handle_sandbox_health_check' ) );
		add_action( 'wp_ajax_ipcd_sandbox_health_check', array( $this, 'handle_sandbox_health_check' ) );
	}

	/**
	 * Add custom WP-Cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['ipcd_twice_daily'] = array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice Daily (IPCD)', 'intelligent-plugin-conflict-detector' ),
		);

		$schedules['ipcd_weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Weekly (IPCD)', 'intelligent-plugin-conflict-detector' ),
		);

		return $schedules;
	}

	/**
	 * Schedule cron events on activation.
	 */
	public function schedule_events() {
		$frequency = get_option( 'ipcd_scan_frequency', 'daily' );

		// Schedule full scan.
		if ( ! wp_next_scheduled( self::CRON_FULL_SCAN ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, self::CRON_FULL_SCAN );
		}

		// Schedule quick health check (twice daily).
		if ( ! wp_next_scheduled( self::CRON_QUICK_CHECK ) ) {
			wp_schedule_event( time() + 30 * MINUTE_IN_SECONDS, 'ipcd_twice_daily', self::CRON_QUICK_CHECK );
		}

		// Schedule cleanup (weekly).
		if ( ! wp_next_scheduled( self::CRON_CLEANUP ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'ipcd_weekly', self::CRON_CLEANUP );
		}
	}

	/**
	 * Remove scheduled cron events on deactivation.
	 */
	public function unschedule_events() {
		wp_clear_scheduled_hook( self::CRON_FULL_SCAN );
		wp_clear_scheduled_hook( self::CRON_QUICK_CHECK );
		wp_clear_scheduled_hook( self::CRON_CLEANUP );
	}

	/**
	 * Run the scheduled full scan.
	 */
	public function run_scheduled_full_scan() {
		if ( ! get_option( 'ipcd_monitoring_enabled', true ) ) {
			return;
		}

		$database = ipcd()->database;

		// Create a scan record.
		$scan_id = $database->insert_scan(
			array(
				'scan_type'     => 'scheduled_full',
				'status'        => 'running',
				'total_plugins' => count( get_option( 'active_plugins', array() ) ),
				'started_at'    => current_time( 'mysql' ),
			)
		);

		if ( ! $scan_id ) {
			return;
		}

		// Run static analysis.
		$static_conflicts = $this->detector->run_full_scan( $scan_id );

		// Run sandbox pairwise tests.
		$sandbox_results = $this->sandbox->run_pairwise_tests( $scan_id );

		$total_conflicts = count( $static_conflicts ) + $this->count_sandbox_failures( $sandbox_results );

		// Update scan record.
		$database->update_scan(
			$scan_id,
			array(
				'status'              => 'completed',
				'tested_combinations' => $sandbox_results['total_combinations'],
				'conflicts_found'     => $total_conflicts,
				'completed_at'        => current_time( 'mysql' ),
			)
		);

		// Send notification if conflicts were found.
		if ( $total_conflicts > 0 ) {
			$this->send_conflict_notification( $total_conflicts, $scan_id );
		}
	}

	/**
	 * Run the scheduled quick health check.
	 */
	public function run_scheduled_quick_check() {
		if ( ! get_option( 'ipcd_monitoring_enabled', true ) ) {
			return;
		}

		$health = $this->detector->quick_health_check();

		// If critical issues are found, trigger a full scan.
		$has_critical = false;

		if ( ! empty( $health['error_log'] ) ) {
			foreach ( $health['error_log'] as $error ) {
				if ( 'critical' === $error['type'] ) {
					$has_critical = true;
					break;
				}
			}
		}

		if ( ! empty( $health['known_conflicts'] ) ) {
			foreach ( $health['known_conflicts'] as $conflict ) {
				if ( 'high' === $conflict['severity'] || 'critical' === $conflict['severity'] ) {
					$has_critical = true;
					break;
				}
			}
		}

		if ( $has_critical ) {
			// Schedule an immediate full scan.
			if ( ! wp_next_scheduled( 'ipcd_immediate_scan' ) ) {
				wp_schedule_single_event( time(), 'ipcd_immediate_scan' );
				add_action( 'ipcd_immediate_scan', array( $this, 'run_scheduled_full_scan' ) );
			}
		}

		// Store the latest health check results.
		update_option( 'ipcd_last_health_check', $health );
		update_option( 'ipcd_last_health_check_time', current_time( 'mysql' ) );
	}

	/**
	 * Run scheduled cleanup of old data.
	 */
	public function run_scheduled_cleanup() {
		$database = ipcd()->database;
		$database->cleanup_old_conflicts( 90 );
	}

	/**
	 * Handle when a plugin is activated — trigger a quick check.
	 *
	 * @param string $plugin      Plugin file.
	 * @param bool   $network_wide Network activation flag.
	 */
	public function on_plugin_activated( $plugin, $network_wide ) {
		// Skip if it's this plugin being activated.
		if ( IPCD_PLUGIN_BASENAME === $plugin ) {
			return;
		}

		// Run a quick conflict check for the newly activated plugin.
		$this->schedule_plugin_check( $plugin, 'activation' );
	}

	/**
	 * Handle when a plugin is deactivated — mark related conflicts as potentially resolved.
	 *
	 * @param string $plugin      Plugin file.
	 * @param bool   $network_wide Network deactivation flag.
	 */
	public function on_plugin_deactivated( $plugin, $network_wide ) {
		$slug = dirname( $plugin );

		// Auto-resolve conflicts related to this plugin.
		$conflicts = ipcd()->database->get_conflicts(
			array(
				'plugin_slug' => $slug,
				'resolved'    => 0,
				'per_page'    => 100,
			)
		);

		foreach ( $conflicts as $conflict ) {
			ipcd()->database->resolve_conflict( $conflict->id );
		}
	}

	/**
	 * Handle plugin updates — schedule a conflict check.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Extra hook data.
	 */
	public function on_plugins_updated( $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		// Schedule a scan after plugins are updated.
		if ( ! wp_next_scheduled( 'ipcd_post_update_scan' ) ) {
			wp_schedule_single_event( time() + 60, 'ipcd_post_update_scan' );
			add_action( 'ipcd_post_update_scan', array( $this, 'run_scheduled_full_scan' ) );
		}
	}

	/**
	 * Handle the sandbox health check AJAX request.
	 */
	public function handle_sandbox_health_check() {
		wp_send_json_success(
			array(
				'status'  => 'ok',
				'time'    => current_time( 'mysql' ),
				'memory'  => memory_get_peak_usage( true ),
			)
		);
	}

	/**
	 * Schedule a targeted check for a specific plugin.
	 *
	 * @param string $plugin Plugin file path.
	 * @param string $reason Reason for the check.
	 */
	private function schedule_plugin_check( $plugin, $reason ) {
		$hook = 'ipcd_check_plugin_' . sanitize_title( dirname( $plugin ) );

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + 30, $hook );
			add_action(
				$hook,
				function () use ( $plugin, $reason ) {
					$this->run_plugin_check( $plugin, $reason );
				}
			);
		}
	}

	/**
	 * Run a targeted conflict check for a specific plugin.
	 *
	 * @param string $plugin Plugin file path.
	 * @param string $reason Reason for the check.
	 */
	private function run_plugin_check( $plugin, $reason ) {
		$database = ipcd()->database;

		$scan_id = $database->insert_scan(
			array(
				'scan_type'     => 'plugin_' . $reason,
				'status'        => 'running',
				'total_plugins' => 1,
				'started_at'    => current_time( 'mysql' ),
			)
		);

		if ( ! $scan_id ) {
			return;
		}

		// Run isolation test for this specific plugin.
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $active_plugins as $other_plugin ) {
			if ( $other_plugin === $plugin || $other_plugin === IPCD_PLUGIN_BASENAME ) {
				continue;
			}

			$result = $this->sandbox->test_plugin_combination(
				array( $plugin, $other_plugin, IPCD_PLUGIN_BASENAME ),
				$scan_id
			);

			if ( $this->is_approaching_time_limit() ) {
				break;
			}
		}

		$conflicts_found = count(
			$database->get_conflicts(
				array(
					'plugin_slug' => dirname( $plugin ),
					'resolved'    => 0,
					'per_page'    => 100,
				)
			)
		);

		$database->update_scan(
			$scan_id,
			array(
				'status'          => 'completed',
				'conflicts_found' => $conflicts_found,
				'completed_at'    => current_time( 'mysql' ),
			)
		);

		if ( $conflicts_found > 0 ) {
			$this->send_conflict_notification( $conflicts_found, $scan_id );
		}
	}

	/**
	 * Send email notification about detected conflicts.
	 *
	 * @param int $conflict_count Number of conflicts found.
	 * @param int $scan_id        Scan ID.
	 */
	private function send_conflict_notification( $conflict_count, $scan_id ) {
		if ( ! get_option( 'ipcd_email_notifications', true ) ) {
			return;
		}

		$to = get_option( 'ipcd_notification_email', get_option( 'admin_email' ) );

		if ( empty( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: number of conflicts, 2: site name */
			__( '[%2$s] Plugin Conflict Detector: %1$d conflict(s) found', 'intelligent-plugin-conflict-detector' ),
			$conflict_count,
			get_bloginfo( 'name' )
		);

		$admin_url = admin_url( 'admin.php?page=ipcd-conflicts&scan_id=' . $scan_id );

		$message = sprintf(
			/* translators: 1: number of conflicts */
			__( 'The Intelligent Plugin Conflict Detector found %d potential conflict(s) on your site.', 'intelligent-plugin-conflict-detector' ),
			$conflict_count
		) . "\n\n";

		$message .= __( 'Please review the conflicts in your WordPress dashboard:', 'intelligent-plugin-conflict-detector' ) . "\n";
		$message .= $admin_url . "\n\n";
		$message .= __( 'It is recommended to address high and critical severity conflicts as soon as possible to prevent site issues.', 'intelligent-plugin-conflict-detector' ) . "\n";

		wp_mail( $to, $subject, $message );
	}

	/**
	 * Count failures in sandbox results.
	 *
	 * @param array $sandbox_results Results from pairwise tests.
	 * @return int
	 */
	private function count_sandbox_failures( $sandbox_results ) {
		$count = 0;

		if ( isset( $sandbox_results['results'] ) ) {
			foreach ( $sandbox_results['results'] as $result ) {
				if ( ! $result['success'] ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Check if we're approaching the PHP time limit.
	 *
	 * @return bool
	 */
	private function is_approaching_time_limit() {
		$max_time = (int) ini_get( 'max_execution_time' );
		if ( 0 === $max_time ) {
			return false;
		}

		$elapsed = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
		return $elapsed > ( $max_time - 30 );
	}
}
