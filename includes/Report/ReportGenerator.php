<?php
/**
 * Conflict Report Generator — produces professional HTML reports.
 *
 * Agencies managing multiple client sites need professional reports
 * they can share with clients or attach to maintenance invoices.
 *
 * Generates a self-contained HTML document with:
 * - Site health score and grade
 * - Conflict summary with severity breakdown
 * - Full conflict details with recommendations
 * - Plugin compatibility matrix
 * - Scan history timeline
 * - Actionable next steps
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Report;

use Jetstrike\ConflictDetector\Database\Repository;
use Jetstrike\ConflictDetector\Monitor\HealthMonitor;
use Jetstrike\ConflictDetector\Resolver\AutoResolver;

final class ReportGenerator {

    /** @var Repository */
    private Repository $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Generate a full conflict report.
     *
     * @param int|null $scan_id Specific scan ID, or null for latest.
     * @return array{html: string, filename: string, generated_at: string}
     */
    public function generate(int $scan_id = null): array {
        $scan = $scan_id
            ? $this->repository->get_scan($scan_id)
            : $this->repository->get_latest_scan();

        $conflicts = $scan
            ? $this->repository->get_conflicts_for_scan((int) $scan->id)
            : [];

        $all_active = $this->repository->list_active_conflicts(1, 200);
        $health_monitor = new HealthMonitor($this->repository);
        $health = $health_monitor->get_health_data();

        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        $generated_at = current_time('mysql');

        $html = $this->build_html([
            'site_name'    => $site_name,
            'site_url'     => $site_url,
            'generated_at' => $generated_at,
            'health'       => $health,
            'scan'         => $scan,
            'conflicts'    => $conflicts,
            'all_active'   => $all_active,
            'summary'      => $this->build_summary($all_active),
            'plugins'      => $this->get_plugin_list(),
        ]);

        $filename = sprintf(
            'jetstrike-report-%s-%s.html',
            sanitize_title($site_name),
            gmdate('Y-m-d')
        );

        return [
            'html'         => $html,
            'filename'     => $filename,
            'generated_at' => $generated_at,
        ];
    }

    /**
     * Build the full HTML report.
     */
    private function build_html(array $data): string {
        $grade_colors = [
            'A' => '#22c55e', 'B' => '#84cc16', 'C' => '#eab308',
            'D' => '#f97316', 'F' => '#ef4444',
        ];
        $grade_color = $grade_colors[$data['health']['grade'] ?? 'F'] ?? '#6b7280';

        $html = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>Plugin Conflict Report — ' . esc_html($data['site_name']) . '</title>';
        $html .= '<style>' . $this->get_report_css() . '</style>';
        $html .= '</head><body>';

        // Header.
        $html .= '<div class="report-header">';
        $html .= '<div class="report-logo">Jetstrike</div>';
        $html .= '<div class="report-meta">';
        $html .= '<h1>Plugin Conflict Report</h1>';
        $html .= '<p>' . esc_html($data['site_name']) . ' &mdash; ' . esc_url($data['site_url']) . '</p>';
        $html .= '<p>Generated: ' . esc_html($data['generated_at']) . '</p>';
        $html .= '</div></div>';

        // Health Score.
        $score = (int) ($data['health']['score'] ?? 0);
        $grade = $data['health']['grade'] ?? 'F';

        $html .= '<div class="report-section">';
        $html .= '<div class="health-banner" style="border-left-color: ' . $grade_color . '">';
        $html .= '<div class="health-grade" style="background: ' . $grade_color . '">' . esc_html($grade) . '</div>';
        $html .= '<div class="health-info">';
        $html .= '<h2>Site Health Score: ' . $score . '/100</h2>';
        $html .= '<p>' . $this->grade_description($grade) . '</p>';
        $html .= '</div></div></div>';

        // Summary.
        $summary = $data['summary'];
        $html .= '<div class="report-section">';
        $html .= '<h2>Conflict Summary</h2>';
        $html .= '<div class="summary-grid">';

        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $count = $summary['by_severity'][$severity] ?? 0;
            $html .= '<div class="summary-card summary-card--' . $severity . '">';
            $html .= '<div class="summary-count">' . $count . '</div>';
            $html .= '<div class="summary-label">' . ucfirst($severity) . '</div>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        // Active plugins.
        $plugins = $data['plugins'];
        $html .= '<div class="report-section">';
        $html .= '<h2>Active Plugins (' . count($plugins) . ')</h2>';
        $html .= '<table class="report-table"><thead><tr><th>Plugin</th><th>Version</th><th>Conflicts</th></tr></thead><tbody>';

        foreach ($plugins as $plugin) {
            $conflict_count = 0;
            foreach ($data['all_active'] as $c) {
                if ($c->plugin_a === $plugin['file'] || $c->plugin_b === $plugin['file']) {
                    $conflict_count++;
                }
            }

            $status_class = $conflict_count > 0 ? 'status--conflict' : 'status--clean';
            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html($plugin['name']) . '</strong></td>';
            $html .= '<td>' . esc_html($plugin['version']) . '</td>';
            $html .= '<td class="' . $status_class . '">' . ($conflict_count > 0 ? $conflict_count . ' conflict(s)' : 'Clean') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        // Conflict details.
        if (! empty($data['all_active'])) {
            $html .= '<div class="report-section">';
            $html .= '<h2>Active Conflicts (' . count($data['all_active']) . ')</h2>';

            foreach ($data['all_active'] as $conflict) {
                $can_fix = AutoResolver::can_auto_resolve($conflict->conflict_type);

                $html .= '<div class="conflict-card conflict-card--' . esc_attr($conflict->severity) . '">';
                $html .= '<div class="conflict-header">';
                $html .= '<span class="severity-badge severity-badge--' . esc_attr($conflict->severity) . '">';
                $html .= strtoupper(esc_html($conflict->severity)) . '</span>';
                $html .= '<span class="conflict-type">' . esc_html(str_replace('_', ' ', $conflict->conflict_type)) . '</span>';

                if ($can_fix['can_resolve']) {
                    $html .= '<span class="autofix-badge">Auto-Fix Available</span>';
                }

                $html .= '</div>';
                $html .= '<p class="conflict-desc">' . esc_html($conflict->description) . '</p>';
                $html .= '<div class="conflict-plugins">';
                $html .= '<code>' . esc_html(dirname($conflict->plugin_a)) . '</code>';

                if (! empty($conflict->plugin_b)) {
                    $html .= ' vs <code>' . esc_html(dirname($conflict->plugin_b)) . '</code>';
                }

                $html .= '</div>';

                if (! empty($conflict->recommendation)) {
                    $html .= '<div class="conflict-recommendation">';
                    $html .= '<strong>Recommendation:</strong> ' . esc_html($conflict->recommendation);
                    $html .= '</div>';
                }

                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Footer.
        $html .= '<div class="report-footer">';
        $html .= '<p>Generated by <strong>Jetstrike Conflict Detector</strong> v' . JETSTRIKE_CD_VERSION . '</p>';
        $html .= '<p><a href="https://jetstrike.io">jetstrike.io</a></p>';
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Build conflict summary statistics.
     */
    private function build_summary(array $conflicts): array {
        $summary = [
            'by_severity' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            'by_type'     => [],
            'total'       => count($conflicts),
        ];

        foreach ($conflicts as $conflict) {
            $severity = $conflict->severity ?? 'medium';
            $type = $conflict->conflict_type ?? 'unknown';

            $summary['by_severity'][$severity] = ($summary['by_severity'][$severity] ?? 0) + 1;
            $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Get active plugin list.
     */
    private function get_plugin_list(): array {
        $active = get_option('active_plugins', []);
        $plugins = [];

        foreach ($active as $file) {
            if ($file === JETSTRIKE_CD_BASENAME) {
                continue;
            }

            $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
            $plugins[] = [
                'file'    => $file,
                'name'    => $data['Name'] ?: dirname($file),
                'version' => $data['Version'] ?? '',
            ];
        }

        return $plugins;
    }

    /**
     * Get description for a health grade.
     */
    private function grade_description(string $grade): string {
        $descriptions = [
            'A' => 'Excellent — your site has no significant plugin conflicts.',
            'B' => 'Good — minor issues detected that should be monitored.',
            'C' => 'Fair — several conflicts found that may affect site stability.',
            'D' => 'Poor — significant conflicts require attention.',
            'F' => 'Critical — your site has serious conflicts that need immediate action.',
        ];

        return $descriptions[$grade] ?? 'Unknown health status.';
    }

    /**
     * Self-contained CSS for the report.
     */
    private function get_report_css(): string {
        return <<<'CSS'
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.6; max-width: 900px; margin: 0 auto; padding: 40px 24px; background: #fff; }
.report-header { display: flex; align-items: center; gap: 24px; padding-bottom: 24px; border-bottom: 2px solid #e2e8f0; margin-bottom: 32px; }
.report-logo { font-size: 28px; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; }
.report-meta h1 { font-size: 20px; font-weight: 600; }
.report-meta p { color: #64748b; font-size: 14px; }
.report-section { margin-bottom: 32px; }
.report-section h2 { font-size: 18px; font-weight: 600; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
.health-banner { display: flex; align-items: center; gap: 20px; padding: 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid; }
.health-grade { width: 60px; height: 60px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700; flex-shrink: 0; }
.health-info h2 { font-size: 18px; border: 0; padding: 0; margin: 0; }
.health-info p { color: #64748b; margin-top: 4px; }
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.summary-card { text-align: center; padding: 16px; border-radius: 8px; background: #f8fafc; }
.summary-card--critical { border-left: 3px solid #ef4444; }
.summary-card--high { border-left: 3px solid #f97316; }
.summary-card--medium { border-left: 3px solid #eab308; }
.summary-card--low { border-left: 3px solid #3b82f6; }
.summary-count { font-size: 32px; font-weight: 700; }
.summary-card--critical .summary-count { color: #ef4444; }
.summary-card--high .summary-count { color: #f97316; }
.summary-card--medium .summary-count { color: #eab308; }
.summary-card--low .summary-count { color: #3b82f6; }
.summary-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; }
.report-table { width: 100%; border-collapse: collapse; }
.report-table th, .report-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
.report-table th { background: #f8fafc; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.status--conflict { color: #ef4444; font-weight: 600; }
.status--clean { color: #22c55e; }
.conflict-card { padding: 16px; border-radius: 8px; margin-bottom: 12px; border: 1px solid #e2e8f0; border-left: 4px solid; }
.conflict-card--critical { border-left-color: #ef4444; background: #fef2f2; }
.conflict-card--high { border-left-color: #f97316; background: #fff7ed; }
.conflict-card--medium { border-left-color: #eab308; background: #fefce8; }
.conflict-card--low { border-left-color: #3b82f6; background: #eff6ff; }
.conflict-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.severity-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; color: #fff; }
.severity-badge--critical { background: #ef4444; }
.severity-badge--high { background: #f97316; }
.severity-badge--medium { background: #eab308; }
.severity-badge--low { background: #3b82f6; }
.conflict-type { font-size: 13px; color: #64748b; text-transform: capitalize; }
.autofix-badge { font-size: 11px; background: #2563eb; color: #fff; padding: 2px 8px; border-radius: 4px; margin-left: auto; }
.conflict-desc { font-size: 14px; margin-bottom: 8px; }
.conflict-plugins { margin-bottom: 8px; }
.conflict-plugins code { background: #f1f5f9; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
.conflict-recommendation { font-size: 13px; color: #475569; padding: 8px 12px; background: #fff; border-radius: 4px; border: 1px solid #e2e8f0; }
.report-footer { text-align: center; padding-top: 24px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 13px; }
.report-footer a { color: #2563eb; text-decoration: none; }
@media print { body { padding: 0; } .summary-grid { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 600px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } .health-banner { flex-direction: column; text-align: center; } }
CSS;
    }
}
