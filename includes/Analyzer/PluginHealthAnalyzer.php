<?php
/**
 * Plugin Health Analyzer — detects abandoned, outdated, and vulnerable plugins.
 *
 * Every WordPress site runs plugins that haven't been updated in years,
 * have been removed from wordpress.org, or have known security vulnerabilities.
 * Store owners don't know this because nobody tells them.
 *
 * This analyzer checks each active plugin against the wordpress.org API
 * and produces a health score with actionable recommendations.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class PluginHealthAnalyzer {

    private const WP_API_BASE = 'https://api.wordpress.org/plugins/info/1.2/';
    private const CACHE_PREFIX = 'jetstrike_cd_pluginhealth_';
    private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

    /** Thresholds for plugin age warnings. */
    private const STALE_MONTHS    = 12;
    private const ABANDONED_MONTHS = 24;

    /** Known PHP functions removed or deprecated across versions. */
    private const RISKY_FUNCTIONS = [
        'create_function',
        'each',
        'mysql_connect',
        'mysql_query',
        'mysql_real_escape_string',
        'mysql_fetch_array',
        'mysql_fetch_assoc',
        'mysql_num_rows',
        'mysql_close',
        'ereg',
        'eregi',
        'ereg_replace',
        'eregi_replace',
        'split',
        'spliti',
        'mcrypt_encrypt',
        'mcrypt_decrypt',
    ];

    /**
     * Analyze all active plugins for health issues.
     *
     * @return array{
     *   plugins: array<string, array>,
     *   summary: array{healthy: int, stale: int, abandoned: int, removed: int, vulnerable: int},
     *   issues: array
     * }
     */
    public function analyze(): array {
        $active_plugins = get_option('active_plugins', []);
        $results = [];
        $issues = [];
        $summary = [
            'healthy'    => 0,
            'stale'      => 0,
            'abandoned'  => 0,
            'removed'    => 0,
            'vulnerable' => 0,
            'outdated_wp' => 0,
        ];

        foreach ($active_plugins as $plugin_file) {
            if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
            $slug = $this->extract_slug($plugin_file);
            $health = $this->check_plugin_health($slug, $plugin_data, $plugin_file);

            $results[$plugin_file] = $health;

            foreach ($health['issues'] as $issue) {
                $issues[] = $issue;

                switch ($issue['type']) {
                    case 'abandoned':
                        $summary['abandoned']++;
                        break;
                    case 'stale':
                        $summary['stale']++;
                        break;
                    case 'removed':
                        $summary['removed']++;
                        break;
                    case 'vulnerable':
                        $summary['vulnerable']++;
                        break;
                    case 'outdated_wp':
                        $summary['outdated_wp']++;
                        break;
                }
            }

            if (empty($health['issues'])) {
                $summary['healthy']++;
            }
        }

        return [
            'plugins' => $results,
            'summary' => $summary,
            'issues'  => $issues,
        ];
    }

    /**
     * Check a single plugin's health.
     *
     * @param string $slug        Plugin slug (directory name).
     * @param array  $plugin_data Data from get_plugin_data().
     * @param string $plugin_file Plugin file path.
     * @return array{score: int, status: string, issues: array, wporg_data: array|null}
     */
    private function check_plugin_health(string $slug, array $plugin_data, string $plugin_file): array {
        $score = 100;
        $issues = [];
        $status = 'healthy';

        // Query wordpress.org for plugin info.
        $wporg = $this->get_wporg_data($slug);

        // Check 1: Is the plugin on wordpress.org?
        if ($wporg === null) {
            // Premium or custom plugin — can't check much, but flag it.
            $issues[] = [
                'type'        => 'unlisted',
                'severity'    => 'low',
                'plugin'      => $plugin_file,
                'plugin_name' => $plugin_data['Name'] ?: $slug,
                'message'     => sprintf(
                    '%s is not listed on wordpress.org. This is normal for premium plugins, but means ' .
                    'we cannot verify its update status or check for known vulnerabilities.',
                    $plugin_data['Name'] ?: $slug
                ),
            ];
            $score -= 5;
        } else {
            // Check 2: Has it been removed from wordpress.org?
            if (! empty($wporg['closed'])) {
                $issues[] = [
                    'type'        => 'removed',
                    'severity'    => 'critical',
                    'plugin'      => $plugin_file,
                    'plugin_name' => $plugin_data['Name'] ?: $slug,
                    'message'     => sprintf(
                        '%s has been REMOVED from wordpress.org. This typically means a security issue or ' .
                        'guideline violation was found. You should find a replacement immediately.',
                        $plugin_data['Name'] ?: $slug
                    ),
                    'reason' => $wporg['closed_reason'] ?? 'Unknown',
                ];
                $score -= 50;
                $status = 'critical';
            }

            // Check 3: When was it last updated?
            if (! empty($wporg['last_updated'])) {
                $last_updated = strtotime($wporg['last_updated']);
                $months_ago = $last_updated
                    ? (int) round((time() - $last_updated) / (30 * DAY_IN_SECONDS))
                    : 0;

                if ($months_ago >= self::ABANDONED_MONTHS) {
                    $issues[] = [
                        'type'        => 'abandoned',
                        'severity'    => 'high',
                        'plugin'      => $plugin_file,
                        'plugin_name' => $plugin_data['Name'] ?: $slug,
                        'message'     => sprintf(
                            '%s has not been updated in %d months. Plugins abandoned for this long often have ' .
                            'unpatched security vulnerabilities and may break with future WordPress updates.',
                            $plugin_data['Name'] ?: $slug,
                            $months_ago
                        ),
                        'last_updated' => $wporg['last_updated'],
                        'months_ago'   => $months_ago,
                    ];
                    $score -= 30;
                    if ($status !== 'critical') {
                        $status = 'warning';
                    }
                } elseif ($months_ago >= self::STALE_MONTHS) {
                    $issues[] = [
                        'type'        => 'stale',
                        'severity'    => 'medium',
                        'plugin'      => $plugin_file,
                        'plugin_name' => $plugin_data['Name'] ?: $slug,
                        'message'     => sprintf(
                            '%s has not been updated in %d months. While it may still work, it\'s worth ' .
                            'checking if a better-maintained alternative exists.',
                            $plugin_data['Name'] ?: $slug,
                            $months_ago
                        ),
                        'last_updated' => $wporg['last_updated'],
                        'months_ago'   => $months_ago,
                    ];
                    $score -= 15;
                }
            }

            // Check 4: WordPress version compatibility.
            if (! empty($wporg['tested'])) {
                $current_wp = get_bloginfo('version');
                $tested_up_to = $wporg['tested'];

                if (version_compare($current_wp, $tested_up_to, '>')) {
                    $issues[] = [
                        'type'        => 'outdated_wp',
                        'severity'    => 'medium',
                        'plugin'      => $plugin_file,
                        'plugin_name' => $plugin_data['Name'] ?: $slug,
                        'message'     => sprintf(
                            '%s has only been tested up to WordPress %s, but you\'re running %s. ' .
                            'It may work fine, but the developer hasn\'t confirmed compatibility.',
                            $plugin_data['Name'] ?: $slug,
                            $tested_up_to,
                            $current_wp
                        ),
                        'tested_up_to' => $tested_up_to,
                        'current_wp'   => $current_wp,
                    ];
                    $score -= 10;
                }
            }

            // Check 5: Very low active installs (might indicate quality issues).
            $installs = (int) ($wporg['active_installs'] ?? 0);
            if ($installs > 0 && $installs < 1000) {
                $issues[] = [
                    'type'        => 'low_adoption',
                    'severity'    => 'low',
                    'plugin'      => $plugin_file,
                    'plugin_name' => $plugin_data['Name'] ?: $slug,
                    'message'     => sprintf(
                        '%s has fewer than 1,000 active installations. Low-adoption plugins receive ' .
                        'less community testing and may have undiscovered issues.',
                        $plugin_data['Name'] ?: $slug
                    ),
                    'active_installs' => $installs,
                ];
                $score -= 5;
            }

            // Check 6: Low rating.
            $rating = (float) ($wporg['rating'] ?? 0);
            $num_ratings = (int) ($wporg['num_ratings'] ?? 0);
            if ($num_ratings >= 10 && $rating < 60) {
                $issues[] = [
                    'type'        => 'low_rating',
                    'severity'    => 'medium',
                    'plugin'      => $plugin_file,
                    'plugin_name' => $plugin_data['Name'] ?: $slug,
                    'message'     => sprintf(
                        '%s has a rating of %d%% from %d reviews. This suggests widespread issues ' .
                        'reported by other users.',
                        $plugin_data['Name'] ?: $slug,
                        (int) $rating,
                        $num_ratings
                    ),
                    'rating'      => $rating,
                    'num_ratings' => $num_ratings,
                ];
                $score -= 10;
            }
        }

        // Check 7: Scan for risky/deprecated PHP function usage.
        $risky = $this->scan_for_risky_functions($plugin_file);
        if (! empty($risky)) {
            $issues[] = [
                'type'        => 'risky_code',
                'severity'    => 'medium',
                'plugin'      => $plugin_file,
                'plugin_name' => $plugin_data['Name'] ?: $slug,
                'message'     => sprintf(
                    '%s uses deprecated or removed PHP functions: %s. These may cause errors on ' .
                    'newer PHP versions and indicate the codebase hasn\'t been modernised.',
                    $plugin_data['Name'] ?: $slug,
                    implode(', ', array_slice($risky, 0, 5))
                ),
                'functions' => $risky,
            ];
            $score -= 15;
        }

        // Check 8: Is the installed version outdated?
        if ($wporg !== null && ! empty($wporg['version']) && ! empty($plugin_data['Version'])) {
            if (version_compare($plugin_data['Version'], $wporg['version'], '<')) {
                $issues[] = [
                    'type'            => 'update_available',
                    'severity'        => 'medium',
                    'plugin'          => $plugin_file,
                    'plugin_name'     => $plugin_data['Name'] ?: $slug,
                    'message'         => sprintf(
                        '%s has an update available: you\'re running v%s, latest is v%s. ' .
                        'Updates often include security patches and bug fixes.',
                        $plugin_data['Name'] ?: $slug,
                        $plugin_data['Version'],
                        $wporg['version']
                    ),
                    'current_version' => $plugin_data['Version'],
                    'latest_version'  => $wporg['version'],
                ];
                $score -= 10;
            }
        }

        return [
            'score'      => max(0, $score),
            'status'     => $status,
            'issues'     => $issues,
            'wporg_data' => $wporg,
        ];
    }

    /**
     * Query wordpress.org API for plugin information.
     *
     * @param string $slug Plugin slug.
     * @return array|null Plugin info, or null if not found.
     */
    private function get_wporg_data(string $slug): ?array {
        if (empty($slug)) {
            return null;
        }

        $cache_key = self::CACHE_PREFIX . $slug;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached === 'not_found' ? null : $cached;
        }

        $response = wp_remote_post(self::WP_API_BASE, [
            'timeout' => 10,
            'body'    => [
                'action'  => 'plugin_information',
                'request' => serialize((object) [
                    'slug'   => $slug,
                    'fields' => [
                        'active_installs' => true,
                        'last_updated'    => true,
                        'tested'          => true,
                        'requires'        => true,
                        'requires_php'    => true,
                        'rating'          => true,
                        'num_ratings'     => true,
                        'version'         => true,
                        'sections'        => false,
                        'description'     => false,
                        'short_description' => false,
                        'screenshots'     => false,
                        'tags'            => false,
                        'donate_link'     => false,
                        'contributors'    => false,
                        'compatibility'   => false,
                    ],
                ]),
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            set_transient($cache_key, 'not_found', self::CACHE_TTL);
            return null;
        }

        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = maybe_unserialize(wp_remote_retrieve_body($response));

        if (! is_object($body) && ! is_array($body)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
        }

        if (is_object($body)) {
            $body = (array) $body;
        }

        if (! is_array($body) || isset($body['error'])) {
            set_transient($cache_key, 'not_found', self::CACHE_TTL);
            return null;
        }

        $data = [
            'version'         => $body['version'] ?? '',
            'last_updated'    => $body['last_updated'] ?? '',
            'tested'          => $body['tested'] ?? '',
            'requires'        => $body['requires'] ?? '',
            'requires_php'    => $body['requires_php'] ?? '',
            'active_installs' => (int) ($body['active_installs'] ?? 0),
            'rating'          => (float) ($body['rating'] ?? 0),
            'num_ratings'     => (int) ($body['num_ratings'] ?? 0),
            'closed'          => ! empty($body['closed']),
            'closed_reason'   => $body['closed_reason'] ?? '',
        ];

        set_transient($cache_key, $data, self::CACHE_TTL);

        return $data;
    }

    /**
     * Scan a plugin's PHP files for deprecated/risky function calls.
     *
     * @param string $plugin_file Plugin file path.
     * @return array List of risky function names found.
     */
    private function scan_for_risky_functions(string $plugin_file): array {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

        if (! is_dir($plugin_dir)) {
            return [];
        }

        $found = [];
        $files = $this->get_php_files($plugin_dir, 50);

        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach (self::RISKY_FUNCTIONS as $func) {
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $contents)) {
                    $found[$func] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Get PHP files in a directory (with depth and count limits).
     *
     * @param string $dir       Directory to scan.
     * @param int    $max_files Maximum files to return.
     * @param int    $depth     Current recursion depth.
     * @return array File paths.
     */
    private function get_php_files(string $dir, int $max_files, int $depth = 0): array {
        if ($depth > 5 || ! is_readable($dir)) {
            return [];
        }

        $files = [];
        $entries = @scandir($dir);

        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (count($files) >= $max_files) {
                break;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $skip = ['node_modules', 'vendor', '.git', 'tests', 'test'];
                if (in_array($entry, $skip, true)) {
                    continue;
                }
                $files = array_merge($files, $this->get_php_files($path, $max_files - count($files), $depth + 1));
            } elseif (pathinfo($entry, PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Extract plugin slug from file path.
     */
    private function extract_slug(string $plugin_file): string {
        $parts = explode('/', $plugin_file);
        return sanitize_title($parts[0] ?? '');
    }
}
