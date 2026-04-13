<?php
/**
 * Script/Style Resolver — generates patches for duplicate resource handles.
 *
 * When two plugins enqueue scripts or styles with the same handle, WordPress
 * silently ignores the second one. This resolver generates a patch that
 * explicitly dequeues the duplicate, giving the user control over which
 * version is used.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Resolver;

final class ScriptResolver {

    /**
     * Generate a patch that dequeues a duplicate resource from plugin B.
     *
     * @param string $handle       The script/style handle.
     * @param string $resource_type 'script' or 'style'.
     * @param string $plugin_a     Plugin whose version is kept.
     * @param string $plugin_b     Plugin whose version is dequeued.
     * @return string PHP code for the mu-plugin patch.
     */
    public function generate_dequeue_patch(
        string $handle,
        string $resource_type,
        string $plugin_a,
        string $plugin_b
    ): string {
        $safe_handle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $handle);
        $slug_a      = sanitize_title(dirname($plugin_a));
        $slug_b      = sanitize_title(dirname($plugin_b));

        $dequeue_fn  = $resource_type === 'style' ? 'wp_dequeue_style' : 'wp_dequeue_script';
        $register_fn = $resource_type === 'style' ? 'wp_style_is' : 'wp_script_is';

        return <<<PHP
/**
 * Resolves duplicate {$resource_type} handle '{$handle}'.
 *
 * Both {$slug_a} and {$slug_b} register a {$resource_type} with the handle '{$handle}'.
 * This patch dequeues {$slug_b}'s version and keeps {$slug_a}'s version.
 *
 * To switch which version is used, swap the plugin slugs in the check below.
 */
add_action('wp_enqueue_scripts', function () {
    // Only dequeue if the handle is registered (to avoid needless calls).
    if ({$register_fn}('{$safe_handle}', 'registered')) {
        // Track whether plugin A's version is loaded by checking the source URL.
        \$wp_deps = '{$resource_type}' === 'style' ? wp_styles() : wp_scripts();
        \$registered = \$wp_deps->registered['{$safe_handle}'] ?? null;

        if (\$registered) {
            \$src = \$registered->src ?? '';
            \$plugin_b_dir = '/' . dirname('{$plugin_b}') . '/';

            // If the registered source belongs to plugin B, dequeue and let
            // plugin A's version take precedence on re-enqueue.
            if (strpos(\$src, \$plugin_b_dir) !== false) {
                {$dequeue_fn}('{$safe_handle}');
                wp_deregister_{$resource_type}('{$safe_handle}');
            }
        }
    }
}, 999);

// Also handle admin enqueue.
add_action('admin_enqueue_scripts', function () {
    \$wp_deps = '{$resource_type}' === 'style' ? wp_styles() : wp_scripts();
    \$registered = \$wp_deps->registered['{$safe_handle}'] ?? null;

    if (\$registered) {
        \$src = \$registered->src ?? '';
        \$plugin_b_dir = '/' . dirname('{$plugin_b}') . '/';

        if (strpos(\$src, \$plugin_b_dir) !== false) {
            {$dequeue_fn}('{$safe_handle}');
            wp_deregister_{$resource_type}('{$safe_handle}');
        }
    }
}, 999);
PHP;
    }

    /**
     * Generate a patch that forces a specific version of jQuery.
     *
     * This is useful when plugins bundle their own jQuery that conflicts
     * with WordPress's bundled version.
     *
     * @param string $plugin_file The plugin bundling jQuery.
     * @return string PHP code for the mu-plugin patch.
     */
    public function generate_jquery_fix(string $plugin_file): string {
        $slug = sanitize_title(dirname($plugin_file));

        return <<<PHP
/**
 * Prevents {$slug} from overriding WordPress's bundled jQuery.
 *
 * Some plugins deregister WordPress's jQuery and load their own version,
 * which can break other plugins and themes that depend on the standard version.
 */
add_action('wp_enqueue_scripts', function () {
    // Restore WordPress's default jQuery if it was deregistered.
    if (! wp_script_is('jquery', 'registered')) {
        wp_register_script('jquery', false, ['jquery-core', 'jquery-migrate'], false, true);
    }

    \$jquery = wp_scripts()->registered['jquery'] ?? null;

    if (\$jquery && ! empty(\$jquery->src)) {
        // jQuery core should have no src (it's a dependency alias).
        // If it has a src, a plugin replaced it.
        \$plugin_dir = '/' . dirname('{$plugin_file}') . '/';

        if (strpos(\$jquery->src, \$plugin_dir) !== false) {
            wp_deregister_script('jquery');
            wp_register_script('jquery', false, ['jquery-core', 'jquery-migrate'], false, true);
        }
    }
}, 1);
PHP;
    }
}
