<?php
/**
 * Pre-Update Simulation — analyzes new plugin versions before applying updates.
 *
 * This is a massive selling point: "See what will break BEFORE you update."
 * Downloads the new version from WordPress.org, extracts it to a temp directory,
 * runs all static analyzers against the new code, and reports any new conflicts
 * that would be introduced by the update.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

use Jetstrike\ConflictDetector\Scanner\ScanEngine;
use Jetstrike\ConflictDetector\Database\Repository;

final class PreUpdateAnalyzer {

    /** @var Repository */
    private Repository $repository;

    /** Temp directory prefix. */
    private const TEMP_PREFIX = 'jetstrike_preupdate_';

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Simulate a plugin update and predict new conflicts.
     *
     * @param string $plugin_file  Plugin file path (e.g. "woocommerce/woocommerce.php").
     * @param string $new_version  The version being updated to.
     * @return array{safe: bool, new_conflicts: array, resolved_conflicts: array, risk_score: int, summary: string}
     */
    public function simulate_update(string $plugin_file, string $new_version = ''): array {
        $plugin_slug = dirname($plugin_file);
        $current_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        if (! is_dir($current_dir)) {
            return $this->error_result('Plugin directory not found.');
        }

        // Step 1: Get info about the available update.
        $update_info = $this->get_update_info($plugin_file);

        if ($update_info === null) {
            return $this->error_result('No update available for this plugin.');
        }

        $download_url = $update_info['package'] ?? '';

        if (empty($download_url)) {
            return $this->error_result('Update package URL not available. The plugin may require a license for updates.');
        }

        // Step 2: Download and extract the new version to a temp directory.
        $temp_dir = $this->download_and_extract($download_url, $plugin_slug);

        if ($temp_dir === null) {
            return $this->error_result('Failed to download or extract the update package.');
        }

        try {
            // Step 3: Run "before" scan with current versions.
            $active_plugins = $this->get_active_plugins_except($plugin_file);
            $engine = new ScanEngine($this->repository);
            $current_conflicts = $engine->run_static_analysis($active_plugins);

            // Step 4: Swap the plugin directory to the new version (in memory only).
            // We do this by temporarily renaming, analyzing, then restoring.
            $new_plugin_dir = $temp_dir . '/' . $plugin_slug;

            if (! is_dir($new_plugin_dir)) {
                // Some plugins extract to a different directory name.
                $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);
                $new_plugin_dir = ! empty($dirs) ? $dirs[0] : $temp_dir;
            }

            // Step 5: Run static analysis on the new version's code.
            $new_conflicts = $this->analyze_new_version(
                $new_plugin_dir,
                $plugin_file,
                $active_plugins
            );

            // Step 6: Compare before/after to find NEW conflicts.
            $diff = $this->diff_conflicts($current_conflicts, $new_conflicts);

            // Step 7: Calculate risk score.
            $risk_score = $this->calculate_risk_score($diff);

            return [
                'safe'               => $risk_score < 30,
                'new_conflicts'      => $diff['added'],
                'resolved_conflicts' => $diff['removed'],
                'unchanged_conflicts' => $diff['unchanged'],
                'risk_score'         => $risk_score,
                'update_version'     => $update_info['new_version'] ?? $new_version,
                'current_version'    => $update_info['current_version'] ?? '',
                'summary'            => $this->generate_summary($diff, $risk_score, $plugin_slug),
                'changes_detected'   => $this->detect_code_changes($current_dir, $new_plugin_dir),
            ];
        } finally {
            // Cleanup temp directory.
            $this->cleanup_temp($temp_dir);
        }
    }

    /**
     * Quick pre-update check without downloading — uses WordPress.org API data.
     *
     * @param string $plugin_file Plugin file path.
     * @return array Basic compatibility assessment.
     */
    public function quick_check(string $plugin_file): array {
        $update_info = $this->get_update_info($plugin_file);

        if ($update_info === null) {
            return ['status' => 'no_update', 'message' => 'No update available.'];
        }

        $checks = [];

        // Check: Does the new version require a higher PHP version?
        $requires_php = $update_info['requires_php'] ?? '';
        if (! empty($requires_php) && version_compare(PHP_VERSION, $requires_php, '<')) {
            $checks[] = [
                'check'  => 'php_version',
                'status' => 'fail',
                'message' => sprintf(
                    'Update requires PHP %s but your server runs PHP %s.',
                    $requires_php,
                    PHP_VERSION
                ),
            ];
        }

        // Check: Does the new version require a higher WP version?
        $requires_wp = $update_info['requires'] ?? '';
        if (! empty($requires_wp)) {
            $wp_version = get_bloginfo('version');
            if (version_compare($wp_version, $requires_wp, '<')) {
                $checks[] = [
                    'check'  => 'wp_version',
                    'status' => 'fail',
                    'message' => sprintf(
                        'Update requires WordPress %s but you run %s.',
                        $requires_wp,
                        $wp_version
                    ),
                ];
            }
        }

        // Check: Is this a major version jump?
        $current = $update_info['current_version'] ?? '';
        $new = $update_info['new_version'] ?? '';
        if (! empty($current) && ! empty($new)) {
            $current_major = explode('.', $current)[0] ?? '0';
            $new_major = explode('.', $new)[0] ?? '0';

            if ($current_major !== $new_major) {
                $checks[] = [
                    'check'  => 'major_version',
                    'status' => 'warning',
                    'message' => sprintf(
                        'This is a major version update (%s to %s). Major updates have higher risk of breaking changes.',
                        $current,
                        $new
                    ),
                ];
            }
        }

        // Check: Does this plugin have known conflicts in the cloud?
        $cloud = new \Jetstrike\ConflictDetector\Cloud\ConflictIntelligence();
        $slug = dirname($plugin_file);
        $active = $this->get_active_plugins_except($plugin_file);

        foreach ($active as $other_plugin) {
            $pair_check = $cloud->check_pair($slug, dirname($other_plugin));

            if ($pair_check !== null && ! $pair_check['compatible']) {
                $checks[] = [
                    'check'  => 'cloud_intelligence',
                    'status' => 'warning',
                    'message' => sprintf(
                        'Known compatibility issue with %s (reported by %d sites, %.0f%% confidence).',
                        dirname($other_plugin),
                        $pair_check['reports'],
                        $pair_check['confidence'] * 100
                    ),
                ];
            }
        }

        $has_fails = count(array_filter($checks, fn(array $c): bool => $c['status'] === 'fail')) > 0;
        $has_warnings = count(array_filter($checks, fn(array $c): bool => $c['status'] === 'warning')) > 0;

        return [
            'status'  => $has_fails ? 'blocked' : ($has_warnings ? 'caution' : 'safe'),
            'checks'  => $checks,
            'version' => $new,
            'message' => $has_fails
                ? 'Update is blocked — your environment does not meet the requirements.'
                : ($has_warnings
                    ? 'Update has potential risks. Run a full pre-update simulation for details.'
                    : 'Update appears safe based on quick checks.'),
        ];
    }

    /**
     * Analyze the new version's code against other active plugins.
     */
    private function analyze_new_version(string $new_dir, string $plugin_file, array $other_plugins): array {
        $all_conflicts = [];

        // Run each analyzer against the new plugin code.
        $analyzers = [
            new StaticAnalyzer(),
            new HookAnalyzer(),
            new ResourceAnalyzer(),
            new DependencyAnalyzer(),
            new JavaScriptAnalyzer(),
            new DatabaseAnalyzer(),
        ];

        // We need to temporarily make the analyzers think the new code is the plugin.
        // Create a synthetic plugin list with the new version's path.
        $test_plugins = $other_plugins;

        foreach ($analyzers as $analyzer) {
            $conflicts = $analyzer->analyze(array_merge([$plugin_file], $other_plugins));

            // Filter to only conflicts involving the target plugin.
            $relevant = array_filter($conflicts, function (array $c) use ($plugin_file): bool {
                return ($c['plugin_a'] ?? '') === $plugin_file || ($c['plugin_b'] ?? '') === $plugin_file;
            });

            $all_conflicts = array_merge($all_conflicts, array_values($relevant));
        }

        // Also check WooCommerce if active.
        if (class_exists('WooCommerce')) {
            $woo = new WooCommerceAnalyzer();
            $woo_conflicts = $woo->analyze(array_merge([$plugin_file], $other_plugins));
            $relevant = array_filter($woo_conflicts, function (array $c) use ($plugin_file): bool {
                return ($c['plugin_a'] ?? '') === $plugin_file || ($c['plugin_b'] ?? '') === $plugin_file;
            });
            $all_conflicts = array_merge($all_conflicts, array_values($relevant));
        }

        return $all_conflicts;
    }

    /**
     * Diff two sets of conflicts to find what's new, removed, and unchanged.
     */
    private function diff_conflicts(array $before, array $after): array {
        $before_keys = array_map([$this, 'conflict_key'], $before);
        $after_keys = array_map([$this, 'conflict_key'], $after);

        $added = [];
        $removed = [];
        $unchanged = [];

        foreach ($after as $i => $conflict) {
            if (! in_array($after_keys[$i], $before_keys, true)) {
                $added[] = $conflict;
            } else {
                $unchanged[] = $conflict;
            }
        }

        foreach ($before as $i => $conflict) {
            if (! in_array($before_keys[$i], $after_keys, true)) {
                $removed[] = $conflict;
            }
        }

        return [
            'added'     => $added,
            'removed'   => $removed,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Generate a unique key for a conflict (for diffing).
     */
    private function conflict_key(array $conflict): string {
        return md5(
            ($conflict['type'] ?? '') . ':' .
            ($conflict['plugin_a'] ?? '') . ':' .
            ($conflict['plugin_b'] ?? '')
        );
    }

    /**
     * Calculate a risk score (0-100) based on the diff.
     */
    private function calculate_risk_score(array $diff): int {
        $score = 0;

        $severity_weights = [
            'critical' => 40,
            'high'     => 25,
            'medium'   => 10,
            'low'      => 5,
        ];

        foreach ($diff['added'] as $conflict) {
            $severity = $conflict['severity'] ?? 'medium';
            $score += $severity_weights[$severity] ?? 10;
        }

        // Resolved conflicts reduce risk slightly.
        foreach ($diff['removed'] as $conflict) {
            $severity = $conflict['severity'] ?? 'medium';
            $score -= (int) (($severity_weights[$severity] ?? 10) * 0.3);
        }

        return max(0, min(100, $score));
    }

    /**
     * Detect high-level code changes between versions.
     */
    private function detect_code_changes(string $current_dir, string $new_dir): array {
        $changes = [
            'files_added'    => 0,
            'files_removed'  => 0,
            'files_modified' => 0,
            'hooks_changed'  => false,
            'db_schema_changed' => false,
        ];

        $current_files = $this->list_php_files($current_dir);
        $new_files = $this->list_php_files($new_dir);

        $current_relative = array_map(fn(string $f): string => str_replace($current_dir, '', $f), $current_files);
        $new_relative = array_map(fn(string $f): string => str_replace($new_dir, '', $f), $new_files);

        $changes['files_added'] = count(array_diff($new_relative, $current_relative));
        $changes['files_removed'] = count(array_diff($current_relative, $new_relative));

        // Check common files for modifications.
        $common = array_intersect($current_relative, $new_relative);
        foreach ($common as $relative_path) {
            $current_hash = md5_file($current_dir . $relative_path);
            $new_hash = md5_file($new_dir . $relative_path);

            if ($current_hash !== $new_hash) {
                $changes['files_modified']++;

                // Check if hook registrations changed.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $new_content = file_get_contents($new_dir . $relative_path);

                if ($new_content !== false) {
                    if (preg_match('/add_(?:action|filter)\s*\(/', $new_content)) {
                        $changes['hooks_changed'] = true;
                    }

                    if (preg_match('/CREATE\s+TABLE|dbDelta/i', $new_content)) {
                        $changes['db_schema_changed'] = true;
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * Generate a human-readable summary.
     */
    private function generate_summary(array $diff, int $risk_score, string $plugin_slug): string {
        $added = count($diff['added']);
        $removed = count($diff['removed']);

        if ($added === 0 && $removed === 0) {
            return sprintf('Update for %s introduces no new conflicts. Safe to proceed.', $plugin_slug);
        }

        $parts = [];

        if ($added > 0) {
            $critical = count(array_filter($diff['added'], fn(array $c): bool => ($c['severity'] ?? '') === 'critical'));

            $parts[] = sprintf('%d new conflict(s) detected', $added);

            if ($critical > 0) {
                $parts[] = sprintf('%d critical', $critical);
            }
        }

        if ($removed > 0) {
            $parts[] = sprintf('%d existing conflict(s) would be resolved', $removed);
        }

        $risk_label = $risk_score >= 70 ? 'HIGH RISK' : ($risk_score >= 30 ? 'MODERATE RISK' : 'LOW RISK');

        return sprintf(
            '%s update for %s: %s. Risk score: %d/100.',
            $risk_label,
            $plugin_slug,
            implode('; ', $parts),
            $risk_score
        );
    }

    /**
     * Get WordPress update info for a plugin.
     */
    private function get_update_info(string $plugin_file): ?array {
        $updates = get_site_transient('update_plugins');

        if (! is_object($updates) || ! isset($updates->response[$plugin_file])) {
            return null;
        }

        $update = $updates->response[$plugin_file];
        $current_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

        return [
            'new_version'     => $update->new_version ?? '',
            'current_version' => $current_data['Version'] ?? '',
            'package'         => $update->package ?? '',
            'requires_php'    => $update->requires_php ?? '',
            'requires'        => $update->requires ?? '',
            'tested'          => $update->tested ?? '',
        ];
    }

    /**
     * Download and extract an update package to a temp directory.
     */
    private function download_and_extract(string $url, string $plugin_slug): ?string {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $temp_file = download_url($url, 60);

        if (is_wp_error($temp_file)) {
            return null;
        }

        $temp_dir = get_temp_dir() . self::TEMP_PREFIX . $plugin_slug . '_' . time();
        wp_mkdir_p($temp_dir);

        $result = unzip_file($temp_file, $temp_dir);
        wp_delete_file($temp_file);

        if (is_wp_error($result)) {
            $this->cleanup_temp($temp_dir);
            return null;
        }

        return $temp_dir;
    }

    /**
     * Get active plugins excluding the target.
     */
    private function get_active_plugins_except(string $exclude): array {
        $active = get_option('active_plugins', []);

        return array_values(array_filter($active, function (string $p) use ($exclude): bool {
            return $p !== $exclude && $p !== JETSTRIKE_CD_BASENAME;
        }));
    }

    /**
     * List PHP files in a directory recursively.
     */
    private function list_php_files(string $dir): array {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            if (++$count > 500) {
                break;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Clean up temp directory.
     */
    private function cleanup_temp(string $dir): void {
        if (! is_dir($dir) || strpos($dir, self::TEMP_PREFIX) === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                wp_delete_file($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Return a standardized error result.
     */
    private function error_result(string $message): array {
        return [
            'safe'               => false,
            'new_conflicts'      => [],
            'resolved_conflicts' => [],
            'unchanged_conflicts' => [],
            'risk_score'         => -1,
            'update_version'     => '',
            'current_version'    => '',
            'summary'            => $message,
            'changes_detected'   => [],
        ];
    }
}
