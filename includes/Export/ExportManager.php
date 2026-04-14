<?php
/**
 * Export/Import Manager — share conflict profiles between sites.
 *
 * Agencies managing multiple WordPress sites can:
 * - Export a site's conflict data as a portable JSON file
 * - Import conflict profiles from other sites to pre-check compatibility
 * - Compare conflict profiles across multiple client sites
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Export;

use Jetstrike\ConflictDetector\Database\Repository;

final class ExportManager {

    /** Export format version for forward compatibility. */
    private const FORMAT_VERSION = '1.0';

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Export the site's conflict data as a JSON structure.
     *
     * @param array $options Export options.
     * @return array{json: string, filename: string, stats: array}
     */
    public function export(array $options = []): array {
        $include_scans = $options['include_scans'] ?? true;
        $include_resolved = $options['include_resolved'] ?? false;

        $export_data = [
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => current_time('c'),
            'site'           => [
                'url'          => get_site_url(),
                'name'         => get_bloginfo('name'),
                'wp_version'   => get_bloginfo('version'),
                'php_version'  => PHP_VERSION,
                'wc_version'   => defined('WC_VERSION') ? WC_VERSION : null,
                'plugin_count' => count(get_option('active_plugins', [])),
            ],
            'plugins'   => $this->export_plugin_data(),
            'conflicts' => $this->export_conflicts($include_resolved),
        ];

        if ($include_scans) {
            $export_data['scans'] = $this->export_scans();
        }

        $stats = [
            'plugins'   => count($export_data['plugins']),
            'conflicts' => count($export_data['conflicts']),
            'scans'     => count($export_data['scans'] ?? []),
        ];

        $filename = sprintf(
            'jetstrike-export-%s-%s.json',
            sanitize_title(get_bloginfo('name')),
            gmdate('Y-m-d-His')
        );

        return [
            'json'     => wp_json_encode($export_data, JSON_PRETTY_PRINT),
            'filename' => $filename,
            'stats'    => $stats,
        ];
    }

    /**
     * Import conflict data from a JSON export.
     *
     * @param string $json JSON string from export.
     * @param array  $options Import options.
     * @return array{success: bool, imported: int, skipped: int, errors: array, message: string}
     */
    public function import(string $json, array $options = []): array {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [
                'success'  => false,
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => ['Invalid JSON format.'],
                'message'  => 'Failed to parse the import file.',
            ];
        }

        // Validate format.
        if (! isset($data['format_version'])) {
            return [
                'success'  => false,
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => ['Missing format version. This may not be a Jetstrike export file.'],
                'message'  => 'Invalid export file format.',
            ];
        }

        $merge = $options['merge'] ?? true;
        $imported = 0;
        $skipped = 0;
        $errors = [];

        // Import conflicts.
        $conflicts = $data['conflicts'] ?? [];

        foreach ($conflicts as $conflict) {
            // Validate required fields.
            if (empty($conflict['plugin_a']) || empty($conflict['type'])) {
                $skipped++;
                continue;
            }

            // Check for duplicates.
            if ($merge && $this->repository->conflict_exists(
                $conflict['plugin_a'],
                $conflict['plugin_b'] ?? '',
                $conflict['type']
            )) {
                $skipped++;
                continue;
            }

            try {
                $this->repository->create_conflict([
                    'scan_id'           => 0,
                    'plugin_a'          => sanitize_text_field($conflict['plugin_a']),
                    'plugin_b'          => sanitize_text_field($conflict['plugin_b'] ?? ''),
                    'conflict_type'     => sanitize_text_field($conflict['type']),
                    'severity'          => sanitize_text_field($conflict['severity'] ?? 'medium'),
                    'description'       => sanitize_text_field($conflict['description'] ?? ''),
                    'technical_details' => wp_json_encode($conflict['details'] ?? []),
                    'recommendation'    => sanitize_text_field($conflict['recommendation'] ?? ''),
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = sprintf('Failed to import conflict: %s', $e->getMessage());
            }
        }

        // Clear caches.
        delete_transient('jetstrike_cd_conflict_summary');

        $message = sprintf(
            'Import complete: %d conflict(s) imported, %d skipped (duplicates).',
            $imported,
            $skipped
        );

        if (! empty($errors)) {
            $message .= sprintf(' %d error(s) occurred.', count($errors));
        }

        return [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => $message,
        ];
    }

    /**
     * Compare this site's conflicts with an imported profile.
     *
     * Useful for agencies checking if a client's site has known issues.
     *
     * @param string $json JSON export from another site.
     * @return array Comparison results.
     */
    public function compare(string $json): array {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['conflicts'])) {
            return ['error' => 'Invalid export data.'];
        }

        $local_conflicts = $this->repository->list_active_conflicts(1, 500);
        $remote_conflicts = $data['conflicts'] ?? [];

        // Find matching plugins.
        $local_plugins = array_map('dirname', get_option('active_plugins', []));
        $remote_plugins = array_column($data['plugins'] ?? [], 'slug');
        $common_plugins = array_intersect($local_plugins, $remote_plugins);

        // Find conflicts that affect plugins we also have.
        $relevant_remote = [];

        foreach ($remote_conflicts as $conflict) {
            $a = dirname($conflict['plugin_a'] ?? '');
            $b = dirname($conflict['plugin_b'] ?? '');

            if (in_array($a, $common_plugins, true) || in_array($b, $common_plugins, true)) {
                $relevant_remote[] = $conflict;
            }
        }

        // Find conflicts present remotely but not locally.
        $local_keys = [];
        foreach ($local_conflicts as $c) {
            $local_keys[] = $c->conflict_type . ':' . $c->plugin_a . ':' . $c->plugin_b;
        }

        $warnings = [];

        foreach ($relevant_remote as $rc) {
            $key = ($rc['type'] ?? '') . ':' . ($rc['plugin_a'] ?? '') . ':' . ($rc['plugin_b'] ?? '');

            if (! in_array($key, $local_keys, true)) {
                $warnings[] = [
                    'type'        => $rc['type'] ?? 'unknown',
                    'plugin_a'    => $rc['plugin_a'] ?? '',
                    'plugin_b'    => $rc['plugin_b'] ?? '',
                    'severity'    => $rc['severity'] ?? 'medium',
                    'description' => $rc['description'] ?? '',
                    'source_site' => $data['site']['url'] ?? 'unknown',
                ];
            }
        }

        return [
            'common_plugins'     => count($common_plugins),
            'remote_conflicts'   => count($remote_conflicts),
            'relevant_to_you'    => count($relevant_remote),
            'new_warnings'       => $warnings,
            'source_site'        => $data['site']['url'] ?? '',
            'source_wp_version'  => $data['site']['wp_version'] ?? '',
        ];
    }

    /**
     * Export plugin data.
     */
    private function export_plugin_data(): array {
        $active = get_option('active_plugins', []);
        $plugins = [];

        foreach ($active as $file) {
            if ($file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);

            $plugins[] = [
                'slug'    => dirname($file),
                'file'    => $file,
                'name'    => $data['Name'] ?? dirname($file),
                'version' => $data['Version'] ?? '',
                'author'  => $data['Author'] ?? '',
            ];
        }

        return $plugins;
    }

    /**
     * Export conflict data.
     */
    private function export_conflicts(bool $include_resolved): array {
        $conflicts = $include_resolved
            ? $this->repository->list_active_conflicts(1, 500)
            : $this->repository->list_active_conflicts(1, 500);

        $exported = [];

        foreach ($conflicts as $c) {
            if (! $include_resolved && $c->status !== 'active') {
                continue;
            }

            $exported[] = [
                'type'           => $c->conflict_type,
                'plugin_a'       => $c->plugin_a,
                'plugin_b'       => $c->plugin_b,
                'severity'       => $c->severity,
                'description'    => $c->description,
                'recommendation' => $c->recommendation,
                'status'         => $c->status,
                'detected_at'    => $c->detected_at,
                'details'        => json_decode($c->technical_details ?? '{}', true),
            ];
        }

        return $exported;
    }

    /**
     * Export scan history.
     */
    private function export_scans(): array {
        $scans = $this->repository->list_scans(1, 50);
        $exported = [];

        foreach ($scans as $scan) {
            $exported[] = [
                'type'            => $scan->scan_type,
                'status'          => $scan->status,
                'conflicts_found' => (int) $scan->conflicts_found,
                'triggered_by'    => $scan->triggered_by,
                'started_at'      => $scan->started_at,
                'completed_at'    => $scan->completed_at,
            ];
        }

        return $exported;
    }
}
