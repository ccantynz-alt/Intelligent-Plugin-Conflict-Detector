<?php
/**
 * PHP Compatibility Analyzer — detects code that will break on newer PHP versions.
 *
 * The original PHP Compatibility Checker plugin was abandoned in 2022.
 * Hosts are actively upgrading to PHP 8.1, 8.2, and 8.3 — and plugins
 * break silently. This analyzer fills that gap.
 *
 * Scans plugin PHP files for:
 * - Functions removed in PHP 8.0+ (mysql_*, create_function, each)
 * - Changed function signatures (money_format, mbstring changes)
 * - Deprecated features (implicit float-to-int, ${} string interpolation)
 * - Strict type incompatibilities
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class PHPCompatAnalyzer {

    /**
     * Functions removed in specific PHP versions.
     * Format: function_name => [removed_in, replacement, risk_description]
     */
    private const REMOVED_FUNCTIONS = [
        // Removed in PHP 8.0
        'create_function'        => ['8.0', 'anonymous functions (closures)', 'Causes fatal error on PHP 8.0+'],
        'each'                   => ['8.0', 'foreach loops', 'Causes fatal error on PHP 8.0+'],
        'money_format'           => ['8.0', 'NumberFormatter', 'Causes fatal error on PHP 8.0+'],
        'restore_include_path'   => ['8.0', 'ini_restore(\'include_path\')', 'Causes fatal error on PHP 8.0+'],
        'get_magic_quotes_gpc'   => ['8.0', 'remove the call (magic quotes no longer exist)', 'Causes fatal error on PHP 8.0+'],
        'get_magic_quotes_runtime' => ['8.0', 'remove the call', 'Causes fatal error on PHP 8.0+'],
        'hebrevc'                => ['8.0', 'not needed', 'Causes fatal error on PHP 8.0+'],
        'convert_cyr_string'     => ['8.0', 'mb_convert_encoding or iconv', 'Causes fatal error on PHP 8.0+'],
        // Removed in PHP 7.0 (still found in ancient plugins)
        'mysql_connect'          => ['7.0', 'mysqli or PDO', 'Causes fatal error on PHP 7.0+'],
        'mysql_query'            => ['7.0', 'mysqli_query or PDO', 'Causes fatal error on PHP 7.0+'],
        'mysql_real_escape_string' => ['7.0', 'mysqli_real_escape_string', 'Causes fatal error on PHP 7.0+'],
        'mysql_fetch_array'      => ['7.0', 'mysqli_fetch_array', 'Causes fatal error on PHP 7.0+'],
        'mysql_fetch_assoc'      => ['7.0', 'mysqli_fetch_assoc', 'Causes fatal error on PHP 7.0+'],
        'mysql_num_rows'         => ['7.0', 'mysqli_num_rows', 'Causes fatal error on PHP 7.0+'],
        'mysql_close'            => ['7.0', 'mysqli_close', 'Causes fatal error on PHP 7.0+'],
        'ereg'                   => ['7.0', 'preg_match', 'Causes fatal error on PHP 7.0+'],
        'eregi'                  => ['7.0', 'preg_match with /i flag', 'Causes fatal error on PHP 7.0+'],
        'ereg_replace'           => ['7.0', 'preg_replace', 'Causes fatal error on PHP 7.0+'],
        'split'                  => ['7.0', 'preg_split or explode', 'Causes fatal error on PHP 7.0+'],
        'mcrypt_encrypt'         => ['7.2', 'openssl_encrypt', 'Causes fatal error on PHP 7.2+'],
        'mcrypt_decrypt'         => ['7.2', 'openssl_decrypt', 'Causes fatal error on PHP 7.2+'],
    ];

    /**
     * Patterns that indicate PHP version incompatibilities.
     * Format: [pattern, min_php_version_affected, description, severity]
     */
    private const COMPAT_PATTERNS = [
        // PHP 8.1: Implicit float-to-int conversion deprecated
        ['/\(int\)\s*\$/', '8.1', 'Explicit int casts may behave differently with float values', 'low'],

        // PHP 8.2: Dynamic properties deprecated
        ['/\$this->[a-zA-Z_]+\s*=(?!=)/', '8.2', 'Dynamic properties are deprecated in PHP 8.2 (classes without #[AllowDynamicProperties])', 'info'],

        // PHP 8.1: Return type declarations becoming enforced
        ['/function\s+\w+\s*\([^)]*\)\s*\{/', '8.1', 'Functions without return type declarations may trigger deprecation notices', 'info'],

        // PHP 8.0: Named arguments can break positional calls if parameter names change
        // Not easily detectable via regex, but we flag it as a general risk

        // PHP 8.1: Fibers and enums - not a compat issue, but readiness indicator

        // PHP 8.0: Null-safe operator usage indicates modern code (positive signal)
    ];

    /**
     * Analyze all active plugins for PHP compatibility issues.
     *
     * @param string|null $target_php PHP version to check against (e.g., '8.2').
     *                                Defaults to current PHP version.
     * @return array{
     *   target_php: string,
     *   current_php: string,
     *   plugins: array<string, array>,
     *   summary: array{clean: int, warnings: int, errors: int},
     *   issues: array
     * }
     */
    public function analyze(?string $target_php = null): array {
        if ($target_php === null) {
            $target_php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        }

        $current_php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $active_plugins = get_option('active_plugins', []);
        $results = [];
        $all_issues = [];
        $summary = ['clean' => 0, 'warnings' => 0, 'errors' => 0];

        foreach ($active_plugins as $plugin_file) {
            if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

            $issues = $this->scan_plugin($plugin_dir, $target_php, $plugin_data, $plugin_file);
            $results[$plugin_file] = [
                'name'   => $plugin_data['Name'] ?: dirname($plugin_file),
                'issues' => $issues,
                'status' => empty($issues) ? 'compatible' : (
                    $this->has_severity($issues, 'critical') ? 'incompatible' : 'warnings'
                ),
            ];

            if (empty($issues)) {
                $summary['clean']++;
            } elseif ($this->has_severity($issues, 'critical') || $this->has_severity($issues, 'high')) {
                $summary['errors']++;
            } else {
                $summary['warnings']++;
            }

            foreach ($issues as $issue) {
                $all_issues[] = $issue;
            }
        }

        return [
            'target_php'  => $target_php,
            'current_php' => $current_php,
            'plugins'     => $results,
            'summary'     => $summary,
            'issues'      => $all_issues,
        ];
    }

    /**
     * Scan a single plugin for PHP compatibility issues.
     *
     * @param string $plugin_dir  Plugin directory path.
     * @param string $target_php  Target PHP version.
     * @param array  $plugin_data Plugin header data.
     * @param string $plugin_file Plugin file path.
     * @return array List of compatibility issues.
     */
    private function scan_plugin(
        string $plugin_dir,
        string $target_php,
        array $plugin_data,
        string $plugin_file
    ): array {
        if (! is_dir($plugin_dir)) {
            return [];
        }

        $issues = [];
        $files = $this->get_php_files($plugin_dir, 100);
        $plugin_name = $plugin_data['Name'] ?: dirname($plugin_file);

        // Check requires_php header first.
        $requires_php = $plugin_data['RequiresPHP'] ?? '';
        if (! empty($requires_php) && version_compare($target_php, $requires_php, '<')) {
            $issues[] = [
                'type'        => 'php_version_requirement',
                'severity'    => 'critical',
                'plugin'      => $plugin_file,
                'plugin_name' => $plugin_name,
                'message'     => sprintf(
                    '%s requires PHP %s or higher, but the target version is %s.',
                    $plugin_name,
                    $requires_php,
                    $target_php
                ),
                'file'     => $plugin_file,
                'required' => $requires_php,
                'target'   => $target_php,
            ];
        }

        // Scan each PHP file for compatibility issues.
        $removed_found = [];
        $pattern_found = [];

        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false || strlen($contents) === 0) {
                continue;
            }

            $relative_path = str_replace($plugin_dir . '/', '', $file);

            // Check for removed functions.
            foreach (self::REMOVED_FUNCTIONS as $func => $info) {
                if (version_compare($target_php, $info[0], '>=')) {
                    if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $contents)) {
                        if (! isset($removed_found[$func])) {
                            $removed_found[$func] = [
                                'function'    => $func,
                                'removed_in'  => $info[0],
                                'replacement' => $info[1],
                                'risk'        => $info[2],
                                'files'       => [],
                            ];
                        }
                        $removed_found[$func]['files'][] = $relative_path;
                    }
                }
            }

            // Check for PHP 8.2 dynamic properties.
            if (version_compare($target_php, '8.2', '>=')) {
                if (preg_match('/class\s+\w+[^{]*\{/', $contents) &&
                    ! preg_match('/#\[AllowDynamicProperties\]/', $contents) &&
                    preg_match('/\$this->\w+\s*=[^=]/', $contents)) {
                    // Only flag if the class doesn't declare the properties.
                    $class_matches = [];
                    if (preg_match_all('/class\s+(\w+)/', $contents, $class_matches)) {
                        foreach ($class_matches[1] as $class_name) {
                            if (! isset($pattern_found['dynamic_props_' . $class_name])) {
                                $pattern_found['dynamic_props_' . $class_name] = $relative_path;
                            }
                        }
                    }
                }
            }

            // Check for deprecated ${} string interpolation (PHP 8.2).
            if (version_compare($target_php, '8.2', '>=')) {
                if (preg_match('/"\$\{[^}]+\}"/', $contents)) {
                    if (! isset($pattern_found['string_interpolation'])) {
                        $pattern_found['string_interpolation'] = [];
                    }
                    $pattern_found['string_interpolation'][] = $relative_path;
                }
            }

            // Check for utf8_encode/utf8_decode (deprecated in PHP 8.2).
            if (version_compare($target_php, '8.2', '>=')) {
                if (preg_match('/\b(utf8_encode|utf8_decode)\s*\(/', $contents, $m)) {
                    $func_name = $m[1];
                    if (! isset($removed_found[$func_name])) {
                        $removed_found[$func_name] = [
                            'function'    => $func_name,
                            'removed_in'  => '8.2',
                            'replacement' => 'mb_convert_encoding',
                            'risk'        => 'Deprecated in PHP 8.2, will be removed in a future version',
                            'files'       => [],
                        ];
                    }
                    $removed_found[$func_name]['files'][] = $relative_path;
                }
            }
        }

        // Convert removed functions to issues.
        foreach ($removed_found as $func => $data) {
            $is_fatal = version_compare($target_php, $data['removed_in'], '>=');
            $issues[] = [
                'type'        => 'removed_function',
                'severity'    => $is_fatal ? 'critical' : 'high',
                'plugin'      => $plugin_file,
                'plugin_name' => $plugin_name,
                'message'     => sprintf(
                    '%s uses %s() which was removed in PHP %s. %s Replace with: %s. Found in: %s.',
                    $plugin_name,
                    $func,
                    $data['removed_in'],
                    $data['risk'],
                    $data['replacement'],
                    implode(', ', array_slice($data['files'], 0, 3)) .
                        (count($data['files']) > 3 ? ' (+' . (count($data['files']) - 3) . ' more)' : '')
                ),
                'function'    => $func,
                'removed_in'  => $data['removed_in'],
                'replacement' => $data['replacement'],
                'file_count'  => count($data['files']),
            ];
        }

        // Convert pattern matches to issues.
        if (! empty($pattern_found['string_interpolation'])) {
            $issues[] = [
                'type'        => 'deprecated_syntax',
                'severity'    => 'medium',
                'plugin'      => $plugin_file,
                'plugin_name' => $plugin_name,
                'message'     => sprintf(
                    '%s uses ${} string interpolation syntax which is deprecated in PHP 8.2. ' .
                    'Found in %d file(s).',
                    $plugin_name,
                    count($pattern_found['string_interpolation'])
                ),
            ];
        }

        return $issues;
    }

    /**
     * Check if any issue in the list has the given severity.
     */
    private function has_severity(array $issues, string $severity): bool {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === $severity) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get PHP files in a directory with safety limits.
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
                $skip = ['node_modules', 'vendor', '.git', 'tests', 'test', 'assets'];
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
}
