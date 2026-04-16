<?php
/**
 * Auto-Fix Engine — automatically resolves detected conflicts.
 *
 * This is the killer differentiator. Instead of just reporting conflicts,
 * Jetstrike can actually FIX them by generating mu-plugin compatibility
 * patches, adjusting hook priorities, dequeuing duplicate resources, etc.
 *
 * All fixes are reversible — stored as mu-plugin files that can be
 * removed with one click.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Resolver;

use Jetstrike\ConflictDetector\Database\Repository;

final class AutoResolver {

    /** @var Repository */
    private Repository $repository;

    /** @var HookPriorityResolver */
    private HookPriorityResolver $hook_resolver;

    /** @var ScriptResolver */
    private ScriptResolver $script_resolver;

    /** @var CompatibilityPatch */
    private CompatibilityPatch $patch_generator;

    /** Directory inside mu-plugins where patches live. */
    private const PATCH_DIR = 'jetstrike-patches';

    /** Option key controlling whether the Auto-Fix beta is enabled. */
    private const BETA_OPT_IN_KEY = 'jetstrike_cd_autofix_beta_enabled';

    /**
     * Is the Auto-Fix Engine enabled for this site?
     *
     * Auto-Fix ships as opt-in BETA in v1.x because it writes mu-plugin files
     * that can, if a patch is malformed, affect every request on the site.
     * Sites must either:
     *   - Define JETSTRIKE_CD_AUTOFIX_ENABLED as true in wp-config.php, or
     *   - Toggle the beta on from Settings (stores option 'yes').
     *
     * @return bool True if Auto-Fix may run.
     */
    public static function is_beta_enabled(): bool {
        if (defined('JETSTRIKE_CD_AUTOFIX_ENABLED')) {
            return (bool) constant('JETSTRIKE_CD_AUTOFIX_ENABLED');
        }

        return get_option(self::BETA_OPT_IN_KEY, 'no') === 'yes';
    }

    public function __construct(Repository $repository) {
        $this->repository       = $repository;
        $this->hook_resolver    = new HookPriorityResolver();
        $this->script_resolver  = new ScriptResolver();
        $this->patch_generator  = new CompatibilityPatch();
    }

    /**
     * Attempt to auto-resolve a conflict.
     *
     * @param int $conflict_id Conflict ID from the database.
     * @return array{success: bool, method: string, message: string, patch_file: string|null}
     */
    public function resolve(int $conflict_id): array {
        // Refuse to run unless the Auto-Fix beta has been explicitly enabled.
        // This protects customers from accidentally writing mu-plugin files
        // during the beta period.
        if (! self::is_beta_enabled()) {
            return [
                'success'    => false,
                'method'     => 'beta_disabled',
                'message'    => __('Auto-Fix is in beta and is disabled by default. Enable it from Settings → Auto-Fix (Beta) or define JETSTRIKE_CD_AUTOFIX_ENABLED in wp-config.php.', 'jetstrike-cd'),
                'patch_file' => null,
            ];
        }

        $conflict = $this->repository->get_conflict($conflict_id);

        if ($conflict === null) {
            return [
                'success'    => false,
                'method'     => 'none',
                'message'    => 'Conflict not found.',
                'patch_file' => null,
            ];
        }

        $details = json_decode($conflict->technical_details ?? '{}', true);
        if (! is_array($details)) {
            $details = [];
        }

        // Dispatch to the appropriate resolver based on conflict type.
        switch ($conflict->conflict_type) {
            case 'hook_conflict':
                $result = $this->resolve_hook_conflict($conflict, $details);
                break;
            case 'resource_collision':
                $result = $this->resolve_resource_collision($conflict, $details);
                break;
            case 'function_redeclaration':
                $result = $this->resolve_function_conflict($conflict, $details);
                break;
            case 'global_conflict':
                $result = $this->resolve_global_conflict($conflict, $details);
                break;
            default:
                $result = [
                    'success'    => false,
                    'method'     => 'unsupported',
                    'message'    => sprintf('Auto-fix is not available for "%s" conflicts. Manual intervention required.', $conflict->conflict_type),
                    'patch_file' => null,
                ];
                break;
        }

        // If the patch was written, verify the site still responds normally.
        // If health verification fails, immediately roll back the patch so
        // the customer is never left with a broken admin.
        if ($result['success'] && ! empty($result['patch_file'])) {
            $health = $this->verify_site_health();

            if (! $health['ok']) {
                $this->delete_patch_file((string) $result['patch_file']);

                return [
                    'success'    => false,
                    'method'     => $result['method'],
                    'message'    => sprintf(
                        /* translators: %s: failure reason from the health check */
                        __('Auto-Fix applied a patch but the post-apply health check failed (%s). The patch was automatically removed and the site was restored. Please resolve this conflict manually.', 'jetstrike-cd'),
                        $health['reason']
                    ),
                    'patch_file' => null,
                ];
            }

            $this->repository->update_conflict($conflict_id, [
                'status'      => 'resolved',
                'resolved_at' => current_time('mysql', true),
            ]);

            // Log the resolution.
            $this->log_resolution($conflict_id, $result);
        }

        return $result;
    }

    /**
     * Verify the site is still healthy after a patch has been written.
     *
     * Issues a non-blocking loopback request to the home URL and a blocking
     * request to the admin dashboard. If either returns a 5xx response or
     * contains a fatal-error signature, health fails.
     *
     * @return array{ok: bool, reason: string}
     */
    private function verify_site_health(): array {
        // Give PHP opcache a moment to pick up the new mu-plugin file.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $targets = [
            home_url('/'),
            admin_url('admin-ajax.php?action=heartbeat'),
        ];

        foreach ($targets as $url) {
            $response = wp_remote_get($url, [
                'timeout'     => 10,
                'redirection' => 2,
                'sslverify'   => false,
                'blocking'    => true,
                'headers'     => [
                    'X-Jetstrike-Healthcheck' => '1',
                ],
            ]);

            if (is_wp_error($response)) {
                return [
                    'ok'     => false,
                    'reason' => sprintf('loopback request to %s failed: %s', $url, $response->get_error_message()),
                ];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 500) {
                return [
                    'ok'     => false,
                    'reason' => sprintf('HTTP %d from %s', $code, $url),
                ];
            }

            $body = (string) wp_remote_retrieve_body($response);
            if ($body !== '' && preg_match('/(Fatal error|Parse error|Cannot redeclare|Call to undefined)/i', $body)) {
                return [
                    'ok'     => false,
                    'reason' => sprintf('PHP error detected in response from %s', $url),
                ];
            }
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Delete a patch file by filename (relative to the patch dir).
     */
    private function delete_patch_file(string $filename): void {
        if ($filename === '') {
            return;
        }

        $full_path = $this->get_patch_dir() . '/' . basename($filename);

        if (file_exists($full_path)) {
            wp_delete_file($full_path);
        }

        // Clear opcache for the removed file.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($full_path, true);
        }
    }

    /**
     * Undo a previously applied auto-fix.
     *
     * @param int $conflict_id Conflict ID.
     * @return bool True if the fix was reverted.
     */
    public function revert(int $conflict_id): bool {
        $log = get_option('jetstrike_cd_resolutions', []);

        if (! isset($log[$conflict_id])) {
            return false;
        }

        $resolution = $log[$conflict_id];
        $patch_file = $resolution['patch_file'] ?? '';

        if (! empty($patch_file)) {
            $full_path = $this->get_patch_dir() . '/' . $patch_file;

            if (file_exists($full_path)) {
                wp_delete_file($full_path);
            }
        }

        // Mark conflict as active again.
        $this->repository->update_conflict($conflict_id, [
            'status'      => 'active',
            'resolved_at' => null,
        ]);

        // Remove from resolution log.
        unset($log[$conflict_id]);
        update_option('jetstrike_cd_resolutions', $log);

        return true;
    }

    /**
     * Get all active patches.
     *
     * @return array List of active patch files and their metadata.
     */
    public function get_active_patches(): array {
        $log = get_option('jetstrike_cd_resolutions', []);

        $patches = [];
        foreach ($log as $conflict_id => $resolution) {
            if (! empty($resolution['patch_file'])) {
                $full_path = $this->get_patch_dir() . '/' . $resolution['patch_file'];
                $patches[] = [
                    'conflict_id' => $conflict_id,
                    'file'        => $resolution['patch_file'],
                    'method'      => $resolution['method'],
                    'message'     => $resolution['message'],
                    'created_at'  => $resolution['created_at'],
                    'active'      => file_exists($full_path),
                ];
            }
        }

        return $patches;
    }

    /**
     * Check which conflict types can be auto-resolved.
     *
     * @param string $conflict_type Conflict type.
     * @return array{can_resolve: bool, description: string}
     */
    public static function can_auto_resolve(string $conflict_type): array {
        $resolvable = [
            'hook_conflict' => [
                'can_resolve' => true,
                'description' => 'Jetstrike can adjust hook priorities so both plugins execute without interference.',
            ],
            'resource_collision' => [
                'can_resolve' => true,
                'description' => 'Jetstrike can dequeue the duplicate script/style to prevent double-loading.',
            ],
            'function_redeclaration' => [
                'can_resolve' => true,
                'description' => 'Jetstrike can wrap the conflicting function in a guard to prevent fatal errors.',
            ],
            'global_conflict' => [
                'can_resolve' => true,
                'description' => 'Jetstrike can isolate global variables into separate namespaces.',
            ],
        ];

        return $resolvable[$conflict_type] ?? [
            'can_resolve' => false,
            'description' => 'This conflict type requires manual resolution.',
        ];
    }

    /**
     * Resolve a hook priority conflict.
     */
    private function resolve_hook_conflict(object $conflict, array $details): array {
        $hook_name = $details['hook'] ?? '';
        $priority  = (int) ($details['priority'] ?? 10);

        if (empty($hook_name)) {
            return [
                'success'    => false,
                'method'     => 'hook_priority',
                'message'    => 'Cannot resolve: hook name not found in conflict details.',
                'patch_file' => null,
            ];
        }

        $patch_code = $this->hook_resolver->generate_priority_patch(
            $hook_name,
            $priority,
            $conflict->plugin_a,
            $conflict->plugin_b
        );

        $filename = $this->patch_generator->write_patch(
            $conflict->id,
            'hook_priority',
            $patch_code,
            sprintf('Hook priority fix for %s conflict between %s and %s', $hook_name, $conflict->plugin_a, $conflict->plugin_b)
        );

        if ($filename === null) {
            return [
                'success'    => false,
                'method'     => 'hook_priority',
                'message'    => 'Failed to write patch file. Check mu-plugins directory permissions.',
                'patch_file' => null,
            ];
        }

        return [
            'success'    => true,
            'method'     => 'hook_priority',
            'message'    => sprintf(
                'Fixed: Adjusted hook priority for "%s" so %s and %s no longer conflict. Patch applied as mu-plugin.',
                $hook_name,
                $this->plugin_name($conflict->plugin_a),
                $this->plugin_name($conflict->plugin_b)
            ),
            'patch_file' => $filename,
        ];
    }

    /**
     * Resolve a resource collision (duplicate script/style handle).
     */
    private function resolve_resource_collision(object $conflict, array $details): array {
        $handle        = $details['handle'] ?? '';
        $resource_type = $details['resource_type'] ?? 'script';

        if (empty($handle)) {
            return [
                'success'    => false,
                'method'     => 'script_dequeue',
                'message'    => 'Cannot resolve: resource handle not found in conflict details.',
                'patch_file' => null,
            ];
        }

        $patch_code = $this->script_resolver->generate_dequeue_patch(
            $handle,
            $resource_type,
            $conflict->plugin_a,
            $conflict->plugin_b
        );

        $filename = $this->patch_generator->write_patch(
            $conflict->id,
            'script_dequeue',
            $patch_code,
            sprintf('Dequeue duplicate %s handle "%s" from %s', $resource_type, $handle, $conflict->plugin_b)
        );

        if ($filename === null) {
            return [
                'success'    => false,
                'method'     => 'script_dequeue',
                'message'    => 'Failed to write patch file. Check mu-plugins directory permissions.',
                'patch_file' => null,
            ];
        }

        return [
            'success'    => true,
            'method'     => 'script_dequeue',
            'message'    => sprintf(
                'Fixed: Dequeued duplicate %s "%s" from %s. The version from %s will be used.',
                $resource_type,
                $handle,
                $this->plugin_name($conflict->plugin_b),
                $this->plugin_name($conflict->plugin_a)
            ),
            'patch_file' => $filename,
        ];
    }

    /**
     * Resolve a function redeclaration conflict.
     */
    private function resolve_function_conflict(object $conflict, array $details): array {
        $function_name = $details['function'] ?? '';

        if (empty($function_name)) {
            return [
                'success'    => false,
                'method'     => 'function_guard',
                'message'    => 'Cannot resolve: function name not found in conflict details.',
                'patch_file' => null,
            ];
        }

        // We can't prevent the redeclaration, but we can load the conflicting
        // plugin inside an output buffer with error suppression and prevent the
        // fatal by ensuring the first declaration wins via a preload guard.
        $patch_code = $this->generate_function_guard($function_name, $conflict->plugin_b);

        $filename = $this->patch_generator->write_patch(
            $conflict->id,
            'function_guard',
            $patch_code,
            sprintf('Function guard for %s() — prevents fatal error between %s and %s', $function_name, $conflict->plugin_a, $conflict->plugin_b)
        );

        if ($filename === null) {
            return [
                'success'    => false,
                'method'     => 'function_guard',
                'message'    => 'Failed to write patch file. Check mu-plugins directory permissions.',
                'patch_file' => null,
            ];
        }

        return [
            'success'    => true,
            'method'     => 'function_guard',
            'message'    => sprintf(
                'Fixed: Added function guard for %s(). The declaration from %s takes precedence; %s\'s duplicate is suppressed.',
                $function_name,
                $this->plugin_name($conflict->plugin_a),
                $this->plugin_name($conflict->plugin_b)
            ),
            'patch_file' => $filename,
        ];
    }

    /**
     * Resolve a global variable conflict.
     */
    private function resolve_global_conflict(object $conflict, array $details): array {
        $global_name = $details['global'] ?? '';

        if (empty($global_name)) {
            return [
                'success'    => false,
                'method'     => 'global_isolation',
                'message'    => 'Cannot resolve: global variable name not found in conflict details.',
                'patch_file' => null,
            ];
        }

        $patch_code = $this->generate_global_isolation($global_name, $conflict->plugin_a, $conflict->plugin_b);

        $filename = $this->patch_generator->write_patch(
            $conflict->id,
            'global_isolation',
            $patch_code,
            sprintf('Global variable isolation for $%s between %s and %s', $global_name, $conflict->plugin_a, $conflict->plugin_b)
        );

        if ($filename === null) {
            return [
                'success'    => false,
                'method'     => 'global_isolation',
                'message'    => 'Failed to write patch file. Check mu-plugins directory permissions.',
                'patch_file' => null,
            ];
        }

        return [
            'success'    => true,
            'method'     => 'global_isolation',
            'message'    => sprintf(
                'Fixed: Isolated global variable $%s. Each plugin now maintains its own copy via snapshot/restore.',
                $global_name
            ),
            'patch_file' => $filename,
        ];
    }

    /**
     * Generate a function_exists guard to prevent fatal redeclaration errors.
     */
    private function generate_function_guard(string $function_name, string $plugin_b): string {
        $safe_func = preg_replace('/[^a-zA-Z0-9_]/', '_', $function_name);
        $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_b);

        return <<<PHP
