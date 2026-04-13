<?php
/**
 * Hook Priority Resolver — generates patches that adjust conflicting hook priorities.
 *
 * When two plugins hook into the same action/filter at the same priority,
 * this resolver generates a mu-plugin that re-registers one plugin's callback
 * at a different priority, eliminating the conflict.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Resolver;

final class HookPriorityResolver {

    /** Default priority offset to apply. */
    private const PRIORITY_OFFSET = 5;

    /**
     * Generate a mu-plugin patch that adjusts hook priority.
     *
     * @param string $hook_name  The WordPress hook (action/filter) name.
     * @param int    $priority   The conflicting priority.
     * @param string $plugin_a   First plugin file path (keeps original priority).
     * @param string $plugin_b   Second plugin file path (gets adjusted).
     * @return string PHP code for the mu-plugin patch.
     */
    public function generate_priority_patch(
        string $hook_name,
        int $priority,
        string $plugin_a,
        string $plugin_b
    ): string {
        $new_priority = $priority + self::PRIORITY_OFFSET;
        $safe_hook    = preg_replace('/[^a-zA-Z0-9_]/', '_', $hook_name);
        $slug_b       = sanitize_title(dirname($plugin_b));

        return <<<PHP
/**
 * Resolves hook priority conflict on '{$hook_name}'.
 *
 * Both plugins register callbacks at priority {$priority}.
 * This patch moves {$slug_b}'s callback to priority {$new_priority},
 * ensuring both execute without interfering with each other.
 *
 * Plugin A (unchanged): {$plugin_a}
 * Plugin B (adjusted):  {$plugin_b}
 */
add_action('plugins_loaded', function () {
    global \$wp_filter;

    if (! isset(\$wp_filter['{$hook_name}'])) {
        return;
    }

    \$hook_obj = \$wp_filter['{$hook_name}'];
    \$callbacks_at_priority = \$hook_obj->callbacks[{$priority}] ?? [];

    foreach (\$callbacks_at_priority as \$key => \$callback) {
        \$func = \$callback['function'] ?? null;

        // Identify callbacks belonging to plugin B by checking the file path.
        \$file = '';
        if (is_array(\$func) && is_object(\$func[0])) {
            \$ref = new \ReflectionMethod(\$func[0], \$func[1]);
            \$file = \$ref->getFileName();
        } elseif (is_string(\$func) && function_exists(\$func)) {
            \$ref = new \ReflectionFunction(\$func);
            \$file = \$ref->getFileName();
        } elseif (\$func instanceof \Closure) {
            \$ref = new \ReflectionFunction(\$func);
            \$file = \$ref->getFileName();
        }

        // Check if this callback belongs to plugin B.
        \$plugin_b_dir = WP_PLUGIN_DIR . '/' . dirname('{$plugin_b}');
        if (! empty(\$file) && strpos(\$file, \$plugin_b_dir) === 0) {
            // Remove from current priority and re-add at new priority.
            \$hook_obj->remove_filter('{$hook_name}', \$func, {$priority});
            \$hook_obj->add_filter('{$hook_name}', \$func, {$new_priority}, \$callback['accepted_args']);
        }
    }
}, PHP_INT_MAX);
PHP;
    }

    /**
     * Calculate the optimal new priority to avoid further conflicts.
     *
     * @param string $hook_name   Hook to check.
     * @param int    $old_priority Current conflicting priority.
     * @return int   New priority that doesn't conflict.
     */
    public function find_safe_priority(string $hook_name, int $old_priority): int {
        global $wp_filter;

        if (! isset($wp_filter[$hook_name])) {
            return $old_priority + self::PRIORITY_OFFSET;
        }

        $new_priority = $old_priority + self::PRIORITY_OFFSET;

        // Keep incrementing until we find an unused slot.
        while (isset($wp_filter[$hook_name]->callbacks[$new_priority])) {
            $new_priority += self::PRIORITY_OFFSET;

            // Safety: don't go beyond reasonable bounds.
            if ($new_priority > 9999) {
                break;
            }
        }

        return $new_priority;
    }
}
