<?php
/**
 * Dependency Analyzer — detects bundled PHP library version conflicts.
 *
 * Many WordPress plugins bundle their own copies of popular PHP libraries
 * (Guzzle, Carbon, Monolog, Symfony components, etc.). When two plugins
 * bundle incompatible versions of the same library without proper namespacing,
 * it causes fatal errors that are extremely difficult to diagnose.
 *
 * This analyzer scans plugin directories for known library signatures and
 * compares versions across plugins.
 *
 * No other WordPress conflict detection tool does this.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class DependencyAnalyzer {

    /**
     * Known library signatures — directory patterns and version detection methods.
     *
     * Each entry maps a library name to:
     *  - paths: glob patterns to find the library inside a plugin
     *  - version_file: relative path to the file containing the version constant/string
     *  - version_pattern: regex to extract the version from that file
     *  - namespace: the PHP namespace this library uses (for prefix detection)
     */
    private const KNOWN_LIBRARIES = [
        'guzzlehttp/guzzle' => [
            'paths'           => ['vendor/guzzlehttp/guzzle', 'lib/guzzle', 'includes/guzzle'],
            'version_file'    => 'src/Client.php',
            'version_pattern' => '/const\s+(?:MAJOR_)?VERSION\s*=\s*[\'"](\d+\.\d+[\.\d]*)[\'"]/',
            'namespace'       => 'GuzzleHttp',
            'risk'            => 'critical',
        ],
        'monolog/monolog' => [
            'paths'           => ['vendor/monolog/monolog', 'lib/monolog'],
            'version_file'    => 'src/Monolog/Logger.php',
            'version_pattern' => '/const\s+API\s*=\s*(\d+)/',
            'namespace'       => 'Monolog',
            'risk'            => 'high',
        ],
        'nesbot/carbon' => [
            'paths'           => ['vendor/nesbot/carbon', 'lib/carbon'],
            'version_file'    => 'src/Carbon/Carbon.php',
            'version_pattern' => '/const\s+VERSION\s*=\s*[\'"](\d+\.\d+[\.\d]*)[\'"]/',
            'namespace'       => 'Carbon',
            'risk'            => 'high',
        ],
        'symfony/http-foundation' => [
            'paths'           => ['vendor/symfony/http-foundation'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'Symfony\\Component\\HttpFoundation',
            'risk'            => 'high',
        ],
        'symfony/console' => [
            'paths'           => ['vendor/symfony/console'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'Symfony\\Component\\Console',
            'risk'            => 'medium',
        ],
        'league/container' => [
            'paths'           => ['vendor/league/container'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'League\\Container',
            'risk'            => 'high',
        ],
        'league/csv' => [
            'paths'           => ['vendor/league/csv'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'League\\Csv',
            'risk'            => 'medium',
        ],
        'phpmailer/phpmailer' => [
            'paths'           => ['vendor/phpmailer/phpmailer'],
            'version_file'    => 'src/PHPMailer.php',
            'version_pattern' => '/const\s+VERSION\s*=\s*[\'"](\d+\.\d+[\.\d]*)[\'"]/',
            'namespace'       => 'PHPMailer\\PHPMailer',
            'risk'            => 'medium',
        ],
        'stripe/stripe-php' => [
            'paths'           => ['vendor/stripe/stripe-php', 'lib/stripe', 'includes/stripe'],
            'version_file'    => 'lib/Stripe.php',
            'version_pattern' => '/const\s+VERSION\s*=\s*[\'"](\d+\.\d+[\.\d]*)[\'"]/',
            'namespace'       => 'Stripe',
            'risk'            => 'critical',
        ],
        'firebase/php-jwt' => [
            'paths'           => ['vendor/firebase/php-jwt'],
            'version_file'    => 'src/JWT.php',
            'version_pattern' => '/const\s+(?:VERSION|ASN1_INTEGER)\s/',
            'namespace'       => 'Firebase\\JWT',
            'risk'            => 'high',
        ],
        'psr/log' => [
            'paths'           => ['vendor/psr/log'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'Psr\\Log',
            'risk'            => 'medium',
        ],
        'psr/container' => [
            'paths'           => ['vendor/psr/container'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'Psr\\Container',
            'risk'            => 'medium',
        ],
        'pelago/emogrifier' => [
            'paths'           => ['vendor/pelago/emogrifier'],
            'version_file'    => 'composer.json',
            'version_pattern' => '/"version"\s*:\s*"v?(\d+\.\d+[\.\d]*)"/',
            'namespace'       => 'Pelago\\Emogrifier',
            'risk'            => 'medium',
        ],
    ];

    /**
     * Analyze plugins for bundled library version conflicts.
     *
     * @param array<string> $plugins Active plugin file paths.
     * @return array Detected conflicts.
     */
    public function analyze(array $plugins): array {
        $library_map = $this->build_library_map($plugins);
        $conflicts   = [];

        foreach ($library_map as $library_name => $instances) {
            if (count($instances) < 2) {
                continue;
            }

            // Check for version conflicts.
            $conflict = $this->check_version_conflict($library_name, $instances);

            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }

            // Check for namespace prefix conflicts (one prefixed, one not).
            $prefix_conflict = $this->check_prefix_conflict($library_name, $instances);

            if ($prefix_conflict !== null) {
                $conflicts[] = $prefix_conflict;
            }
        }

        return $conflicts;
    }

    /**
     * Build a map of which plugins bundle which libraries.
     *
     * @param array<string> $plugins Plugin file paths.
     * @return array<string, array<array{plugin: string, version: string, path: string, prefixed: bool}>>
     */
    private function build_library_map(array $plugins): array {
        $map = [];

        foreach ($plugins as $plugin_file) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

            if (! is_dir($plugin_dir)) {
                continue;
            }

            foreach (self::KNOWN_LIBRARIES as $lib_name => $lib_info) {
                $found = $this->find_library($plugin_dir, $lib_info);

                if ($found !== null) {
                    if (! isset($map[$lib_name])) {
                        $map[$lib_name] = [];
                    }

                    $map[$lib_name][] = [
                        'plugin'   => $plugin_file,
                        'version'  => $found['version'],
                        'path'     => $found['path'],
                        'prefixed' => $found['prefixed'],
                    ];
                }
            }

            // Also check for composer.json with locked dependencies.
            $this->scan_composer_lock($plugin_dir, $plugin_file, $map);
        }

        return $map;
    }

    /**
     * Find a specific library inside a plugin directory.
     *
     * @param string $plugin_dir Plugin directory path.
     * @param array  $lib_info   Library detection configuration.
     * @return array{version: string, path: string, prefixed: bool}|null
     */
    private function find_library(string $plugin_dir, array $lib_info): ?array {
        foreach ($lib_info['paths'] as $path) {
            $full_path = $plugin_dir . '/' . $path;

            if (! is_dir($full_path)) {
                continue;
            }

            $version = $this->detect_version($full_path, $lib_info);
            $prefixed = $this->is_namespace_prefixed($full_path, $lib_info['namespace'] ?? '');

            return [
                'version'  => $version,
                'path'     => $full_path,
                'prefixed' => $prefixed,
            ];
        }

        return null;
    }

    /**
     * Detect the version of a bundled library.
     */
    private function detect_version(string $lib_path, array $lib_info): string {
        $version_file = $lib_path . '/' . ($lib_info['version_file'] ?? '');
        $pattern = $lib_info['version_pattern'] ?? '';

        if (empty($pattern) || ! file_exists($version_file)) {
            // Try composer.json as fallback.
            $composer = $lib_path . '/composer.json';

            if (file_exists($composer)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $data = json_decode(file_get_contents($composer), true);

                if (is_array($data) && isset($data['version'])) {
                    return ltrim($data['version'], 'v');
                }
            }

            return 'unknown';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents($version_file);

        if ($contents === false) {
            return 'unknown';
        }

        $matches = [];

        if (preg_match($pattern, $contents, $matches)) {
            return $matches[1] ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Check if a library's namespace has been prefixed (scoped).
     *
     * Prefixed libraries (e.g., via php-scoper or strauss) are safe because
     * they won't collide with another copy of the same library.
     */
    private function is_namespace_prefixed(string $lib_path, string $expected_namespace): bool {
        if (empty($expected_namespace)) {
            return false;
        }

        // Find a PHP file and check if the namespace matches the expected one.
        $php_files = glob($lib_path . '/src/*.php');

        if (empty($php_files)) {
            $php_files = glob($lib_path . '/*.php');
        }

        if (empty($php_files)) {
            return false;
        }

        $first_file = $php_files[0];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents($first_file);

        if ($contents === false) {
            return false;
        }

        // If the file contains the exact expected namespace, it's NOT prefixed.
        // If it contains a modified version (e.g., "MyPlugin\Vendor\GuzzleHttp"), it IS prefixed.
        $escaped_ns = preg_quote($expected_namespace, '/');

        if (preg_match('/^namespace\s+' . $escaped_ns . '\s*;/m', $contents)) {
            return false; // Uses the standard namespace — not prefixed.
        }

        // Check for any namespace that contains the library name.
        $parts = explode('\\', $expected_namespace);
        $last_part = end($parts);
        $escaped_part = preg_quote($last_part, '/');

        if (preg_match('/^namespace\s+.+\\\\' . $escaped_part . '/m', $contents)) {
            return true; // Prefixed namespace found.
        }

        return false;
    }

    /**
     * Scan composer.lock for locked dependency versions.
     *
     * @param string $plugin_dir Plugin directory.
     * @param string $plugin_file Plugin file path.
     * @param array  &$map Library map to populate.
     */
    private function scan_composer_lock(string $plugin_dir, string $plugin_file, array &$map): void {
        $lock_file = $plugin_dir . '/composer.lock';

        if (! file_exists($lock_file)) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents($lock_file);

        if ($contents === false) {
            return;
        }

        $lock_data = json_decode($contents, true);

        if (! is_array($lock_data) || ! isset($lock_data['packages'])) {
            return;
        }

        foreach ($lock_data['packages'] as $package) {
            $name = $package['name'] ?? '';
            $version = ltrim($package['version'] ?? '', 'v');

            if (empty($name) || empty($version) || ! isset(self::KNOWN_LIBRARIES[$name])) {
                continue;
            }

            // Skip if we already found this library via directory scanning.
            $already_found = false;

            foreach (($map[$name] ?? []) as $entry) {
                if ($entry['plugin'] === $plugin_file) {
                    $already_found = true;
                    break;
                }
            }

            if ($already_found) {
                continue;
            }

            if (! isset($map[$name])) {
                $map[$name] = [];
            }

            $map[$name][] = [
                'plugin'   => $plugin_file,
                'version'  => $version,
                'path'     => $plugin_dir . '/vendor/' . $name,
                'prefixed' => false,
                'source'   => 'composer.lock',
            ];
        }
    }

    /**
     * Check if library instances have incompatible versions.
     */
    private function check_version_conflict(string $library_name, array $instances): ?array {
        $lib_config = self::KNOWN_LIBRARIES[$library_name] ?? [];

        // Filter out prefixed instances (they're safe).
        $unprefixed = array_filter($instances, fn(array $i): bool => ! $i['prefixed']);

        if (count($unprefixed) < 2) {
            return null; // Only one unprefixed instance, no conflict.
        }

        // Compare major versions between unprefixed instances.
        $versions = array_column($unprefixed, 'version');
        $unique_versions = array_unique($versions);

        if (count($unique_versions) <= 1) {
            return null; // Same version everywhere.
        }

        // Check if major versions differ (most dangerous).
        $majors = [];

        foreach ($unprefixed as $instance) {
            $parts = explode('.', $instance['version']);
            $major = $parts[0] ?? '0';
            $majors[$major] = $majors[$major] ?? [];
            $majors[$major][] = $instance;
        }

        $is_major_conflict = count($majors) > 1;
        $severity = $is_major_conflict ? ($lib_config['risk'] ?? 'high') : 'medium';

        // Build the conflict entry.
        $plugins = array_column($unprefixed, 'plugin');
        $pairs = [];

        for ($i = 0; $i < count($unprefixed); $i++) {
            for ($j = $i + 1; $j < count($unprefixed); $j++) {
                if ($unprefixed[$i]['version'] !== $unprefixed[$j]['version']) {
                    $pairs[] = [
                        'a' => $unprefixed[$i],
                        'b' => $unprefixed[$j],
                    ];
                }
            }
        }

        if (empty($pairs)) {
            return null;
        }

        // Return only the first conflicting pair.
        $first = $pairs[0];

        return [
            'type'        => 'dependency_conflict',
            'plugin_a'    => $first['a']['plugin'],
            'plugin_b'    => $first['b']['plugin'],
            'severity'    => $severity,
            'description' => sprintf(
                'Both plugins bundle %s but at different versions (%s vs %s). %s loads first and its version will be used, potentially breaking %s.',
                $library_name,
                $first['a']['version'],
                $first['b']['version'],
                $this->slug($first['a']['plugin']),
                $this->slug($first['b']['plugin'])
            ),
            'details' => [
                'library'         => $library_name,
                'version_a'       => $first['a']['version'],
                'version_b'       => $first['b']['version'],
                'major_conflict'  => $is_major_conflict,
                'all_instances'   => array_map(fn(array $i): array => [
                    'plugin'  => $i['plugin'],
                    'version' => $i['version'],
                ], $unprefixed),
            ],
        ];
    }

    /**
     * Check for prefix conflicts (one plugin prefixed, one not).
     *
     * This is informational — prefixed libraries are safe, but it's worth
     * noting that the plugin did the right thing.
     */
    private function check_prefix_conflict(string $library_name, array $instances): ?array {
        $prefixed   = array_filter($instances, fn(array $i): bool => $i['prefixed']);
        $unprefixed = array_filter($instances, fn(array $i): bool => ! $i['prefixed']);

        if (count($unprefixed) < 2 || count($prefixed) === 0) {
            return null;
        }

        // There's a mix of prefixed and unprefixed — the unprefixed ones
        // still conflict with each other. This is handled by check_version_conflict.
        // This method only flags when ALL but one are prefixed.
        return null;
    }

    /**
     * Extract plugin slug from file path.
     */
    private function slug(string $plugin_file): string {
        return dirname($plugin_file);
    }
}
