<?php
/**
 * REST API controller — full CRUD for scans, conflicts, settings, and status.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\API;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanQueue;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;
use Jetstrike\ConflictDetector\Subscription\LicenseManager;

final class RestController {

    private const NAMESPACE = 'jetstrike/v1';

    /** @var Repository */
    private Repository $repository;

    /** @var LicenseManager */
    private LicenseManager $license;

    public function __construct(Repository $repository, LicenseManager $license) {
        $this->repository = $repository;
        $this->license = $license;
    }

    /**
     * Register all REST API routes.
     */
    public function register_routes(): void {
        // Scans.
        register_rest_route(self::NAMESPACE, '/scans', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create_scan'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'scan_type' => [
                        'type'              => 'string',
                        'enum'              => ['quick', 'full', 'targeted', 'pre_update'],
                        'default'           => 'quick',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'target_plugin' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_scans'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                    'status'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/scans/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_scan'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => ['type' => 'integer', 'required' => true],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_scan'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args'                => [
                    'id' => ['type' => 'integer', 'required' => true],
                ],
            ],
        ]);

        // Conflicts.
        register_rest_route(self::NAMESPACE, '/conflicts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_conflicts'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/conflicts/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'update_conflict'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args'                => [
                'id'     => ['type' => 'integer', 'required' => true],
                'status' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => ['active', 'resolved', 'ignored', 'false_positive'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Plugins list with health data.
        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_plugins'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // System status.
        register_rest_route(self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        // Settings.
        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);
    }

    // ── Scan Endpoints ─────────────────────────────────────────

    /**
     * Start a new scan.
     */
    public function create_scan(\WP_REST_Request $request): \WP_REST_Response {
        $scan_type = $request->get_param('scan_type');

        // Feature gate: full scans need Pro.
        if ($scan_type === 'full' && ! FeatureFlags::can('unlimited_full_scan')) {
            // Check weekly limit for free tier.
            $weekly_limit = FeatureFlags::weekly_scan_limit();

            if ($weekly_limit > 0) {
                $scans_this_week = $this->count_scans_this_week('full');

                if ($scans_this_week >= $weekly_limit) {
                    return new \WP_REST_Response([
                        'error'   => 'scan_limit_reached',
                        'message' => sprintf(
                            'Free plan allows %d full scan(s) per week. Upgrade to Pro for unlimited scans.',
                            $weekly_limit
                        ),
                        'upgrade_url' => 'https://jetstrike.io/pricing',
                    ], 403);
                }
            }
        }

        // Feature gate: pre-update scans need Pro.
        if ($scan_type === 'pre_update' && ! FeatureFlags::can('pre_update_scan')) {
            return new \WP_REST_Response([
                'error'   => 'feature_locked',
                'message' => 'Pre-update scanning requires a Pro subscription.',
            ], 403);
        }

        $options = [];

        if ($scan_type === 'targeted') {
            $target = $request->get_param('target_plugin');

            if (empty($target)) {
                return new \WP_REST_Response([
                    'error'   => 'missing_target',
                    'message' => 'Targeted scans require a target_plugin parameter.',
                ], 400);
            }

            $options['target_plugin'] = $target;
        }

        $queue = new ScanQueue($this->repository);
        $scan_id = $queue->enqueue($scan_type, 'api', $options);

        $scan = $this->repository->get_scan($scan_id);

        return new \WP_REST_Response([
            'scan' => $this->format_scan($scan),
        ], 201);
    }

    /**
     * List scans.
     */
    public function list_scans(\WP_REST_Request $request): \WP_REST_Response {
        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $status = $request->get_param('status') ?? '';

        $result = $this->repository->list_scans($page, $per_page, $status);

        return new \WP_REST_Response([
            'scans' => array_map([$this, 'format_scan'], $result['items']),
            'total' => $result['total'],
            'page'  => $page,
            'pages' => (int) ceil($result['total'] / $per_page),
        ]);
    }

    /**
     * Get a single scan with its conflicts.
     */
    public function get_scan(\WP_REST_Request $request): \WP_REST_Response {
        $scan = $this->repository->get_scan((int) $request->get_param('id'));

        if ($scan === null) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        $conflicts = $this->repository->get_conflicts_for_scan((int) $scan->id);

        return new \WP_REST_Response([
            'scan'      => $this->format_scan($scan),
            'conflicts' => array_map([$this, 'format_conflict'], $conflicts),
        ]);
    }

    /**
     * Cancel/delete a scan.
     */
    public function delete_scan(\WP_REST_Request $request): \WP_REST_Response {
        $scan_id = (int) $request->get_param('id');
        $scan = $this->repository->get_scan($scan_id);

        if ($scan === null) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        if (in_array($scan->status, ['queued', 'running'], true)) {
            $queue = new ScanQueue($this->repository);
            $queue->cancel($scan_id);
        } else {
            $this->repository->delete_scan($scan_id);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    // ── Conflict Endpoints ─────────────────────────────────────

    /**
     * List active conflicts.
     */
    public function list_conflicts(\WP_REST_Request $request): \WP_REST_Response {
        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');

        $result = $this->repository->list_active_conflicts($page, $per_page);

        return new \WP_REST_Response([
            'conflicts' => array_map([$this, 'format_conflict'], $result['items']),
            'total'     => $result['total'],
            'summary'   => $this->repository->get_conflict_summary(),
        ]);
    }

    /**
     * Update a conflict's status.
     */
    public function update_conflict(\WP_REST_Request $request): \WP_REST_Response {
        $conflict_id = (int) $request->get_param('id');
        $new_status = $request->get_param('status');

        $conflict = $this->repository->get_conflict($conflict_id);

        if ($conflict === null) {
            return new \WP_REST_Response(['error' => 'not_found'], 404);
        }

        $update_data = ['status' => $new_status];

        if ($new_status === 'resolved') {
            $update_data['resolved_at'] = current_time('mysql', true);
        }

        $this->repository->update_conflict($conflict_id, $update_data);

        // Clear cached summary.
        delete_transient('jetstrike_cd_conflict_summary');

        $updated = $this->repository->get_conflict($conflict_id);

        return new \WP_REST_Response([
            'conflict' => $this->format_conflict($updated),
        ]);
    }

    // ── Plugin List ────────────────────────────────────────────

    /**
     * List all active plugins with conflict data.
     */
    public function list_plugins(\WP_REST_Request $request): \WP_REST_Response {
        $active_plugins = get_option('active_plugins', []);
        $plugins = [];

        foreach ($active_plugins as $plugin_file) {
            if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

            $plugins[] = [
                'file'        => $plugin_file,
                'name'        => $plugin_data['Name'] ?? basename($plugin_file),
                'version'     => $plugin_data['Version'] ?? '',
                'author'      => $plugin_data['AuthorName'] ?? '',
                'description' => $plugin_data['Description'] ?? '',
            ];
        }

        return new \WP_REST_Response(['plugins' => $plugins]);
    }

    // ── Status ─────────────────────────────────────────────────

    /**
     * Get overall system status.
     */
    public function get_status(\WP_REST_Request $request): \WP_REST_Response {
        $health_monitor = new HealthMonitor($this->repository);
        $health = $health_monitor->calculate_health_score();

        $latest_scan = $this->repository->get_latest_scan();
        $conflict_summary = $this->repository->get_conflict_summary();

        return new \WP_REST_Response([
            'health'           => $health,
            'conflicts'        => $conflict_summary,
            'total_conflicts'  => array_sum($conflict_summary),
            'last_scan'        => $latest_scan ? $this->format_scan($latest_scan) : null,
            'has_running_scan' => $this->repository->has_running_scan(),
            'license'          => $this->license->get_info(),
            'plugin_count'     => count(get_option('active_plugins', [])) - 1,
            'version'          => JETSTRIKE_CD_VERSION,
        ]);
    }

    // ── Settings ───────────────────────────────────────────────

    /**
     * Get plugin settings.
     */
    public function get_settings(\WP_REST_Request $request): \WP_REST_Response {
        $settings = get_option('jetstrike_cd_settings', []);

        return new \WP_REST_Response(['settings' => $settings]);
    }

    /**
     * Update plugin settings.
     */
    public function update_settings(\WP_REST_Request $request): \WP_REST_Response {
        $body = $request->get_json_params();
        $current = get_option('jetstrike_cd_settings', []);

        $allowed_keys = [
            'auto_scan_enabled',
            'scan_frequency',
            'email_alerts',
            'alert_email',
            'slack_webhook_url',
            'scan_timeout',
            'max_pairs_per_batch',
            'performance_threshold',
            'excluded_plugins',
        ];

        foreach ($body as $key => $value) {
            if (in_array($key, $allowed_keys, true)) {
                $current[$key] = $this->sanitize_setting($key, $value);
            }
        }

        update_option('jetstrike_cd_settings', $current);

        // Reschedule cron if frequency changed.
        if (isset($body['scan_frequency']) || isset($body['auto_scan_enabled'])) {
            $this->reschedule_cron($current);
        }

        return new \WP_REST_Response(['settings' => $current]);
    }

    // ── Permission Checks ──────────────────────────────────────

    /**
     * Check if the current user has admin permissions.
     */
    public function check_admin_permission(\WP_REST_Request $request): bool {
        return current_user_can('manage_options');
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Format a scan record for API response.
     */
    private function format_scan(?object $scan): ?array {
        if ($scan === null) {
            return null;
        }

        return [
            'id'              => (int) $scan->id,
            'scan_type'       => $scan->scan_type,
            'status'          => $scan->status,
            'plugins_tested'  => json_decode($scan->plugins_tested ?? '[]', true),
            'conflicts_found' => (int) $scan->conflicts_found,
            'started_at'      => $scan->started_at,
            'completed_at'    => $scan->completed_at,
            'triggered_by'    => $scan->triggered_by,
            'error_message'   => $scan->error_message ?? null,
            'created_at'      => $scan->created_at,
        ];
    }

    /**
     * Format a conflict record for API response.
     */
    private function format_conflict(?object $conflict): ?array {
        if ($conflict === null) {
            return null;
        }

        return [
            'id'                => (int) $conflict->id,
            'scan_id'           => (int) $conflict->scan_id,
            'plugin_a'          => $conflict->plugin_a,
            'plugin_b'          => $conflict->plugin_b,
            'conflict_type'     => $conflict->conflict_type,
            'severity'          => $conflict->severity,
            'description'       => $conflict->description,
            'technical_details' => json_decode($conflict->technical_details ?? '{}', true),
            'recommendation'    => $conflict->recommendation,
            'status'            => $conflict->status,
            'detected_at'       => $conflict->detected_at,
            'resolved_at'       => $conflict->resolved_at,
        ];
    }

    /**
     * Sanitize a setting value based on its key.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Raw value.
     * @return mixed Sanitized value.
     */
    private function sanitize_setting(string $key, $value) {
        switch ($key) {
            case 'auto_scan_enabled':
            case 'email_alerts':
                return (bool) $value;

            case 'scan_frequency':
                $allowed = ['hourly', 'twicedaily', 'daily', 'jetstrike_six_hours'];
                return in_array($value, $allowed, true) ? $value : 'twicedaily';

            case 'alert_email':
                return sanitize_email($value);

            case 'slack_webhook_url':
                if (empty($value)) {
                    return '';
                }
                $url = esc_url_raw($value);
                if (! preg_match('#^https://hooks\.slack\.com/services/#', $url)) {
                    return '';
                }
                return $url;

            case 'scan_timeout':
                return max(10, min(120, (int) $value));

            case 'max_pairs_per_batch':
                return max(1, min(20, (int) $value));

            case 'performance_threshold':
                return max(1.5, min(10.0, (float) $value));

            case 'excluded_plugins':
                return is_array($value) ? array_map('sanitize_text_field', $value) : [];

            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Reschedule cron events after settings change.
     */
    private function reschedule_cron(array $settings): void {
        $timestamp = wp_next_scheduled('jetstrike_cd_background_scan');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'jetstrike_cd_background_scan');
        }

        if (! empty($settings['auto_scan_enabled'])) {
            $frequency = $settings['scan_frequency'] ?? 'twicedaily';
            wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, 'jetstrike_cd_background_scan');
        }
    }

    /**
     * Count scans of a given type this week.
     */
    private function count_scans_this_week(string $scan_type): int {
        global $wpdb;
        $table = $wpdb->prefix . 'jetstrike_scans';
        $week_ago = gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE scan_type = %s AND triggered_by = 'manual' AND created_at >= %s",
                $scan_type,
                $week_ago
            )
        );
    }
}
