<?php
/**
 * Static code analyzer — parses PHP source files without executing them.
 *
 * Detects function redeclarations, class name collisions, and global variable conflicts
 * across all active plugins.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class StaticAnalyzer {

    /**
     * Analyze all active plugins for static code conflicts.
     *
     * @param array<string> $plugin_files List of plugin main file paths relative to plugins dir.
     * @return array<array{type: string, plugin_a: string, plugin_b: string, severity: string, description: string, details: array}>
     */
    public function analyze(array $plugin_files): array {
        $conflicts = [];

        // Gather declarations from each plugin.
        $declarations = [];
        foreach ($plugin_files as $plugin_file) {
            $plugin_dir = $this->get_plugin_directory($plugin_file);

            if ($plugin_dir === null) {
                continue;
            }

            $declarations[$plugin_file] = $this->scan_plugin_directory($plugin_dir);
        }

        // Cross-reference for collisions.
        $plugin_list = array_keys($declarations);
        $count = count($plugin_list);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $plugin_list[$i];
                $b = $plugin_list[$j];

                $conflicts = array_merge(
                    $conflicts,
                    $this->find_function_collisions($a, $declarations[$a], $b, $declarations[$b]),
                    $this->find_class_collisions($a, $declarations[$a], $b, $declarations[$b]),
                    $this->find_global_collisions($a, $declarations[$a], $b, $declarations[$b])
                );
            }
        }

        return $conflicts;
    }

    /**
     * Scan a single plugin directory for all PHP declarations.
     *
     * @param string $directory Absolute path to plugin directory.
     * @return array{functions: array, classes: array, globals: array}
     */
    private function scan_plugin_directory(string $directory): array {
        $result = [
            'functions' => [],
            'classes'   => [],
            'globals'   => [],
        ];

        $php_files = $this->get_php_files($directory);

        foreach ($php_files as $file) {
            $parsed = $this->parse_php_file($file);
            $relative = str_replace($directory . '/', '', $file);

            foreach ($parsed['functions'] as $func) {
                $result['functions'][] = [
                    'name' => $func,
                    'file' => $relative,
                ];
            }

            foreach ($parsed['classes'] as $class) {
                $result['classes'][] = [
                    'name'       => $class['name'],
                    'namespaced' => $class['namespaced'],
                    'file'       => $relative,
                ];
            }

            foreach ($parsed['globals'] as $global) {
                $result['globals'][] = [
                    'name' => $global,
                    'file' => $relative,
                ];
            }
        }

        return $result;
    }

    /**
     * Parse a PHP file for function declarations, class declarations, and globals.
     *
     * @param string $file Absolute path to PHP file.
     * @return array{functions: array<string>, classes: array<array{name: string, namespaced: bool}>, globals: array<string>}
     */
    private function parse_php_file(string $file): array {
        $result = [
            'functions' => [],
            'classes'   => [],
            'globals'   => [],
        ];

        $source = @file_get_contents($file);
        if ($source === false) {
            return $result;
        }

        // Limit file size to 1MB to prevent memory issues.
        if (strlen($source) > 1048576) {
            return $result;
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $result;
        }

        $count = count($tokens);
        $current_namespace = '';

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }

            $token_type = $tokens[$i][0];

            // Track namespace.
            if ($token_type === T_NAMESPACE) {
                $current_namespace = $this->extract_namespace($tokens, $i, $count);
                continue;
            }

            // Function declarations.
            if ($token_type === T_FUNCTION) {
                $func_name = $this->extract_function_name($tokens, $i, $count);
                if ($func_name !== null && $current_namespace === '') {
                    // Only track non-namespaced functions (those can collide).
                    $result['functions'][] = $func_name;
                }
                continue;
            }

            // Class/interface/trait declarations.
            if ($token_type === T_CLASS || $token_type === T_INTERFACE || $token_type === T_TRAIT) {
                // Skip anonymous classes.
                $class_name = $this->extract_class_name($tokens, $i, $count);
                if ($class_name !== null) {
                    $result['classes'][] = [
                        'name'       => $class_name,
                        'namespaced' => $current_namespace !== '',
                    ];
                }
                continue;
            }

            // Global variable declarations.
            if ($token_type === T_GLOBAL) {
                $globals = $this->extract_globals($tokens, $i, $count);
                $result['globals'] = array_merge($result['globals'], $globals);
                continue;
            }
        }

        $result['globals'] = array_unique($result['globals']);

        return $result;
    }

    /**
     * Extract namespace from tokens.
     */
    private function extract_namespace(array $tokens, int &$pos, int $count): string {
        $namespace = '';

        for ($pos++; $pos < $count; $pos++) {
            if (is_array($tokens[$pos])) {
                if ($tokens[$pos][0] === T_STRING || $tokens[$pos][0] === T_NAME_QUALIFIED) {
                    $namespace .= $tokens[$pos][1];
                } elseif ($tokens[$pos][0] === T_NS_SEPARATOR) {
                    $namespace .= '\\';
                } elseif ($tokens[$pos][0] !== T_WHITESPACE) {
                    break;
                }
            } elseif ($tokens[$pos] === ';' || $tokens[$pos] === '{') {
                break;
            }
        }

        return $namespace;
    }

    /**
     * Extract function name from tokens after T_FUNCTION.
     */
    private function extract_function_name(array $tokens, int $pos, int $count): ?string {
        for ($pos++; $pos < $count; $pos++) {
            if (is_array($tokens[$pos])) {
                if ($tokens[$pos][0] === T_STRING) {
                    return $tokens[$pos][1];
                }
                if ($tokens[$pos][0] !== T_WHITESPACE) {
                    return null; // Anonymous function or closure.
                }
            } elseif ($tokens[$pos] === '(') {
                return null; // Anonymous function.
            }
        }

        return null;
    }

    /**
     * Extract class name from tokens after T_CLASS/T_INTERFACE/T_TRAIT.
     */
    private function extract_class_name(array $tokens, int $pos, int $count): ?string {
        for ($pos++; $pos < $count; $pos++) {
            if (is_array($tokens[$pos])) {
                if ($tokens[$pos][0] === T_STRING) {
                    return $tokens[$pos][1];
                }
                if ($tokens[$pos][0] !== T_WHITESPACE) {
                    return null;
                }
            } elseif ($tokens[$pos] === '(' || $tokens[$pos] === '{') {
                return null; // Anonymous class.
            }
        }

        return null;
    }

    /**
     * Extract global variable names from a `global` statement.
     */
    private function extract_globals(array $tokens, int &$pos, int $count): array {
        $globals = [];

        for ($pos++; $pos < $count; $pos++) {
            if (is_array($tokens[$pos]) && $tokens[$pos][0] === T_VARIABLE) {
                $globals[] = $tokens[$pos][1];
            } elseif (! is_array($tokens[$pos]) && $tokens[$pos] === ';') {
                break;
            }
        }

        return $globals;
    }

    /**
     * Find function name collisions between two plugins.
     */
    private function find_function_collisions(string $a, array $decl_a, string $b, array $decl_b): array {
        $conflicts = [];
        $funcs_a = array_column($decl_a['functions'], 'name');
        $funcs_b = array_column($decl_b['functions'], 'name');

        $collisions = array_intersect($funcs_a, $funcs_b);

        foreach ($collisions as $func_name) {
            // Find the file locations.
            $file_a = '';
            foreach ($decl_a['functions'] as $f) {
                if ($f['name'] === $func_name) {
                    $file_a = $f['file'];
                    break;
                }
            }

            $file_b = '';
            foreach ($decl_b['functions'] as $f) {
                if ($f['name'] === $func_name) {
                    $file_b = $f['file'];
                    break;
                }
            }

            $conflicts[] = [
                'type'        => 'function_redeclaration',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'critical',
                'description' => sprintf(
                    'Function "%s" is declared in both plugins. This will cause a fatal error.',
                    $func_name
                ),
                'details' => [
                    'function_name' => $func_name,
                    'file_a'        => $file_a,
                    'file_b'        => $file_b,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find class name collisions between two plugins.
     */
    private function find_class_collisions(string $a, array $decl_a, string $b, array $decl_b): array {
        $conflicts = [];

        // Only compare non-namespaced classes (namespaced classes can share names safely).
        $classes_a = [];
        foreach ($decl_a['classes'] as $class) {
            if (! $class['namespaced']) {
                $classes_a[$class['name']] = $class['file'];
            }
        }

        $classes_b = [];
        foreach ($decl_b['classes'] as $class) {
            if (! $class['namespaced']) {
                $classes_b[$class['name']] = $class['file'];
            }
        }

        $collisions = array_intersect_key($classes_a, $classes_b);

        foreach ($collisions as $class_name => $file_a) {
            $conflicts[] = [
                'type'        => 'class_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'critical',
                'description' => sprintf(
                    'Class "%s" is declared in both plugins without namespacing. This will cause a fatal error.',
                    $class_name
                ),
                'details' => [
                    'class_name' => $class_name,
                    'file_a'     => $file_a,
                    'file_b'     => $classes_b[$class_name],
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find global variable conflicts between two plugins.
     */
    private function find_global_collisions(string $a, array $decl_a, string $b, array $decl_b): array {
        $conflicts = [];

        // WordPress core globals to ignore.
        $wp_globals = [
            '$wpdb', '$wp_query', '$wp', '$post', '$wp_rewrite', '$wp_the_query',
            '$wp_version', '$wp_actions', '$wp_filter', '$wp_object_cache',
            '$pagenow', '$current_user', '$current_screen', '$menu', '$submenu',
        ];

        $globals_a = array_unique(array_column($decl_a['globals'], 'name'));
        $globals_b = array_unique(array_column($decl_b['globals'], 'name'));

        $collisions = array_intersect($globals_a, $globals_b);
        $collisions = array_diff($collisions, $wp_globals);

        foreach ($collisions as $var_name) {
            $conflicts[] = [
                'type'        => 'global_conflict',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'medium',
                'description' => sprintf(
                    'Both plugins use the global variable "%s". They may overwrite each other\'s data.',
                    $var_name
                ),
                'details' => [
                    'variable_name' => $var_name,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Get the full directory path for a plugin.
     *
     * @param string $plugin_file Plugin file relative to plugins directory.
     * @return string|null Absolute directory path or null.
     */
    private function get_plugin_directory(string $plugin_file): ?string {
        $plugins_dir = WP_PLUGIN_DIR;

        // Single-file plugin.
        if (strpos($plugin_file, '/') === false) {
            $file_path = $plugins_dir . '/' . $plugin_file;
            return file_exists($file_path) ? dirname($file_path) : null;
        }

        $dir = $plugins_dir . '/' . dirname($plugin_file);
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Recursively get all PHP files in a directory.
     *
     * @param string $directory Directory to scan.
     * @return array<string> File paths.
     */
    private function get_php_files(string $directory): array {
        $files = [];

        // Skip vendor/node_modules directories.
        $skip_dirs = ['vendor', 'node_modules', '.git', 'tests', 'test'];

        $iterator = new \RecursiveDirectoryIterator(
            $directory,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $iterator,
            function (\SplFileInfo $current, string $key, \RecursiveDirectoryIterator $iterator) use ($skip_dirs): bool {
                if ($current->isDir()) {
                    return ! in_array($current->getFilename(), $skip_dirs, true);
                }
                return $current->getExtension() === 'php';
            }
        );

        $flat = new \RecursiveIteratorIterator($filter);

        foreach ($flat as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
