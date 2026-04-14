<?php
/**
 * Feature flags for Free/Pro/Agency tier gating.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Subscription;

final class FeatureFlags {

    public const TIER_FREE   = 'free';
    public const TIER_PRO    = 'pro';
    public const TIER_AGENCY = 'agency';

    /**
     * Feature definitions with required tier.
     *
     * @var array<string, string>
     */
    private const FEATURES = [
        'quick_scan'           => self::TIER_FREE,
        'manual_full_scan'     => self::TIER_FREE,
        'static_analysis'      => self::TIER_FREE,
        'basic_performance'    => self::TIER_FREE,
        'scan_history_3'       => self::TIER_FREE,
        'auto_background_scan' => self::TIER_PRO,
        'pre_update_scan'      => self::TIER_PRO,
        'woocommerce_deep'     => self::TIER_PRO,
        'email_alerts'         => self::TIER_PRO,
        'unlimited_full_scan'  => self::TIER_PRO,
        'advanced_performance' => self::TIER_PRO,
        'auto_fix'             => self::TIER_PRO,
        'dependency_analysis'  => self::TIER_PRO,
        'js_analysis'          => self::TIER_PRO,
        'db_analysis'          => self::TIER_PRO,
        'scan_history_50'      => self::TIER_PRO,
        'priority_support'     => self::TIER_PRO,
        'cli_access'           => self::TIER_PRO,
        'slack_integration'    => self::TIER_AGENCY,
        'rest_api'             => self::TIER_AGENCY,
        'multisite_support'    => self::TIER_AGENCY,
        'unlimited_history'    => self::TIER_AGENCY,
        'webhook_notifications' => self::TIER_AGENCY,
    ];

    /**
     * Tier hierarchy for comparison.
     *
     * @var array<string, int>
     */
    private const TIER_LEVELS = [
        self::TIER_FREE   => 0,
        self::TIER_PRO    => 1,
        self::TIER_AGENCY => 2,
    ];

    /**
     * Get the current license tier.
     *
     * @return string Current tier.
     */
    public static function get_tier(): string {
        $tier = get_option('jetstrike_cd_license_tier', self::TIER_FREE);

        if (! isset(self::TIER_LEVELS[$tier])) {
            return self::TIER_FREE;
        }

        return $tier;
    }

    /**
     * Check if a feature is available in the current tier.
     *
     * @param string $feature Feature key.
     * @return bool True if feature is available.
     */
    public static function can(string $feature): bool {
        if (! isset(self::FEATURES[$feature])) {
            return false;
        }

        $required_tier = self::FEATURES[$feature];
        $current_tier = self::get_tier();

        return self::TIER_LEVELS[$current_tier] >= self::TIER_LEVELS[$required_tier];
    }

    /**
     * Get the required tier for a feature.
     *
     * @param string $feature Feature key.
     * @return string|null Required tier, or null if feature doesn't exist.
     */
    public static function required_tier(string $feature): ?string {
        return self::FEATURES[$feature] ?? null;
    }

    /**
     * Get all features available in the current tier.
     *
     * @return array<string> Feature keys.
     */
    public static function available_features(): array {
        $current_tier = self::get_tier();
        $current_level = self::TIER_LEVELS[$current_tier];

        return array_keys(
            array_filter(
                self::FEATURES,
                fn(string $required_tier): bool => self::TIER_LEVELS[$required_tier] <= $current_level
            )
        );
    }

    /**
     * Get the scan history retention limit for the current tier.
     *
     * @return int Number of scans to retain (0 = unlimited).
     */
    public static function scan_history_limit(): int {
        $tier = self::get_tier();

        return match ($tier) {
            self::TIER_AGENCY => 0,
            self::TIER_PRO    => 50,
            default           => 3,
        };
    }

    /**
     * Get weekly full scan limit for the current tier.
     *
     * @return int Number of manual full scans allowed per week (0 = unlimited).
     */
    public static function weekly_scan_limit(): int {
        $tier = self::get_tier();

        return match ($tier) {
            self::TIER_AGENCY, self::TIER_PRO => 0,
            default => 1,
        };
    }

    /**
     * Check if the current tier meets or exceeds the given tier.
     *
     * @param string $tier Tier to check against.
     * @return bool True if current tier is at least the given tier.
     */
    public static function is_at_least(string $tier): bool {
        $current_level = self::TIER_LEVELS[self::get_tier()] ?? 0;
        $required_level = self::TIER_LEVELS[$tier] ?? 0;

        return $current_level >= $required_level;
    }
}
