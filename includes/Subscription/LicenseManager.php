<?php
/**
 * License key validation and management.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Subscription;

final class LicenseManager {

    private const LICENSE_API_URL = 'https://api.jetstrike.io/v1/license';
    private const CACHE_KEY       = 'jetstrike_cd_license_status';
    private const CACHE_TTL       = DAY_IN_SECONDS;

    /**
     * Activate a license key.
     *
     * @param string $license_key License key to activate.
     * @return array{success: bool, tier: string, message: string}
     */
    public function activate(string $license_key): array {
        $license_key = sanitize_text_field(trim($license_key));

        if (empty($license_key)) {
            return [
                'success' => false,
                'tier'    => FeatureFlags::TIER_FREE,
                'message' => __('Please enter a valid license key.', 'jetstrike-cd'),
            ];
        }

        $response = $this->api_request('activate', [
            'license_key' => $license_key,
            'site_url'    => home_url(),
            'wp_version'  => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => JETSTRIKE_CD_VERSION,
        ]);

        if ($response === null) {
            // When API is unreachable, store key and validate later.
            update_option('jetstrike_cd_license_key', $license_key);
            update_option('jetstrike_cd_license_status', 'pending');

            return [
                'success' => true,
                'tier'    => FeatureFlags::TIER_FREE,
                'message' => __('License key saved. Validation will complete when the license server is available.', 'jetstrike-cd'),
            ];
        }

        if (! empty($response['valid'])) {
            $tier = $response['tier'] ?? FeatureFlags::TIER_PRO;

            update_option('jetstrike_cd_license_key', $license_key);
            update_option('jetstrike_cd_license_tier', $tier);
            update_option('jetstrike_cd_license_status', 'active');
            update_option('jetstrike_cd_license_expires', $response['expires'] ?? '');

            delete_transient(self::CACHE_KEY);

            return [
                'success' => true,
                'tier'    => $tier,
                'message' => sprintf(
                    /* translators: %s: tier name */
                    __('License activated successfully. Your plan: %s', 'jetstrike-cd'),
                    ucfirst($tier)
                ),
            ];
        }

        return [
            'success' => false,
            'tier'    => FeatureFlags::TIER_FREE,
            'message' => $response['message'] ?? __('Invalid license key.', 'jetstrike-cd'),
        ];
    }

    /**
     * Deactivate the current license.
     *
     * @return bool Success.
     */
    public function deactivate(): bool {
        $license_key = get_option('jetstrike_cd_license_key', '');

        if (! empty($license_key)) {
            $this->api_request('deactivate', [
                'license_key' => $license_key,
                'site_url'    => home_url(),
            ]);
        }

        update_option('jetstrike_cd_license_key', '');
        update_option('jetstrike_cd_license_tier', FeatureFlags::TIER_FREE);
        update_option('jetstrike_cd_license_status', 'inactive');
        update_option('jetstrike_cd_license_expires', '');

        delete_transient(self::CACHE_KEY);

        return true;
    }

    /**
     * Validate the current license (periodic check).
     *
     * @return bool True if license is valid.
     */
    public function validate(): bool {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false) {
            return $cached === 'valid';
        }

        $license_key = get_option('jetstrike_cd_license_key', '');

        if (empty($license_key)) {
            set_transient(self::CACHE_KEY, 'free', self::CACHE_TTL);
            return false;
        }

        $response = $this->api_request('validate', [
            'license_key' => $license_key,
            'site_url'    => home_url(),
        ]);

        if ($response === null) {
            // API unreachable — trust existing stored status.
            return get_option('jetstrike_cd_license_status') === 'active';
        }

        $is_valid = ! empty($response['valid']);

        if ($is_valid) {
            $tier = $response['tier'] ?? FeatureFlags::TIER_PRO;
            update_option('jetstrike_cd_license_tier', $tier);
            update_option('jetstrike_cd_license_status', 'active');
            set_transient(self::CACHE_KEY, 'valid', self::CACHE_TTL);
        } else {
            update_option('jetstrike_cd_license_tier', FeatureFlags::TIER_FREE);
            update_option('jetstrike_cd_license_status', 'expired');
            set_transient(self::CACHE_KEY, 'invalid', self::CACHE_TTL);
        }

        return $is_valid;
    }

    /**
     * Get current license info.
     *
     * @return array{key: string, tier: string, status: string, expires: string}
     */
    public function get_info(): array {
        return [
            'key'     => get_option('jetstrike_cd_license_key', ''),
            'tier'    => FeatureFlags::get_tier(),
            'status'  => get_option('jetstrike_cd_license_status', 'inactive'),
            'expires' => get_option('jetstrike_cd_license_expires', ''),
        ];
    }

    /**
     * Make an API request to the license server.
     *
     * @param string $action API action.
     * @param array  $body   Request body.
     * @return array|null Response data or null on failure.
     */
    private function api_request(string $action, array $body): ?array {
        $response = wp_remote_post(
            self::LICENSE_API_URL . '/' . $action,
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
