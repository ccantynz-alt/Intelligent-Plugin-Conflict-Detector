<?php
/**
 * WP-CLI Commands — full command-line interface for Jetstrike.
 *
 * Provides professional developer tooling:
 *   wp jetstrike scan [--type=<type>] [--format=<format>]
 *   wp jetstrike conflicts [--severity=<severity>] [--format=<format>]
 *   wp jetstrike fix <conflict_id> [--dry-run]
 *   wp jetstrike revert <conflict_id>
 *   wp jetstrike patches [--format=<format>]
 *   wp jetstrike status
 *   wp jetstrike health
 *   wp jetstrike reset
 *
 * Enables CI/CD pipeline integration — run conflict detection as part of
 * automated deployment workflows.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\CLI;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanEngine;
use Jetstrike\ConflictDetector\Resolver\AutoResolver;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;

final class Commands {

    /** @var Repository */
    private Repository $repository;

    public function __construct() {
        $this->repository = new Repository();
    }

    /**
     * Register all WP-CLI commands.
     */
    public static function register(): void {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        $instance = new self();

        \WP_CLI::add_command('jetstrike scan', [$instance, 'scan']);
        \WP_CLI::add_command('jetstrike conflicts', [$instance, 'conflicts']);
        \WP_CLI::add_command('jetstrike fix', [$instance, 'fix']);
        \WP_CLI::add_command('jetstrike revert', [$instance, 'revert']);
        \WP_CLI::add_command('jetstrike patches', [$instance, 'patches']);
        \WP_CLI::add_command('jetstrike status', [$instance, 'status']);
        \WP_CLI::add_command('jetstrike health', [$instance, 'health']);
        \WP_CLI::add_command('jetstrike reset', [$instance, 'reset']);
    }

    /**
     * Run a conflict detection scan.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Scan type to run.
     * ---
     * default: quick
     * options:
     *   - quick
     *   - full
     *   - targeted
     * ---
     *
     * [--target=<plugin>]
     * : Target plugin slug for targeted scans.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     # Quick static analysis scan
     *     wp jetstrike scan
     *
     *     # Full scan with runtime testing
     *     wp jetstrike scan --type=full
     *
     *     # Scan a specific plugin
     *     wp jetstrike scan --type=targeted --target=woocommerce
     *
     *     # JSON output for CI/CD
     *     wp jetstrike scan --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function scan(array $args, array $assoc_args): void {
        $type   = $assoc_args['type'] ?? 'quick';
        $format = $assoc_args['format'] ?? 'table';
        $target = $assoc_args['target'] ?? '';

        $active_plugins = get_option('active_plugins', []);
        $plugins = array_values(array_filter($active_plugins, function (string $p): bool {
            return $p !== JETSTRIKE_CD_BASENAME;
        }));

        $plugin_count = count($plugins);
        \WP_CLI::log(sprintf('Found %d active plugin(s) to scan.', $plugin_count));

        if ($plugin_count === 0) {
            \WP_CLI::success('No third-party plugins active. Nothing to scan.');
            return;
        }

        $engine = new ScanEngine($this->repository);

        $start = microtime(true);

        switch ($type) {
            case 'quick':
                \WP_CLI::log('Running quick scan (static analysis)...');
                $conflicts = $engine->run_static_analysis($plugins);
                break;

            case 'full':
                \WP_CLI::log('Running full scan (static + runtime)...');
                $conflicts = $engine->run($plugins, 'full');
                break;

            case 'targeted':
                if (empty($target)) {
                    \WP_CLI::error('--target is required for targeted scans.');
                    return;
                }

                $target_file = $this->find_plugin_file($target, $plugins);

                if ($target_file === null) {
                    \WP_CLI::error(sprintf('Plugin "%s" not found in active plugins.', $target));
                    return;
                }

                \WP_CLI::log(sprintf('Running targeted scan for %s...', $target));
                $all = $engine->run_static_analysis($plugins);
                $conflicts = array_filter($all, function (array $c) use ($target_file): bool {
                    return $c['plugin_a'] === $target_file || $c['plugin_b'] === $target_file;
                });
                $conflicts = array_values($conflicts);
                break;

            default:
                \WP_CLI::error(sprintf('Unknown scan type: %s', $type));
                return;
        }

        $elapsed = round(microtime(true) - $start, 2);

        // Store scan in database.
        $scan_id = $this->repository->create_scan([
            'scan_type'       => $type,
            'status'          => 'completed',
            'triggered_by'    => 'cli',
            'plugins_tested'  => wp_json_encode($plugins),
            'conflicts_found' => count($conflicts),
            'started_at'      => current_time('mysql', true),
            'completed_at'    => current_time('mysql', true),
            'results'         => wp_json_encode(['total_conflicts' => count($conflicts)]),
        ]);

        // Store conflicts.
        foreach ($conflicts as $conflict) {
            if (! $this->repository->conflict_exists(
                $conflict['plugin_a'],
                $conflict['plugin_b'] ?? '',
                $conflict['type']
            )) {
                $this->repository->create_conflict([
                    'scan_id'           => $scan_id,
                    'plugin_a'          => $conflict['plugin_a'],
                    'plugin_b'          => $conflict['plugin_b'] ?? '',
                    'conflict_type'     => $conflict['type'],
                    'severity'          => $conflict['severity'],
                    'description'       => $conflict['description'],
                    'technical_details' => wp_json_encode($conflict['details'] ?? []),
                    'recommendation'    => '',
                ]);
            }
        }

        $this->output_conflicts($conflicts, $format);

        \WP_CLI::log('');
        \WP_CLI::log(sprintf('Scan completed in %ss. %d conflict(s) found. (Scan ID: %d)', $elapsed, count($conflicts), $scan_id));

        if (count($conflicts) > 0) {
            $critical = count(array_filter($conflicts, fn(array $c): bool => ($c['severity'] ?? '') === 'critical'));

            if ($critical > 0) {
                \WP_CLI::warning(sprintf('%d critical conflict(s) require immediate attention.', $critical));
            }
        } else {
            \WP_CLI::success('No conflicts detected.');
        }
    }

    /**
     * List all detected conflicts.
     *
     * ## OPTIONS
     *
     * [--severity=<severity>]
     * : Filter by severity.
     * ---
     * options:
     *   - critical
     *   - high
     *   - medium
     *   - low
     * ---
     *
     * [--status=<status>]
     * : Filter by status.
     * ---
     * default: active
     * options:
     *   - active
     *   - resolved
     *   - ignored
     *   - false_positive
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp jetstrike conflicts
     *     wp jetstrike conflicts --severity=critical
     *     wp jetstrike conflicts --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function conflicts(array $args, array $assoc_args): void {
        $severity = $assoc_args['severity'] ?? '';
        $status   = $assoc_args['status'] ?? 'active';
        $format   = $assoc_args['format'] ?? 'table';

        $all_conflicts = $this->repository->list_active_conflicts(1, 100);
        $conflicts = [];

        foreach ($all_conflicts as $c) {
            if (! empty($severity) && $c->severity !== $severity) {
                continue;
            }

            if (! empty($status) && $c->status !== $status) {
                continue;
            }

            $auto_fix = AutoResolver::can_auto_resolve($c->conflict_type);

            $conflicts[] = [
                'ID'          => $c->id,
                'Type'        => $c->conflict_type,
                'Severity'    => strtoupper($c->severity),
                'Plugin A'    => dirname($c->plugin_a),
                'Plugin B'    => dirname($c->plugin_b),
                'Auto-Fix'    => $auto_fix['can_resolve'] ? 'Yes' : 'No',
                'Description' => substr($c->description, 0, 80),
            ];
        }

        if ($format === 'count') {
            \WP_CLI::log((string) count($conflicts));
            return;
        }

        if ($format === 'json') {
            \WP_CLI::log(wp_json_encode($conflicts, JSON_PRETTY_PRINT));
            return;
        }

        if (empty($conflicts)) {
            \WP_CLI::success('No active conflicts found.');
            return;
        }

        \WP_CLI\Utils\format_items($format, $conflicts, array_keys($conflicts[0]));
    }

    /**
     * Auto-fix a detected conflict.
     *
     * ## OPTIONS
     *
     * <conflict_id>
     * : The conflict ID to fix.
     *
     * [--dry-run]
     * : Show what would be done without applying the fix.
     *
     * ## EXAMPLES
     *
     *     wp jetstrike fix 42
     *     wp jetstrike fix 42 --dry-run
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function fix(array $args, array $assoc_args): void {
        $conflict_id = (int) ($args[0] ?? 0);
        $dry_run     = isset($assoc_args['dry-run']);

        if ($conflict_id <= 0) {
            \WP_CLI::error('Please provide a valid conflict ID.');
            return;
        }

        $conflict = $this->repository->get_conflict($conflict_id);

        if ($conflict === null) {
            \WP_CLI::error(sprintf('Conflict #%d not found.', $conflict_id));
            return;
        }

        $can_fix = AutoResolver::can_auto_resolve($conflict->conflict_type);

        if (! $can_fix['can_resolve']) {
            \WP_CLI::error(sprintf(
                'Conflict #%d (%s) cannot be auto-fixed. %s',
                $conflict_id,
                $conflict->conflict_type,
                $can_fix['description']
            ));
            return;
        }

        if ($dry_run) {
            \WP_CLI::log('--- DRY RUN ---');
            \WP_CLI::log(sprintf('Conflict #%d: %s', $conflict_id, $conflict->conflict_type));
            \WP_CLI::log(sprintf('Plugin A: %s', dirname($conflict->plugin_a)));
            \WP_CLI::log(sprintf('Plugin B: %s', dirname($conflict->plugin_b)));
            \WP_CLI::log(sprintf('Fix strategy: %s', $can_fix['description']));
            \WP_CLI::log('A mu-plugin patch would be generated in: ' . WPMU_PLUGIN_DIR . '/jetstrike-patches/');
            \WP_CLI::success('Dry run complete. Use without --dry-run to apply the fix.');
            return;
        }

        \WP_CLI::log(sprintf('Applying auto-fix for conflict #%d...', $conflict_id));

        $resolver = new AutoResolver($this->repository);
        $result = $resolver->resolve($conflict_id);

        if ($result['success']) {
            \WP_CLI::success($result['message']);

            if (! empty($result['patch_file'])) {
                \WP_CLI::log(sprintf('Patch file: %s', $result['patch_file']));
            }
        } else {
            \WP_CLI::error($result['message']);
        }
    }

    /**
     * Revert an auto-fix.
     *
     * ## OPTIONS
     *
     * <conflict_id>
     * : The conflict ID whose fix should be reverted.
     *
     * ## EXAMPLES
     *
     *     wp jetstrike revert 42
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function revert(array $args, array $assoc_args): void {
        $conflict_id = (int) ($args[0] ?? 0);

        if ($conflict_id <= 0) {
            \WP_CLI::error('Please provide a valid conflict ID.');
            return;
        }

        $resolver = new AutoResolver($this->repository);
        $reverted = $resolver->revert($conflict_id);

        if ($reverted) {
            \WP_CLI::success(sprintf('Auto-fix for conflict #%d has been reverted.', $conflict_id));
        } else {
            \WP_CLI::error(sprintf('No auto-fix found for conflict #%d.', $conflict_id));
        }
    }

    /**
     * List all active compatibility patches.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp jetstrike patches
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function patches(array $args, array $assoc_args): void {
        $format = $assoc_args['format'] ?? 'table';

        $resolver = new AutoResolver($this->repository);
        $patches = $resolver->get_active_patches();

        if (empty($patches)) {
            \WP_CLI::log('No active Jetstrike patches.');
            return;
        }

        $items = array_map(function (array $p): array {
            return [
                'Conflict ID' => $p['conflict_id'],
                'File'        => $p['file'],
                'Method'      => $p['method'],
                'Active'      => $p['active'] ? 'Yes' : 'No',
                'Created'     => $p['created_at'],
            ];
        }, $patches);

        \WP_CLI\Utils\format_items($format, $items, array_keys($items[0]));
    }

    /**
     * Show system status and scan summary.
     *
     * ## EXAMPLES
     *
     *     wp jetstrike status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function status(array $args, array $assoc_args): void {
        $active_plugins = count(get_option('active_plugins', [])) - 1; // Exclude self.
        $summary = $this->repository->get_conflict_summary();
        $latest_scan = $this->repository->get_latest_scan();

        \WP_CLI::log('=== Jetstrike Conflict Detector ===');
        \WP_CLI::log('');
        \WP_CLI::log(sprintf('Version:         %s', JETSTRIKE_CD_VERSION));
        \WP_CLI::log(sprintf('Active Plugins:  %d', $active_plugins));
        \WP_CLI::log('');
        \WP_CLI::log('--- Conflict Summary ---');
        \WP_CLI::log(sprintf('Critical:  %d', $summary['critical'] ?? 0));
        \WP_CLI::log(sprintf('High:      %d', $summary['high'] ?? 0));
        \WP_CLI::log(sprintf('Medium:    %d', $summary['medium'] ?? 0));
        \WP_CLI::log(sprintf('Low:       %d', $summary['low'] ?? 0));
        \WP_CLI::log('');

        if ($latest_scan) {
            \WP_CLI::log('--- Last Scan ---');
            \WP_CLI::log(sprintf('Type:      %s', $latest_scan->scan_type));
            \WP_CLI::log(sprintf('Status:    %s', $latest_scan->status));
            \WP_CLI::log(sprintf('Date:      %s', $latest_scan->completed_at ?? $latest_scan->created_at));
            \WP_CLI::log(sprintf('Conflicts: %d', $latest_scan->conflicts_found));
        } else {
            \WP_CLI::log('No scans have been run yet. Run: wp jetstrike scan');
        }
    }

    /**
     * Show site health score.
     *
     * ## EXAMPLES
     *
     *     wp jetstrike health
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function health(array $args, array $assoc_args): void {
        $monitor = new HealthMonitor($this->repository);
        $health = $monitor->get_health_data();

        $grade = $health['grade'] ?? 'F';
        $score = $health['score'] ?? 0;

        \WP_CLI::log('=== Site Health Score ===');
        \WP_CLI::log('');
        \WP_CLI::log(sprintf('  Grade: %s (%d/100)', $grade, $score));
        \WP_CLI::log('');

        $components = $health['components'] ?? [];

        if (! empty($components)) {
            \WP_CLI::log('Components:');

            foreach ($components as $name => $value) {
                \WP_CLI::log(sprintf('  %-25s %s', $name . ':', is_array($value) ? wp_json_encode($value) : $value));
            }
        }

        \WP_CLI::log('');

        if ($score >= 80) {
            \WP_CLI::success('Your site is in good health.');
        } elseif ($score >= 50) {
            \WP_CLI::warning('Your site has some issues that should be addressed.');
        } else {
            \WP_CLI::error('Your site has critical issues. Run a scan immediately.');
        }
    }

    /**
     * Reset all Jetstrike data (scans, conflicts, patches).
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp jetstrike reset --yes
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function reset(array $args, array $assoc_args): void {
        \WP_CLI::confirm('This will delete all scan history, detected conflicts, and applied patches. Continue?', $assoc_args);

        // Remove all patches.
        $patch_gen = new \Jetstrike\ConflictDetector\Resolver\CompatibilityPatch();
        $removed = $patch_gen->remove_all_patches();

        \WP_CLI::log(sprintf('Removed %d patch file(s).', $removed));

        // Purge database.
        $this->repository->purge_old_scans(0);

        // Clear options and transients.
        delete_option('jetstrike_cd_resolutions');
        delete_transient('jetstrike_cd_scan_results');
        delete_transient('jetstrike_cd_conflict_summary');

        \WP_CLI::success('All Jetstrike data has been reset.');
    }

    /**
     * Output conflicts in the requested format.
     *
     * @param array  $conflicts Raw conflict data.
     * @param string $format    Output format.
     */
    private function output_conflicts(array $conflicts, string $format): void {
        if ($format === 'count') {
            \WP_CLI::log((string) count($conflicts));
            return;
        }

        if (empty($conflicts)) {
            return;
        }

        if ($format === 'json') {
            \WP_CLI::log(wp_json_encode($conflicts, JSON_PRETTY_PRINT));
            return;
        }

        $items = array_map(function (array $c): array {
            $auto_fix = AutoResolver::can_auto_resolve($c['type']);

            return [
                'Type'     => $c['type'],
                'Severity' => strtoupper($c['severity']),
                'Plugin A' => dirname($c['plugin_a'] ?? ''),
                'Plugin B' => dirname($c['plugin_b'] ?? ''),
                'Auto-Fix' => $auto_fix['can_resolve'] ? 'Yes' : 'No',
                'Details'  => substr($c['description'], 0, 60),
            ];
        }, $conflicts);

        \WP_CLI\Utils\format_items($format, $items, array_keys($items[0]));
    }

    /**
     * Find a plugin file path from a slug.
     *
     * @param string $slug    Plugin slug.
     * @param array  $plugins Active plugin file paths.
     * @return string|null Plugin file path or null.
     */
    private function find_plugin_file(string $slug, array $plugins): ?string {
        foreach ($plugins as $plugin) {
            if (dirname($plugin) === $slug || $plugin === $slug) {
                return $plugin;
            }
        }

        return null;
    }
}
