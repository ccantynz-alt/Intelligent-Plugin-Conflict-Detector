<?php
/**
 * Core conflict detection engine.
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IPCD_Conflict_Detector
 *
 * Analyses active plugins for conflicts by checking for PHP errors,
 * function/class collisions, hook interference, and resource conflicts.
 */
class IPCD_Conflict_Detector {

	/**
	 * Database handler.
	 *
	 * @var IPCD_Database
	 */
	private $database;

	/**
	 * Severity levels ordered by priority.
	 *
	 * @var array
	 */
	const SEVERITY_LEVELS = array(
		'critical' => 4,
		'high'     => 3,
		'medium'   => 2,
		'low'      => 1,
	);

	/**
	 * Constructor.
	 *
	 * @param IPCD_Database $database Database handler.
	 */
	public function __construct( IPCD_Database $database ) {
		$this->database = $database;
	}

	/**
	 * Run a full conflict detection scan.
	 *
	 * @param int|null $scan_id Optional scan ID to associate results with.
	 * @return array Detected conflicts.
	 */
	public function run_full_scan( $scan_id = null ) {
		$conflicts = array();

		// Get all active plugins.
		$active_plugins = $this->get_active_plugins();

		if ( count( $active_plugins ) < 2 ) {
			return $conflicts;
		}

		// Run different detection strategies.
		$conflicts = array_merge(
			$conflicts,
			$this->detect_function_collisions( $active_plugins ),
			$this->detect_class_collisions( $active_plugins ),
			$this->detect_hook_conflicts( $active_plugins ),
			$this->detect_resource_conflicts( $active_plugins ),
			$this->detect_dependency_conflicts( $active_plugins )
		);

		// Store results in database.
		foreach ( $conflicts as $conflict ) {
			if ( $scan_id ) {
				$conflict['scan_id'] = $scan_id;
			}

			// Avoid duplicates.
			if ( ! $this->database->conflict_exists(
				$conflict['plugin_slug'],
				$conflict['conflicting_plugin_slug'],
				$conflict['error_message']
			) ) {
				$this->database->insert_conflict( $conflict );
			}
		}

		return $conflicts;
	}

	/**
	 * Quick health check — detect obvious issues without deep scanning.
	 *
	 * @return array
	 */
	public function quick_health_check() {
		$issues = array();

		// Check for PHP errors in the error log.
		$issues['error_log'] = $this->check_error_log();

		// Check for known incompatible plugin pairs.
		$issues['known_conflicts'] = $this->check_known_conflicts();

		// Check WordPress site health.
		$issues['site_health'] = $this->check_site_health_issues();

		return $issues;
	}

	/**
	 * Get all active plugins with metadata.
	 *
	 * @return array
	 */
	public function get_active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugin_files = get_option( 'active_plugins', array() );
		$all_plugins         = get_plugins();
		$active_plugins      = array();

