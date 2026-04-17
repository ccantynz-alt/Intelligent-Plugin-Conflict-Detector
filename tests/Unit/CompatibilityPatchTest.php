<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Resolver\CompatibilityPatch;
use PHPUnit\Framework\TestCase;

final class CompatibilityPatchTest extends TestCase {

    private CompatibilityPatch $patcher;
    private string $patch_dir;

    protected function setUp(): void {
        $this->patcher = new CompatibilityPatch();
        $this->patch_dir = WPMU_PLUGIN_DIR . '/jetstrike-patches';

        // Ensure the mu-plugins directory exists for testing.
        if (! is_dir(WPMU_PLUGIN_DIR)) {
            mkdir(WPMU_PLUGIN_DIR, 0755, true);
        }
    }

    protected function tearDown(): void {
        // Clean up any test patches.
        if (is_dir($this->patch_dir)) {
            $files = glob($this->patch_dir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->patch_dir);
        }

        $loader = WPMU_PLUGIN_DIR . '/jetstrike-patch-loader.php';
        if (file_exists($loader)) {
            @unlink($loader);
        }
    }

    public function test_write_patch_creates_file(): void {
        $filename = $this->patcher->write_patch(
            42,
            'hook_priority',
            "add_filter('init', '__return_true', 99);",
            'Test patch for hook conflict'
        );

        $this->assertNotNull($filename);
        $this->assertStringContains('jetstrike-fix-42', $filename);
        $this->assertFileExists($this->patch_dir . '/' . $filename);
    }

    public function test_write_patch_rejects_zero_id(): void {
        $filename = $this->patcher->write_patch(
            0,
            'hook_priority',
            "// noop",
            'Should not be created'
        );

        $this->assertNull($filename);
    }

    public function test_write_patch_rejects_negative_id(): void {
        $filename = $this->patcher->write_patch(
            -5,
            'hook_priority',
            "// noop",
            'Should not be created'
        );

        $this->assertNull($filename);
    }

    public function test_write_patch_includes_abspath_guard(): void {
        $filename = $this->patcher->write_patch(
            1,
            'test_type',
            "echo 'patched';",
            'Test description'
        );

        $this->assertNotNull($filename);
        $content = file_get_contents($this->patch_dir . '/' . $filename);
        $this->assertStringContains("defined('ABSPATH')", $content);
    }

    public function test_write_patch_creates_loader(): void {
        $this->patcher->write_patch(
            1,
            'test_type',
            "// test",
            'Create loader test'
        );

        $loader_path = WPMU_PLUGIN_DIR . '/jetstrike-patch-loader.php';
        $this->assertFileExists($loader_path);
    }

    public function test_remove_patch_removes_file(): void {
        $filename = $this->patcher->write_patch(
            99,
            'removal_test',
            "// to be removed",
            'Removal test'
        );

        $this->assertNotNull($filename);
        $this->assertFileExists($this->patch_dir . '/' . $filename);

        $removed = $this->patcher->remove_patch($filename);
        $this->assertTrue($removed);
        $this->assertFileDoesNotExist($this->patch_dir . '/' . $filename);
    }

    public function test_remove_nonexistent_patch_returns_true(): void {
        $result = $this->patcher->remove_patch('nonexistent-file.php');
        $this->assertTrue($result);
    }

    public function test_list_patches_returns_written_patches(): void {
        $this->patcher->write_patch(1, 'type_a', '// a', 'Patch A');
        $this->patcher->write_patch(2, 'type_b', '// b', 'Patch B');

        $patches = $this->patcher->list_patches();

        $this->assertCount(2, $patches);

        foreach ($patches as $name => $info) {
            $this->assertArrayHasKey('file', $info);
            $this->assertArrayHasKey('conflict_id', $info);
            $this->assertArrayHasKey('fix_type', $info);
            $this->assertArrayHasKey('size', $info);
            $this->assertArrayHasKey('modified', $info);
        }
    }

    public function test_list_patches_empty_when_no_patches(): void {
        $patches = $this->patcher->list_patches();
        $this->assertIsArray($patches);
        $this->assertEmpty($patches);
    }

    public function test_remove_all_patches_cleans_everything(): void {
        $this->patcher->write_patch(1, 'a', '// a', 'A');
        $this->patcher->write_patch(2, 'b', '// b', 'B');
        $this->patcher->write_patch(3, 'c', '// c', 'C');

        $count = $this->patcher->remove_all_patches();

        $this->assertSame(3, $count);
        $this->assertEmpty($this->patcher->list_patches());
    }

    /**
     * Custom assertion for PHP <8.1 compatibility.
     */
    private static function assertStringContains(string $needle, string $haystack): void {
        self::assertTrue(
            strpos($haystack, $needle) !== false,
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
