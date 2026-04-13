<?php
/**
 * REST API endpoints for the conflict detector.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Rest_API
 *
 * Provides REST API endpoints for the admin dashboard.
 */
class IPCD_Rest_API {

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
	 * Database handler.
	 *
	 * @var IPCD_Database
	 */
	private $database;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'ipcd/v1';

	/**
	 * Constructor.
	 *
	 * @param IPCD_Conflict_Detector $detector Conflict detector.
	 * @param IPCD_Sandbox_Tester    $sandbox  Sandbox tester.
	 * @param IPCD_Database          $database Database handler.
	 */
	public function __construct( IPCD_Conflict_Detector $detector, IPCD_Sandbox_Tester $sandbox, IPCD_Database $database ) {
		$this->detector = $detector;
		$this->sandbox  = $sandbox;
		$this->database = $database;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Get conflicts list.
		register_rest_route(
			self::NAMESPACE,
			'/conflicts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conflicts' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'page'        => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'    => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'severity'    => array(
						'type'              => 'string',
						'enum'              => array( '', 'critical', 'high', 'medium', 'low' ),
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'resolved'    => array(
						'type'              => 'string',
						'enum'              => array( '', '0', '1' ),
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'plugin_slug' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Get single conflict.
		register_rest_route(
			self::NAMESPACE,
			'/conflicts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conflict' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Resolve a conflict.
		register_rest_route(
			self::NAMESPACE,
			'/conflicts/(?P<id>\d+)/resolve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_conflict' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get dashboard stats.
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Trigger a manual scan.
		register_rest_route(
			self::NAMESPACE,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trigger_scan' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'type' => array(
						'type'              => 'string',
						'enum'              => array( 'full', 'quick', 'isolation' ),
						'default'           => 'full',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Get scan history.
		register_rest_route(
			self::NAMESPACE,
			'/scans',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scans' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get active plugins info.
		register_rest_route(
			self::NAMESPACE,
			'/plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_active_plugins' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Update settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'monitoring_enabled'  => array(
							'type' => 'boolean',
						),
						'scan_frequency'      => array(
							'type' => 'string',
							'enum' => array( 'hourly', 'twicedaily', 'daily', 'ipcd_weekly' ),
						),
						'error_threshold'     => array(
							'type' => 'string',
							'enum' => array( 'notice', 'warning', 'error' ),
						),
						'email_notifications' => array(
							'type' => 'boolean',
						),
						'notification_email'  => array(
							'type'              => 'string',
							'format'            => 'email',
							'sanitize_callback' => 'sanitize_email',
						),
					),
				),
			)
		);
	}

	/**
	 * Check admin permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_admin_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'intelligent-plugin-conflict-detector' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get conflicts list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_conflicts( $request ) {
		$conflicts = $this->database->get_conflicts(
			array(
				'page'        => $request->get_param( 'page' ),
				'per_page'    => $request->get_param( 'per_page' ),
				'severity'    => $request->get_param( 'severity' ),
				'resolved'    => $request->get_param( 'resolved' ),
				'plugin_slug' => $request->get_param( 'plugin_slug' ),
			)
		);

		return rest_ensure_response( $conflicts );
	}

	/**
	 * Get a single conflict.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conflict( $request ) {
		$conflict = $this->database->get_conflict( $request->get_param( 'id' ) );

		if ( ! $conflict ) {
			return new WP_Error(
				'not_found',
				__( 'Conflict not found.', 'intelligent-plugin-conflict-detector' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $conflict );
	}

	/**
	 * Resolve a conflict.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_conflict( $request ) {
		$id = $request->get_param( 'id' );

		$conflict = $this->database->get_conflict( $id );
		if ( ! $conflict ) {
			return new WP_Error(
				'not_found',
				__( 'Conflict not found.', 'intelligent-plugin-conflict-detector' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->database->resolve_conflict( $id );

		if ( ! $result ) {
			return new WP_Error(
				'resolve_failed',
				__( 'Failed to resolve conflict.', 'intelligent-plugin-conflict-detector' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Conflict marked as resolved.', 'intelligent-plugin-conflict-detector' ),
			)
		);
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_stats( $request ) {
		$stats = $this->database->get_conflict_stats();

		$stats['last_scan']         = get_option( 'ipcd_last_health_check_time', '' );
		$stats['monitoring_active'] = (bool) get_option( 'ipcd_monitoring_enabled', true );
		$stats['active_plugins']    = count( get_option( 'active_plugins', array() ) );

		return rest_ensure_response( $stats );
	}

	/**
	 * Trigger a manual scan.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function trigger_scan( $request ) {
		$scan_type = $request->get_param( 'type' );

		$scan_id = $this->database->insert_scan(
			array(
				'scan_type'     => 'manual_' . $scan_type,
				'status'        => 'running',
				'total_plugins' => count( get_option( 'active_plugins', array() ) ),
				'started_at'    => current_time( 'mysql' ),
			)
		);

		if ( ! $scan_id ) {
			return new WP_Error(
				'scan_failed',
				__( 'Failed to start scan.', 'intelligent-plugin-conflict-detector' ),
				array( 'status' => 500 )
			);
		}

		$conflicts = array();

		switch ( $scan_type ) {
			case 'quick':
				$health    = $this->detector->quick_health_check();
				$conflicts = $health;
				break;

			case 'isolation':
				$results   = $this->sandbox->run_isolation_tests( $scan_id );
				$conflicts = $results;
				break;

			case 'full':
			default:
				$static_conflicts = $this->detector->run_full_scan( $scan_id );
				$sandbox_results  = $this->sandbox->run_pairwise_tests( $scan_id );
				$conflicts        = array(
					'static'  => $static_conflicts,
					'sandbox' => $sandbox_results,
				);
				break;
		}

		// Update scan record.
		$this->database->update_scan(
			$scan_id,
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'scan_id'   => $scan_id,
				'scan_type' => $scan_type,
				'results'   => $conflicts,
			)
		);
	}

	/**
	 * Get scan history.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_scans( $request ) {
		$scans = $this->database->get_scans(
			array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return rest_ensure_response( $scans );
	}

	/**
	 * Get active plugins info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_active_plugins( $request ) {
		$plugins = $this->detector->get_active_plugins();

		$plugin_list = array();
		foreach ( $plugins as $file => $data ) {
			$plugin_list[] = array(
				'file'        => $file,
				'slug'        => $this->detector->get_plugin_slug( $file ),
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'author'      => $data['Author'],
				'description' => $data['Description'],
			);
		}

		return rest_ensure_response( $plugin_list );
	}

	/**
	 * Get plugin settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_settings( $request ) {
		return rest_ensure_response(
			array(
				'monitoring_enabled'  => (bool) get_option( 'ipcd_monitoring_enabled', true ),
				'scan_frequency'      => get_option( 'ipcd_scan_frequency', 'daily' ),
				'error_threshold'     => get_option( 'ipcd_error_threshold', 'warning' ),
				'email_notifications' => (bool) get_option( 'ipcd_email_notifications', true ),
				'notification_email'  => get_option( 'ipcd_notification_email', get_option( 'admin_email' ) ),
			)
		);
	}

	/**
	 * Update plugin settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params = $request->get_params();

		$setting_map = array(
			'monitoring_enabled'  => 'ipcd_monitoring_enabled',
			'scan_frequency'      => 'ipcd_scan_frequency',
			'error_threshold'     => 'ipcd_error_threshold',
			'email_notifications' => 'ipcd_email_notifications',
			'notification_email'  => 'ipcd_notification_email',
		);

		foreach ( $setting_map as $param_key => $option_key ) {
			if ( isset( $params[ $param_key ] ) ) {
				update_option( $option_key, $params[ $param_key ] );
			}
		}

		// Reschedule cron if frequency changed.
		if ( isset( $params['scan_frequency'] ) ) {
			wp_clear_scheduled_hook( IPCD_Background_Monitor::CRON_FULL_SCAN );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $params['scan_frequency'], IPCD_Background_Monitor::CRON_FULL_SCAN );
		}

		return $this->get_settings( $request );
	}
}
