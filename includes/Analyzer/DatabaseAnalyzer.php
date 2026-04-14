<?php
/**
 * Database Conflict Analyzer — detects database-level conflicts between plugins.
 *
 * Scans for:
 * - Custom table name collisions (two plugins creating the same table)
 * - wp_options key collisions (same option name used by multiple plugins)
 * - Cron hook collisions (same hook name for different scheduled tasks)
 * - Post meta key conflicts (same meta_key used with different semantics)
 * - Transient key collisions
 * - Custom post type slug conflicts
 * - Taxonomy slug conflicts
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class DatabaseAnalyzer {

    /** WordPress core option names to ignore. */
    private const CORE_OPTIONS = [
        'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register',
        'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
        'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt',
        'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port',
        'default_category', 'default_comment_status', 'default_ping_status',
        'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format',
        'links_updated_date_format', 'comment_moderation', 'moderation_notify',
        'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset',
        'moderation_keys', 'active_plugins', 'category_base', 'ping_sites',
        'comment_max_links', 'gmt_offset', 'default_email_category', 'recently_edited',
        'template', 'stylesheet', 'comment_registration', 'html_type',
        'default_role', 'db_version', 'uploads_use_yearmonth_folders',
        'upload_path', 'blog_public', 'default_link_category',
        'show_on_front', 'tag_base', 'show_avatars', 'avatar_rating',
        'upload_url_path', 'thumbnail_size_w', 'thumbnail_size_h',
        'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'avatar_default',
        'large_size_w', 'large_size_h', 'image_default_link_type',
        'image_default_size', 'image_default_align', 'close_comments_for_old_posts',
        'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
        'page_comments', 'comments_per_page', 'default_comments_page',
        'comment_order', 'sticky_posts', 'widget_categories',
        'widget_text', 'widget_rss', 'uninstall_plugins', 'timezone_string',
        'page_for_posts', 'page_on_front', 'default_post_format',
        'link_manager_enabled', 'finished_splitting_shared_terms',
        'site_icon', 'medium_large_size_w', 'medium_large_size_h',
        'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in',
        'admin_email_lifespan', 'disallowed_keys', 'comment_previously_approved',
        'auto_plugin_theme_update_emails', 'auto_update_core_dev',
        'auto_update_core_minor', 'auto_update_core_major', 'wp_force_deactivated_plugins',
        'wp_attachment_pages_enabled',
    ];

    /**
     * Analyze plugins for database-level conflicts.
     *
     * @param array<string> $plugins Active plugin file paths.
     * @return array Detected conflicts.
     */
    public function analyze(array $plugins): array {
        $conflicts = [];

        $table_map     = [];
        $option_map    = [];
        $cron_map      = [];
        $cpt_map       = [];
        $taxonomy_map  = [];
        $transient_map = [];
        $meta_key_map  = [];

        foreach ($plugins as $plugin_file) {
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);

            if (! is_dir($plugin_dir)) {
                continue;
            }

            $php_files = $this->find_php_files($plugin_dir);

            foreach ($php_files as $file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $contents = file_get_contents($file);

                if ($contents === false) {
                    continue;
                }

                // 1. Custom table creation (CREATE TABLE).
                $this->extract_tables($contents, $plugin_file, $table_map);

                // 2. Option names (add_option, update_option, get_option).
                $this->extract_options($contents, $plugin_file, $option_map);

                // 3. Cron hooks (wp_schedule_event, wp_schedule_single_event).
                $this->extract_cron_hooks($contents, $plugin_file, $cron_map);

                // 4. Custom post types (register_post_type).
                $this->extract_post_types($contents, $plugin_file, $cpt_map);

                // 5. Taxonomies (register_taxonomy).
                $this->extract_taxonomies($contents, $plugin_file, $taxonomy_map);

                // 6. Transients (set_transient).
                $this->extract_transients($contents, $plugin_file, $transient_map);

                // 7. Post meta keys (update_post_meta, add_post_meta).
                $this->extract_meta_keys($contents, $plugin_file, $meta_key_map);
            }
        }

        // Cross-reference all maps for collisions.
        $conflicts = array_merge(
            $conflicts,
            $this->find_collisions($table_map, 'db_table_collision', 'critical', 'custom database table'),
            $this->find_collisions($option_map, 'db_option_collision', 'medium', 'wp_options key'),
            $this->find_collisions($cron_map, 'db_cron_collision', 'medium', 'WP-Cron hook'),
            $this->find_collisions($cpt_map, 'db_cpt_collision', 'high', 'custom post type slug'),
            $this->find_collisions($taxonomy_map, 'db_taxonomy_collision', 'high', 'taxonomy slug'),
            $this->find_collisions($transient_map, 'db_transient_collision', 'low', 'transient key'),
            $this->find_collisions($meta_key_map, 'db_meta_collision', 'medium', 'post meta key')
        );

        return $conflicts;
    }

    /**
     * Extract custom table names from SQL CREATE TABLE statements.
     */
    private function extract_tables(string $contents, string $plugin, array &$map): void {
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`)?(?:\{?\$(?:wpdb->prefix|table_prefix)\}?)?([a-zA-Z0-9_]+)(?:`)?/i';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $table) {
                $table = strtolower(trim($table, '`'));

                if (! isset($map[$table])) {
                    $map[$table] = [];
                }

                $map[$table][] = $plugin;
            }
        }
    }

    /**
     * Extract option names from WordPress option API calls.
     */
    private function extract_options(string $contents, string $plugin, array &$map): void {
        $pattern = '/(?:add_option|update_option|get_option|delete_option)\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $option) {
                // Skip core WordPress options.
                if (in_array($option, self::CORE_OPTIONS, true)) {
                    continue;
                }

                // Skip options that clearly belong to the plugin (start with plugin slug).
                $plugin_slug = strtolower(str_replace('-', '_', dirname($plugin)));

                if (strpos($option, $plugin_slug) === 0) {
                    continue;
                }

                if (! isset($map[$option])) {
                    $map[$option] = [];
                }

                if (! in_array($plugin, $map[$option], true)) {
                    $map[$option][] = $plugin;
                }
            }
        }
    }

    /**
     * Extract WP-Cron hook names.
     */
    private function extract_cron_hooks(string $contents, string $plugin, array &$map): void {
        $pattern = '/wp_schedule_(?:event|single_event)\s*\([^,]+,\s*[^,]*,\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $hook) {
                if (! isset($map[$hook])) {
                    $map[$hook] = [];
                }

                if (! in_array($plugin, $map[$hook], true)) {
                    $map[$hook][] = $plugin;
                }
            }
        }
    }

    /**
     * Extract custom post type slugs.
     */
    private function extract_post_types(string $contents, string $plugin, array &$map): void {
        $pattern = '/register_post_type\s*\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $cpt) {
                if (! isset($map[$cpt])) {
                    $map[$cpt] = [];
                }

                if (! in_array($plugin, $map[$cpt], true)) {
                    $map[$cpt][] = $plugin;
                }
            }
        }
    }

    /**
     * Extract taxonomy slugs.
     */
    private function extract_taxonomies(string $contents, string $plugin, array &$map): void {
        $pattern = '/register_taxonomy\s*\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $taxonomy) {
                // Skip core taxonomies.
                if (in_array($taxonomy, ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format'], true)) {
                    continue;
                }

                if (! isset($map[$taxonomy])) {
                    $map[$taxonomy] = [];
                }

                if (! in_array($plugin, $map[$taxonomy], true)) {
                    $map[$taxonomy][] = $plugin;
                }
            }
        }
    }

    /**
     * Extract transient names.
     */
    private function extract_transients(string $contents, string $plugin, array &$map): void {
        $pattern = '/set_transient\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $transient) {
                if (! isset($map[$transient])) {
                    $map[$transient] = [];
                }

                if (! in_array($plugin, $map[$transient], true)) {
                    $map[$transient][] = $plugin;
                }
            }
        }
    }

    /**
     * Extract post meta key names.
     */
    private function extract_meta_keys(string $contents, string $plugin, array &$map): void {
        $pattern = '/(?:update_post_meta|add_post_meta|get_post_meta|delete_post_meta)\s*\(\s*[^,]+,\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';

        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $key) {
                // Skip obviously plugin-specific keys (prefixed with plugin slug).
                $plugin_slug = strtolower(str_replace('-', '_', dirname($plugin)));

                if (strpos($key, $plugin_slug) === 0) {
                    continue;
                }

                // Skip single underscore prefix (WordPress internal).
                if ($key === '_edit_lock' || $key === '_edit_last' || $key === '_wp_page_template') {
                    continue;
                }

                if (! isset($map[$key])) {
                    $map[$key] = [];
                }

                if (! in_array($plugin, $map[$key], true)) {
                    $map[$key][] = $plugin;
                }
            }
        }
    }

    /**
     * Find collisions in a name->plugins map.
     *
     * @param array  $map           Map of name => [plugin_file, ...].
     * @param string $conflict_type Conflict type identifier.
     * @param string $severity      Severity level.
     * @param string $resource_name Human-readable resource name for descriptions.
     * @return array Conflicts found.
     */
    private function find_collisions(array $map, string $conflict_type, string $severity, string $resource_name): array {
        $conflicts = [];

        foreach ($map as $name => $plugins) {
            $unique_plugins = array_unique($plugins);

            if (count($unique_plugins) < 2) {
                continue;
            }

            // Generate conflicts for each unique pair.
            for ($i = 0; $i < count($unique_plugins); $i++) {
                for ($j = $i + 1; $j < count($unique_plugins); $j++) {
                    $conflicts[] = [
                        'type'        => $conflict_type,
                        'plugin_a'    => $unique_plugins[$i],
                        'plugin_b'    => $unique_plugins[$j],
                        'severity'    => $severity,
                        'description' => sprintf(
                            'Both %s and %s use the same %s "%s". This may cause data corruption or unexpected behavior.',
                            dirname($unique_plugins[$i]),
                            dirname($unique_plugins[$j]),
                            $resource_name,
                            $name
                        ),
                        'details' => [
                            'resource_type' => $conflict_type,
                            'resource_name' => $name,
                            'all_plugins'   => $unique_plugins,
                            'database'      => true,
                        ],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Find all PHP files in a plugin directory (recursive, max depth 3).
     *
     * @param string $plugin_dir Plugin directory path.
     * @return array<string> PHP file paths.
     */
    private function find_php_files(string $plugin_dir): array {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $iterator->setMaxDepth(3);
        $count = 0;

        foreach ($iterator as $file) {
            if (++$count > 300) {
                break; // Safety limit.
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();

                // Skip vendor directories (too much noise).
                if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
                    continue;
                }

                $files[] = $path;
            }
        }

        return $files;
    }
}
