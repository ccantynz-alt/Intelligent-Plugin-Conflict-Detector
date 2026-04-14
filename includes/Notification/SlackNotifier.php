<?php
/**
 * Slack webhook notification sender.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Notification;

final class SlackNotifier {

    /** @var string Slack webhook URL. */
    private string $webhook_url;

    public function __construct(string $webhook_url) {
        $this->webhook_url = $webhook_url;
    }

    /**
     * Send scan results to Slack.
     *
     * @param int   $scan_id        Scan ID.
     * @param int   $conflict_count Total conflicts.
     * @param int   $critical_count Critical conflicts.
     * @param array $conflicts      Conflict records.
     */
    public function send_scan_results(int $scan_id, int $conflict_count, int $critical_count, array $conflicts): void {
        $site_name = get_bloginfo('name');
        $dashboard_url = admin_url('admin.php?page=jetstrike-cd');

        $color = $critical_count > 0 ? '#dc3545' : ($conflict_count > 0 ? '#fd7e14' : '#28a745');

        $fields = [];

        // Group conflicts by severity.
        $by_severity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($conflicts as $conflict) {
            $severity = $conflict->severity ?? 'medium';
            $by_severity[$severity] = ($by_severity[$severity] ?? 0) + 1;
        }

        foreach ($by_severity as $severity => $count) {
            if ($count > 0) {
                $fields[] = [
                    'title' => ucfirst($severity),
                    'value' => (string) $count,
                    'short' => true,
                ];
            }
        }

        // Top 3 conflicts as text.
        $top_conflicts = '';

        foreach (array_slice($conflicts, 0, 3) as $conflict) {
            $top_conflicts .= sprintf(
                "- [%s] %s\n",
                strtoupper(sanitize_text_field($conflict->severity ?? 'medium')),
                sanitize_text_field($conflict->description ?? 'Unknown conflict')
            );
        }

        $payload = [
            'username'    => 'Jetstrike Conflict Detector',
            'icon_emoji'  => ':shield:',
            'attachments' => [
                [
                    'color'      => $color,
                    'title'      => sprintf('Plugin Scan Complete — %d Conflict(s) Found', $conflict_count),
                    'title_link' => $dashboard_url,
                    'text'       => $top_conflicts,
                    'fields'     => $fields,
                    'footer'     => $site_name,
                    'ts'         => time(),
                ],
            ],
        ];

        $this->send($payload);
    }

    /**
     * Send critical conflict alert to Slack.
     *
     * @param object $conflict Conflict record.
     */
    public function send_critical_alert(object $conflict): void {
        $site_name = get_bloginfo('name');
        $dashboard_url = admin_url('admin.php?page=jetstrike-cd');

        $payload = [
            'username'    => 'Jetstrike Conflict Detector',
            'icon_emoji'  => ':rotating_light:',
            'attachments' => [
                [
                    'color'      => '#dc3545',
                    'title'      => 'CRITICAL Plugin Conflict Detected',
                    'title_link' => $dashboard_url,
                    'text'       => $conflict->description ?? 'A critical conflict has been detected.',
                    'fields'     => [
                        [
                            'title' => 'Plugin A',
                            'value' => $conflict->plugin_a ?? 'Unknown',
                            'short' => true,
                        ],
                        [
                            'title' => 'Plugin B',
                            'value' => $conflict->plugin_b ?? 'Unknown',
                            'short' => true,
                        ],
                        [
                            'title' => 'Recommendation',
                            'value' => $conflict->recommendation ?? 'Review immediately.',
                            'short' => false,
                        ],
                    ],
                    'footer' => $site_name,
                    'ts'     => time(),
                ],
            ],
        ];

        $this->send($payload);
    }

    /**
     * Send payload to Slack webhook.
     *
     * @param array $payload Slack message payload.
     */
    private function send(array $payload): void {
        if (empty($this->webhook_url)) {
            return;
        }

        wp_remote_post($this->webhook_url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);
    }
}
