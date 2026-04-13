<?php
/**
 * Sandbox tester for safe plugin conflict testing.
 *
 * Tests plugin combinations via WordPress loopback requests with selective
 * plugin activation to detect runtime conflicts without affecting the live site.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Sandbox_Tester
 */
class IPCD_Sandbox_Tester {

	/**
	 * Database handler.
	 *
	 * @var IPCD_Database
	 */
	private $database;

	/**
	 * The option key used to store the sandbox test config.
	 *
	 * @var string
	 */
	const SANDBOX_OPTION = 'ipcd_sandbox_active_plugins';

	/**
	 * The query parameter used to trigger a sandbox request.
	 *
	 * @var string
	 */
	const SANDBOX_QUERY_PARAM = 'ipcd_sandbox_test';

	/**
	 * Constructor.
	 *
	 * @param IPCD_Database $database Database handler.
	 */
	public function __construct( IPCD_Database $database ) {
		$this->database = $database;

		add_action( 'plugins_loaded', array( $this, 'maybe_init_sandbox_mode' ), 1 );
	}

	/**
	 * If this request is a sandbox test, override the active plugins list.
	 *
	 * This hooks very early (priority 1) on plugins_loaded to intercept
	 * WordPress before all plugins have initialized.
	 */
	public function maybe_init_sandbox_mode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via sandbox token.
		if ( empty( $_GET[ self::SANDBOX_QUERY_PARAM ] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::SANDBOX_QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$sandbox_config = get_transient( 'ipcd_sandbox_' . $token );
		if ( false === $sandbox_config ) {
			return;
		}

		// This is a sandbox request — set up the error handler.
		$this->install_error_handler( $token );
	}

	/**
	 * Run a sandbox test for a specific plugin combination.
	 *
	 * @param array $plugins     Array of plugin file paths to test together.
	 * @param int   $scan_id     Associated scan ID.
	 * @return array Test results.
	 */
	public function test_plugin_combination( $plugins, $scan_id = 0 ) {
		$token = wp_generate_password( 32, false );

		// Store the test configuration in a transient.
		$config = array(
			'plugins'  => $plugins,
			'scan_id'  => $scan_id,
			'errors'   => array(),
			'created'  => time(),
		);

		set_transient( 'ipcd_sandbox_' . $token, $config, 300 ); // 5 minute expiry.

		// Make a loopback request with the sandbox parameter.
		$result = $this->make_sandbox_request( $token, $plugins );

		// Retrieve recorded errors.
		$updated_config = get_transient( 'ipcd_sandbox_' . $token );
		$errors         = is_array( $updated_config ) && isset( $updated_config['errors'] )
			? $updated_config['errors']
			: array();

		// Clean up.
		delete_transient( 'ipcd_sandbox_' . $token );

		return array(
			'plugins'    => $plugins,
			'success'    => ! is_wp_error( $result ) && empty( $errors ),
			'http_code'  => is_wp_error( $result ) ? 0 : wp_remote_retrieve_response_code( $result ),
			'errors'     => $errors,
			'wp_error'   => is_wp_error( $result ) ? $result->get_error_message() : null,
		);
	}

	/**
	 * Run a full pairwise test of all active plugins.
	 *
	 * Tests each pair of plugins together to identify which specific
	 * combination causes conflicts.
	 *
	 * @param int $scan_id Scan ID to associate with results.
	 * @return array All test results.
	 */
	public function run_pairwise_tests( $scan_id = 0 ) {
		$active_plugins = get_option( 'active_plugins', array() );
		$results        = array();
		$combinations   = 0;

		// Always include this plugin in each test.
		$self_plugin = IPCD_PLUGIN_BASENAME;

		for ( $i = 0; $i < count( $active_plugins ); $i++ ) {
			for ( $j = $i + 1; $j < count( $active_plugins ); $j++ ) {
				$plugin_pair = array( $active_plugins[ $i ], $active_plugins[ $j ] );

				// Ensure our detector is always active in tests.
				if ( ! in_array( $self_plugin, $plugin_pair, true ) ) {
					$plugin_pair[] = $self_plugin;
				}

				$result   = $this->test_plugin_combination( $plugin_pair, $scan_id );
				$results[] = $result;
				++$combinations;

				// Store conflicts found in this test.
				if ( ! $result['success'] ) {
					$this->record_sandbox_conflict( $result, $scan_id );
				}

				// Prevent timeout on large plugin sets — check time limit.
				if ( $this->is_approaching_time_limit() ) {
					break 2;
				}
			}
		}

		return array(
			'total_combinations' => $combinations,
			'results'            => $results,
		);
	}

	/**
	 * Run an isolation test — test each plugin individually to detect
	 * which single plugin is causing issues.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Test results per plugin.
	 */
	public function run_isolation_tests( $scan_id = 0 ) {
		$active_plugins = get_option( 'active_plugins', array() );
		$results        = array();
		$self_plugin    = IPCD_PLUGIN_BASENAME;

		foreach ( $active_plugins as $plugin ) {
			// Skip testing ourselves in isolation.
			if ( $plugin === $self_plugin ) {
				continue;
			}

			$test_plugins = array( $plugin, $self_plugin );
			$result       = $this->test_plugin_combination( $test_plugins, $scan_id );
			$results[]    = $result;

			if ( $this->is_approaching_time_limit() ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Make a loopback HTTP request with specific plugins activated.
	 *
	 * @param string $token   Sandbox token.
	 * @param array  $plugins Plugins to activate for this request.
	 * @return array|WP_Error Response or error.
	 */
	private function make_sandbox_request( $token, $plugins ) {
		$url = add_query_arg(
			array(
				self::SANDBOX_QUERY_PARAM => $token,
			),
			admin_url( 'admin-ajax.php?action=ipcd_sandbox_health_check' )
		);

		/**
		 * Use the MU-plugin approach: temporarily write a must-use plugin
		 * that overrides active_plugins for this specific request token.
		 */
		$this->create_sandbox_mu_plugin( $token, $plugins );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => false,
				'cookies'   => array(),
				'headers'   => array(
					'X-IPCD-Sandbox' => $token,
				),
			)
		);

		// Clean up the MU-plugin.
		$this->remove_sandbox_mu_plugin( $token );

		return $response;
	}

	/**
	 * Create a temporary must-use plugin that overrides active_plugins
	 * for the sandbox request.
	 *
	 * @param string $token   Unique test token.
	 * @param array  $plugins Plugins to activate.
	 */
	private function create_sandbox_mu_plugin( $token, $plugins ) {
		$mu_dir = WPMU_PLUGIN_DIR;

		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		$safe_token   = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
		$plugin_list  = var_export( $plugins, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$sandbox_param = self::SANDBOX_QUERY_PARAM;

		$mu_plugin_content = <<<PHP
<?php
/**
 * IPCD Sandbox MU-Plugin (temporary).
 * Auto-generated — will be removed after the test completes.
 */
if ( isset( \$_GET['{$sandbox_param}'] ) && '{$safe_token}' === preg_replace( '/[^a-zA-Z0-9]/', '', \$_GET['{$sandbox_param}'] ) ) {
	add_filter( 'option_active_plugins', function() {
		return {$plugin_list};
	}, 1 );
}
PHP;

		$mu_file = $mu_dir . '/ipcd-sandbox-' . $safe_token . '.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $mu_file, $mu_plugin_content );
	}

	/**
	 * Remove the temporary sandbox MU-plugin.
	 *
	 * @param string $token Unique test token.
	 */
	private function remove_sandbox_mu_plugin( $token ) {
		$safe_token = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
		$mu_file    = WPMU_PLUGIN_DIR . '/ipcd-sandbox-' . $safe_token . '.php';

		if ( file_exists( $mu_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $mu_file );
		}
	}

	/**
	 * Install a custom error handler to capture errors during sandbox tests.
	 *
	 * @param string $token Sandbox token.
	 */
	private function install_error_handler( $token ) {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			function ( $errno, $errstr, $errfile, $errline ) use ( $token ) {
				$config = get_transient( 'ipcd_sandbox_' . $token );
				if ( is_array( $config ) ) {
					$config['errors'][] = array(
						'errno'   => $errno,
						'message' => $errstr,
						'file'    => $errfile,
						'line'    => $errline,
					);
					set_transient( 'ipcd_sandbox_' . $token, $config, 300 );
				}

				// Let PHP handle the error normally too.
				return false;
			}
		);
	}

	/**
	 * Record a conflict discovered during sandbox testing.
	 *
	 * @param array $result  Test result.
	 * @param int   $scan_id Scan ID.
	 */
	private function record_sandbox_conflict( $result, $scan_id ) {
		$plugins = $result['plugins'];

		if ( count( $plugins ) < 2 ) {
			return;
		}

		$error_message = '';
		$severity      = 'medium';

		if ( ! empty( $result['wp_error'] ) ) {
			$error_message = $result['wp_error'];
			$severity      = 'critical';
		} elseif ( ! empty( $result['errors'] ) ) {
			$first_error   = $result['errors'][0];
			$error_message = $first_error['message'];
			$severity      = $this->error_number_to_severity( $first_error['errno'] );
		}

		// Skip self-plugin references.
		$test_plugins = array_filter(
			$plugins,
			function ( $p ) {
				return IPCD_PLUGIN_BASENAME !== $p;
			}
		);

		$test_plugins = array_values( $test_plugins );

		if ( count( $test_plugins ) < 2 ) {
			return;
		}

		$data = array(
			'plugin_slug'             => dirname( $test_plugins[0] ),
			'conflicting_plugin_slug' => dirname( $test_plugins[1] ),
			'conflict_type'           => 'runtime_error',
			'severity'                => $severity,
			'error_message'           => $error_message,
			'scan_id'                 => $scan_id,
		);

		if ( ! $this->database->conflict_exists(
			$data['plugin_slug'],
			$data['conflicting_plugin_slug'],
			$data['error_message']
		) ) {
			$this->database->insert_conflict( $data );
		}
	}

	/**
	 * Convert PHP error number to severity string.
	 *
	 * @param int $errno PHP error number.
	 * @return string
	 */
	private function error_number_to_severity( $errno ) {
		switch ( $errno ) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
				return 'critical';

			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				return 'high';

			case E_NOTICE:
			case E_USER_NOTICE:
				return 'low';

			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return 'low';

			default:
				return 'medium';
		}
	}

	/**
	 * Check if we're approaching the PHP time limit.
	 *
	 * @return bool
	 */
	private function is_approaching_time_limit() {
		$max_time = (int) ini_get( 'max_execution_time' );
		if ( 0 === $max_time ) {
			return false; // No limit.
		}

		// Leave 30 seconds of buffer.
		$elapsed = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
		return $elapsed > ( $max_time - 30 );
	}
}