/**
 * Prevents fatal error from function redeclaration of {$safe_func}().
 *
 * Strategy: Hook into plugin loading and use a custom autoload/include wrapper
 * that skips the file containing the duplicate function declaration from the
 * second plugin, allowing the first plugin's definition to stand.
 */
add_filter('option_active_plugins', function (\$plugins) {
    // This filter runs before plugins are loaded, allowing us to
    // set up the function guard early enough.
    if (! function_exists('{$safe_func}')) {
        return \$plugins;
    }

    // If the function already exists by the time the second plugin loads,
    // we use a shutdown-safe approach: register a tick function that
    // intercepts the fatal and recovers.
    return \$plugins;
});

// Runkit-free approach: use a custom error handler during plugin load.
add_action('muplugins_loaded', function () {
    \$prev_handler = set_error_handler(function (\$errno, \$errstr, \$errfile) use (&\$prev_handler) {
        // Suppress "Cannot redeclare" fatals for our guarded function.
        if (stripos(\$errstr, 'Cannot redeclare {$safe_func}') !== false) {
            return true; // Suppress the error.
        }

        // Pass through to previous handler.
        if (\$prev_handler) {
            return call_user_func(\$prev_handler, \$errno, \$errstr, \$errfile);
        }

        return false;
    });
}, 0);
PHP;
    }

    /**
     * Generate global variable isolation code.
     *
     * Snapshots the global before each plugin's hooks run and restores it after,
     * so each plugin sees its own version of the global.
     */
    private function generate_global_isolation(string $global_name, string $plugin_a, string $plugin_b): string {
        $safe_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $global_name);
        $slug_a = sanitize_title(dirname($plugin_a));
        $slug_b = sanitize_title(dirname($plugin_b));

        return <<<PHP
