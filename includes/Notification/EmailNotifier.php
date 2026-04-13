<?php
/**
 * Email notification sender.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Notification;

final class EmailNotifier {

    /**
     * Send scan results email.
     *
     * @param string $to             Recipient email.
     * @param int    $scan_id        Scan ID.
     * @param int    $conflict_count Total conflicts.
     * @param int    $critical_count Critical conflicts.
     * @param array  $conflicts      Conflict records.
     */
    public function send_scan_results(string $to, int $scan_id, int $conflict_count, int $critical_count, array $conflicts): void {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $subject = $critical_count > 0
            ? sprintf('[%s] %d Critical Plugin Conflict(s) Detected', $site_name, $critical_count)
            : sprintf('[%s] Plugin Scan Complete — %d Conflict(s) Found', $site_name, $conflict_count);

        $dashboard_url = admin_url('admin.php?page=jetstrike-cd');

        // Build HTML email.
        $body = $this->build_email_html($site_name, $site_url, $conflict_count, $critical_count, $conflicts, $dashboard_url);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: Jetstrike Conflict Detector <%s>', get_option('admin_email')),
        ];

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Send critical conflict alert.
     *
     * @param string $to       Recipient email.
     * @param object $conflict Conflict record.
     */
    public function send_critical_alert(string $to, object $conflict): void {
        $site_name = get_bloginfo('name');

        $subject = sprintf('[%s] CRITICAL: Plugin Conflict Detected', $site_name);

        $body = sprintf(
            "<h2>Critical Plugin Conflict Detected</h2>
            <p><strong>Site:</strong> %s</p>
            <p><strong>Conflict:</strong> %s</p>
            <p><strong>Plugins:</strong> %s vs %s</p>
            <p><strong>Severity:</strong> %s</p>
            <p><strong>Recommendation:</strong> %s</p>
            <p><a href=\"%s\">View in Dashboard</a></p>",
            esc_html($site_name),
            esc_html($conflict->description),
            esc_html($conflict->plugin_a),
            esc_html($conflict->plugin_b),
            esc_html(strtoupper($conflict->severity)),
            esc_html($conflict->recommendation ?? ''),
            esc_url(admin_url('admin.php?page=jetstrike-cd'))
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Build formatted HTML email for scan results.
     */
    private function build_email_html(
        string $site_name,
        string $site_url,
        int $conflict_count,
        int $critical_count,
        array $conflicts,
        string $dashboard_url
    ): string {
        $severity_colors = [
            'critical' => '#dc3545',
            'high'     => '#fd7e14',
            'medium'   => '#ffc107',
            'low'      => '#6c757d',
        ];

        $conflict_rows = '';

        foreach (array_slice($conflicts, 0, 10) as $conflict) {
            $severity = $conflict->severity ?? 'medium';
            $color = $severity_colors[$severity] ?? '#6c757d';

            $conflict_rows .= sprintf(
                '<tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <span style="background: %s; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase;">%s</span>
                    </td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">%s</td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 13px; color: #666;">%s</td>
                </tr>',
                esc_attr($color),
                esc_html($severity),
                esc_html($conflict->description ?? ''),
                esc_html($conflict->recommendation ?? '')
            );
        }

        $remaining = max(0, $conflict_count - 10);
        $remaining_text = $remaining > 0
            ? sprintf('<p style="color: #666; font-size: 13px;">...and %d more conflict(s). <a href="%s">View all in dashboard</a></p>', $remaining, esc_url($dashboard_url))
            : '';

        return sprintf(
            '<!DOCTYPE html>
            <html>
            <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h1 style="margin: 0 0 5px 0; font-size: 20px; color: #1a1a1a;">Jetstrike Conflict Detector</h1>
                    <p style="margin: 0; color: #666; font-size: 14px;">Scan Report for %s</p>
                </div>

                <div style="background: %s; color: #fff; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h2 style="margin: 0; font-size: 24px;">%d Conflict%s Found</h2>
                    %s
                </div>

                <table style="width: 100%%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #dee2e6;">
                            <th style="padding: 8px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666;">Severity</th>
                            <th style="padding: 8px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666;">Description</th>
                            <th style="padding: 8px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666;">Recommendation</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                </table>

                %s

                <div style="text-align: center; margin-top: 30px;">
                    <a href="%s" style="display: inline-block; background: #0073aa; color: #fff; padding: 12px 30px; border-radius: 5px; text-decoration: none; font-weight: 600;">View Full Report</a>
                </div>

                <p style="color: #999; font-size: 12px; margin-top: 30px; text-align: center;">
                    Sent by Jetstrike Conflict Detector from %s
                </p>
            </body>
            </html>',
            esc_html($site_name),
            $critical_count > 0 ? '#dc3545' : '#fd7e14',
            $conflict_count,
            $conflict_count !== 1 ? 's' : '',
            $critical_count > 0
                ? sprintf('<p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;">Including %d critical conflict%s requiring immediate attention.</p>', $critical_count, $critical_count !== 1 ? 's' : '')
                : '',
            $conflict_rows,
            $remaining_text,
            esc_url($dashboard_url),
            esc_url($site_url)
        );
    }
}
