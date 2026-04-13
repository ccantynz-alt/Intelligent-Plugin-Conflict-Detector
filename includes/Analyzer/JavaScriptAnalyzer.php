<?php
/**
 * JavaScript Conflict Analyzer — detects frontend JS conflicts between plugins.
 *
 * Scans JavaScript files for:
 * - Global namespace pollution (window.X, var X at top level)
 * - jQuery version overrides (deregistering WordPress's jQuery)
 * - Prototype pollution (modifying Object/Array/String prototypes)
 * - jQuery noConflict violations
 * - Module name collisions
 * - Inline script conflicts
 *
 * No other WordPress conflict detection tool analyzes JavaScript.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class JavaScriptAnalyzer {

    /** Common globals that are expected and should be ignored. */
    private const SAFE_GLOBALS = [
        'jQuery', '$', 'wp', 'lodash', '_', 'Backbone', 'JSON',
        'console', 'window', 'document', 'navigator', 'location',
        'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval',
        'requestAnimationFrame', 'cancelAnimationFrame',
        'Promise', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Symbol',
        'Proxy', 'Reflect', 'ArrayBuffer', 'DataView',
        'undefined', 'NaN', 'Infinity', 'null', 'true', 'false',
        'React', 'ReactDOM', 'regeneratorRuntime',
    ];

    /** Patterns that indicate jQuery version override. */
    private const JQUERY_OVERRIDE_PATTERNS = [
        '/wp_deregister_script\s*\(\s*[\'"]jquery[\'"]\s*\)/',
        '/wp_dequeue_script\s*\(\s*[\'"]jquery[\'"]\s*\)/',
        '/\$\.noConflict\s*\(\s*true\s*\)/',
    ];

    /**
     * Analyze plugins for JavaScript conflicts.
     *
     * @param array<string> $plugins Active plugin file paths.
     * @return array Detected JS conflicts.
     */
    public function analyze(array $plugins): array {
        $global_map     = [];
        $jquery_issues  = [];
        $prototype_mods = [];
        $localize_vars  = [];
        $conflicts      = [];

        foreach ($plugins as $plugin_file) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

            if (! is_dir($plugin_dir)) {
                continue;
            }

            $js_files = $this->find_js_files($plugin_dir);

            foreach ($js_files as $js_file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $contents = file_get_contents($js_file);

                if ($contents === false || strlen($contents) > 500000) {
                    continue; // Skip unreadable or very large files (likely minified vendor).
                }

                // 1. Extract global variable declarations.
                $globals = $this->extract_globals($contents);

                foreach ($globals as $global) {
                    if (! isset($global_map[$global])) {
                        $global_map[$global] = [];
                    }

                    $global_map[$global][] = [
                        'plugin' => $plugin_file,
                        'file'   => basename($js_file),
                    ];
                }

                // 2. Detect jQuery overrides.
                $jquery_issue = $this->detect_jquery_override($contents, $plugin_file, $js_file);

                if ($jquery_issue !== null) {
                    $jquery_issues[] = $jquery_issue;
                }

                // 3. Detect prototype pollution.
                $protos = $this->detect_prototype_pollution($contents, $plugin_file, $js_file);
                $prototype_mods = array_merge($prototype_mods, $protos);

                // 4. Extract wp_localize_script variable names from PHP files.
                // (We scan the PHP files for this.)
            }

            // Scan PHP files for wp_localize_script collisions.
            $php_files = $this->find_php_files($plugin_dir);

            foreach ($php_files as $php_file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $contents = file_get_contents($php_file);

                if ($contents === false) {
                    continue;
                }

                $vars = $this->extract_localize_vars($contents, $plugin_file);
                $localize_vars = array_merge($localize_vars, $vars);
            }
        }

        // Cross-reference globals for conflicts.
        foreach ($global_map as $global_name => $instances) {
            if (count($instances) < 2) {
                continue;
            }

            // Get unique plugins.
            $plugins_involved = array_unique(array_column($instances, 'plugin'));

            if (count($plugins_involved) < 2) {
                continue;
            }

            $conflicts[] = [
                'type'        => 'js_global_conflict',
                'plugin_a'    => $plugins_involved[0],
                'plugin_b'    => $plugins_involved[1],
                'severity'    => 'medium',
                'description' => sprintf(
                    'JavaScript global variable "%s" is defined by both %s and %s. One will silently overwrite the other.',
                    $global_name,
                    dirname($plugins_involved[0]),
                    dirname($plugins_involved[1])
                ),
                'details' => [
                    'global_name' => $global_name,
                    'instances'   => $instances,
                    'js_conflict' => true,
                ],
            ];
        }

        // jQuery override conflicts.
        if (count($jquery_issues) > 0) {
            foreach ($jquery_issues as $issue) {
                $conflicts[] = [
                    'type'        => 'js_jquery_override',
                    'plugin_a'    => $issue['plugin'],
                    'plugin_b'    => '',
                    'severity'    => 'high',
                    'description' => sprintf(
                        '%s overrides WordPress\'s bundled jQuery, which can break other plugins and the block editor.',
                        dirname($issue['plugin'])
                    ),
                    'details' => [
                        'file'        => $issue['file'],
                        'pattern'     => $issue['pattern'],
                        'js_conflict' => true,
                    ],
                ];
            }
        }

        // Prototype pollution.
        if (count($prototype_mods) > 0) {
            $proto_by_target = [];

            foreach ($prototype_mods as $mod) {
                $key = $mod['target'];

                if (! isset($proto_by_target[$key])) {
                    $proto_by_target[$key] = [];
                }

                $proto_by_target[$key][] = $mod;
            }

            foreach ($proto_by_target as $target => $mods) {
                $plugins_involved = array_unique(array_column($mods, 'plugin'));

                if (count($plugins_involved) >= 2) {
                    $conflicts[] = [
                        'type'        => 'js_prototype_pollution',
                        'plugin_a'    => $plugins_involved[0],
                        'plugin_b'    => $plugins_involved[1],
                        'severity'    => 'high',
                        'description' => sprintf(
                            'Both %s and %s modify %s.prototype, which can cause unpredictable behavior across all JavaScript.',
                            dirname($plugins_involved[0]),
                            dirname($plugins_involved[1]),
                            $target
                        ),
                        'details' => [
                            'target'      => $target,
                            'modifications' => $mods,
                            'js_conflict' => true,
                        ],
                    ];
                } elseif (count($mods) > 0) {
                    // Even a single plugin modifying built-in prototypes is risky.
                    $conflicts[] = [
                        'type'        => 'js_prototype_pollution',
                        'plugin_a'    => $mods[0]['plugin'],
                        'plugin_b'    => '',
                        'severity'    => 'medium',
                        'description' => sprintf(
                            '%s modifies %s.prototype, which may affect other plugins.',
                            dirname($mods[0]['plugin']),
                            $target
                        ),
                        'details' => [
                            'target'      => $target,
                            'modifications' => $mods,
                            'js_conflict' => true,
                        ],
                    ];
                }
            }
        }

        // wp_localize_script variable collisions.
        $var_map = [];

        foreach ($localize_vars as $var) {
            $key = $var['variable'];

            if (! isset($var_map[$key])) {
                $var_map[$key] = [];
            }

            $var_map[$key][] = $var;
        }

        foreach ($var_map as $var_name => $instances) {
            $plugins_involved = array_unique(array_column($instances, 'plugin'));

            if (count($plugins_involved) < 2) {
                continue;
            }

            $conflicts[] = [
                'type'        => 'js_localize_collision',
                'plugin_a'    => $plugins_involved[0],
                'plugin_b'    => $plugins_involved[1],
                'severity'    => 'medium',
                'description' => sprintf(
                    'Both %s and %s inject data via wp_localize_script using the same JS variable name "%s". The last one loaded wins.',
                    dirname($plugins_involved[0]),
                    dirname($plugins_involved[1]),
                    $var_name
                ),
                'details' => [
                    'variable'    => $var_name,
                    'instances'   => $instances,
                    'js_conflict' => true,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Extract global variable declarations from JavaScript content.
     *
     * @param string $contents JavaScript file contents.
     * @return array<string> Global variable names.
     */
    private function extract_globals(string $contents): array {
        $globals = [];

        // Remove comments to avoid false positives.
        $contents = $this->strip_js_comments($contents);

        // Detect: window.VarName = ...
        if (preg_match_all('/window\.([A-Z][a-zA-Z0-9_$]+)\s*=/', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                if (! in_array($name, self::SAFE_GLOBALS, true)) {
                    $globals[] = $name;
                }
            }
        }

        // Detect: window['VarName'] = ... or window["VarName"] = ...
        if (preg_match_all('/window\[[\'"]([A-Z][a-zA-Z0-9_$]+)[\'"]\]\s*=/', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                if (! in_array($name, self::SAFE_GLOBALS, true)) {
                    $globals[] = $name;
                }
            }
        }

        // Detect top-level var/let/const (not inside a function).
        // This is approximate — we check for declarations at indent level 0.
        if (preg_match_all('/^(?:var|let|const)\s+([A-Z][a-zA-Z0-9_$]+)\s*=/m', $contents, $matches)) {
            foreach ($matches[1] as $name) {
                if (! in_array($name, self::SAFE_GLOBALS, true)) {
                    $globals[] = $name;
                }
            }
        }

        return array_unique($globals);
    }

    /**
     * Detect jQuery version override patterns.
     *
     * @param string $contents  File contents (could be JS or PHP).
     * @param string $plugin    Plugin file path.
     * @param string $file_path Full path to the file.
     * @return array|null Issue details if jQuery override detected.
     */
    private function detect_jquery_override(string $contents, string $plugin, string $file_path): ?array {
        foreach (self::JQUERY_OVERRIDE_PATTERNS as $pattern) {
            if (preg_match($pattern, $contents)) {
                return [
                    'plugin'  => $plugin,
                    'file'    => basename($file_path),
                    'pattern' => $pattern,
                ];
            }
        }

        return null;
    }

    /**
     * Detect prototype pollution (modifying built-in prototypes).
     *
     * @param string $contents  JavaScript file contents.
     * @param string $plugin    Plugin file path.
     * @param string $file_path Full path to the file.
     * @return array List of prototype modifications found.
     */
    private function detect_prototype_pollution(string $contents, string $plugin, string $file_path): array {
        $mods = [];
        $targets = ['Array', 'Object', 'String', 'Number', 'Date', 'Function', 'RegExp', 'Boolean'];

        foreach ($targets as $target) {
            $pattern = '/' . preg_quote($target, '/') . '\.prototype\.\w+\s*=/';

            if (preg_match_all($pattern, $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $mods[] = [
                        'plugin' => $plugin,
                        'file'   => basename($file_path),
                        'target' => $target,
                        'code'   => trim($match),
                    ];
                }
            }
        }

        return $mods;
    }

    /**
     * Extract wp_localize_script variable names from PHP code.
     *
     * @param string $contents PHP file contents.
     * @param string $plugin   Plugin file path.
     * @return array List of localized variables.
     */
    private function extract_localize_vars(string $contents, string $plugin): array {
        $vars = [];

        // wp_localize_script( 'handle', 'variableName', $data )
        $pattern = '/wp_localize_script\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $var_name) {
                $vars[] = [
                    'plugin'   => $plugin,
                    'variable' => $var_name,
                ];
            }
        }

        // Also check wp_add_inline_script for global assignments.
        $inline_pattern = '/wp_add_inline_script\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"](.*?)[\'"]/s';

        if (preg_match_all($inline_pattern, $contents, $matches)) {
            foreach ($matches[1] as $inline_js) {
                if (preg_match_all('/(?:var|let|const|window\.)\s*([A-Z][a-zA-Z0-9_$]+)\s*=/', $inline_js, $var_matches)) {
                    foreach ($var_matches[1] as $var_name) {
                        if (! in_array($var_name, self::SAFE_GLOBALS, true)) {
                            $vars[] = [
                                'plugin'   => $plugin,
                                'variable' => $var_name,
                            ];
                        }
                    }
                }
            }
        }

        return $vars;
    }

    /**
     * Find all JavaScript files in a plugin directory.
     *
     * @param string $plugin_dir Plugin directory path.
     * @return array<string> JavaScript file paths.
     */
    private function find_js_files(string $plugin_dir): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;

        foreach ($iterator as $file) {
            // Safety: limit to prevent scanning massive plugin directories.
            if (++$count > 500) {
                break;
            }

            if ($file->isFile() && $file->getExtension() === 'js') {
                $path = $file->getPathname();

                // Skip vendor/node_modules directories.
                if (strpos($path, '/node_modules/') !== false) {
                    continue;
                }

                // Skip minified vendor libraries (but keep plugin's own min files).
                if (preg_match('/vendor.*\.min\.js$/', $path)) {
                    continue;
                }

                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Find PHP files in a plugin directory (non-recursive, top level + includes).
     *
     * @param string $plugin_dir Plugin directory path.
     * @return array<string> PHP file paths.
     */
    private function find_php_files(string $plugin_dir): array {
        $files = glob($plugin_dir . '/*.php') ?: [];
        $includes = glob($plugin_dir . '/includes/*.php') ?: [];
        $src = glob($plugin_dir . '/src/*.php') ?: [];

        return array_merge($files, $includes, $src);
    }

    /**
     * Strip JavaScript comments to reduce false positives.
     *
     * @param string $js JavaScript content.
     * @return string Content without comments.
     */
    private function strip_js_comments(string $js): string {
        // Remove single-line comments.
        $js = preg_replace('#//[^\n]*#', '', $js);

        // Remove multi-line comments.
        $js = preg_replace('#/\*.*?\*/#s', '', $js);

        return $js;
    }
}
