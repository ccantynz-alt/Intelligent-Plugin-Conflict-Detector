<?php
/**
 * Anonymous telemetry — sends anonymized scan results to the Jetstrike cloud
 * to power the global conflict intelligence database.
 *
 * All data is anonymized: no site URLs, no user data, no plugin license keys.
 * Only plugin slugs, conflict types, and severity levels are transmitted.
 *
 * Users must explicitly opt in. No data is sent without consent.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Cloud;

final class Telemetry {

    private const API_ENDPOINT = 'https://api.jetstrike.io/v1/telemetry';
    private const OPT_IN_KEY   = 'jetstrike_cd_telemetry_opt_in';

    /**
     * Register hooks.
     */
    public function register(): void {
        // Show opt-in notice on first activation.
        add_action('admin_notices', [$this, 'show_opt_in_notice']);
        add_action('wp_ajax_jetstrike_cd_telemetry_opt', [$this, 'handle_opt_in']);
    }

    /**
     * Check if user has opted in to telemetry.
     *
     * @return bool True if opted in.
     */
    public static function is_opted_in(): bool {
        return get_option(self::OPT_IN_KEY, 'undecided') === 'yes';
    }

    /**
     * Check if user has already made a decision.
     *
     * @return bool True if user has decided (yes or no).
     */
    public static function has_decided(): bool {
        return get_option(self::OPT_IN_KEY, 'undecided') !== 'undecided';
    }

    /**
     * Send anonymized scan results to the cloud.
     *
     * @param array $conflicts Conflict data from a completed scan.
     * @param int   $plugin_count Number of plugins scanned.
     */
    public function report_scan(array $conflicts, int $plugin_count): void {
        if (! self::is_opted_in()) {
            return;
        }

        $anonymized = $this->anonymize_conflicts($conflicts);

        $payload = [
            'schema_version' => 1,
            'plugin_version' => JETSTRIKE_CD_VERSION,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'wc_active'      => class_exists('WooCommerce'),
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
            'plugin_count'   => $plugin_count,
            'conflict_count' => count($anonymized),
            'conflicts'      => $anonymized,
            'site_hash'      => self::get_site_hash(),
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        // Non-blocking fire-and-forget request.
        wp_remote_post(self::API_ENDPOINT, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [
                'Content-Type' => 'application/json',
                'X-Jetstrike-Telemetry' => '1',
            ],
            'body' => wp_json_encode($payload),
        ]);
    }

    /**
     * Strip all identifying information from conflict data.
     *
     * Only transmits: plugin slug (directory name), conflict type, severity.
     * No file paths, no function names, no descriptions, no site data.
     *
     * @param array $conflicts Raw conflict data.
     * @return array Anonymized conflicts.
     */
    private function anonymize_conflicts(array $conflicts): array {
        $anonymized = [];

        foreach ($conflicts as $conflict) {
            $anonymized[] = [
                'plugin_a_slug' => $this->extract_slug($conflict['plugin_a'] ?? ''),
                'plugin_b_slug' => $this->extract_slug($conflict['plugin_b'] ?? ''),
                'type'          => $conflict['type'] ?? 'unknown',
                'severity'      => $conflict['severity'] ?? 'medium',
            ];
        }

        return $anonymized;
    }

    /**
     * Extract plugin slug (directory name only) from a plugin file path.
     *
     * "woocommerce/woocommerce.php" → "woocommerce"
     *
     * @param string $plugin_file Plugin file path.
     * @return string Plugin slug.
     */
    private function extract_slug(string $plugin_file): string {
        if (empty($plugin_file)) {
            return '';
        }

        $parts = explode('/', $plugin_file);
        return sanitize_title($parts[0] ?? '');
    }

    /**
     * Generate a stable anonymous hash for this site.
     *
     * Used to deduplicate reports from the same site across scans.
     * Cannot be reverse-engineered to identify the site.
     *
     * @return string Hex hash.
     */
    private static function get_site_hash(): string {
        $hash = get_option('jetstrike_cd_site_hash', '');

        if (empty($hash)) {
            $hash = hash('sha256', wp_generate_password(64, true, true) . microtime(true));
            update_option('jetstrike_cd_site_hash', $hash);
        }

        return $hash;
    }

    /**
     * Show opt-in notice (only once, only to admins).
     */
    public function show_opt_in_notice(): void {
        if (! current_user_can('manage_options') || self::has_decided()) {
            return;
        }

        // Only show on our plugin pages.
        $screen = get_current_screen();

        if ($screen === null || strpos($screen->id, 'jetstrike') === false) {
            return;
        }

        ?>
        <div class="notice notice-info jetstrike-cd-telemetry-notice" id="jetstrike-telemetry-notice">
            <p>
                <strong><?php esc_html_e('Help improve Jetstrike Conflict Detector', 'jetstrike-cd'); ?></strong><br>
                <?php esc_html_e('Share anonymous conflict data to help us build the world\'s largest plugin compatibility database. Only plugin slugs and conflict types are shared — no personal data, no site URLs, no identifying information.', 'jetstrike-cd'); ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="jetstrike-telemetry-yes">
                    <?php esc_html_e('Yes, I\'ll help', 'jetstrike-cd'); ?>
                </button>
                <button type="button" class="button" id="jetstrike-telemetry-no">
                    <?php esc_html_e('No thanks', 'jetstrike-cd'); ?>
                </button>
            </p>
        </div>
        <script>
        jQuery(function($) {
            $('#jetstrike-telemetry-yes, #jetstrike-telemetry-no').on('click', function() {
                var opted = $(this).attr('id') === 'jetstrike-telemetry-yes' ? 'yes' : 'no';
                $.post(ajaxurl, {
                    action: 'jetstrike_cd_telemetry_opt',
                    nonce: '<?php echo esc_js(wp_create_nonce('jetstrike_cd_telemetry')); ?>',
                    opted: opted
                });
                $('#jetstrike-telemetry-notice').fadeOut();
            });
        });
        </script>
        <?php
    }

    /**
     * Handle telemetry opt-in/out AJAX.
     */
    public function handle_opt_in(): void {
        check_ajax_referer('jetstrike_cd_telemetry', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $opted = sanitize_text_field($_POST['opted'] ?? 'no');
        $value = $opted === 'yes' ? 'yes' : 'no';

        update_option(self::OPT_IN_KEY, $value);

        wp_send_json_success();
    }
}
