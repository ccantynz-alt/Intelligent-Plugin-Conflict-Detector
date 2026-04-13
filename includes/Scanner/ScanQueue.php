<?php
/**
 * Scan job queue — manages batched scan processing via WP-Cron.
 *
 * Full scans are too expensive to run in a single request.
 * This breaks them into batches processed across multiple cron ticks.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Scanner;

use Jetstrike\ConflictDetector\Database\Repository;

final class ScanQueue {

    private const BATCH_OPTION = 'jetstrike_cd_scan_batch';
    private const LOCK_OPTION  = 'jetstrike_cd_scan_lock';
    private const LOCK_TTL     = 300; // 5 minutes.

    /** @var Repository */
    private Repository $repository;

    /** @var int Maximum pairs to test per batch. */
    private int $max_pairs_per_batch;

    public function __construct(Repository $repository, int $max_pairs_per_batch = 5) {
        $this->repository = $repository;
        $this->max_pairs_per_batch = $max_pairs_per_batch;
    }

    /**
     * Enqueue a new scan job.
     *
     * @param string $scan_type   Scan type.
     * @param string $triggered_by Who triggered this scan.
     * @param array  $options     Scan options (targeted plugin, etc.).
     * @return int Scan ID.
     */
    public function enqueue(string $scan_type, string $triggered_by = 'manual', array $options = []): int {
        // Don't queue if a scan is already running.
        if ($this->repository->has_running_scan()) {
            $running = $this->get_running_scan_id();
            if ($running > 0) {
                return $running;
            }
        }

        $scan_id = $this->repository->create_scan([
            'scan_type'    => $scan_type,
            'status'       => 'queued',
            'triggered_by' => $triggered_by,
            'results'      => wp_json_encode($options),
        ]);

        // Schedule immediate processing.
        if (! wp_next_scheduled('jetstrike_cd_process_scan', [$scan_id])) {
            wp_schedule_single_event(time(), 'jetstrike_cd_process_scan', [$scan_id]);
        }

        return $scan_id;
    }

    /**
     * Process the next batch of a scan.
     *
     * @param int $scan_id Scan to process.
     * @return bool True if scan is complete, false if more batches needed.
     */
    public function process_batch(int $scan_id): bool {
        // Acquire lock.
        if (! $this->acquire_lock($scan_id)) {
            return false;
        }

        try {
            $scan = $this->repository->get_scan($scan_id);

            if ($scan === null || $scan->status === 'cancelled') {
                $this->release_lock();
                return true;
            }

            // Mark as running on first batch.
            if ($scan->status === 'queued') {
                $this->repository->update_scan($scan_id, [
                    'status'     => 'running',
                    'started_at' => current_time('mysql', true),
                ]);
            }

            // Get batch state.
            $batch_state = get_option(self::BATCH_OPTION . '_' . $scan_id, [
                'completed_pairs' => [],
                'pending_pairs'   => null,
                'static_done'     => false,
                'runtime_done'    => false,
                'all_conflicts'   => [],
            ]);

            $is_complete = false;

            switch ($scan->scan_type) {
                case 'quick':
                    $is_complete = $this->process_quick_scan($scan_id, $batch_state);
                    break;

                case 'full':
                    $is_complete = $this->process_full_scan($scan_id, $batch_state);
                    break;

                case 'targeted':
                    $is_complete = $this->process_targeted_scan($scan_id, $batch_state);
                    break;

                case 'pre_update':
                    $is_complete = $this->process_quick_scan($scan_id, $batch_state);
                    break;

                default:
                    $is_complete = true;
            }

            if ($is_complete) {
                $this->finalize_scan($scan_id, $batch_state);
                delete_option(self::BATCH_OPTION . '_' . $scan_id);
            } else {
                update_option(self::BATCH_OPTION . '_' . $scan_id, $batch_state);

                // Schedule next batch.
                wp_schedule_single_event(
                    time() + 5,
                    'jetstrike_cd_process_scan',
                    [$scan_id]
                );
            }

            return $is_complete;
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Cancel a running scan.
     *
     * @param int $scan_id Scan to cancel.
     * @return bool Success.
     */
    public function cancel(int $scan_id): bool {
        $this->repository->update_scan($scan_id, [
            'status'       => 'cancelled',
            'completed_at' => current_time('mysql', true),
        ]);

        delete_option(self::BATCH_OPTION . '_' . $scan_id);
        wp_clear_scheduled_hook('jetstrike_cd_process_scan', [$scan_id]);

        return true;
    }

    /**
     * Process a quick scan (static analysis only).
     */
    private function process_quick_scan(int $scan_id, array &$state): bool {
        if ($state['static_done']) {
            return true;
        }

        $plugins = $this->get_active_third_party_plugins();

        $engine = new ScanEngine($this->repository);
        $conflicts = $engine->run_static_analysis($plugins);

        $state['all_conflicts'] = array_merge($state['all_conflicts'], $conflicts);
        $state['static_done'] = true;

        // Update progress.
        $this->repository->update_scan($scan_id, [
            'plugins_tested' => wp_json_encode($plugins),
        ]);

        return true;
    }

    /**
     * Process a full scan (static + runtime) in batches.
     */
    private function process_full_scan(int $scan_id, array &$state): bool {
        $plugins = $this->get_active_third_party_plugins();

        // Phase 1: Static analysis.
        if (! $state['static_done']) {
            $engine = new ScanEngine($this->repository);
            $conflicts = $engine->run_static_analysis($plugins);
            $state['all_conflicts'] = array_merge($state['all_conflicts'], $conflicts);
            $state['static_done'] = true;

            $this->repository->update_scan($scan_id, [
                'plugins_tested' => wp_json_encode($plugins),
            ]);

            return false; // More work to do.
        }

        // Phase 2: Runtime testing.
        if (! $state['runtime_done']) {
            $engine = new ScanEngine($this->repository);
            $runtime_conflicts = $engine->run_runtime_scan($plugins);
            $state['all_conflicts'] = array_merge($state['all_conflicts'], $runtime_conflicts);
            $state['runtime_done'] = true;
        }

        return true;
    }

    /**
     * Process a targeted scan for a specific plugin.
     */
    private function process_targeted_scan(int $scan_id, array &$state): bool {
        $scan = $this->repository->get_scan($scan_id);
        $options = json_decode($scan->results ?? '{}', true);
        $target_plugin = $options['target_plugin'] ?? '';

        if (empty($target_plugin)) {
            return true;
        }

        $plugins = $this->get_active_third_party_plugins();

        if (! in_array($target_plugin, $plugins, true)) {
            return true;
        }

        $engine = new ScanEngine($this->repository);

        // Static analysis of just this plugin vs others.
        $conflicts = $engine->run_static_analysis($plugins);

        // Filter to only conflicts involving the target plugin.
        $state['all_conflicts'] = array_filter($conflicts, function (array $c) use ($target_plugin): bool {
            return $c['plugin_a'] === $target_plugin || $c['plugin_b'] === $target_plugin;
        });

        return true;
    }

    /**
     * Finalize a completed scan.
     */
    private function finalize_scan(int $scan_id, array $state): void {
        $conflicts = $state['all_conflicts'];
        $conflict_count = 0;

        // Store conflicts in the database.
        foreach ($conflicts as $conflict) {
            // Avoid duplicates.
            if ($this->repository->conflict_exists(
                $conflict['plugin_a'],
                $conflict['plugin_b'] ?? '',
                $conflict['type']
            )) {
                continue;
            }

            $this->repository->create_conflict([
                'scan_id'           => $scan_id,
                'plugin_a'          => $conflict['plugin_a'],
                'plugin_b'          => $conflict['plugin_b'] ?? '',
                'conflict_type'     => $conflict['type'],
                'severity'          => $conflict['severity'],
                'description'       => $conflict['description'],
                'technical_details' => wp_json_encode($conflict['details'] ?? []),
                'recommendation'    => $this->generate_recommendation($conflict),
            ]);

            $conflict_count++;
        }

        $this->repository->update_scan($scan_id, [
            'status'          => 'completed',
            'completed_at'    => current_time('mysql', true),
            'conflicts_found' => $conflict_count,
            'results'         => wp_json_encode([
                'total_conflicts'   => $conflict_count,
                'conflict_summary'  => $this->summarize_conflicts($conflicts),
            ]),
        ]);

        // Clear cached results.
        delete_transient('jetstrike_cd_scan_results');
        delete_transient('jetstrike_cd_conflict_summary');

        // Send anonymized telemetry (if opted in).
        $telemetry = new \Jetstrike\ConflictDetector\Cloud\Telemetry();
        $plugins_tested = json_decode($this->repository->get_scan($scan_id)->plugins_tested ?? '[]', true);
        $telemetry->report_scan($conflicts, count(is_array($plugins_tested) ? $plugins_tested : []));
    }

    /**
     * Generate an actionable recommendation for a conflict.
     */
    private function generate_recommendation(array $conflict): string {
        switch ($conflict['type']) {
            case 'function_redeclaration':
                return 'Contact one of the plugin authors to namespace their functions. As a workaround, you can deactivate one of the conflicting plugins.';

            case 'class_collision':
                return 'Both plugins define the same class name. Contact the plugin authors to use PHP namespaces. Deactivate one plugin to prevent fatal errors.';

            case 'global_conflict':
                return 'Both plugins use the same global variable, which may cause data corruption. Monitor for unexpected behavior and report to the plugin authors.';

            case 'hook_conflict':
                $details = $conflict['details'] ?? [];
                if (! empty($details['woocommerce'])) {
                    return 'These plugins conflict on a critical WooCommerce hook. Test your checkout flow thoroughly. Consider changing one plugin\'s priority or finding an alternative plugin.';
                }
                return 'Both plugins hook into the same action/filter at the same priority. This may cause unpredictable behavior. Try adjusting the priority of one plugin\'s hooks.';

            case 'resource_collision':
                $details = $conflict['details'] ?? [];
                if (($details['resource_type'] ?? '') === 'shortcode') {
                    return 'Both plugins register the same shortcode. Only the last one loaded will work. Deactivate one or contact the authors for a rename.';
                }
                return 'Both plugins register resources with the same handle. One will silently override the other. Contact the plugin authors.';

            case 'performance_degradation':
                return 'This plugin significantly impacts site performance. Consider finding a lighter alternative, or enable caching to mitigate the impact.';

            case 'dependency_conflict':
                return 'Both plugins bundle different versions of the same PHP library. Contact the newer plugin\'s author to use PHP-Scoper or Strauss for dependency isolation. As a Pro user, you can use Auto-Fix to attempt automatic resolution.';

            case 'js_global_conflict':
                return 'Both plugins define the same JavaScript global variable. One will silently overwrite the other. Contact the plugin authors to namespace their JS code.';

            case 'js_jquery_override':
                return 'This plugin overrides WordPress\'s bundled jQuery, which breaks other plugins and the block editor. Contact the plugin author or use Auto-Fix to restore the default jQuery.';

            case 'js_prototype_pollution':
                return 'This plugin modifies built-in JavaScript prototypes, which can cause unpredictable behavior across all frontend code. Contact the plugin author.';

            case 'js_localize_collision':
                return 'Both plugins inject data using the same JavaScript variable name via wp_localize_script. The last plugin loaded wins. Contact one author to rename their variable.';

            case 'db_table_collision':
                return 'Both plugins create the same database table. This will cause installation failures or data corruption. Deactivate one plugin immediately.';

            case 'db_option_collision':
                return 'Both plugins use the same wp_options key. Data may be silently overwritten. Contact the plugin authors to prefix their option names.';

            case 'db_cron_collision':
                return 'Both plugins register the same WP-Cron hook name. Only one scheduled task will execute. Contact the authors to use unique hook names.';

            case 'db_cpt_collision':
                return 'Both plugins register the same custom post type slug. This causes a fatal error. Deactivate one plugin immediately.';

            case 'db_taxonomy_collision':
                return 'Both plugins register the same taxonomy slug. Only the last-registered taxonomy will work correctly.';

            case 'db_meta_collision':
                return 'Both plugins use the same post meta key with potentially different data formats. This may cause data corruption.';

            default:
                return 'Review the technical details and test your site thoroughly with these plugins active.';
        }
    }

    /**
     * Summarize conflicts by type and severity.
     */
    private function summarize_conflicts(array $conflicts): array {
        $summary = [
            'by_severity' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            'by_type'     => [],
        ];

        foreach ($conflicts as $conflict) {
            $severity = $conflict['severity'] ?? 'medium';
            $type = $conflict['type'] ?? 'unknown';

            $summary['by_severity'][$severity] = ($summary['by_severity'][$severity] ?? 0) + 1;
            $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Get active third-party plugins (excluding this plugin and mu-plugins).
     *
     * @return array<string> Plugin file paths.
     */
    private function get_active_third_party_plugins(): array {
        $active = get_option('active_plugins', []);

        return array_values(array_filter($active, function (string $plugin): bool {
            return $plugin !== JETSTRIKE_CD_BASENAME;
        }));
    }

    /**
     * Acquire a processing lock.
     */
    private function acquire_lock(int $scan_id): bool {
        $lock_value = get_option(self::LOCK_OPTION);

        if ($lock_value !== false) {
            $lock_data = json_decode($lock_value, true);

            if (is_array($lock_data) && ($lock_data['expires'] ?? 0) > time()) {
                return false; // Lock is held.
            }
        }

        $lock = wp_json_encode([
            'scan_id' => $scan_id,
            'expires' => time() + self::LOCK_TTL,
        ]);

        update_option(self::LOCK_OPTION, $lock);

        return true;
    }

    /**
     * Release the processing lock.
     */
    private function release_lock(): void {
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Get the ID of a currently running scan.
     */
    private function get_running_scan_id(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'jetstrike_scans';

        $id = $wpdb->get_var(
            "SELECT id FROM {$table} WHERE status IN ('queued', 'running') ORDER BY created_at DESC LIMIT 1"
        );

        return $id ? (int) $id : 0;
    }
}
