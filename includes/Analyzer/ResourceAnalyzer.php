<?php
/**
 * Resource collision analyzer — detects JS/CSS handle conflicts and asset collisions.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class ResourceAnalyzer {

    /**
     * Analyze plugins for resource handle collisions.
     *
     * @param array<string> $plugin_files Plugin file paths.
     * @return array<array{type: string, plugin_a: string, plugin_b: string, severity: string, description: string, details: array}>
     */
    public function analyze(array $plugin_files): array {
        $conflicts = [];
        $resource_maps = [];

        foreach ($plugin_files as $plugin_file) {
            $plugin_dir = $this->get_plugin_directory($plugin_file);

            if ($plugin_dir === null) {
                continue;
            }

            $resource_maps[$plugin_file] = $this->extract_resources($plugin_dir);
        }

        // Cross-reference for collisions.
        $plugin_list = array_keys($resource_maps);
        $count = count($plugin_list);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $plugin_list[$i];
                $b = $plugin_list[$j];

                $conflicts = array_merge(
                    $conflicts,
                    $this->find_handle_collisions($a, $resource_maps[$a], $b, $resource_maps[$b]),
                    $this->find_shortcode_collisions($a, $resource_maps[$a], $b, $resource_maps[$b]),
                    $this->find_rest_namespace_collisions($a, $resource_maps[$a], $b, $resource_maps[$b])
                );
            }
        }

        return $conflicts;
    }

    /**
     * Extract resource registrations from a plugin.
     *
     * @param string $directory Plugin directory.
     * @return array{scripts: array, styles: array, shortcodes: array, rest_namespaces: array}
     */
    private function extract_resources(string $directory): array {
        $result = [
            'scripts'         => [],
            'styles'          => [],
            'shortcodes'      => [],
            'rest_namespaces' => [],
        ];

        $files = $this->get_php_files($directory);

        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false || strlen($source) > 1048576) {
                continue;
            }

            $relative = str_replace($directory . '/', '', $file);

            // wp_register_script / wp_enqueue_script.
            if (preg_match_all(
                '/wp_(?:register|enqueue)_script\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $handle) {
                    $result['scripts'][] = [
                        'handle' => $handle,
                        'file'   => $relative,
                    ];
                }
            }

            // wp_register_style / wp_enqueue_style.
            if (preg_match_all(
                '/wp_(?:register|enqueue)_style\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $handle) {
                    $result['styles'][] = [
                        'handle' => $handle,
                        'file'   => $relative,
                    ];
                }
            }

            // add_shortcode.
            if (preg_match_all(
                '/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $shortcode) {
                    $result['shortcodes'][] = [
                        'tag'  => $shortcode,
                        'file' => $relative,
                    ];
                }
            }

            // register_rest_route namespace extraction.
            if (preg_match_all(
                '/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $namespace) {
                    $result['rest_namespaces'][] = [
                        'namespace' => $namespace,
                        'file'      => $relative,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Find script/style handle collisions.
     */
    private function find_handle_collisions(string $a, array $res_a, string $b, array $res_b): array {
        $conflicts = [];

        // Script handle collisions.
        $handles_a = array_column($res_a['scripts'], 'handle');
        $handles_b = array_column($res_b['scripts'], 'handle');
        $script_collisions = array_intersect($handles_a, $handles_b);

        foreach ($script_collisions as $handle) {
            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'medium',
                'description' => sprintf(
                    'Both plugins register a JavaScript handle "%s". One will silently override the other.',
                    $handle
                ),
                'details' => [
                    'resource_type' => 'script',
                    'handle'        => $handle,
                ],
            ];
        }

        // Style handle collisions.
        $handles_a = array_column($res_a['styles'], 'handle');
        $handles_b = array_column($res_b['styles'], 'handle');
        $style_collisions = array_intersect($handles_a, $handles_b);

        foreach ($style_collisions as $handle) {
            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'medium',
                'description' => sprintf(
                    'Both plugins register a CSS handle "%s". One will silently override the other.',
                    $handle
                ),
                'details' => [
                    'resource_type' => 'style',
                    'handle'        => $handle,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find shortcode tag collisions.
     */
    private function find_shortcode_collisions(string $a, array $res_a, string $b, array $res_b): array {
        $conflicts = [];

        $tags_a = array_column($res_a['shortcodes'], 'tag');
        $tags_b = array_column($res_b['shortcodes'], 'tag');
        $collisions = array_intersect($tags_a, $tags_b);

        foreach ($collisions as $tag) {
            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'high',
                'description' => sprintf(
                    'Both plugins register the shortcode [%s]. Only the last one loaded will work.',
                    $tag
                ),
                'details' => [
                    'resource_type' => 'shortcode',
                    'tag'           => $tag,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find REST API namespace collisions.
     */
    private function find_rest_namespace_collisions(string $a, array $res_a, string $b, array $res_b): array {
        $conflicts = [];

        $ns_a = array_unique(array_column($res_a['rest_namespaces'], 'namespace'));
        $ns_b = array_unique(array_column($res_b['rest_namespaces'], 'namespace'));
        $collisions = array_intersect($ns_a, $ns_b);

        foreach ($collisions as $namespace) {
            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => 'high',
                'description' => sprintf(
                    'Both plugins register REST API routes under the namespace "%s". Routes may collide.',
                    $namespace
                ),
                'details' => [
                    'resource_type' => 'rest_namespace',
                    'namespace'     => $namespace,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Get plugin directory path.
     */
    private function get_plugin_directory(string $plugin_file): ?string {
        $dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Recursively get all PHP files.
     */
    private function get_php_files(string $directory): array {
        $files = [];
        $skip = ['vendor', 'node_modules', '.git', 'tests'];

        try {
            $iterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator(
                $iterator,
                fn(\SplFileInfo $current) => $current->isDir()
                    ? ! in_array($current->getFilename(), $skip, true)
                    : $current->getExtension() === 'php'
            );

            foreach (new \RecursiveIteratorIterator($filter) as $file) {
                $files[] = $file->getPathname();
            }
        } catch (\Exception $e) {
            // Skip on error.
        }

        return $files;
    }
}
