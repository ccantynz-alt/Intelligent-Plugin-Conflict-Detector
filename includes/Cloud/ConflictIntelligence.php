<?php
/**
 * Conflict Intelligence client — queries the Jetstrike cloud for known conflicts.
 *
 * Before running an expensive local scan, checks the global conflict database
 * for already-known issues between specific plugin pairs. This gives instant
 * results powered by community data.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Cloud;

final class ConflictIntelligence {

    private const API_ENDPOINT = 'https://api.jetstrike.io/v1/intelligence';
    private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
    private const CACHE_PREFIX = 'jetstrike_cd_intel_';

    /**
     * Query the cloud for known conflicts between installed plugins.
     *
     * @param array<string> $plugin_files Active plugin file paths.
     * @return array Known conflicts from the global database.
     */
    public function query_known_conflicts(array $plugin_files): array {
        $slugs = array_map([$this, 'extract_slug'], $plugin_files);
        $slugs = array_filter($slugs);

        if (count($slugs) < 2) {
            return [];
        }

        // Check cache first.
        $cache_key = self::CACHE_PREFIX . md5(implode(',', $slugs));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Query the cloud API.
        $response = wp_remote_post(self::API_ENDPOINT . '/check', [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode([
                'plugins'        => $slugs,
                'wp_version'     => get_bloginfo('version'),
                'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
                'plugin_version' => JETSTRIKE_CD_VERSION,
            ]),
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $status = wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($body) || ! isset($body['conflicts'])) {
            return [];
        }

        $known_conflicts = $this->format_cloud_conflicts($body['conflicts']);

        // Cache the results.
        set_transient($cache_key, $known_conflicts, self::CACHE_TTL);

        return $known_conflicts;
    }

    /**
     * Get compatibility score between two specific plugins.
     *
     * @param string $plugin_a First plugin slug.
     * @param string $plugin_b Second plugin slug.
     * @return array{compatible: bool, confidence: float, reports: int, conflicts: array}|null
     */
    public function check_pair(string $plugin_a, string $plugin_b): ?array {
        $cache_key = self::CACHE_PREFIX . 'pair_' . md5($plugin_a . ':' . $plugin_b);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'plugin_a' => sanitize_title($plugin_a),
                    'plugin_b' => sanitize_title($plugin_b),
                ],
                self::API_ENDPOINT . '/pair'
            ),
            [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($body)) {
            return null;
        }

        $result = [
            'compatible' => $body['compatible'] ?? true,
            'confidence' => (float) ($body['confidence'] ?? 0),
            'reports'    => (int) ($body['reports'] ?? 0),
            'conflicts'  => $body['conflicts'] ?? [],
        ];

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Format cloud conflict data into our standard conflict format.
     *
     * @param array $cloud_conflicts Conflicts from the cloud API.
     * @return array Formatted conflicts.
     */
    private function format_cloud_conflicts(array $cloud_conflicts): array {
        $formatted = [];

        foreach ($cloud_conflicts as $conflict) {
            $formatted[] = [
                'type'        => sanitize_text_field($conflict['type'] ?? 'hook_conflict'),
                'plugin_a'    => sanitize_text_field($conflict['plugin_a'] ?? ''),
                'plugin_b'    => sanitize_text_field($conflict['plugin_b'] ?? ''),
                'severity'    => sanitize_text_field($conflict['severity'] ?? 'medium'),
                'description' => sprintf(
                    'Known conflict reported by %d site(s): %s',
                    (int) ($conflict['reports'] ?? 0),
                    sanitize_text_field($conflict['description'] ?? 'Compatibility issue detected')
                ),
                'details' => [
                    'source'     => 'cloud_intelligence',
                    'confidence' => (float) ($conflict['confidence'] ?? 0),
                    'reports'    => (int) ($conflict['reports'] ?? 0),
                    'first_seen' => sanitize_text_field($conflict['first_seen'] ?? ''),
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Extract plugin slug from file path.
     *
     * @param string $plugin_file Plugin file path.
     * @return string Plugin slug.
     */
    private function extract_slug(string $plugin_file): string {
        $parts = explode('/', $plugin_file);
        return sanitize_title($parts[0] ?? '');
    }
}
