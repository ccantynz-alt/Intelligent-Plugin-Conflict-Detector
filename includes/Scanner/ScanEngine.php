<?php
/**
 * Core scan engine — orchestrates all analyzers and runtime testing.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Scanner;

use Jetstrike\ConflictDetector\Analyzer\StaticAnalyzer;
use Jetstrike\ConflictDetector\Analyzer\HookAnalyzer;
use Jetstrike\ConflictDetector\Analyzer\ResourceAnalyzer;
use Jetstrike\ConflictDetector\Analyzer\PerformanceAnalyzer;
use Jetstrike\ConflictDetector\Analyzer\WooCommerceAnalyzer;
use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Subscription\FeatureFlags;

final class ScanEngine {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Run a complete scan (static + runtime).
     *
     * @param array<string> $plugins Active plugin file paths.
     * @param string        $scan_type Scan type for feature gating.
     * @return array All conflicts found.
     */
    public function run(array $plugins, string $scan_type = 'full'): array {
        $all_conflicts = [];

        // Phase 0: Check cloud intelligence for already-known conflicts (instant).
        $cloud = new \Jetstrike\ConflictDetector\Cloud\ConflictIntelligence();
        $known = $cloud->query_known_conflicts($plugins);
        $all_conflicts = array_merge($all_conflicts, $known);

        // Phase 1: Static analysis (available to all tiers).
        $all_conflicts = array_merge($all_conflicts, $this->run_static_analysis($plugins));

        // Phase 2: Runtime testing (full scan only, requires sandbox).
        if ($scan_type === 'full') {
            $runtime = $this->run_runtime_scan($plugins);
            $all_conflicts = array_merge($all_conflicts, $runtime);
        }

        // Phase 3: Performance analysis.
        if (FeatureFlags::can('advanced_performance')) {
            $perf = $this->run_performance_analysis($plugins);
            $all_conflicts = array_merge($all_conflicts, $perf);
        }

        return $all_conflicts;
    }

    /**
     * Run all static analyzers.
     *
     * @param array<string> $plugins Plugin file paths.
     * @return array Conflicts found.
     */
    public function run_static_analysis(array $plugins): array {
        $conflicts = [];

        // 1. Code-level analysis (functions, classes, globals).
        $static = new StaticAnalyzer();
        $conflicts = array_merge($conflicts, $static->analyze($plugins));

        // 2. Hook/filter analysis.
        $hooks = new HookAnalyzer();
        $conflicts = array_merge($conflicts, $hooks->analyze($plugins));

        // 3. Resource analysis (scripts, styles, shortcodes, REST).
        $resources = new ResourceAnalyzer();
        $conflicts = array_merge($conflicts, $resources->analyze($plugins));

        // 4. WooCommerce-specific analysis (if WC is active and user has Pro).
        if (class_exists('WooCommerce') && FeatureFlags::can('woocommerce_deep')) {
            $woo = new WooCommerceAnalyzer();
            $conflicts = array_merge($conflicts, $woo->analyze($plugins));
        }

        return $conflicts;
    }

    /**
     * Run runtime sandbox testing with binary search.
     *
     * @param array<string> $plugins Plugin file paths.
     * @return array Conflicts found.
     */
    public function run_runtime_scan(array $plugins): array {
        $settings = get_option('jetstrike_cd_settings', []);
        $threshold = (float) ($settings['performance_threshold'] ?? 3.0);

        $sandbox = new SandboxEnvironment($threshold);

        if (! $sandbox->initialize()) {
            return [[
                'type'        => 'performance_degradation',
                'plugin_a'    => '',
                'plugin_b'    => '',
                'severity'    => 'low',
                'description' => 'Runtime testing skipped — loopback requests are not available on this server.',
                'details'     => ['loopback_failed' => true],
            ]];
        }

        try {
            $binary = new BinarySearch($sandbox);
            $result = $binary->find_conflicts($plugins);

            $conflicts = [];

            foreach ($result['conflicts'] as $pair) {
                $severity = $pair['result']['fatal'] ? 'critical' : 'high';

                $conflicts[] = [
                    'type'        => $pair['result']['fatal'] ? 'fatal_error' : 'hook_conflict',
                    'plugin_a'    => $pair['plugin_a'],
                    'plugin_b'    => $pair['plugin_b'],
                    'severity'    => $severity,
                    'description' => sprintf(
                        'Runtime conflict detected: %s (HTTP %d, %.0fms)',
                        implode('; ', $pair['result']['errors']),
                        $pair['result']['http_status'],
                        $pair['result']['time_ms']
                    ),
                    'details' => [
                        'http_status' => $pair['result']['http_status'],
                        'time_ms'     => $pair['result']['time_ms'],
                        'errors'      => $pair['result']['errors'],
                        'warnings'    => $pair['result']['warnings'],
                        'fatal'       => $pair['result']['fatal'],
                        'runtime'     => true,
                    ],
                ];
            }

            return $conflicts;
        } finally {
            $sandbox->cleanup();
        }
    }

    /**
     * Run performance analysis.
     *
     * @param array<string> $plugins Plugin file paths.
     * @return array Performance conflicts found.
     */
    private function run_performance_analysis(array $plugins): array {
        $settings = get_option('jetstrike_cd_settings', []);
        $threshold = (float) ($settings['performance_threshold'] ?? 3.0);

        $analyzer = new PerformanceAnalyzer($threshold);
        $result = $analyzer->analyze($plugins);

        return $result['conflicts'] ?? [];
    }
}
