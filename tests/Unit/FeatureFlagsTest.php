<?php

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Tests\Unit;

use Jetstrike\ConflictDetector\Subscription\FeatureFlags;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsTest extends TestCase {

    protected function setUp(): void {
        jetstrike_test_reset_wp_state();
    }

    public function test_default_tier_is_free(): void {
        $this->assertSame('free', FeatureFlags::get_tier());
    }

    public function test_get_tier_returns_stored_tier(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertSame('pro', FeatureFlags::get_tier());
    }

    public function test_invalid_stored_tier_falls_back_to_free(): void {
        update_option('jetstrike_cd_license_tier', 'enterprise');
        $this->assertSame('free', FeatureFlags::get_tier());
    }

    public function test_free_tier_cannot_access_pro_features(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $this->assertFalse(FeatureFlags::can('auto_background_scan'));
        $this->assertFalse(FeatureFlags::can('auto_fix'));
        $this->assertFalse(FeatureFlags::can('woocommerce_deep'));
        $this->assertFalse(FeatureFlags::can('email_alerts'));
        $this->assertFalse(FeatureFlags::can('dependency_analysis'));
        $this->assertFalse(FeatureFlags::can('js_analysis'));
        $this->assertFalse(FeatureFlags::can('db_analysis'));
        $this->assertFalse(FeatureFlags::can('cli_access'));
    }

    public function test_free_tier_can_access_free_features(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $this->assertTrue(FeatureFlags::can('quick_scan'));
        $this->assertTrue(FeatureFlags::can('static_analysis'));
        $this->assertTrue(FeatureFlags::can('manual_full_scan'));
        $this->assertTrue(FeatureFlags::can('basic_performance'));
    }

    public function test_pro_tier_can_access_pro_features(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertTrue(FeatureFlags::can('auto_background_scan'));
        $this->assertTrue(FeatureFlags::can('auto_fix'));
        $this->assertTrue(FeatureFlags::can('woocommerce_deep'));
        $this->assertTrue(FeatureFlags::can('email_alerts'));
        $this->assertTrue(FeatureFlags::can('pre_update_scan'));
        $this->assertTrue(FeatureFlags::can('dependency_analysis'));
        $this->assertTrue(FeatureFlags::can('js_analysis'));
        $this->assertTrue(FeatureFlags::can('db_analysis'));
        $this->assertTrue(FeatureFlags::can('cli_access'));
        $this->assertTrue(FeatureFlags::can('priority_support'));
    }

    public function test_pro_tier_cannot_access_agency_features(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertFalse(FeatureFlags::can('slack_integration'));
        $this->assertFalse(FeatureFlags::can('rest_api'));
        $this->assertFalse(FeatureFlags::can('multisite_support'));
        $this->assertFalse(FeatureFlags::can('webhook_notifications'));
    }

    public function test_agency_tier_can_access_all_features(): void {
        update_option('jetstrike_cd_license_tier', 'agency');
        $this->assertTrue(FeatureFlags::can('quick_scan'));
        $this->assertTrue(FeatureFlags::can('auto_fix'));
        $this->assertTrue(FeatureFlags::can('slack_integration'));
        $this->assertTrue(FeatureFlags::can('rest_api'));
        $this->assertTrue(FeatureFlags::can('multisite_support'));
        $this->assertTrue(FeatureFlags::can('webhook_notifications'));
        $this->assertTrue(FeatureFlags::can('unlimited_history'));
    }

    public function test_unknown_feature_returns_false(): void {
        update_option('jetstrike_cd_license_tier', 'agency');
        $this->assertFalse(FeatureFlags::can('nonexistent_feature'));
    }

    public function test_required_tier_returns_correct_tier(): void {
        $this->assertSame('free', FeatureFlags::required_tier('quick_scan'));
        $this->assertSame('pro', FeatureFlags::required_tier('auto_fix'));
        $this->assertSame('agency', FeatureFlags::required_tier('slack_integration'));
    }

    public function test_required_tier_returns_null_for_unknown(): void {
        $this->assertNull(FeatureFlags::required_tier('nonexistent'));
    }

    public function test_scan_history_limit_free(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $this->assertSame(3, FeatureFlags::scan_history_limit());
    }

    public function test_scan_history_limit_pro(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertSame(50, FeatureFlags::scan_history_limit());
    }

    public function test_scan_history_limit_agency(): void {
        update_option('jetstrike_cd_license_tier', 'agency');
        $this->assertSame(0, FeatureFlags::scan_history_limit());
    }

    public function test_weekly_scan_limit_free(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $this->assertSame(1, FeatureFlags::weekly_scan_limit());
    }

    public function test_weekly_scan_limit_pro(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertSame(0, FeatureFlags::weekly_scan_limit());
    }

    public function test_is_at_least_free(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $this->assertTrue(FeatureFlags::is_at_least('free'));
        $this->assertFalse(FeatureFlags::is_at_least('pro'));
        $this->assertFalse(FeatureFlags::is_at_least('agency'));
    }

    public function test_is_at_least_pro(): void {
        update_option('jetstrike_cd_license_tier', 'pro');
        $this->assertTrue(FeatureFlags::is_at_least('free'));
        $this->assertTrue(FeatureFlags::is_at_least('pro'));
        $this->assertFalse(FeatureFlags::is_at_least('agency'));
    }

    public function test_is_at_least_agency(): void {
        update_option('jetstrike_cd_license_tier', 'agency');
        $this->assertTrue(FeatureFlags::is_at_least('free'));
        $this->assertTrue(FeatureFlags::is_at_least('pro'));
        $this->assertTrue(FeatureFlags::is_at_least('agency'));
    }

    public function test_available_features_includes_correct_set(): void {
        update_option('jetstrike_cd_license_tier', 'free');
        $features = FeatureFlags::available_features();
        $this->assertContains('quick_scan', $features);
        $this->assertContains('static_analysis', $features);
        $this->assertNotContains('auto_fix', $features);

        update_option('jetstrike_cd_license_tier', 'pro');
        $features = FeatureFlags::available_features();
        $this->assertContains('auto_fix', $features);
        $this->assertNotContains('slack_integration', $features);
    }
}
