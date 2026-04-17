<?php
/**
 * Binary search algorithm for efficient conflict isolation.
 *
 * Instead of testing all O(N²) plugin pairs, uses divide-and-conquer
 * to narrow down conflicting pairs in O(N log N) tests.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Scanner;

final class BinarySearch {

    /** @var SandboxEnvironment */
    private SandboxEnvironment $sandbox;

    /** @var array<array{plugin_a: string, plugin_b: string, result: array}> Found conflicts. */
    private array $conflicts = [];

    /** @var int Total number of tests executed. */
    private int $test_count = 0;

    /** @var callable|null Progress callback. */
    private $progress_callback;

    public function __construct(SandboxEnvironment $sandbox, ?callable $progress_callback = null) {
        $this->sandbox = $sandbox;
        $this->progress_callback = $progress_callback;
    }

    /**
     * Find all conflicting plugin pairs using binary search.
     *
     * @param array<string> $plugins All active plugin file paths.
     * @return array{conflicts: array, test_count: int}
     */
    public function find_conflicts(array $plugins): array {
        $this->conflicts = [];
        $this->test_count = 0;

        $count = count($plugins);

        if ($count < 2) {
            return ['conflicts' => [], 'test_count' => 0];
        }

        // First, test all plugins together. If no conflict, we're done.
        $all_result = $this->sandbox->test_plugins($plugins);
        $this->test_count++;

        if ($all_result['success'] && empty($all_result['errors'])) {
            $this->report_progress('All plugins passed — no runtime conflicts detected.');
            return ['conflicts' => [], 'test_count' => $this->test_count];
        }

        $this->report_progress(sprintf(
            'Conflict detected with all %d plugins active. Starting binary search isolation...',
            $count
        ));

        // Binary search to isolate conflicting groups.
        $this->isolate_conflicts($plugins);

        return [
            'conflicts'  => $this->conflicts,
            'test_count' => $this->test_count,
        ];
    }

    /**
     * Recursively isolate conflicts using binary search.
     *
     * @param array<string> $plugins Plugin set to test.
     */
    private function isolate_conflicts(array $plugins): void {
        $count = count($plugins);

        // Base case: 2 plugins — test the pair directly.
        if ($count === 2) {
            $this->test_pair($plugins[0], $plugins[1]);
            return;
        }

        // Base case: 1 plugin — can't conflict with itself.
        if ($count <= 1) {
            return;
        }

        // Split into two halves.
        $mid = (int) ceil($count / 2);
        $left = array_slice($plugins, 0, $mid);
        $right = array_slice($plugins, $mid);

        // Test left half.
        $left_result = $this->sandbox->test_plugins($left);
        $this->test_count++;
        $left_has_conflict = ! $left_result['success'] || ! empty($left_result['errors']);

        // Test right half.
        $right_result = $this->sandbox->test_plugins($right);
        $this->test_count++;
        $right_has_conflict = ! $right_result['success'] || ! empty($right_result['errors']);

        // Recurse into halves that have conflicts.
        if ($left_has_conflict && count($left) >= 2) {
            $this->report_progress(sprintf(
                'Conflict found in left group (%d plugins). Narrowing down...',
                count($left)
            ));
            $this->isolate_conflicts($left);
        }

        if ($right_has_conflict && count($right) >= 2) {
            $this->report_progress(sprintf(
                'Conflict found in right group (%d plugins). Narrowing down...',
                count($right)
            ));
            $this->isolate_conflicts($right);
        }

        // Also test for cross-group conflicts:
        // Pair each left plugin with each right plugin if groups are small enough.
        if (count($left) <= 3 && count($right) <= 3) {
            $this->test_cross_pairs($left, $right);
        } else {
            // For larger groups, use a smarter approach:
            // Test each left plugin with the entire right group.
            $this->test_cross_group($left, $right);
        }
    }

    /**
     * Test a specific pair of plugins.
     *
     * @param string $plugin_a First plugin.
     * @param string $plugin_b Second plugin.
     */
    private function test_pair(string $plugin_a, string $plugin_b): void {
        // Deduplicate: skip if we've already recorded this pair.
        foreach ($this->conflicts as $existing) {
            if (
                ($existing['plugin_a'] === $plugin_a && $existing['plugin_b'] === $plugin_b) ||
                ($existing['plugin_a'] === $plugin_b && $existing['plugin_b'] === $plugin_a)
            ) {
                return;
            }
        }

        $result = $this->sandbox->test_plugins([$plugin_a, $plugin_b]);
        $this->test_count++;

        if (! $result['success'] || ! empty($result['errors'])) {
            $this->conflicts[] = [
                'plugin_a' => $plugin_a,
                'plugin_b' => $plugin_b,
                'result'   => $result,
            ];

            $this->report_progress(sprintf(
                'CONFLICT FOUND: "%s" vs "%s" — %s',
                basename(dirname($plugin_a)),
                basename(dirname($plugin_b)),
                $result['fatal'] ? 'FATAL ERROR' : implode('; ', $result['errors'])
            ));
        }
    }

    /**
     * Test all pairs between two small groups.
     */
    private function test_cross_pairs(array $left, array $right): void {
        foreach ($left as $l) {
            foreach ($right as $r) {
                $this->test_pair($l, $r);
            }
        }
    }

    /**
     * Test cross-group conflicts for larger groups.
     *
     * Tests each plugin from the left group against the entire right group,
     * then isolates if a conflict is found.
     */
    private function test_cross_group(array $left, array $right): void {
        foreach ($left as $left_plugin) {
            $test_set = array_merge([$left_plugin], $right);
            $result = $this->sandbox->test_plugins($test_set);
            $this->test_count++;

            if (! $result['success'] || ! empty($result['errors'])) {
                // This left plugin conflicts with something in the right group.
                // Binary search within right to find the specific conflict.
                $this->isolate_with_fixed($left_plugin, $right);
            }
        }
    }

    /**
     * Find which plugin in a set conflicts with a fixed plugin.
     *
     * @param string        $fixed_plugin The plugin we know is involved.
     * @param array<string> $candidates   Plugins to test against.
     */
    private function isolate_with_fixed(string $fixed_plugin, array $candidates): void {
        if (count($candidates) === 1) {
            $this->test_pair($fixed_plugin, $candidates[0]);
            return;
        }

        if (count($candidates) === 0) {
            return;
        }

        $mid = (int) ceil(count($candidates) / 2);
        $left = array_slice($candidates, 0, $mid);
        $right = array_slice($candidates, $mid);

        // Test fixed plugin with left half.
        $left_set = array_merge([$fixed_plugin], $left);
        $left_result = $this->sandbox->test_plugins($left_set);
        $this->test_count++;

        if (! $left_result['success'] || ! empty($left_result['errors'])) {
            $this->isolate_with_fixed($fixed_plugin, $left);
        }

        // Test fixed plugin with right half.
        $right_set = array_merge([$fixed_plugin], $right);
        $right_result = $this->sandbox->test_plugins($right_set);
        $this->test_count++;

        if (! $right_result['success'] || ! empty($right_result['errors'])) {
            $this->isolate_with_fixed($fixed_plugin, $right);
        }
    }

    /**
     * Report progress via callback.
     */
    private function report_progress(string $message): void {
        if ($this->progress_callback !== null) {
            try {
                ($this->progress_callback)($message, $this->test_count);
            } catch (\Throwable $e) {
                // Never let a callback failure break the scan.
                $this->progress_callback = null;
            }
        }
    }
}
