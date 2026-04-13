<?php
/**
 * Plugin Name:       Jetstrike Conflict Detector
 * Plugin URI:        https://jetstrike.io/conflict-detector
 * Description:       Intelligent plugin conflict detection for WordPress and WooCommerce. Automatically scans, detects, and reports plugin conflicts before they break your store.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jetstrike
 * Author URI:        https://jetstrike.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jetstrike-cd
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Plugin constants.
define('JETSTRIKE_CD_VERSION', '1.0.0');
define('JETSTRIKE_CD_FILE', __FILE__);
define('JETSTRIKE_CD_PATH', plugin_dir_path(__FILE__));
define('JETSTRIKE_CD_URL', plugin_dir_url(__FILE__));
define('JETSTRIKE_CD_BASENAME', plugin_basename(__FILE__));
define('JETSTRIKE_CD_SLUG', 'jetstrike-conflict-detector');
define('JETSTRIKE_CD_DB_VERSION', '1.0.0');

// Autoloader.
if (file_exists(JETSTRIKE_CD_PATH . 'vendor/autoload.php')) {
    require_once JETSTRIKE_CD_PATH . 'vendor/autoload.php';
} else {
    // Fallback autoloader for when Composer is not available.
    spl_autoload_register(function (string $class): void {
        $prefix = 'Jetstrike\\ConflictDetector\\';
        $base_dir = JETSTRIKE_CD_PATH . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Activation hook.
register_activation_hook(__FILE__, function (): void {
    \Jetstrike\ConflictDetector\Activator::activate();
});

// Deactivation hook.
register_deactivation_hook(__FILE__, function (): void {
    \Jetstrike\ConflictDetector\Deactivator::deactivate();
});

// Initialize plugin after all plugins are loaded.
add_action('plugins_loaded', function (): void {
    \Jetstrike\ConflictDetector\Plugin::instance()->init();
}, 10);

// Declare WooCommerce HPOS compatibility.
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});
