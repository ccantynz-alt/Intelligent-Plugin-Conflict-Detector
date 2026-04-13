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

// ── Freemius SDK Integration ─────────────────────────────────
// Initialize Freemius BEFORE plugins_loaded (per Freemius best practices).
if (! function_exists('jetstrike_cd_fs')) {
    /**
     * Create a helper function for easy Freemius SDK access.
     *
     * @return \Freemius
     */
    function jetstrike_cd_fs() {
        global $jetstrike_cd_fs;

        if (! isset($jetstrike_cd_fs)) {
            // Freemius SDK path — install via: composer require freemius/wordpress-sdk
            // Or download and place in /freemius/ directory.
            $freemius_start = JETSTRIKE_CD_PATH . 'freemius/start.php';
            $freemius_vendor = JETSTRIKE_CD_PATH . 'vendor/freemius/wordpress-sdk/start.php';

            if (file_exists($freemius_start)) {
                require_once $freemius_start;
            } elseif (file_exists($freemius_vendor)) {
                require_once $freemius_vendor;
            } else {
                // Freemius SDK not installed — fall back to built-in license manager.
                return null;
            }

            $jetstrike_cd_fs = fs_dynamic_init([
                'id'                  => '', // Set after Freemius account creation.
                'slug'                => 'jetstrike-conflict-detector',
                'premium_slug'        => 'jetstrike-conflict-detector-pro',
                'type'                => 'plugin',
                'public_key'          => '', // Set after Freemius account creation.
                'is_premium'          => false,
                'has_premium_version' => true,
                'has_paid_plans'      => true,
                'has_addons'          => false,
                'is_org_compliant'    => true,
                'trial'               => [
                    'days'               => 14,
                    'is_require_payment' => false,
                ],
                'menu'                => [
                    'slug'    => 'jetstrike-cd',
                    'contact' => true,
                    'support' => true,
                ],
            ]);

            // Sync Freemius plan with our FeatureFlags system.
            $jetstrike_cd_fs->add_action('after_license_change', function () {
                jetstrike_cd_sync_freemius_tier();
            });

            $jetstrike_cd_fs->add_action('after_plan_change', function () {
                jetstrike_cd_sync_freemius_tier();
            });
        }

        return $jetstrike_cd_fs;
    }

    /**
     * Sync Freemius subscription tier with our FeatureFlags.
     */
    function jetstrike_cd_sync_freemius_tier(): void {
        $fs = jetstrike_cd_fs();

        if ($fs === null) {
            return;
        }

        if ($fs->is_plan('agency')) {
            update_option('jetstrike_cd_license_tier', 'agency');
        } elseif ($fs->is_plan('pro') || $fs->is_paying()) {
            update_option('jetstrike_cd_license_tier', 'pro');
        } else {
            update_option('jetstrike_cd_license_tier', 'free');
        }
    }

    // Initialize Freemius.
    jetstrike_cd_fs();
    do_action('jetstrike_cd_fs_loaded');
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
