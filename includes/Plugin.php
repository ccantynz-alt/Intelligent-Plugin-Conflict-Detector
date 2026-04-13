<?php
/**
 * Main plugin orchestrator.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector;

use Jetstrike\ConflictDetector\Admin\AdminPage;
use Jetstrike\ConflictDetector\Admin\AdminNotices;
use Jetstrike\ConflictDetector\Admin\AdminAjax;
use Jetstrike\ConflictDetector\API\RestController;
use Jetstrike\ConflictDetector\Database\Migrator;
use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Monitor\BackgroundMonitor;
use Jetstrike\ConflictDetector\Monitor\UpdateWatcher;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;
use Jetstrike\ConflictDetector\Cloud\Telemetry;
use Jetstrike\ConflictDetector\Notification\NotificationManager;
use Jetstrike\ConflictDetector\Subscription\LicenseManager;

final class Plugin {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var Repository */
    private Repository $repository;

    /** @var LicenseManager */
    private LicenseManager $license;

    /** @var NotificationManager */
    private NotificationManager $notifications;

    /**
     * Get singleton instance.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->repository    = new Repository();
        $this->license       = new LicenseManager();
        $this->notifications = new NotificationManager();
    }

    /**
     * Initialize all plugin subsystems.
     */
    public function init(): void {
        // Run migrations if needed.
        if (Migrator::needs_migration()) {
            Migrator::migrate();
        }

        // Register custom cron schedules.
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);

        // Admin hooks.
        if (is_admin()) {
            $this->init_admin();
        }

        // REST API.
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Background monitoring hooks.
        $this->init_monitors();

        // Cloud telemetry (opt-in anonymous data sharing).
        $telemetry = new Telemetry();
        $telemetry->register();

        // Plugin action links.
        add_filter('plugin_action_links_' . JETSTRIKE_CD_BASENAME, [$this, 'add_action_links']);

        // Load translations.
        add_action('init', function (): void {
            load_plugin_textdomain('jetstrike-cd', false, dirname(JETSTRIKE_CD_BASENAME) . '/languages');
        });
    }

    /**
     * Initialize admin-side components.
     */
    private function init_admin(): void {
        $admin_page = new AdminPage($this->repository, $this->license);
        $admin_page->register();

        $admin_notices = new AdminNotices($this->repository);
        $admin_notices->register();

        $admin_ajax = new AdminAjax($this->repository);
        $admin_ajax->register();
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        $controller = new RestController($this->repository, $this->license);
        $controller->register_routes();
    }

    /**
     * Initialize background monitors.
     */
    private function init_monitors(): void {
        $background = new BackgroundMonitor($this->repository, $this->notifications);
        $background->register();

        $update_watcher = new UpdateWatcher($this->repository);
        $update_watcher->register();

        $health_monitor = new HealthMonitor($this->repository);
        $health_monitor->register();
    }

    /**
     * Register custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function register_cron_schedules(array $schedules): array {
        $schedules['jetstrike_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __('Every 6 Hours', 'jetstrike-cd'),
        ];

        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __('Once Weekly', 'jetstrike-cd'),
        ];

        return $schedules;
    }

    /**
     * Add plugin action links.
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_action_links(array $links): array {
        $custom_links = [
            '<a href="' . esc_url(admin_url('admin.php?page=jetstrike-cd')) . '">'
                . esc_html__('Dashboard', 'jetstrike-cd') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=jetstrike-cd-settings')) . '">'
                . esc_html__('Settings', 'jetstrike-cd') . '</a>',
        ];

        return array_merge($custom_links, $links);
    }

    /**
     * Get the repository instance.
     */
    public function repository(): Repository {
        return $this->repository;
    }

    /**
     * Get the license manager instance.
     */
    public function license(): LicenseManager {
        return $this->license;
    }

    /**
     * Get the notification manager instance.
     */
    public function notifications(): NotificationManager {
        return $this->notifications;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}
}
