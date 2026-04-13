<?php
/**
 * Sandbox environment for isolated plugin testing.
 *
 * Spawns loopback HTTP requests to the site with specific plugin combinations
 * activated, checking for fatal errors, PHP warnings, and HTTP failures
 * without affecting the live site.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Scanner;

final class SandboxEnvironment {

    /** @var string Secure token for authenticating sandbox requests. */
    private string $auth_token;

    /** @var float Baseline response time in milliseconds. */
    private float $baseline_time = 0.0;

    /** @var float Performance threshold multiplier. */
    private float $performance_threshold;

    public function __construct(float $performance_threshold = 3.0) {
        $this->auth_token = wp_generate_password(40, false);
        $this->performance_threshold = $performance_threshold;
    }

    /**
     * Initialize the sandbox by setting up the auth token and measuring baseline.
     *
     * @return bool True if sandbox is ready.
     */
    public function initialize(): bool {
        set_transient('jetstrike_cd_sandbox_token', $this->auth_token, HOUR_IN_SECONDS);

        // Measure baseline response time with no extra plugins.
        $baseline = $this->execute_request([]);

        if ($baseline === null) {
            return false;
        }

        $this->baseline_time = $baseline['time_ms'];

        return true;
    }

    /**
     * Test a specific set of plugins for conflicts.
     *
     * @param array<string> $plugins Plugin file paths to activate together.
     * @return array{success: bool, http_status: int, time_ms: float, errors: array, warnings: array, fatal: bool}
     */
    public function test_plugins(array $plugins): array {
        $result = $this->execute_request($plugins);

        if ($result === null) {
            return [
                'success'     => false,
                'http_status' => 0,
                'time_ms'     => 0,
                'errors'      => ['Failed to connect to the site. Loopback request failed.'],
                'warnings'    => [],
                'fatal'       => true,
            ];
        }

        return $result;
    }

    /**
     * Clean up sandbox resources.
     */
    public function cleanup(): void {
        delete_transient('jetstrike_cd_sandbox_token');
        delete_transient('jetstrike_cd_sandbox_plugins');
    }

    /**
     * Get the baseline response time.
     *
     * @return float Baseline time in milliseconds.
     */
    public function get_baseline_time(): float {
        return $this->baseline_time;
    }

    /**
     * Execute a sandboxed loopback request with specific plugins.
     *
     * @param array<string> $plugins Plugin file paths to activate.
     * @return array|null Request results or null on connection failure.
     */
    private function execute_request(array $plugins): ?array {
        // Store the plugin list for the sandbox endpoint to pick up.
        set_transient('jetstrike_cd_sandbox_plugins', $plugins, 300);

        $nonce = wp_create_nonce('jetstrike_cd_sandbox');

        $url = add_query_arg(
            [
                'jetstrike_sandbox' => '1',
                'nonce'             => $nonce,
            ],
            home_url('/')
        );

        $start = microtime(true);

        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'sslverify'   => false,
            'redirection' => 0,
            'headers'     => [
                'X-Jetstrike-Sandbox' => $this->auth_token,
                'X-Jetstrike-Plugins' => wp_json_encode($plugins),
            ],
        ]);

        $elapsed_ms = (microtime(true) - $start) * 1000;

        if (is_wp_error($response)) {
            return null;
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Parse response for errors.
        $errors = [];
        $warnings = [];
        $fatal = false;

        // HTTP 500 = fatal error.
        if ($http_status >= 500) {
            $fatal = true;
            $errors[] = sprintf('HTTP %d error — likely a fatal PHP error.', $http_status);

            // Try to extract error from body.
            if (preg_match('/Fatal error:\s*(.+?)(?:\s+in\s+|$)/i', $body, $match)) {
                $errors[] = 'Fatal: ' . trim($match[1]);
            }
        }

        // Check for PHP errors in the output.
        if (preg_match_all('/(?:Warning|Notice|Deprecated):\s*(.+?)(?:\s+in\s+|$)/i', $body, $matches)) {
            foreach ($matches[1] as $warning) {
                $warnings[] = trim($warning);
            }
        }

        // Check for WP error page markers.
        if (strpos($body, 'wp-die-message') !== false || strpos($body, 'error-page') !== false) {
            $errors[] = 'WordPress displayed an error page.';
        }

        // Check for performance degradation.
        $slow = false;
        if ($this->baseline_time > 0 && $elapsed_ms > ($this->baseline_time * $this->performance_threshold)) {
            $slow = true;
            $warnings[] = sprintf(
                'Response time %.0fms exceeds %.1fx threshold (baseline: %.0fms).',
                $elapsed_ms,
                $this->performance_threshold,
                $this->baseline_time
            );
        }

        // Check the sandbox response header for structured error data.
        $sandbox_header = wp_remote_retrieve_header($response, 'x-jetstrike-sandbox-result');

        if (! empty($sandbox_header)) {
            $sandbox_data = json_decode(is_array($sandbox_header) ? $sandbox_header[0] : $sandbox_header, true);

            if (is_array($sandbox_data)) {
                if (! empty($sandbox_data['errors']) && is_array($sandbox_data['errors'])) {
                    $errors = array_merge($errors, array_map('sanitize_text_field', $sandbox_data['errors']));
                }
                if (! empty($sandbox_data['warnings']) && is_array($sandbox_data['warnings'])) {
                    $warnings = array_merge($warnings, array_map('sanitize_text_field', $sandbox_data['warnings']));
                }
            }
        }

        return [
            'success'     => $http_status >= 200 && $http_status < 400 && ! $fatal,
            'http_status' => $http_status,
            'time_ms'     => round($elapsed_ms, 2),
            'errors'      => array_unique($errors),
            'warnings'    => array_unique(array_slice($warnings, 0, 20)),
            'fatal'       => $fatal,
            'slow'        => $slow,
        ];
    }
}