		foreach ( $active_plugin_files as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$active_plugins[ $plugin_file ] = $all_plugins[ $plugin_file ];
			}
		}

		return $active_plugins;
	}

	/**
	 * Detect function name collisions between plugins.
	 *
	 * @param array $active_plugins Active plugins list.
	 * @return array Detected conflicts.
	 */
	private function detect_function_collisions( $active_plugins ) {
		$conflicts          = array();
		$plugin_functions   = array();

		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$functions  = $this->extract_defined_functions( $plugin_dir );

			foreach ( $functions as $function_name => $file_path ) {
				if ( isset( $plugin_functions[ $function_name ] ) ) {
					$existing = $plugin_functions[ $function_name ];

					$conflicts[] = array(
						'plugin_slug'             => $this->get_plugin_slug( $plugin_file ),
						'conflicting_plugin_slug' => $this->get_plugin_slug( $existing['plugin_file'] ),
						'conflict_type'           => 'function_collision',
						'severity'                => 'high',
						'error_message'           => sprintf(
							/* translators: 1: function name, 2: plugin name, 3: plugin name */
							__( 'Function "%1$s" is defined in both "%2$s" and "%3$s".', 'intelligent-plugin-conflict-detector' ),
							$function_name,
							$plugin_data['Name'],
							$existing['plugin_name']
						),
						'file_reference' => $file_path,
					);
				} else {
					$plugin_functions[ $function_name ] = array(
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_data['Name'],
						'file_path'   => $file_path,
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Detect class name collisions between plugins.
	 *
	 * @param array $active_plugins Active plugins list.
	 * @return array Detected conflicts.
	 */
	private function detect_class_collisions( $active_plugins ) {
		$conflicts      = array();
		$plugin_classes = array();

		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$classes    = $this->extract_defined_classes( $plugin_dir );

			foreach ( $classes as $class_name => $file_path ) {
				if ( isset( $plugin_classes[ $class_name ] ) ) {
					$existing = $plugin_classes[ $class_name ];

					$conflicts[] = array(
						'plugin_slug'             => $this->get_plugin_slug( $plugin_file ),
						'conflicting_plugin_slug' => $this->get_plugin_slug( $existing['plugin_file'] ),
						'conflict_type'           => 'class_collision',
						'severity'                => 'high',
						'error_message'           => sprintf(
							/* translators: 1: class name, 2: plugin name, 3: plugin name */
							__( 'Class "%1$s" is defined in both "%2$s" and "%3$s".', 'intelligent-plugin-conflict-detector' ),
							$class_name,
							$plugin_data['Name'],
							$existing['plugin_name']
						),
						'file_reference' => $file_path,
					);
				} else {
					$plugin_classes[ $class_name ] = array(
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_data['Name'],
						'file_path'   => $file_path,
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Detect hook conflicts where plugins might interfere with each other.
	 *
	 * @param array $active_plugins Active plugins list.
	 * @return array Detected conflicts.
	 */
	private function detect_hook_conflicts( $active_plugins ) {
		$conflicts    = array();
		$plugin_hooks = array();

		// Critical hooks that can cause conflicts when multiple plugins modify them.
		$sensitive_hooks = array(
			'the_content'           => 'medium',
			'wp_head'               => 'low',
			'wp_footer'             => 'low',
			'woocommerce_checkout_process' => 'high',
			'woocommerce_payment_complete' => 'high',
			'woocommerce_cart_calculate_fees' => 'medium',
			'woocommerce_before_cart' => 'low',
			'template_redirect'     => 'medium',
			'wp_enqueue_scripts'    => 'low',
			'admin_enqueue_scripts' => 'low',
			'init'                  => 'low',
			'wp_loaded'             => 'low',
		);

		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$hooks      = $this->extract_hook_registrations( $plugin_dir );

			foreach ( $hooks as $hook_info ) {
				$hook_name = $hook_info['hook'];

				if ( ! isset( $plugin_hooks[ $hook_name ] ) ) {
					$plugin_hooks[ $hook_name ] = array();
				}

				$plugin_hooks[ $hook_name ][] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'priority'    => $hook_info['priority'],
					'file_path'   => $hook_info['file'],
				);
			}
		}

		// Check for conflicts on sensitive hooks.
		foreach ( $sensitive_hooks as $hook_name => $base_severity ) {
			if ( isset( $plugin_hooks[ $hook_name ] ) && count( $plugin_hooks[ $hook_name ] ) > 1 ) {
				$hooking_plugins = $plugin_hooks[ $hook_name ];

				// Check for same-priority conflicts (more likely to cause issues).
				$priorities = array_column( $hooking_plugins, 'priority' );
				$duplicates = array_diff_assoc( $priorities, array_unique( $priorities ) );

				if ( ! empty( $duplicates ) ) {
					for ( $i = 0; $i < count( $hooking_plugins ) - 1; $i++ ) {
						for ( $j = $i + 1; $j < count( $hooking_plugins ); $j++ ) {
							if ( $hooking_plugins[ $i ]['priority'] === $hooking_plugins[ $j ]['priority'] ) {
								$conflicts[] = array(
									'plugin_slug'             => $this->get_plugin_slug( $hooking_plugins[ $i ]['plugin_file'] ),
									'conflicting_plugin_slug' => $this->get_plugin_slug( $hooking_plugins[ $j ]['plugin_file'] ),
									'conflict_type'           => 'hook_conflict',
									'severity'                => $base_severity,
									'error_message'           => sprintf(
										/* translators: 1: hook name, 2: plugin name, 3: plugin name, 4: priority number */
										__( 'Hook "%1$s" is registered by both "%2$s" and "%3$s" at priority %4$d, which may cause execution order conflicts.', 'intelligent-plugin-conflict-detector' ),
										$hook_name,
										$hooking_plugins[ $i ]['plugin_name'],
										$hooking_plugins[ $j ]['plugin_name'],
										$hooking_plugins[ $i ]['priority']
									),
									'file_reference' => $hooking_plugins[ $i ]['file_path'],
								);
							}
						}
					}
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Detect resource conflicts (scripts, styles with same handle).
	 *
	 * @param array $active_plugins Active plugins list.
	 * @return array Detected conflicts.
	 */
	private function detect_resource_conflicts( $active_plugins ) {
		$conflicts        = array();
		$script_handles   = array();
		$style_handles    = array();

		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$resources  = $this->extract_enqueued_resources( $plugin_dir );

			foreach ( $resources['scripts'] as $handle => $file_path ) {
				if ( isset( $script_handles[ $handle ] ) ) {
					$existing = $script_handles[ $handle ];

					$conflicts[] = array(
						'plugin_slug'             => $this->get_plugin_slug( $plugin_file ),
						'conflicting_plugin_slug' => $this->get_plugin_slug( $existing['plugin_file'] ),
						'conflict_type'           => 'resource_conflict',
						'severity'                => 'medium',
						'error_message'           => sprintf(
							/* translators: 1: handle name, 2: plugin name, 3: plugin name */
							__( 'Script handle "%1$s" is registered by both "%2$s" and "%3$s".', 'intelligent-plugin-conflict-detector' ),
							$handle,
							$plugin_data['Name'],
							$existing['plugin_name']
						),
						'file_reference' => $file_path,
					);
				} else {
					$script_handles[ $handle ] = array(
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_data['Name'],
					);
				}
			}

			foreach ( $resources['styles'] as $handle => $file_path ) {
				if ( isset( $style_handles[ $handle ] ) ) {
					$existing = $style_handles[ $handle ];

					$conflicts[] = array(
						'plugin_slug'             => $this->get_plugin_slug( $plugin_file ),
						'conflicting_plugin_slug' => $this->get_plugin_slug( $existing['plugin_file'] ),
						'conflict_type'           => 'resource_conflict',
						'severity'                => 'low',
						'error_message'           => sprintf(
							/* translators: 1: handle name, 2: plugin name, 3: plugin name */
							__( 'Style handle "%1$s" is registered by both "%2$s" and "%3$s".', 'intelligent-plugin-conflict-detector' ),
							$handle,
							$plugin_data['Name'],
							$existing['plugin_name']
						),
						'file_reference' => $file_path,
					);
				} else {
					$style_handles[ $handle ] = array(
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_data['Name'],
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Detect dependency/version conflicts between plugins.
	 *
	 * @param array $active_plugins Active plugins list.
	 * @return array Detected conflicts.
	 */
	private function detect_dependency_conflicts( $active_plugins ) {
		$conflicts    = array();
		$library_uses = array();

		// Common library directories that plugins bundle.
		$common_libraries = array(
			'vendor/autoload.php'      => 'Composer autoload',
			'vendor/guzzlehttp'        => 'Guzzle HTTP',
			'vendor/stripe'            => 'Stripe SDK',
			'vendor/phpmailer'         => 'PHPMailer',
			'vendor/monolog'           => 'Monolog',
			'includes/libraries/cmb2'  => 'CMB2',
			'includes/lib/redux'       => 'Redux Framework',
		);

		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );

			foreach ( $common_libraries as $lib_path => $lib_name ) {
				$full_path = $plugin_dir . '/' . $lib_path;
				if ( file_exists( $full_path ) || is_dir( $full_path ) ) {
					if ( ! isset( $library_uses[ $lib_name ] ) ) {
						$library_uses[ $lib_name ] = array();
					}

					$library_uses[ $lib_name ][] = array(
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_data['Name'],
						'path'        => $full_path,
					);
				}
			}
		}

		// Flag conflicts for shared libraries.
		foreach ( $library_uses as $lib_name => $users ) {
			if ( count( $users ) > 1 ) {
				for ( $i = 0; $i < count( $users ) - 1; $i++ ) {
					for ( $j = $i + 1; $j < count( $users ); $j++ ) {
						$conflicts[] = array(
							'plugin_slug'             => $this->get_plugin_slug( $users[ $i ]['plugin_file'] ),
							'conflicting_plugin_slug' => $this->get_plugin_slug( $users[ $j ]['plugin_file'] ),
							'conflict_type'           => 'dependency_conflict',
							'severity'                => 'medium',
							'error_message'           => sprintf(
								/* translators: 1: library name, 2: plugin name, 3: plugin name */
								__( 'Library "%1$s" is bundled by both "%2$s" and "%3$s", which may cause version conflicts.', 'intelligent-plugin-conflict-detector' ),
								$lib_name,
								$users[ $i ]['plugin_name'],
								$users[ $j ]['plugin_name']
							),
							'file_reference' => $users[ $i ]['path'],
						);
					}
				}
			}
		}

		return $conflicts;
	}

	/**
	 * Extract defined functions from plugin PHP files.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 * @return array Function name => file path.
	 */
	private function extract_defined_functions( $plugin_dir ) {
		$functions = array();
		$files     = $this->get_php_files( $plugin_dir );

		foreach ( $files as $file ) {
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}

			if ( preg_match_all( '/^\s*function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/m', $content, $matches ) ) {
				foreach ( $matches[1] as $function_name ) {
					// Skip WordPress/PHP core function patterns and class methods (already inside class scope).
					$lowercase = strtolower( $function_name );
					if ( 0 === strpos( $lowercase, '__' ) ) {
						continue;
					}
					$functions[ $function_name ] = $file;
				}
			}
		}

		return $functions;
	}

	/**
	 * Extract defined classes from plugin PHP files.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 * @return array Class name => file path.
	 */
	private function extract_defined_classes( $plugin_dir ) {
		$classes = array();
		$files   = $this->get_php_files( $plugin_dir );

		foreach ( $files as $file ) {
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}

			if ( preg_match_all( '/^\s*(?:abstract\s+|final\s+)?class\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/m', $content, $matches ) ) {
				foreach ( $matches[1] as $class_name ) {
					$classes[ $class_name ] = $file;
				}
			}
		}

		return $classes;
	}

	/**
	 * Extract hook registrations (add_action/add_filter) from plugin files.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 * @return array Hook info arrays.
	 */
	private function extract_hook_registrations( $plugin_dir ) {
		$hooks = array();
		$files = $this->get_php_files( $plugin_dir );

		foreach ( $files as $file ) {
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}

			// Match add_action and add_filter calls.
			$pattern = '/(?:add_action|add_filter)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[^,)]+(?:\s*,\s*(\d+))?/';

			if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$hooks[] = array(
						'hook'     => $match[1],
						'priority' => isset( $match[2] ) ? (int) $match[2] : 10,
						'file'     => $file,
					);
				}
			}
		}

		return $hooks;
	}

	/**
	 * Extract enqueued script/style handles from plugin files.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 * @return array Array with 'scripts' and 'styles' keys.
	 */
	private function extract_enqueued_resources( $plugin_dir ) {
		$resources = array(
			'scripts' => array(),
			'styles'  => array(),
		);

		$files = $this->get_php_files( $plugin_dir );

		foreach ( $files as $file ) {
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}

			// Match wp_enqueue_script / wp_register_script calls.
			if ( preg_match_all( '/(?:wp_enqueue_script|wp_register_script)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $handle ) {
					$resources['scripts'][ $handle ] = $file;
				}
			}

			// Match wp_enqueue_style / wp_register_style calls.
			if ( preg_match_all( '/(?:wp_enqueue_style|wp_register_style)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches ) ) {
				foreach ( $matches[1] as $handle ) {
					$resources['styles'][ $handle ] = $file;
				}
			}
		}

		return $resources;
	}

	/**
	 * Get PHP files recursively from a directory.
	 *
	 * @param string $dir     Directory path.
	 * @param int    $max_depth Maximum recursion depth.
	 * @return array File paths.
	 */
	private function get_php_files( $dir, $max_depth = 5 ) {
		$files = array();

		if ( ! is_dir( $dir ) || $max_depth <= 0 ) {
			return $files;
		}

		// Skip vendor directories in recursion.
		$skip_dirs = array( 'vendor', 'node_modules', '.git', 'tests', 'test' );

		$iterator = new DirectoryIterator( $dir );

		foreach ( $iterator as $item ) {
			if ( $item->isDot() ) {
				continue;
			}

			if ( $item->isDir() ) {
				if ( ! in_array( $item->getFilename(), $skip_dirs, true ) ) {
					$files = array_merge( $files, $this->get_php_files( $item->getPathname(), $max_depth - 1 ) );
				}
			} elseif ( 'php' === $item->getExtension() ) {
				$files[] = $item->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Check WordPress error log for plugin-related errors.
	 *
	 * @return array
	 */
	private function check_error_log() {
		$issues = array();

		$error_log = ini_get( 'error_log' );
		if ( empty( $error_log ) || ! file_exists( $error_log ) || ! is_readable( $error_log ) ) {
			// Fallback to wp-content/debug.log.
			$error_log = WP_CONTENT_DIR . '/debug.log';
		}

		if ( ! file_exists( $error_log ) || ! is_readable( $error_log ) ) {
			return $issues;
		}

		// Read the last portion of the log file (last 50KB).
		$file_size = filesize( $error_log );
		$read_size = min( $file_size, 50 * 1024 );

		$handle = fopen( $error_log, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return $issues;
		}

		if ( $file_size > $read_size ) {
			fseek( $handle, $file_size - $read_size );
		}

		$content = fread( $handle, $read_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $content ) {
			return $issues;
		}

		// Parse for plugin-related errors.
		$lines = explode( "\n", $content );
		$plugin_dir_pattern = preg_quote( WP_PLUGIN_DIR, '/' );

		foreach ( $lines as $line ) {
			if ( preg_match( '/(?:Fatal error|Warning|Notice|Deprecated).*' . $plugin_dir_pattern . '\/([^\/]+)/i', $line, $matches ) ) {
				$issues[] = array(
					'plugin_slug' => $matches[1],
					'message'     => trim( $line ),
					'type'        => $this->classify_error_severity( $line ),
				);
			}
		}

		return $issues;
	}

	/**
	 * Check for known incompatible plugin pairs.
	 *
	 * @return array
	 */
	private function check_known_conflicts() {
		$known_conflicts = array(
			array(
				'plugins'  => array( 'woocommerce', 'wp-super-cache' ),
				'message'  => __( 'WooCommerce cart and checkout pages should be excluded from WP Super Cache to prevent caching issues.', 'intelligent-plugin-conflict-detector' ),
				'severity' => 'medium',
			),
			array(
				'plugins'  => array( 'jetpack', 'wordfence' ),
				'message'  => __( 'Jetpack Protect and Wordfence may conflict on brute-force protection features. Consider disabling one.', 'intelligent-plugin-conflict-detector' ),
				'severity' => 'low',
			),
			array(
				'plugins'  => array( 'yoast-seo', 'all-in-one-seo-pack' ),
				'message'  => __( 'Running two SEO plugins simultaneously will cause duplicate meta tags and conflicting sitemaps.', 'intelligent-plugin-conflict-detector' ),
				'severity' => 'high',
			),
			array(
				'plugins'  => array( 'elementor', 'beaver-builder' ),
				'message'  => __( 'Using multiple page builders simultaneously can cause editor conflicts and performance issues.', 'intelligent-plugin-conflict-detector' ),
				'severity' => 'medium',
			),
			array(
				'plugins'  => array( 'contact-form-7', 'wpforms-lite' ),
				'message'  => __( 'Multiple form plugins may load redundant frontend assets affecting performance.', 'intelligent-plugin-conflict-detector' ),
				'severity' => 'low',
			),
		);

		$active_slugs = array_map(
			function ( $file ) {
				return $this->get_plugin_slug( $file );
			},
			get_option( 'active_plugins', array() )
		);

		$detected = array();

		foreach ( $known_conflicts as $conflict ) {
			$found = array_intersect( $conflict['plugins'], $active_slugs );
			if ( count( $found ) >= 2 ) {
				$detected[] = $conflict;
			}
		}

		return $detected;
	}

	/**
	 * Check WordPress Site Health for relevant issues.
	 *
	 * @return array
	 */
	private function check_site_health_issues() {
		$issues = array();

		// Check PHP version compatibility.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$issues[] = array(
				'type'    => 'php_version',
				'message' => sprintf(
					/* translators: %s: PHP version */
					__( 'PHP version %s is outdated and may cause compatibility issues with plugins.', 'intelligent-plugin-conflict-detector' ),
					PHP_VERSION
				),
				'severity' => 'high',
			);
		}

		// Check memory limit.
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit < 128 * 1024 * 1024 ) {
			$issues[] = array(
				'type'    => 'memory_limit',
				'message' => sprintf(
					/* translators: %s: memory limit */
					__( 'PHP memory limit (%s) is low. Multiple plugins may cause memory exhaustion.', 'intelligent-plugin-conflict-detector' ),
					ini_get( 'memory_limit' )
				),
				'severity' => 'medium',
			);
		}

		return $issues;
	}

	/**
	 * Classify error severity from a log line.
	 *
	 * @param string $line Error log line.
	 * @return string
	 */
	private function classify_error_severity( $line ) {
		if ( stripos( $line, 'Fatal error' ) !== false ) {
			return 'critical';
		}

		if ( stripos( $line, 'Warning' ) !== false ) {
			return 'high';
		}

		if ( stripos( $line, 'Notice' ) !== false || stripos( $line, 'Deprecated' ) !== false ) {
			return 'low';
		}

		return 'medium';
	}

	/**
	 * Get plugin slug from plugin file path.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return string
	 */
	public function get_plugin_slug( $plugin_file ) {
		$parts = explode( '/', $plugin_file );
		return sanitize_title( $parts[0] );
	}
}
