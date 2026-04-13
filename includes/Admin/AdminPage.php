<?php
/**
 * Admin menu pages and dashboard rendering.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Admin;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Subscription\LicenseManager;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;

final class AdminPage {

    /** @var Repository */
    private Repository $repository;

    /** @var LicenseManager */
    private LicenseManager $license;

    public function __construct(Repository $repository, LicenseManager $license) {
        $this->repository = $repository;
        $this->license = $license;
    }

    /**
     * Register admin menu and page hooks.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu_pages(): void {
        // Main dashboard page.
        add_menu_page(
            __('Conflict Detector', 'jetstrike-cd'),
            __('Conflict Detector', 'jetstrike-cd'),
            'manage_options',
            'jetstrike-cd',
            [$this, 'render_dashboard'],
            'dashicons-shield',
            75
        );

        // Dashboard submenu (rename default).
        add_submenu_page(
            'jetstrike-cd',
            __('Dashboard', 'jetstrike-cd'),
            __('Dashboard', 'jetstrike-cd'),
            'manage_options',
            'jetstrike-cd',
            [$this, 'render_dashboard']
        );

        // Scan Results page.
        add_submenu_page(
            'jetstrike-cd',
            __('Scan Results', 'jetstrike-cd'),
            __('Scan Results', 'jetstrike-cd'),
            'manage_options',
            'jetstrike-cd-results',
            [$this, 'render_scan_results']
        );

        // Settings page.
        add_submenu_page(
            'jetstrike-cd',
            __('Settings', 'jetstrike-cd'),
            __('Settings', 'jetstrike-cd'),
            'manage_options',
            'jetstrike-cd-settings',
            [$this, 'render_settings']
        );
    }

    /**
     * Enqueue admin CSS and JS assets.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public function enqueue_assets(string $hook_suffix): void {
        // Only load on our pages.
        if (strpos($hook_suffix, 'jetstrike-cd') === false) {
            return;
        }

        wp_enqueue_style(
            'jetstrike-cd-admin',
            JETSTRIKE_CD_URL . 'assets/css/admin-dashboard.css',
            [],
            JETSTRIKE_CD_VERSION
        );

        wp_enqueue_script(
            'jetstrike-cd-admin',
            JETSTRIKE_CD_URL . 'assets/js/admin-dashboard.js',
            ['jquery'],
            JETSTRIKE_CD_VERSION,
            true
        );

        // Pass data to JavaScript.
        wp_localize_script('jetstrike-cd-admin', 'jetstrikeCD', [
            'restUrl'  => esc_url_raw(rest_url('jetstrike/v1/')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'ajaxNonce' => wp_create_nonce('jetstrike_cd_ajax'),
            'tier'     => FeatureFlags::get_tier(),
            'strings'  => [
                'scanning'     => __('Scanning...', 'jetstrike-cd'),
                'scanComplete' => __('Scan complete!', 'jetstrike-cd'),
                'scanFailed'   => __('Scan failed.', 'jetstrike-cd'),
                'confirm'      => __('Are you sure?', 'jetstrike-cd'),
                'noConflicts'  => __('No conflicts detected.', 'jetstrike-cd'),
            ],
        ]);
    }

    /**
     * Render main dashboard page.
     */
    public function render_dashboard(): void {
        $health_monitor = new HealthMonitor($this->repository);
        $health = $health_monitor->calculate_health_score();
        $conflict_summary = $this->repository->get_conflict_summary();
        $latest_scan = $this->repository->get_latest_scan();
        $active_conflicts = $this->repository->list_active_conflicts(1, 10);
        $has_running = $this->repository->has_running_scan();
        $tier = FeatureFlags::get_tier();
        $license_info = $this->license->get_info();
        $plugin_count = count(get_option('active_plugins', [])) - 1;

        include JETSTRIKE_CD_PATH . 'templates/admin/dashboard.php';
    }

    /**
     * Render scan results page.
     */
    public function render_scan_results(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page navigation.
        $page = max(1, absint($_GET['paged'] ?? 1));
        $scans = $this->repository->list_scans($page, 20);
        $tier = FeatureFlags::get_tier();

        // If viewing a specific scan.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page navigation.
        $scan_id = absint($_GET['scan_id'] ?? 0);
        $current_scan = null;
        $scan_conflicts = [];

        if ($scan_id > 0) {
            $current_scan = $this->repository->get_scan($scan_id);

            if ($current_scan) {
                $scan_conflicts = $this->repository->get_conflicts_for_scan($scan_id);
            }
        }

        include JETSTRIKE_CD_PATH . 'templates/admin/scan-results.php';
    }

    /**
     * Render settings page.
     */
    public function render_settings(): void {
        $settings = get_option('jetstrike_cd_settings', []);
        $license_info = $this->license->get_info();
        $tier = FeatureFlags::get_tier();
        $active_plugins = get_option('active_plugins', []);

        include JETSTRIKE_CD_PATH . 'templates/admin/settings.php';
    }
}
