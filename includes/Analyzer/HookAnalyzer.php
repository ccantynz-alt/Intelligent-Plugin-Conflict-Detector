<?php
/**
 * WordPress hook and filter conflict analyzer.
 *
 * Parses plugin source code to detect conflicting add_action/add_filter calls,
 * priority collisions, and aggressive hook removals.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class HookAnalyzer {

    /**
     * Critical hooks where conflicts are most dangerous.
     *
     * @var array<string>
     */
    private const CRITICAL_HOOKS = [
        'init',
        'wp_loaded',
        'template_redirect',
        'wp_head',
        'wp_footer',
        'the_content',
        'the_title',
        'wp_enqueue_scripts',
        'admin_enqueue_scripts',
        'wp_ajax_',
        'wp_ajax_nopriv_',
        'save_post',
        'pre_get_posts',
        'posts_where',
        'query_vars',
        'rewrite_rules_array',
        'login_form',
        'authenticate',
        'wp_login',
        'wp_logout',
        'user_register',
        'delete_user',
        'switch_theme',
        'after_switch_theme',
        'wp_mail',
        'wp_mail_from',
        'rest_api_init',
        'rest_pre_dispatch',
    ];

    /**
     * Analyze plugins for hook/filter conflicts.
     *
     * @param array<string> $plugin_files Plugin file paths relative to plugins dir.
     * @return array<array{type: string, plugin_a: string, plugin_b: string, severity: string, description: string, details: array}>
     */
    public function analyze(array $plugin_files): array {
        $conflicts = [];
        $hook_maps = [];

        // Build hook maps per plugin.
        foreach ($plugin_files as $plugin_file) {
            $plugin_dir = $this->get_plugin_directory($plugin_file);

            if ($plugin_dir === null) {
                continue;
            }

            $hook_maps[$plugin_file] = $this->extract_hooks($plugin_dir);
        }

        // Cross-reference for conflicts.
        $plugin_list = array_keys($hook_maps);
        $count = count($plugin_list);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $plugin_list[$i];
                $b = $plugin_list[$j];

                $conflicts = array_merge(
                    $conflicts,
                    $this->find_hook_conflicts($a, $hook_maps[$a], $b, $hook_maps[$b])
                );
            }
        }

        // Check for aggressive hook removals.
        foreach ($plugin_list as $plugin_file) {
            $removals = $this->find_aggressive_removals($plugin_file, $hook_maps[$plugin_file]);
            $conflicts = array_merge($conflicts, $removals);
        }

        return $conflicts;
    }

    /**
     * Extract all hook registrations from a plugin directory.
     *
     * @param string $directory Plugin directory path.
     * @return array{actions: array, filters: array, removals: array}
     */
    private function extract_hooks(string $directory): array {
        $result = [
            'actions'  => [],
            'filters'  => [],
            'removals' => [],
        ];

        $files = $this->get_php_files($directory);

        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false || strlen($source) > 1048576) {
                continue;
            }

            $relative = str_replace($directory . '/', '', $file);

            // Match add_action calls.
            if (preg_match_all(
                '/add_action\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*(?:,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['actions'][] = [
                        'hook'     => $match[1],
                        'callback' => trim($match[2], '\'" '),
                        'priority' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 10,
                        'file'     => $relative,
                    ];
                }
            }

            // Match add_filter calls.
            if (preg_match_all(
                '/add_filter\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*(?:,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['filters'][] = [
                        'hook'     => $match[1],
                        'callback' => trim($match[2], '\'" '),
                        'priority' => isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 10,
                        'file'     => $relative,
                    ];
                }
            }

            // Match remove_all_actions / remove_all_filters.
            if (preg_match_all(
                '/remove_all_(actions|filters)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['removals'][] = [
                        'type' => $match[1],
                        'hook' => $match[2],
                        'file' => $relative,
                    ];
                }
            }

            // Match remove_action / remove_filter on common hooks.
            if (preg_match_all(
                '/remove_(action|filter)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['removals'][] = [
                        'type'     => $match[1],
                        'hook'     => $match[2],
                        'callback' => trim($match[3], '\'" '),
                        'priority' => isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 10,
                        'file'     => $relative,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Find hook conflicts between two plugins.
     */
    private function find_hook_conflicts(string $a, array $hooks_a, string $b, array $hooks_b): array {
        $conflicts = [];

        // Check filters on the same hook with the same priority — potential data corruption.
        $conflicts = array_merge(
            $conflicts,
            $this->find_priority_collisions($a, $hooks_a['filters'], $b, $hooks_b['filters'], 'filter')
        );

        // Check actions on the same hook with the same priority on critical hooks.
        $conflicts = array_merge(
            $conflicts,
            $this->find_priority_collisions($a, $hooks_a['actions'], $b, $hooks_b['actions'], 'action')
        );

        // Check if one plugin removes hooks that the other adds.
        $conflicts = array_merge(
            $conflicts,
            $this->find_removal_conflicts($a, $hooks_a, $b, $hooks_b),
            $this->find_removal_conflicts($b, $hooks_b, $a, $hooks_a)
        );

        return $conflicts;
    }

    /**
     * Find same-hook same-priority conflicts between two plugins.
     */
    private function find_priority_collisions(
        string $a,
        array $hooks_a,
        string $b,
        array $hooks_b,
        string $hook_type
    ): array {
        $conflicts = [];

        // Index hooks by hook_name:priority.
        $indexed_a = [];
        foreach ($hooks_a as $hook) {
            $key = $hook['hook'] . ':' . $hook['priority'];
            $indexed_a[$key][] = $hook;
        }

        foreach ($hooks_b as $hook) {
            $key = $hook['hook'] . ':' . $hook['priority'];

            if (isset($indexed_a[$key])) {
                $is_critical = $this->is_critical_hook($hook['hook']);
                $severity = $is_critical ? 'high' : 'low';

                // Only flag filters on the same priority as potential data conflicts.
                if ($hook_type === 'filter' || $is_critical) {
                    $conflicts[] = [
                        'type'        => 'hook_conflict',
                        'plugin_a'    => $a,
                        'plugin_b'    => $b,
                        'severity'    => $severity,
                        'description' => sprintf(
                            'Both plugins add a %s on "%s" at priority %d. This may cause unpredictable behavior%s.',
                            $hook_type,
                            $hook['hook'],
                            $hook['priority'],
                            $hook_type === 'filter' ? ' as both modify the same data' : ''
                        ),
                        'details' => [
                            'hook_name' => $hook['hook'],
                            'priority'  => $hook['priority'],
                            'hook_type' => $hook_type,
                            'critical'  => $is_critical,
                        ],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Find cases where one plugin removes hooks that another adds.
     */
    private function find_removal_conflicts(string $remover, array $hooks_remover, string $adder, array $hooks_adder): array {
        $conflicts = [];

        foreach ($hooks_remover['removals'] as $removal) {
            // Check if removal targets something the other plugin adds.
            $target_hooks = $removal['type'] === 'actions' || $removal['type'] === 'action'
                ? $hooks_adder['actions']
                : $hooks_adder['filters'];

            foreach ($target_hooks as $hook) {
                if ($hook['hook'] !== $removal['hook']) {
                    continue;
                }

                // For targeted removals, check callback match.
                if (isset($removal['callback'])) {
                    // Rough string match — callback representations vary.
                    if (strpos($hook['callback'], $removal['callback']) === false &&
                        strpos($removal['callback'], $hook['callback']) === false) {
                        continue;
                    }
                }

                $conflicts[] = [
                    'type'        => 'hook_conflict',
                    'plugin_a'    => $remover,
                    'plugin_b'    => $adder,
                    'severity'    => 'high',
                    'description' => sprintf(
                        '"%s" removes a %s hook on "%s" that "%s" registers. This will disable functionality.',
                        $this->get_plugin_name($remover),
                        $removal['type'],
                        $removal['hook'],
                        $this->get_plugin_name($adder)
                    ),
                    'details' => [
                        'hook_name'   => $removal['hook'],
                        'removal_type' => isset($removal['callback']) ? 'targeted' : 'blanket',
                        'file'        => $removal['file'],
                    ],
                ];

                break; // One conflict per removal is enough.
            }
        }

        return $conflicts;
    }

    /**
     * Find aggressive hook removals (remove_all_actions/remove_all_filters on critical hooks).
     */
    private function find_aggressive_removals(string $plugin_file, array $hooks): array {
        $conflicts = [];

        foreach ($hooks['removals'] as $removal) {
            // Only flag remove_all_* calls.
            if ($removal['type'] !== 'actions' && $removal['type'] !== 'filters') {
                continue;
            }

            if ($this->is_critical_hook($removal['hook'])) {
                $conflicts[] = [
                    'type'        => 'hook_conflict',
                    'plugin_a'    => $plugin_file,
                    'plugin_b'    => '',
                    'severity'    => 'critical',
                    'description' => sprintf(
                        'Plugin removes ALL %s from critical hook "%s". This may break other plugins.',
                        $removal['type'],
                        $removal['hook']
                    ),
                    'details' => [
                        'hook_name'   => $removal['hook'],
                        'removal_type' => 'remove_all',
                        'file'        => $removal['file'],
                    ],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Check if a hook is in the critical list.
     */
    private function is_critical_hook(string $hook_name): bool {
        foreach (self::CRITICAL_HOOKS as $critical) {
            if ($hook_name === $critical || strpos($hook_name, $critical) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a human-readable plugin name from its file path.
     */
    private function get_plugin_name(string $plugin_file): string {
        if (! function_exists('get_plugin_data')) {
            return dirname($plugin_file) ?: $plugin_file;
        }

        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
        return ! empty($data['Name']) ? $data['Name'] : dirname($plugin_file);
    }

    /**
     * Get plugin directory path.
     */
    private function get_plugin_directory(string $plugin_file): ?string {
        $dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        return is_dir($dir) ? $dir : (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file) ? WP_PLUGIN_DIR : null);
    }

    /**
     * Get all PHP files in a directory recursively.
     */
    private function get_php_files(string $directory): array {
        $files = [];
        $skip = ['vendor', 'node_modules', '.git', 'tests', 'test'];

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
            // Directory access error — skip silently.
        }

        return $files;
    }
}
