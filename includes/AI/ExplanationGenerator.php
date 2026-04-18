<?php
/**
 * AI-powered conflict explanation generator.
 *
 * Takes raw technical conflict data and produces plain-English explanations
 * that non-technical store owners can understand, including estimated
 * business impact. Also generates narrative report summaries.
 *
 * Uses the Jetstrike AI API (backed by Claude) for generation.
 * Falls back to template-based explanations when the API is unavailable
 * or when the site has no AI credits remaining.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\AI;

final class ExplanationGenerator {

    private const API_ENDPOINT = 'https://api.jetstrike.io/v1/ai/explain';
    private const CACHE_PREFIX = 'jetstrike_cd_ai_';
    private const CACHE_TTL    = 7 * DAY_IN_SECONDS;

    /**
     * Generate a plain-English explanation for a single conflict.
     *
     * @param object $conflict Conflict row from the database.
     * @return array{explanation: string, impact: string, source: string}
     */
    public function explain_conflict(object $conflict): array {
        $cache_key = self::CACHE_PREFIX . 'explain_' . md5(
            $conflict->conflict_type . $conflict->plugin_a . $conflict->plugin_b .
            ($conflict->technical_details ?? '')
        );

        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $details = json_decode($conflict->technical_details ?? '{}', true);
        if (! is_array($details)) {
            $details = [];
        }

        $result = $this->call_ai_api($conflict, $details);

        if ($result === null) {
            $result = $this->generate_fallback($conflict, $details);
        }

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Generate plain-English explanations for multiple conflicts at once.
     *
     * @param array $conflicts Array of conflict objects.
     * @return array<int, array{explanation: string, impact: string, source: string}>
     *               Keyed by conflict ID.
     */
    public function explain_batch(array $conflicts): array {
        $results = [];

        foreach ($conflicts as $conflict) {
            $id = (int) ($conflict->id ?? 0);
            $results[$id] = $this->explain_conflict($conflict);
        }

        return $results;
    }

    /**
     * Generate a narrative executive summary for a full scan report.
     *
     * @param array  $conflicts   All active conflicts.
     * @param array  $health_data Health score data.
     * @param string $site_name   Site name.
     * @param int    $plugin_count Number of active plugins.
     * @return array{summary: string, source: string}
     */
    public function generate_executive_summary(
        array $conflicts,
        array $health_data,
        string $site_name,
        int $plugin_count
    ): array {
        $cache_key = self::CACHE_PREFIX . 'summary_' . md5(
            $site_name . $plugin_count . count($conflicts) .
            ($health_data['score'] ?? 0)
        );

        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $severity_counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $conflict_types = [];

        foreach ($conflicts as $c) {
            $sev = $c->severity ?? 'medium';
            $severity_counts[$sev] = ($severity_counts[$sev] ?? 0) + 1;
            $type = $c->conflict_type ?? 'unknown';
            $conflict_types[$type] = ($conflict_types[$type] ?? 0) + 1;
        }

        $prompt = $this->build_summary_prompt(
            $site_name,
            $plugin_count,
            $health_data,
            $severity_counts,
            $conflict_types,
            $conflicts
        );

        $response = $this->send_to_api($prompt, 'executive_summary');

        if ($response !== null) {
            $result = [
                'summary' => $response,
                'source'  => 'ai',
            ];
        } else {
            $result = [
                'summary' => $this->fallback_summary(
                    $site_name, $plugin_count, $health_data, $severity_counts
                ),
                'source' => 'template',
            ];
        }

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Call the Jetstrike AI API to generate an explanation.
     *
     * @return array{explanation: string, impact: string, source: string}|null
     */
    private function call_ai_api(object $conflict, array $details): ?array {
        $prompt = $this->build_conflict_prompt($conflict, $details);
        $response = $this->send_to_api($prompt, 'conflict_explanation');

        if ($response === null) {
            return null;
        }

        $parsed = json_decode($response, true);

        if (is_array($parsed) && isset($parsed['explanation'])) {
            return [
                'explanation' => sanitize_text_field($parsed['explanation']),
                'impact'      => sanitize_text_field($parsed['impact'] ?? ''),
                'source'      => 'ai',
            ];
        }

        return [
            'explanation' => sanitize_text_field($response),
            'impact'      => '',
            'source'      => 'ai',
        ];
    }

    /**
     * Send a prompt to the Jetstrike AI API.
     *
     * @param string $prompt    The prompt to send.
     * @param string $task_type Type of task for billing/routing.
     * @return string|null Response text, or null on failure.
     */
    private function send_to_api(string $prompt, string $task_type): ?string {
        $api_key = get_option('jetstrike_cd_ai_api_key', '');

        if (empty($api_key)) {
            $api_key = defined('JETSTRIKE_AI_API_KEY')
                ? constant('JETSTRIKE_AI_API_KEY')
                : '';
        }

        if (empty($api_key)) {
            return null;
        }

        $response = wp_remote_post(self::API_ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'prompt'         => $prompt,
                'task_type'      => $task_type,
                'plugin_version' => JETSTRIKE_CD_VERSION,
                'max_tokens'     => $task_type === 'executive_summary' ? 800 : 400,
            ]),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['text'] ?? null;
    }

    /**
     * Build the AI prompt for a single conflict explanation.
     */
    private function build_conflict_prompt(object $conflict, array $details): string {
        $plugin_a = $this->get_plugin_display_name($conflict->plugin_a ?? '');
        $plugin_b = $this->get_plugin_display_name($conflict->plugin_b ?? '');
        $type = str_replace('_', ' ', $conflict->conflict_type ?? 'unknown');
        $severity = $conflict->severity ?? 'medium';
        $description = $conflict->description ?? '';

        $detail_summary = '';
        foreach (['hook', 'function', 'handle', 'global', 'resource_type'] as $key) {
            if (! empty($details[$key])) {
                $detail_summary .= ucfirst($key) . ': ' . $details[$key] . '. ';
            }
        }

        $has_woo = class_exists('WooCommerce');

        return "You are a WordPress and WooCommerce expert writing for a non-technical store owner.\n\n" .
            "Explain this plugin conflict in 2-3 plain sentences. Then estimate the business impact in 1-2 sentences.\n\n" .
            "Respond as JSON: {\"explanation\": \"...\", \"impact\": \"...\"}\n\n" .
            "Conflict details:\n" .
            "- Type: {$type}\n" .
            "- Severity: {$severity}\n" .
            "- Plugin A: {$plugin_a}\n" .
            "- Plugin B: {$plugin_b}\n" .
            "- Technical description: {$description}\n" .
            "- Technical details: {$detail_summary}\n" .
            "- WooCommerce active: " . ($has_woo ? 'Yes' : 'No') . "\n\n" .
            "Rules:\n" .
            "- Write for a store owner, not a developer. No code, no jargon.\n" .
            "- Be specific about WHAT could go wrong (broken checkout, slow pages, lost orders).\n" .
            "- Quantify impact where possible (e.g. 'could affect 5-15% of orders').\n" .
            "- Keep it under 100 words total.";
    }

    /**
     * Build the AI prompt for an executive summary.
     */
    private function build_summary_prompt(
        string $site_name,
        int $plugin_count,
        array $health_data,
        array $severity_counts,
        array $conflict_types,
        array $conflicts
    ): string {
        $score = $health_data['score'] ?? 0;
        $grade = $health_data['grade'] ?? 'F';
        $total = array_sum($severity_counts);

        $type_summary = '';
        foreach ($conflict_types as $type => $count) {
            $type_summary .= str_replace('_', ' ', $type) . " ({$count}), ";
        }
        $type_summary = rtrim($type_summary, ', ');

        $top_conflicts = '';
        $critical_and_high = array_filter($conflicts, function ($c) {
            return in_array($c->severity ?? '', ['critical', 'high'], true);
        });

        foreach (array_slice($critical_and_high, 0, 3) as $c) {
            $pa = $this->get_plugin_display_name($c->plugin_a ?? '');
            $pb = $this->get_plugin_display_name($c->plugin_b ?? '');
            $top_conflicts .= "- {$c->severity}: {$pa} vs {$pb} ({$c->conflict_type}): {$c->description}\n";
        }

        $has_woo = class_exists('WooCommerce');

        return "You are a senior WordPress consultant writing an executive summary for a client.\n\n" .
            "Write a 3-4 paragraph executive summary of this site's plugin conflict audit.\n" .
            "Write in first person as the consultant. Be professional but clear.\n\n" .
            "Site: {$site_name}\n" .
            "Active plugins: {$plugin_count}\n" .
            "Health score: {$score}/100 (Grade: {$grade})\n" .
            "WooCommerce active: " . ($has_woo ? 'Yes' : 'No') . "\n" .
            "Total conflicts: {$total}\n" .
            "- Critical: {$severity_counts['critical']}\n" .
            "- High: {$severity_counts['high']}\n" .
            "- Medium: {$severity_counts['medium']}\n" .
            "- Low: {$severity_counts['low']}\n" .
            "Conflict types found: {$type_summary}\n\n" .
            "Top issues:\n{$top_conflicts}\n" .
            "Rules:\n" .
            "- Write for a business owner, not a developer.\n" .
            "- Lead with the most important finding.\n" .
            "- Include specific business impact (revenue risk, checkout failures, customer experience).\n" .
            "- End with a prioritised recommendation.\n" .
            "- Keep it under 250 words.";
    }

    /**
     * Generate a template-based explanation when AI is unavailable.
     *
     * @return array{explanation: string, impact: string, source: string}
     */
    private function generate_fallback(object $conflict, array $details): array {
        $plugin_a = $this->get_plugin_display_name($conflict->plugin_a ?? '');
        $plugin_b = $this->get_plugin_display_name($conflict->plugin_b ?? '');
        $severity = $conflict->severity ?? 'medium';

        $explanations = [
            'hook_conflict' => [
                'explanation' => sprintf(
                    '%s and %s are both trying to modify the same part of your site at the same time. ' .
                    'Because they run at the same priority, one plugin silently overrides the other, ' .
                    'which can cause features to stop working unpredictably.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => $severity === 'critical'
                    ? 'This could cause checkout failures or payment processing errors that directly affect your revenue.'
                    : 'This may cause intermittent issues that are difficult to diagnose, especially during high traffic.',
            ],
            'resource_collision' => [
                'explanation' => sprintf(
                    '%s and %s both load a file with the same name. WordPress can only load one, ' .
                    'so the other plugin\'s version gets dropped. This means one of the two plugins ' .
                    'may not work correctly on pages where both are active.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'You may see broken layouts, missing features, or JavaScript errors on your site. ' .
                    'Customers might see a broken page and leave without buying.',
            ],
            'function_redeclaration' => [
                'explanation' => sprintf(
                    '%s and %s both define a function with the same name. When WordPress tries to load both, ' .
                    'it crashes with a fatal error — your entire site goes down with a white screen.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'This is a site-breaking issue. When triggered, your store becomes completely ' .
                    'inaccessible to customers until you manually deactivate one of the plugins via FTP or database.',
            ],
            'class_collision' => [
                'explanation' => sprintf(
                    '%s and %s both define a class with the same name. This causes a fatal error that ' .
                    'takes your entire site offline — customers see a blank white page instead of your store.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'Complete site outage. Every minute your store is down, you lose potential sales ' .
                    'and damage customer trust.',
            ],
            'global_conflict' => [
                'explanation' => sprintf(
                    '%s and %s both use a shared variable to store data, but they expect different values. ' .
                    'This means they corrupt each other\'s data — settings get overwritten, ' .
                    'calculations produce wrong results, or features break silently.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'This can cause subtle, hard-to-diagnose issues like wrong prices, ' .
                    'missing products, or incorrect shipping calculations.',
            ],
            'performance_degradation' => [
                'explanation' => sprintf(
                    'When %s and %s are both active, your site becomes significantly slower. ' .
                    'Pages that normally load in 1-2 seconds may take 4 or more seconds.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'Slow page loads directly reduce sales. Studies show that every extra second of load time ' .
                    'reduces conversions by 7%%. If your store does $30,000/month, this could cost $2,000+ in lost sales.',
            ],
            'dependency_conflict' => [
                'explanation' => sprintf(
                    '%s and %s both include their own copy of the same code library, but different versions. ' .
                    'WordPress loads one version and ignores the other, which means one plugin is using an ' .
                    'incompatible library version and may malfunction.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'This often causes random errors in payment processing, email sending, or API connections — ' .
                    'problems that seem to come and go without explanation.',
            ],
            'js_global_conflict' => [
                'explanation' => sprintf(
                    '%s and %s both create a JavaScript variable with the same name. One plugin\'s code ' .
                    'overwrites the other\'s, causing interactive features like sliders, popups, or ' .
                    'checkout forms to break.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'Customers may see broken forms, unresponsive buttons, or errors during checkout ' .
                    'that prevent them from completing their purchase.',
            ],
            'db_option_collision' => [
                'explanation' => sprintf(
                    '%s and %s both store their settings under the same name in the database. ' .
                    'Each time one plugin saves its settings, it overwrites the other plugin\'s settings.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'Plugin settings keep resetting themselves. You configure one plugin, ' .
                    'and the next time you check, the settings have changed back.',
            ],
            'db_cpt_collision' => [
                'explanation' => sprintf(
                    '%s and %s both register the same custom content type. WordPress can only have one ' .
                    'definition, so one plugin\'s content may appear in the wrong place or disappear entirely.',
                    $plugin_a,
                    $plugin_b
                ),
                'impact' => 'Content created by one plugin may become inaccessible or display incorrectly. ' .
                    'In WooCommerce stores, this could affect product listings or order management.',
            ],
        ];

        $type = $conflict->conflict_type ?? 'unknown';

        if (isset($explanations[$type])) {
            return [
                'explanation' => $explanations[$type]['explanation'],
                'impact'      => $explanations[$type]['impact'],
                'source'      => 'template',
            ];
        }

        return [
            'explanation' => sprintf(
                'A %s conflict was detected between %s and %s. ' .
                'These two plugins are interfering with each other in a way that could cause errors or unexpected behavior.',
                str_replace('_', ' ', $type),
                $plugin_a,
                $plugin_b
            ),
            'impact' => $severity === 'critical'
                ? 'This is a critical issue that could take your site offline or break your checkout.'
                : 'This may cause intermittent issues that are difficult to diagnose.',
            'source' => 'template',
        ];
    }

    /**
     * Generate a template-based executive summary when AI is unavailable.
     */
    private function fallback_summary(
        string $site_name,
        int $plugin_count,
        array $health_data,
        array $severity_counts
    ): string {
        $score = (int) ($health_data['score'] ?? 0);
        $grade = $health_data['grade'] ?? 'F';
        $total = array_sum($severity_counts);

        $condition = 'good condition';
        if ($score < 40) {
            $condition = 'serious trouble';
        } elseif ($score < 60) {
            $condition = 'fair condition but needs attention';
        } elseif ($score < 75) {
            $condition = 'reasonable shape with some issues';
        }

        $summary = sprintf(
            '%s is running %d active plugins and scored %d/100 (Grade %s) in our conflict audit. ' .
            'Overall, the site is in %s.',
            $site_name,
            $plugin_count,
            $score,
            $grade,
            $condition
        );

        if ($severity_counts['critical'] > 0) {
            $summary .= sprintf(
                "\n\nWe found %d critical conflict(s) that pose an immediate risk to your store's checkout and payment " .
                'processing. These should be resolved this week to prevent revenue loss.',
                $severity_counts['critical']
            );
        }

        if ($severity_counts['high'] > 0) {
            $summary .= sprintf(
                "\n\n%d high-severity conflict(s) were detected that could cause intermittent errors " .
                'or performance degradation. We recommend addressing these within the next two weeks.',
                $severity_counts['high']
            );
        }

        if ($total === 0) {
            $summary .= "\n\nNo conflicts were detected. Your plugin stack is clean and well-maintained.";
        } else {
            $summary .= sprintf(
                "\n\nIn total, %d conflict(s) were found across all severity levels. " .
                'We recommend starting with the critical and high-severity issues, then addressing ' .
                'medium and low issues during your next maintenance window.',
                $total
            );
        }

        return $summary;
    }

    /**
     * Get a human-readable plugin name from a file path.
     */
    private function get_plugin_display_name(string $plugin_file): string {
        if (empty($plugin_file)) {
            return 'Unknown Plugin';
        }

        if (function_exists('get_plugin_data') && defined('WP_PLUGIN_DIR')) {
            $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($full_path)) {
                $data = get_plugin_data($full_path, false, false);
                if (! empty($data['Name'])) {
                    return $data['Name'];
                }
            }
        }

        $dir = dirname($plugin_file);
        return $dir !== '.' ? ucwords(str_replace('-', ' ', $dir)) : $plugin_file;
    }
}
