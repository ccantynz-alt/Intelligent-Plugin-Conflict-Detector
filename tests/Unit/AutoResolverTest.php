<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Resolver\AutoResolver;
use PHPUnit\Framework\TestCase;

final class AutoResolverTest extends TestCase {

    public function test_can_auto_resolve_hook_conflict(): void {
        $result = AutoResolver::can_auto_resolve('hook_conflict');
        $this->assertTrue($result['can_resolve']);
        $this->assertNotEmpty($result['description']);
    }

    public function test_can_auto_resolve_resource_collision(): void {
        $result = AutoResolver::can_auto_resolve('resource_collision');
        $this->assertTrue($result['can_resolve']);
    }

    public function test_can_auto_resolve_function_redeclaration(): void {
        $result = AutoResolver::can_auto_resolve('function_redeclaration');
        $this->assertTrue($result['can_resolve']);
    }

    public function test_can_auto_resolve_global_conflict(): void {
        $result = AutoResolver::can_auto_resolve('global_conflict');
        $this->assertTrue($result['can_resolve']);
    }

    public function test_cannot_auto_resolve_fatal_error(): void {
        $result = AutoResolver::can_auto_resolve('fatal_error');
        $this->assertFalse($result['can_resolve']);
    }

    public function test_cannot_auto_resolve_unknown_type(): void {
        $result = AutoResolver::can_auto_resolve('made_up_conflict');
        $this->assertFalse($result['can_resolve']);
    }

    public function test_cannot_auto_resolve_class_collision(): void {
        $result = AutoResolver::can_auto_resolve('class_collision');
        $this->assertFalse($result['can_resolve']);
    }

    public function test_can_auto_resolve_returns_array_shape(): void {
        $result = AutoResolver::can_auto_resolve('hook_conflict');
        $this->assertArrayHasKey('can_resolve', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertIsBool($result['can_resolve']);
        $this->assertIsString($result['description']);
    }

    /**
     * @dataProvider conflictTypesProvider
     */
    public function test_can_auto_resolve_returns_valid_shape(string $type): void {
        $result = AutoResolver::can_auto_resolve($type);
        $this->assertArrayHasKey('can_resolve', $result);
        $this->assertArrayHasKey('description', $result);
    }

    public function conflictTypesProvider(): array {
        return [
            'hook_conflict'           => ['hook_conflict'],
            'resource_collision'      => ['resource_collision'],
            'function_redeclaration'  => ['function_redeclaration'],
            'global_conflict'         => ['global_conflict'],
            'fatal_error'             => ['fatal_error'],
            'class_collision'         => ['class_collision'],
            'performance_degradation' => ['performance_degradation'],
            'dependency_conflict'     => ['dependency_conflict'],
            'js_global_conflict'      => ['js_global_conflict'],
            'db_option_collision'     => ['db_option_collision'],
        ];
    }
}
