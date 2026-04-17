<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Analyzer\HookAnalyzer;
use PHPUnit\Framework\TestCase;

final class HookAnalyzerTest extends TestCase {

    private HookAnalyzer $analyzer;

    protected function setUp(): void {
        $this->analyzer = new HookAnalyzer();
    }

    public function test_detects_hook_priority_collision(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $hook_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'hook_conflict';
        });

        $this->assertNotEmpty($hook_conflicts, 'Should detect hooks registered at same priority');
    }

    public function test_no_hook_conflicts_with_single_plugin(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
        ]);

        $hook_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'hook_conflict';
        });

        $this->assertEmpty($hook_conflicts);
    }

    public function test_empty_plugin_list(): void {
        $conflicts = $this->analyzer->analyze([]);
        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function test_conflict_has_required_keys(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        foreach ($conflicts as $conflict) {
            $this->assertArrayHasKey('type', $conflict);
            $this->assertArrayHasKey('plugin_a', $conflict);
            $this->assertArrayHasKey('plugin_b', $conflict);
            $this->assertArrayHasKey('severity', $conflict);
            $this->assertArrayHasKey('description', $conflict);
        }
    }

    public function test_detects_critical_hook_conflicts(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $critical_hooks = ['init', 'wp_head', 'the_content'];
        $found_critical = false;

        foreach ($conflicts as $conflict) {
            if (($conflict['type'] ?? '') !== 'hook_conflict') {
                continue;
            }
            $hook_name = $conflict['details']['hook_name'] ?? '';
            if (in_array($hook_name, $critical_hooks, true)) {
                $found_critical = true;
                break;
            }
        }

        $this->assertTrue($found_critical, 'Should detect conflicts on critical hooks like init, wp_head, or the_content');
    }

    public function test_severity_is_valid(): void {
        $valid = ['critical', 'high', 'medium', 'low'];

        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        foreach ($conflicts as $conflict) {
            $this->assertContains($conflict['severity'], $valid);
        }
    }
}
