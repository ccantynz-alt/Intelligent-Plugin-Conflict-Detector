<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Subscription\LicenseManager;
use PHPUnit\Framework\TestCase;

final class LicenseManagerTest extends TestCase {

    private LicenseManager $manager;

    protected function setUp(): void {
        jetstrike_test_reset_wp_state();
        $this->manager = new LicenseManager();
    }

    public function test_activate_empty_key_returns_failure(): void {
        $result = $this->manager->activate('');

        $this->assertFalse($result['success']);
        $this->assertSame('free', $result['tier']);
    }

    public function test_activate_whitespace_key_returns_failure(): void {
        $result = $this->manager->activate('   ');

        $this->assertFalse($result['success']);
    }

    public function test_deactivate_clears_license_data(): void {
        update_option('jetstrike_cd_license_key', 'test-key-123');
        update_option('jetstrike_cd_license_tier', 'pro');
        update_option('jetstrike_cd_license_status', 'active');

        $result = $this->manager->deactivate();

        $this->assertTrue($result);
        $this->assertSame('', get_option('jetstrike_cd_license_key'));
        $this->assertSame('free', get_option('jetstrike_cd_license_tier'));
        $this->assertSame('inactive', get_option('jetstrike_cd_license_status'));
    }

    public function test_get_info_returns_correct_shape(): void {
        update_option('jetstrike_cd_license_key', 'abc-123');
        update_option('jetstrike_cd_license_tier', 'pro');
        update_option('jetstrike_cd_license_status', 'active');
        update_option('jetstrike_cd_license_expires', '2027-01-01');

        $info = $this->manager->get_info();

        $this->assertArrayHasKey('key', $info);
        $this->assertArrayHasKey('tier', $info);
        $this->assertArrayHasKey('status', $info);
        $this->assertArrayHasKey('expires', $info);
        $this->assertSame('abc-123', $info['key']);
        $this->assertSame('active', $info['status']);
        $this->assertSame('2027-01-01', $info['expires']);
    }

    public function test_get_info_defaults(): void {
        $info = $this->manager->get_info();

        $this->assertSame('', $info['key']);
        $this->assertSame('free', $info['tier']);
        $this->assertSame('inactive', $info['status']);
        $this->assertSame('', $info['expires']);
    }

    public function test_validate_returns_false_with_no_key(): void {
        $result = $this->manager->validate();
        $this->assertFalse($result);
    }

    public function test_validate_uses_cached_result(): void {
        set_transient('jetstrike_cd_license_status', 'valid');
        update_option('jetstrike_cd_license_key', 'some-key');

        $result = $this->manager->validate();
        $this->assertTrue($result);
    }

    public function test_validate_cached_invalid(): void {
        set_transient('jetstrike_cd_license_status', 'invalid');
        update_option('jetstrike_cd_license_key', 'some-key');

        $result = $this->manager->validate();
        $this->assertFalse($result);
    }
}
