<?php
/**
 * Performance impact analyzer — measures plugin load time, memory, and query overhead.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class PerformanceAnalyzer {

    /** @var float Performance degradation multiplier threshold. */
    private float $threshold;

    public function __construct(float $threshold = 3.0) {
        $this->threshold = $threshold;
    }

    /**
     * Get performance profile for all active plugins.
     *
     * Uses loopback requests with individual plugins isolated to measure
     * each plugin's impact on response time, memory, and queries.
     *
     * @param array<string> $plugin_files Active plugin file paths.
     * @return array{profiles: array, conflicts: array}
     */
    public function analyze(array $plugin_files): array {
        $profiles = [];
        $conflicts = [];

        // Get baseline with no plugins (just core WordPress).
        $baseline = $this->measure_baseline();

        if ($baseline === null) {
            return ['profiles' => [], 'conflicts' => []];
        }

        // Measure each plugin's incremental impact.
        foreach ($plugin_files as $plugin_file) {
            $profile = $this->measure_plugin($plugin_file, $baseline);

            if ($profile !== null) {
                $profiles[$plugin_file] = $profile;

                // Flag plugins that exceed the performance threshold.
                if ($profile['time_multiplier'] >= $this->threshold) {
                    $conflicts[] = [
                        'type'        => 'performance_degradation',
                        'plugin_a'    => $plugin_file,
                        'plugin_b'    => '',
                        'severity'    => $profile['time_multiplier'] >= 5.0 ? 'high' : 'medium',
                        'description' => sprintf(
                            'Plugin increases page load time by %.1fx (%.0fms added). Baseline: %.0fms, With plugin: %.0fms.',
                            $profile['time_multiplier'],
                            $profile['time_added_ms'],
                            $baseline['time_ms'],
                            $profile['time_ms']
                        ),
                        'details' => [
                            'baseline_time_ms'  => $baseline['time_ms'],
                            'plugin_time_ms'    => $profile['time_ms'],
                            'time_added_ms'     => $profile['time_added_ms'],
                            'time_multiplier'   => $profile['time_multiplier'],
                            'memory_added_mb'   => $profile['memory_added_mb'],
                            'queries_added'     => $profile['queries_added'],
                        ],
                    ];
                }
            }
        }

        // Check for plugin pairs that are fine individually but slow together.
        $pair_conflicts = $this->find_compound_slowdowns($plugin_files, $profiles, $baseline);
        $conflicts = array_merge($conflicts, $pair_conflicts);

        return [
            'profiles'  => $profiles,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Measure baseline performance with no third-party plugins.
     *
     * @return array{time_ms: float, memory_mb: float, queries: int}|null
     */
    private function measure_baseline(): ?array {
        return $this->loopback_measure([]);
    }

    /**
     * Measure performance with a single plugin active.
     *
     * @param string $plugin_file Plugin to test.
     * @param array  $baseline    Baseline measurements.
     * @return array|null Plugin performance profile.
     */
    private function measure_plugin(string $plugin_file, array $baseline): ?array {
        $measurement = $this->loopback_measure([$plugin_file]);

        if ($measurement === null) {
            return null;
        }

        $time_added = $measurement['time_ms'] - $baseline['time_ms'];
        $time_multiplier = $baseline['time_ms'] > 0
            ? $measurement['time_ms'] / $baseline['time_ms']
            : 1.0;

        return [
            'time_ms'         => $measurement['time_ms'],
            'memory_mb'       => $measurement['memory_mb'],
            'queries'         => $measurement['queries'],
            'time_added_ms'   => max(0, $time_added),
            'time_multiplier' => round($time_multiplier, 2),
            'memory_added_mb' => max(0, round($measurement['memory_mb'] - $baseline['memory_mb'], 2)),
            'queries_added'   => max(0, $measurement['queries'] - $baseline['queries']),
            'score'           => $this->calculate_score($time_multiplier, $measurement['memory_mb'], $measurement['queries']),
        ];
    }

    /**
     * Find plugin pairs that cause compound performance degradation.
     *
     * Compares expected additive overhead vs actual combined overhead.
     *
     * @param array<string> $plugin_files All plugins.
     * @param array         $profiles     Individual profiles.
     * @param array         $baseline     Baseline measurements.
     * @return array Conflicts found.
     */
    private function find_compound_slowdowns(array $plugin_files, array $profiles, array $baseline): array {
        $conflicts = [];
        $count = count($plugin_files);

        // Only test pairs of plugins that are individually fast but might be slow together.
        // We sample up to 10 pairs to avoid excessive loopback requests.
        $pairs_tested = 0;
        $max_pairs = 10;

        for ($i = 0; $i < $count && $pairs_tested < $max_pairs; $i++) {
            for ($j = $i + 1; $j < $count && $pairs_tested < $max_pairs; $j++) {
                $a = $plugin_files[$i];
                $b = $plugin_files[$j];

                // Skip if either plugin is already flagged as slow individually.
                if (
                    (isset($profiles[$a]) && $profiles[$a]['time_multiplier'] >= $this->threshold) ||
                    (isset($profiles[$b]) && $profiles[$b]['time_multiplier'] >= $this->threshold)
                ) {
                    continue;
                }

                $combined = $this->loopback_measure([$a, $b]);
                $pairs_tested++;

                if ($combined === null) {
                    continue;
                }

                // Expected time = baseline + overhead_a + overhead_b.
                $expected_time = $baseline['time_ms']
                    + (isset($profiles[$a]) ? $profiles[$a]['time_added_ms'] : 0)
                    + (isset($profiles[$b]) ? $profiles[$b]['time_added_ms'] : 0);

                // If actual time is 2x+ the expected additive overhead, flag it.
                if ($expected_time > 0 && ($combined['time_ms'] / $expected_time) >= 2.0) {
                    $conflicts[] = [
                        'type'        => 'performance_degradation',
                        'plugin_a'    => $a,
                        'plugin_b'    => $b,
                        'severity'    => 'high',
                        'description' => sprintf(
                            'These plugins cause compound slowdown when active together. Expected: %.0fms, Actual: %.0fms (%.1fx worse than expected).',
                            $expected_time,
                            $combined['time_ms'],
                            $combined['time_ms'] / $expected_time
                        ),
                        'details' => [
                            'expected_time_ms' => $expected_time,
                            'actual_time_ms'   => $combined['time_ms'],
                            'compound_factor'  => round($combined['time_ms'] / $expected_time, 2),
                        ],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Perform a loopback request with specific plugins active and collect metrics.
     *
     * @param array<string> $active_plugins Plugins to activate for this measurement.
     * @return array{time_ms: float, memory_mb: float, queries: int}|null
     */
    private function loopback_measure(array $active_plugins): ?array {
        $token = wp_generate_password(32, false);
        set_transient('jetstrike_cd_perf_token', $token, 60);
        set_transient('jetstrike_cd_perf_plugins', $active_plugins, 60);

        $url = add_query_arg(
            [
                'jetstrike_perf_measure' => 1,
                'token' => $token,
            ],
            home_url('/')
        );

        $start = microtime(true);

        $response = wp_remote_get($url, [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [
                'X-Jetstrike-Perf' => $token,
            ],
        ]);

        $elapsed = (microtime(true) - $start) * 1000;

        delete_transient('jetstrike_cd_perf_token');
        delete_transient('jetstrike_cd_perf_plugins');

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status >= 500) {
            return null;
        }

        // Extract server-timing headers if available.
        $memory = 0.0;
        $queries = 0;

        $perf_header = wp_remote_retrieve_header($response, 'x-jetstrike-perf');

        if ($perf_header !== '' && $perf_header !== []) {
            $perf_data = json_decode(is_array($perf_header) ? $perf_header[0] : $perf_header, true);

            if (is_array($perf_data)) {
                $memory = $perf_data['memory_mb'] ?? 0.0;
                $queries = $perf_data['queries'] ?? 0;
            }
        }

        return [
            'time_ms'   => round($elapsed, 2),
            'memory_mb' => round($memory, 2),
            'queries'   => $queries,
        ];
    }

    /**
     * Calculate a performance score (0-100, where 100 = no impact).
     */
    private function calculate_score(float $time_multiplier, float $memory_mb, int $queries): int {
        $score = 100;

        // Deduct for time impact.
        if ($time_multiplier > 1.0) {
            $score -= (int) min(50, ($time_multiplier - 1.0) * 25);
        }

        // Deduct for memory impact (> 10MB is concerning).
        if ($memory_mb > 10) {
            $score -= (int) min(25, ($memory_mb - 10) * 2);
        }

        // Deduct for excessive queries (> 20 queries).
        if ($queries > 20) {
            $score -= (int) min(25, ($queries - 20));
        }

        return max(0, $score);
    }
}
