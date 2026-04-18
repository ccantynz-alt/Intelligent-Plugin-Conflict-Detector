<?php
/**
 * Background Tester
 *
 * Schedules and executes background test runs that probe the site for
 * conflicts introduced by a plugin activation or update.  Tests run via
 * WordPress Cron so they never block a real page request.
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IPCD_Background_Tester
 */
class IPCD_Background_Tester {

	/** Cron action name. */
	const CRON_HOOK = 'ipcd_background_test';

	/** Option key that stores the queue of pending tests. */
	const QUEUE_OPTION = 'ipcd_test_queue';

	/** @var IPCD_Conflict_Detector */
	private $detector;

	/** @var IPCD_Plugin_State_Manager */
	private $state_manager;

	/**
	 * Constructor.
	 *
	 * @param IPCD_Conflict_Detector    $detector       Conflict detector instance.
	 * @param IPCD_Plugin_State_Manager $state_manager  State manager instance.
	 */
	public function __construct(
		IPCD_Conflict_Detector $detector,
		IPCD_Plugin_State_Manager $state_manager
	) {
		$this->detector      = $detector;
		$this->state_manager = $state_manager;

		add_action( self::CRON_HOOK, array( $this, 'run_queued_tests' ) );
	}

	/**
	 * Register the custom cron schedule (every 6 hours).
	 */
	public static function register_cron_schedule(): void {
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['ipcd_every_6_hours'] = array(
					'interval' => 6 * HOUR_IN_SECONDS,
					'display'  => __( 'Every 6 Hours (IPCD)', 'ipcd' ),
				);
				return $schedules;
			}
		);
	}

	/**
	 * Add a test to the queue.
	 *
	 * @param string $plugin_basename  Plugin that was changed.
	 * @param string $event            'activated' | 'updated'.
	 */
	public function schedule_test( string $plugin_basename, string $event ): void {
		$queue   = $this->get_queue();
		$queue[] = array(
			'plugin'    => $plugin_basename,
			'event'     => $event,
			'queued_at' => time(),
		);
		update_option( self::QUEUE_OPTION, $queue );

		// Trigger immediately via a one-off cron event.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Process all queued tests.  Called by WP-Cron.
	 */
	public function run_queued_tests(): void {
		$queue = $this->get_queue();
		if ( empty( $queue ) ) {
			return;
		}

		// Clear the queue before processing so parallel runs don't double-test.
		delete_option( self::QUEUE_OPTION );

		foreach ( $queue as $item ) {
			$this->run_single_test( $item['plugin'], $item['event'] );
		}
	}

	/**
	 * Run tests for a single plugin change.
	 *
	 * Tests performed:
	 *  1. Probe the site home URL for HTTP 5xx.
	 *  2. Probe the WP admin URL for HTTP 5xx (uses nonce-free admin-ajax ping).
	 *  3. Scan the PHP error log for fatals introduced since the change.
	 *
	 * @param string $plugin_basename  Plugin being tested.
	 * @param string $event            'activated' | 'updated'.
	 */
	public function run_single_test( string $plugin_basename, string $event ): void {
		$since = time();

		/**
		 * Fires before a background test run starts.
		 *
		 * @param string $plugin_basename
		 * @param string $event
		 */
		do_action( 'ipcd_before_test', $plugin_basename, $event );

		$conflict_ids = array();

		// 1. Probe home URL.
		$home_conflict = $this->detector->probe_url( home_url( '/' ), $plugin_basename );
		if ( $home_conflict ) {
			$conflict_ids[] = $home_conflict;
		}

		// 2. Probe admin-ajax health check (lightweight, no auth needed).
		$ajax_conflict = $this->detector->probe_url(
			admin_url( 'admin-ajax.php?action=ipcd_health_ping' ),
			$plugin_basename
		);
		if ( $ajax_conflict ) {
			$conflict_ids[] = $ajax_conflict;
		}

		// 3. Scan PHP error log.
		$log_conflicts = $this->detector->scan_error_log( $plugin_basename, $since );
		$conflict_ids  = array_merge( $conflict_ids, $log_conflicts );

		/**
		 * Fires after a background test run completes.
		 *
		 * @param string $plugin_basename
		 * @param string $event
		 * @param array  $conflict_ids   IDs of any newly recorded conflicts.
		 */
		do_action( 'ipcd_after_test', $plugin_basename, $event, $conflict_ids );

		// If conflicts were found, notify the admin.
		if ( ! empty( $conflict_ids ) ) {
			do_action( 'ipcd_conflicts_detected', $plugin_basename, $event, $conflict_ids );
		}
	}

	/**
	 * Return the current test queue.
	 *
	 * @return array
	 */
	public function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Clear the test queue.
	 */
	public function clear_queue(): void {
		delete_option( self::QUEUE_OPTION );
	}

	/**
	 * Return the timestamp of the next scheduled cron run, or null.
	 *
	 * @return int|null
	 */
	public function get_next_run(): ?int {
		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ?: null;
	}
}
