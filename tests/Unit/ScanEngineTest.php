<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Scanner\ScanEngine;
use PHPUnit\Framework\TestCase;

final class ScanEngineTest extends TestCase {

    private ScanEngine $engine;

    protected function setUp(): void {
        jetstrike_test_reset_wp_state();
        $repo = new Repository();
        $this->engine = new ScanEngine($repo);
    }

    public function test_deduplicate_conflicts_removes_duplicates(): void {
        $method = new \ReflectionMethod($this->engine, 'deduplicate_conflicts');
        $method->setAccessible(true);

        $conflicts = [
            [
                'plugin_a' => 'alpha/alpha.php',
                'plugin_b' => 'beta/beta.php',
                'type'     => 'hook_conflict',
                'severity' => 'medium',
            ],
            [
                'plugin_a' => 'beta/beta.php',
                'plugin_b' => 'alpha/alpha.php',
                'type'     => 'hook_conflict',
                'severity' => 'high',
            ],
        ];

        $result = $method->invoke($this->engine, $conflicts);

        $this->assertCount(1, $result, 'Should merge duplicate pair');
        $this->assertSame('high', $result[0]['severity'], 'Should keep higher severity');
    }

    public function test_deduplicate_keeps_different_types(): void {
        $method = new \ReflectionMethod($this->engine, 'deduplicate_conflicts');
        $method->setAccessible(true);

        $conflicts = [
            [
                'plugin_a' => 'alpha/alpha.php',
                'plugin_b' => 'beta/beta.php',
                'type'     => 'hook_conflict',
                'severity' => 'medium',
            ],
            [
                'plugin_a' => 'alpha/alpha.php',
                'plugin_b' => 'beta/beta.php',
                'type'     => 'resource_collision',
                'severity' => 'low',
            ],
        ];

        $result = $method->invoke($this->engine, $conflicts);

        $this->assertCount(2, $result, 'Different conflict types should not be merged');
    }

    public function test_deduplicate_empty_array(): void {
        $method = new \ReflectionMethod($this->engine, 'deduplicate_conflicts');
        $method->setAccessible(true);

        $result = $method->invoke($this->engine, []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_deduplicate_normalizes_pair_order(): void {
        $method = new \ReflectionMethod($this->engine, 'deduplicate_conflicts');
        $method->setAccessible(true);

        $conflicts = [
            [
                'plugin_a' => 'z-plugin/z.php',
                'plugin_b' => 'a-plugin/a.php',
                'type'     => 'hook_conflict',
                'severity' => 'low',
            ],
            [
                'plugin_a' => 'a-plugin/a.php',
                'plugin_b' => 'z-plugin/z.php',
                'type'     => 'hook_conflict',
                'severity' => 'critical',
            ],
        ];

        $result = $method->invoke($this->engine, $conflicts);

        $this->assertCount(1, $result, 'Reversed pair should be treated as duplicate');
        $this->assertSame('critical', $result[0]['severity']);
    }

    public function test_deduplicate_single_conflict_unchanged(): void {
        $method = new \ReflectionMethod($this->engine, 'deduplicate_conflicts');
        $method->setAccessible(true);

        $conflicts = [
            [
                'plugin_a' => 'alpha/alpha.php',
                'plugin_b' => 'beta/beta.php',
                'type'     => 'hook_conflict',
                'severity' => 'medium',
            ],
        ];

        $result = $method->invoke($this->engine, $conflicts);

        $this->assertCount(1, $result);
        $this->assertSame('medium', $result[0]['severity']);
    }

    public function test_run_static_analysis_returns_array(): void {
        $result = $this->engine->run_static_analysis([]);
        $this->assertIsArray($result);
    }

    public function test_run_static_analysis_detects_conflicts(): void {
        $result = $this->engine->run_static_analysis([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Should detect conflicts between plugin-a and plugin-b');
    }
}
