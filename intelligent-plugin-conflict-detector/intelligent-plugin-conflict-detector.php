<?php
/**
 * Plugin Name:       Intelligent Plugin Conflict Detector
 * Plugin URI:        https://github.com/ccantynz-alt/Intelligent-Plugin-Conflict-Detector
 * Description:       Automatically detects plugin conflicts in a safe background environment, alerts store owners before problems occur, and provides one-click rollback.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Intelligent Plugin Conflict Detector Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ipcd
 * Domain Path:       /languages
 *
 * @package IPCD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'IPCD_VERSION', '1.0.0' );
define( 'IPCD_PLUGIN_FILE', __FILE__ );
define( 'IPCD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IPCD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IPCD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Required includes.
require_once IPCD_PLUGIN_DIR . 'includes/class-plugin-state-manager.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-conflict-detector.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-background-tester.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-rollback-manager.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-notification-manager.php';

if ( is_admin() ) {
	require_once IPCD_PLUGIN_DIR . 'admin/class-admin.php';
}

/**
 * Returns the main plugin instance (singleton).
 *
 * @return IPCD_Plugin
 */
function ipcd() {
	return IPCD_Plugin::instance();
}

/**
 * Main plugin class.
 */
final class IPCD_Plugin {

	/** @var IPCD_Plugin|null */
	private static $instance = null;

	/** @var IPCD_Plugin_State_Manager */
	public $state_manager;

	/** @var IPCD_Conflict_Detector */
	public $conflict_detector;

	/** @var IPCD_Background_Tester */
	public $background_tester;

	/** @var IPCD_Rollback_Manager */
	public $rollback_manager;

	/** @var IPCD_Notification_Manager */
	public $notification_manager;

	/** @var IPCD_Admin|null */
	public $admin;

	/**
	 * Returns/creates singleton.
	 *
	 * @return IPCD_Plugin
	 */
	public static function instance(): IPCD_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor – wire up hooks.
	 */
	private function __construct() {
		$this->state_manager        = new IPCD_Plugin_State_Manager();
		$this->conflict_detector    = new IPCD_Conflict_Detector();
		$this->background_tester    = new IPCD_Background_Tester( $this->conflict_detector, $this->state_manager );
		$this->rollback_manager     = new IPCD_Rollback_Manager( $this->state_manager );
		$this->notification_manager = new IPCD_Notification_Manager();

		if ( is_admin() ) {
			$this->admin = new IPCD_Admin(
				$this->conflict_detector,
				$this->rollback_manager,
				$this->notification_manager,
				$this->background_tester,
				$this->state_manager
			);
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ipcd', false, dirname( IPCD_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Triggered when any plugin is activated – snapshot state and schedule a test run.
	 *
	 * @param string $plugin        Plugin basename.
	 * @param bool   $network_wide  Whether activated network-wide.
	 */
	public function on_plugin_activated( string $plugin, bool $network_wide ): void {
		if ( $plugin === IPCD_PLUGIN_BASENAME ) {
			return;
		}
		$this->state_manager->capture_snapshot( 'before_activate_' . sanitize_key( $plugin ) );
		$this->background_tester->schedule_test( $plugin, 'activated' );
	}

	/**
	 * Triggered when any plugin is deactivated – clear stored conflict data for it.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether deactivated network-wide.
	 */
	public function on_plugin_deactivated( string $plugin, bool $network_wide ): void {
		$this->conflict_detector->clear_conflicts_for_plugin( $plugin );
	}

	/**
	 * Triggered after a plugin or WooCommerce update – capture snapshot and schedule test.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options  Upgrade options.
	 */
	public function on_upgrade_complete( $upgrader, array $options ): void {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			$plugins = $options['plugins'] ?? array();
			foreach ( $plugins as $plugin ) {
				$this->state_manager->capture_snapshot( 'before_update_' . sanitize_key( $plugin ) );
				$this->background_tester->schedule_test( $plugin, 'updated' );
			}
		}
	}
}

// Activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, 'ipcd_activate' );
register_deactivation_hook( __FILE__, 'ipcd_deactivate' );

/**
 * Plugin activation: create DB tables and schedule cron.
 */
function ipcd_activate(): void {
	IPCD_Plugin_State_Manager::create_tables();
	IPCD_Background_Tester::register_cron_schedule();
	if ( ! wp_next_scheduled( 'ipcd_background_test' ) ) {
		wp_schedule_event( time(), 'ipcd_every_6_hours', 'ipcd_background_test' );
	}
}

/**
 * Plugin deactivation: clear cron events.
 */
function ipcd_deactivate(): void {
	wp_clear_scheduled_hook( 'ipcd_background_test' );
}

// Boot the plugin.
ipcd();