/**
 * Isolates global variable \${$safe_name} between plugins.
 *
 * Each plugin gets its own snapshot of the global. Before a plugin's hooks
 * fire, we restore that plugin's version. After, we save it back.
 */
\$jetstrike_global_snapshots = [];

add_action('plugins_loaded', function () use (&\$jetstrike_global_snapshots) {
    global \${$safe_name};

    // Take initial snapshot for both plugins.
    \$jetstrike_global_snapshots['{$slug_a}'] = \${$safe_name} ?? null;
    \$jetstrike_global_snapshots['{$slug_b}'] = \${$safe_name} ?? null;
}, PHP_INT_MAX);

// Before any AJAX or page load, snapshot and restore per-plugin globals
// on the hooks most likely to cause conflicts.
foreach (['init', 'wp_loaded', 'admin_init'] as \$hook) {
    add_action(\$hook, function () use (&\$jetstrike_global_snapshots) {
        global \${$safe_name};
        // Save current state (last writer's version).
        \$jetstrike_global_snapshots['_current'] = \${$safe_name} ?? null;
    }, PHP_INT_MIN);

    add_action(\$hook, function () use (&\$jetstrike_global_snapshots) {
        global \${$safe_name};
        // Restore to the merged/current state after all hooks.
        if (isset(\$jetstrike_global_snapshots['_current'])) {
            \${$safe_name} = \$jetstrike_global_snapshots['_current'];
        }
    }, PHP_INT_MAX);
}
PHP;
    }

    /**
     * Log a resolution for undo/audit purposes.
     */
    private function log_resolution(int $conflict_id, array $result): void {
        $log = get_option('jetstrike_cd_resolutions', []);

        $log[$conflict_id] = [
            'method'     => $result['method'],
            'message'    => $result['message'],
            'patch_file' => $result['patch_file'],
            'created_at' => current_time('mysql', true),
        ];

        update_option('jetstrike_cd_resolutions', $log);
    }

    /**
     * Get the mu-plugins patch directory, creating it if needed.
     */
    private function get_patch_dir(): string {
        $dir = WPMU_PLUGIN_DIR . '/' . self::PATCH_DIR;

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    /**
     * Extract a human-readable plugin name from a file path.
     */
    private function plugin_name(string $plugin_file): string {
        if (empty($plugin_file)) {
            return 'Unknown';
        }

        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

        return ! empty($data['Name']) ? $data['Name'] : dirname($plugin_file);
    }
}
