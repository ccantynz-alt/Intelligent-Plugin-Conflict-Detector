<?php
/**
 * Compatibility Matrix — visual heat map of plugin-to-plugin compatibility.
 *
 * Generates an interactive grid showing the compatibility status between
 * every pair of active plugins. Green = compatible, yellow = minor issues,
 * red = critical conflict, gray = untested.
 *
 * This is a visual "wow factor" feature that makes agency demos killer.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Admin;

use Jetstrike\ConflictDetector\Database\Repository;

final class CompatibilityMatrix {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Generate the compatibility matrix data.
     *
     * @return array{plugins: array, matrix: array, stats: array}
     */
    public function generate(): array {
        $active_plugins = $this->get_plugin_info();
        $conflicts = $this->repository->list_active_conflicts(1, 500);

        // Build the conflict lookup map.
        $conflict_map = [];

        foreach ($conflicts as $conflict) {
            $key_ab = $this->pair_key($conflict->plugin_a, $conflict->plugin_b);
            $key_ba = $this->pair_key($conflict->plugin_b, $conflict->plugin_a);

            if (! isset($conflict_map[$key_ab])) {
                $conflict_map[$key_ab] = [];
            }

            $conflict_map[$key_ab][] = [
                'type'     => $conflict->conflict_type,
                'severity' => $conflict->severity,
                'status'   => $conflict->status,
            ];

            // Mirror for reverse lookup.
            $conflict_map[$key_ba] = $conflict_map[$key_ab];
        }

        // Build the matrix.
        $plugin_slugs = array_keys($active_plugins);
        $matrix = [];
        $stats = [
            'total_pairs'   => 0,
            'compatible'    => 0,
            'conflicting'   => 0,
            'untested'      => 0,
        ];

        for ($i = 0; $i < count($plugin_slugs); $i++) {
            for ($j = $i + 1; $j < count($plugin_slugs); $j++) {
                $a = $plugin_slugs[$i];
                $b = $plugin_slugs[$j];
                $key = $this->pair_key(
                    $active_plugins[$a]['file'],
                    $active_plugins[$b]['file']
                );

                $stats['total_pairs']++;

                if (isset($conflict_map[$key])) {
                    $pair_conflicts = $conflict_map[$key];
                    $worst_severity = $this->worst_severity($pair_conflicts);
                    $active_count = count(array_filter($pair_conflicts, fn(array $c): bool => $c['status'] === 'active'));

                    $cell = [
                        'status'    => $active_count > 0 ? 'conflict' : 'resolved',
                        'severity'  => $worst_severity,
                        'conflicts' => $pair_conflicts,
                        'count'     => $active_count,
                    ];

                    if ($active_count > 0) {
                        $stats['conflicting']++;
                    } else {
                        $stats['compatible']++;
                    }
                } else {
                    $cell = [
                        'status'    => 'compatible',
                        'severity'  => 'none',
                        'conflicts' => [],
                        'count'     => 0,
                    ];
                    $stats['compatible']++;
                }

                $matrix[$a][$b] = $cell;
                $matrix[$b][$a] = $cell;
            }
        }

        return [
            'plugins' => $active_plugins,
            'matrix'  => $matrix,
            'stats'   => $stats,
        ];
    }

    /**
     * Render the matrix as HTML.
     *
     * @return string HTML table.
     */
    public function render_html(): string {
        $data = $this->generate();
        $plugins = $data['plugins'];
        $matrix = $data['matrix'];
        $stats = $data['stats'];

        if (count($plugins) < 2) {
            return '<p class="jetstrike-cd-matrix-empty">Need at least 2 active plugins to generate a compatibility matrix.</p>';
        }

        $slugs = array_keys($plugins);

        $html = '<div class="jetstrike-cd-matrix-wrapper">';

        // Stats bar.
        $html .= '<div class="jetstrike-cd-matrix-stats">';
        $html .= sprintf(
            '<span class="jetstrike-cd-matrix-stat"><strong>%d</strong> plugin pairs</span>',
            $stats['total_pairs']
        );
        $html .= sprintf(
            '<span class="jetstrike-cd-matrix-stat jetstrike-cd-matrix-stat--good"><strong>%d</strong> compatible</span>',
            $stats['compatible']
        );
        $html .= sprintf(
            '<span class="jetstrike-cd-matrix-stat jetstrike-cd-matrix-stat--bad"><strong>%d</strong> conflicting</span>',
            $stats['conflicting']
        );
        $html .= '</div>';

        // Matrix table.
        $html .= '<div class="jetstrike-cd-matrix-scroll"><table class="jetstrike-cd-matrix-table">';

        // Header row.
        $html .= '<thead><tr><th></th>';
        foreach ($slugs as $slug) {
            $short_name = $this->short_name($plugins[$slug]['name']);
            $html .= sprintf(
                '<th class="jetstrike-cd-matrix-header" title="%s"><span>%s</span></th>',
                esc_attr($plugins[$slug]['name']),
                esc_html($short_name)
            );
        }
        $html .= '</tr></thead>';

        // Data rows.
        $html .= '<tbody>';
        foreach ($slugs as $row_slug) {
            $html .= '<tr>';
            $html .= sprintf(
                '<th class="jetstrike-cd-matrix-row-header" title="%s">%s</th>',
                esc_attr($plugins[$row_slug]['name']),
                esc_html($this->short_name($plugins[$row_slug]['name']))
            );

            foreach ($slugs as $col_slug) {
                if ($row_slug === $col_slug) {
                    $html .= '<td class="jetstrike-cd-matrix-cell jetstrike-cd-matrix-cell--self">&mdash;</td>';
                    continue;
                }

                $cell = $matrix[$row_slug][$col_slug] ?? ['status' => 'compatible', 'severity' => 'none', 'count' => 0];
                $class = 'jetstrike-cd-matrix-cell--' . $cell['status'];

                if ($cell['status'] === 'conflict') {
                    $class .= ' jetstrike-cd-matrix-cell--' . $cell['severity'];
                }

                $tooltip = $cell['status'] === 'conflict'
                    ? sprintf('%d active conflict(s) — worst: %s', $cell['count'], $cell['severity'])
                    : 'Compatible';

                $icon = $cell['status'] === 'conflict'
                    ? ($cell['severity'] === 'critical' ? '&#10060;' : '&#9888;')
                    : '&#10003;';

                $html .= sprintf(
                    '<td class="jetstrike-cd-matrix-cell %s" title="%s" data-plugin-a="%s" data-plugin-b="%s">%s</td>',
                    esc_attr($class),
                    esc_attr($tooltip),
                    esc_attr($row_slug),
                    esc_attr($col_slug),
                    $icon
                );
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        // Legend.
        $html .= '<div class="jetstrike-cd-matrix-legend">';
        $html .= '<span class="jetstrike-cd-legend-item"><span class="jetstrike-cd-legend-color jetstrike-cd-legend--compatible"></span> Compatible</span>';
        $html .= '<span class="jetstrike-cd-legend-item"><span class="jetstrike-cd-legend-color jetstrike-cd-legend--medium"></span> Medium</span>';
        $html .= '<span class="jetstrike-cd-legend-item"><span class="jetstrike-cd-legend-color jetstrike-cd-legend--high"></span> High</span>';
        $html .= '<span class="jetstrike-cd-legend-item"><span class="jetstrike-cd-legend-color jetstrike-cd-legend--critical"></span> Critical</span>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Get info about all active third-party plugins.
     *
     * @return array<string, array{name: string, version: string, file: string}>
     */
    private function get_plugin_info(): array {
        $active = get_option('active_plugins', []);
        $plugins = [];

        foreach ($active as $plugin_file) {
            if ($plugin_file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
            $slug = dirname($plugin_file);

            $plugins[$slug] = [
                'name'    => $data['Name'] ?: $slug,
                'version' => $data['Version'] ?? '',
                'file'    => $plugin_file,
            ];
        }

        return $plugins;
    }

    /**
     * Create a consistent pair key for two plugins.
     */
    private function pair_key(string $a, string $b): string {
        $pair = [$a, $b];
        sort($pair);
        return implode(':', $pair);
    }

    /**
     * Get the worst severity from a set of conflicts.
     */
    private function worst_severity(array $conflicts): string {
        $levels = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $worst = 0;
        $worst_name = 'low';

        foreach ($conflicts as $conflict) {
            $severity = $conflict['severity'] ?? 'low';
            $level = $levels[$severity] ?? 0;

            if ($level > $worst) {
                $worst = $level;
                $worst_name = $severity;
            }
        }

        return $worst_name;
    }

    /**
     * Shorten a plugin name for matrix headers.
     */
    private function short_name(string $name): string {
        if (strlen($name) <= 15) {
            return $name;
        }

        // Try to use abbreviation or truncate.
        $words = explode(' ', $name);

        if (count($words) >= 3) {
            return implode(' ', array_slice($words, 0, 2)) . '...';
        }

        return substr($name, 0, 14) . '...';
    }
}
