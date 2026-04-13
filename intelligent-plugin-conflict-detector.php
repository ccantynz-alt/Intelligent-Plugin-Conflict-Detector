<?php
/**
 * Plugin Name: Intelligent Plugin Conflict Detector
 * Plugin URI:  https://github.com/ccantynz-alt/Intelligent-Plugin-Conflict-Detector
 * Description: Automatically detects plugin conflicts by testing combinations in a safe environment. Helps WooCommerce store owners identify which plugins cause issues without manual deactivation.
 * Version:     1.0.0
 * Author:      Intelligent Plugin Conflict Detector Contributors
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: intelligent-plugin-conflict-detector
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package IntelligentPluginConflictDetector
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'IPCD_VERSION', '1.0.0' );
define( 'IPCD_PLUGIN_FILE', __FILE__ );
define( 'IPCD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IPCD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IPCD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload plugin classes.
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-database.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-conflict-detector.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-sandbox-tester.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-background-monitor.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-rest-api.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-ipcd-admin-page.php';

/**
 * Main plugin class.
 */
final class Intelligent_Plugin_Conflict_Detector {

	/**
	 * Singleton instance.
	 *
	 * @var Intelligent_Plugin_Conflict_Detector|null
	 */
	private static $instance = null;

	/**
	 * Database handler.
	 *
	 * @var IPCD_Database
	 */
	public $database;

	/**
	 * Conflict detector engine.
	 *
	 * @var IPCD_Conflict_Detector
	 */
	public $detector;

	/**
	 * Sandbox tester.
	 *
	 * @var IPCD_Sandbox_Tester
	 */
	public $sandbox;

	/**
	 * Background monitor.
	 *
	 * @var IPCD_Background_Monitor
	 */
	public $monitor;

	/**
	 * REST API handler.
	 *
	 * @var IPCD_Rest_API
	 */
	public $rest_api;

	/**
	 * Admin page handler.
	 *
	 * @var IPCD_Admin_Page
	 */
	public $admin_page;

	/**
	 * Get singleton instance.
	 *
	 * @return Intelligent_Plugin_Conflict_Detector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_components();
		$this->register_hooks();
	}

	/**
	 * Initialize plugin components.
	 */
	private function init_components() {
		$this->database   = new IPCD_Database();
		$this->detector   = new IPCD_Conflict_Detector( $this->database );
		$this->sandbox    = new IPCD_Sandbox_Tester( $this->database );
		$this->monitor    = new IPCD_Background_Monitor( $this->detector, $this->sandbox );
		$this->rest_api   = new IPCD_Rest_API( $this->detector, $this->sandbox, $this->database );
		$this->admin_page = new IPCD_Admin_Page( $this->database );
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		register_activation_hook( IPCD_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( IPCD_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		$this->database->create_tables();
		$this->monitor->schedule_events();

		// Set default options.
		$defaults = array(
			'ipcd_monitoring_enabled'  => true,
			'ipcd_scan_frequency'      => 'daily',
			'ipcd_error_threshold'     => 'warning',
			'ipcd_email_notifications' => true,
			'ipcd_notification_email'  => get_option( 'admin_email' ),
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		$this->monitor->unschedule_events();
		flush_rewrite_rules();
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'intelligent-plugin-conflict-detector',
			false,
			dirname( IPCD_PLUGIN_BASENAME ) . '/languages/'
		);
	}
}

/**
 * Returns the main instance of the plugin.
 *
 * @return Intelligent_Plugin_Conflict_Detector
 */
function ipcd() {
	return Intelligent_Plugin_Conflict_Detector::get_instance();
}

// Initialize plugin.
ipcd();
