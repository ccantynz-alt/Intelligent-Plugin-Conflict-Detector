<?php
/**
 * PHPUnit bootstrap file for IPCD tests.
 *
 * Uses Brain\Monkey to mock WordPress functions so tests can run without
 * a live WordPress installation.
 *
 * @package IPCD\Tests
 */

// Composer autoloader.
$autoloader = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	echo "Composer autoloader not found. Run `composer install` inside the plugin directory.\n";
	exit( 1 );
}
require_once $autoloader;

// Define ABSPATH so plugin files don't bail out.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}

// Stub WP_PLUGIN_DIR.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
}

// Constants that the plugin defines.
if ( ! defined( 'IPCD_VERSION' ) ) {
	define( 'IPCD_VERSION', '1.0.0' );
}
if ( ! defined( 'IPCD_PLUGIN_DIR' ) ) {
	define( 'IPCD_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'IPCD_PLUGIN_URL' ) ) {
	define( 'IPCD_PLUGIN_URL', 'https://example.com/wp-content/plugins/intelligent-plugin-conflict-detector/' );
}
if ( ! defined( 'IPCD_PLUGIN_FILE' ) ) {
	define( 'IPCD_PLUGIN_FILE', dirname( __DIR__ ) . '/intelligent-plugin-conflict-detector.php' );
}
if ( ! defined( 'IPCD_PLUGIN_BASENAME' ) ) {
	define( 'IPCD_PLUGIN_BASENAME', 'intelligent-plugin-conflict-detector/intelligent-plugin-conflict-detector.php' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load the plugin classes under test (without loading the main plugin file
// which has side-effects like hooking into WP and calling ipcd()).
require_once IPCD_PLUGIN_DIR . 'includes/class-plugin-state-manager.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-conflict-detector.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-background-tester.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-rollback-manager.php';
require_once IPCD_PLUGIN_DIR . 'includes/class-notification-manager.php';
