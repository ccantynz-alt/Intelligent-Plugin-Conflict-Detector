<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Analyzer\StaticAnalyzer;
use PHPUnit\Framework\TestCase;

final class StaticAnalyzerTest extends TestCase {

    private StaticAnalyzer $analyzer;
    private string $fixtures;

    protected function setUp(): void {
        $this->analyzer = new StaticAnalyzer();
        $this->fixtures = dirname(__DIR__) . '/fixtures';
    }

    public function test_detects_function_collision(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $function_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'function_redeclaration';
        });

        $this->assertNotEmpty($function_conflicts, 'Should detect the duplicate function');
    }

    public function test_detects_class_collision(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $class_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'class_collision';
        });

        $this->assertNotEmpty($class_conflicts, 'Should detect the duplicate class');
    }

    public function test_detects_global_collision(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        $global_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'global_conflict';
        });

        $this->assertNotEmpty($global_conflicts, 'Should detect the shared global variable');
    }

    public function test_no_conflicts_for_clean_plugin(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-clean/plugin-clean.php',
        ]);

        $function_conflicts = array_filter($conflicts, function ($c) {
            return ($c['type'] ?? '') === 'function_redeclaration';
        });

        $this->assertEmpty($function_conflicts, 'Clean plugin should have no function collisions');
    }

    public function test_empty_plugin_list_returns_empty(): void {
        $conflicts = $this->analyzer->analyze([]);
        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function test_single_plugin_returns_empty(): void {
        $conflicts = $this->analyzer->analyze(['plugin-a/plugin-a.php']);
        $this->assertIsArray($conflicts);
        $this->assertEmpty($conflicts);
    }

    public function test_conflict_has_required_keys(): void {
        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        if (empty($conflicts)) {
            $this->markTestSkipped('No conflicts detected to validate shape');
        }

        $conflict = $conflicts[0];
        $this->assertArrayHasKey('type', $conflict);
        $this->assertArrayHasKey('plugin_a', $conflict);
        $this->assertArrayHasKey('plugin_b', $conflict);
        $this->assertArrayHasKey('severity', $conflict);
        $this->assertArrayHasKey('description', $conflict);
    }

    public function test_severity_is_valid_value(): void {
        $valid = ['critical', 'high', 'medium', 'low'];

        $conflicts = $this->analyzer->analyze([
            'plugin-a/plugin-a.php',
            'plugin-b/plugin-b.php',
        ]);

        foreach ($conflicts as $conflict) {
            $this->assertContains(
                $conflict['severity'],
                $valid,
                "Severity '{$conflict['severity']}' is not valid"
            );
        }
    }
}
