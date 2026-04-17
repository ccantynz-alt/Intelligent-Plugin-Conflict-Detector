<?php
/**
 * PHPUnit bootstrap — stubs WordPress functions so unit tests run standalone.
 *
 * @package Jetstrike\ConflictDetector\Tests
 */

declare(strict_types=1);

// Autoloader.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Constants WordPress normally defines.
if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (! defined('WPMU_PLUGIN_DIR')) {
    define('WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/jetstrike-test-mu-plugins');
}
if (! defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', __DIR__ . '/fixtures');
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (! defined('FS_CHMOD_FILE')) {
    define('FS_CHMOD_FILE', 0644);
}
if (! defined('JETSTRIKE_CD_VERSION')) {
    define('JETSTRIKE_CD_VERSION', '1.0.0-test');
}
if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (! defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

/*
 * WordPress function stubs.
 *
 * These are minimal mocks that satisfy static analysis calls without
 * requiring a full WordPress installation.  They are intentionally
 * simple — tests that need more nuanced behaviour should use
 * PHPUnit mocking or override these in a setUp().
 */

// ── Options store (in-memory) ────────────────────────────────
if (! isset($GLOBALS['__wp_options'])) {
    $GLOBALS['__wp_options'] = [];
}

if (! function_exists('get_option')) {
    function get_option(string $key, $default = false) {
        return $GLOBALS['__wp_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool {
        $GLOBALS['__wp_options'][$key] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $key): bool {
        unset($GLOBALS['__wp_options'][$key]);
        return true;
    }
}

// ── Transients (in-memory) ────────────────────────────────────
if (! isset($GLOBALS['__wp_transients'])) {
    $GLOBALS['__wp_transients'] = [];
}

if (! function_exists('get_transient')) {
    function get_transient(string $key) {
        return $GLOBALS['__wp_transients'][$key] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool {
        $GLOBALS['__wp_transients'][$key] = $value;
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['__wp_transients'][$key]);
        return true;
    }
}

// ── Sanitisation / escaping stubs ──────────────────────────────
if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (! function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

// ── i18n stubs ──────────────────────────────────────────────────
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// ── Hooks stubs ──────────────────────────────────────────────────
if (! function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $args = 1): bool {
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool {
        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) {
        return $value;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {}
}

// ── Cron stubs ───────────────────────────────────────────────────
if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int {
        return 0;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        return true;
    }
}

// ── Misc stubs ───────────────────────────────────────────────────
if (! function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}

if (! function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string {
        return gmdate('Y-m-d H:i:s');
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.com' . $path;
    }
}

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        return $show === 'version' ? '6.7' : '';
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        return true;
    }
}

if (! function_exists('get_plugins')) {
    function get_plugins(): array {
        return [];
    }
}

if (! function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool {
        return true;
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array {
        return ['response' => ['code' => 200], 'body' => '{}'];
    }
}

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array {
        return ['response' => ['code' => 200], 'body' => ''];
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int {
        return $response['response']['code'] ?? 0;
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        return $response['body'] ?? '';
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return false;
    }
}

if (! function_exists('get_plugin_data')) {
    function get_plugin_data(string $plugin_file): array {
        return ['Name' => 'Test Plugin', 'Version' => '1.0.0'];
    }
}

if (! function_exists('human_time_diff')) {
    function human_time_diff(int $from, int $to = 0): string {
        return '5 mins';
    }
}

// ── WP_Filesystem stub ────────────────────────────────────────
if (! function_exists('WP_Filesystem')) {
    function WP_Filesystem(): bool {
        global $wp_filesystem;
        $wp_filesystem = null;
        return false;
    }
}

// ── Mock $wpdb ────────────────────────────────────────────────
$GLOBALS['wpdb'] = new class {
    public string $prefix = 'wp_';
    public string $last_query = '';
    public ?string $last_error = null;
    public int $insert_id = 1;
    public int $rows_affected = 1;

    /** @var array Configurable return values for tests. */
    public array $__mock_results = [];

    public function prepare(string $query, ...$args): string {
        if (empty($args)) {
            return $query;
        }
        $flat = [];
        foreach ($args as $a) {
            if (is_array($a)) {
                foreach ($a as $v) { $flat[] = $v; }
            } else {
                $flat[] = $a;
            }
        }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function () use (&$i, $flat) {
            return "'" . ($flat[$i++] ?? '') . "'";
        }, $query);
    }

    public function get_results(string $query, $output_type = OBJECT): array {
        return $this->__mock_results['get_results'] ?? [];
    }

    public function get_row(string $query, $output_type = OBJECT): ?object {
        return $this->__mock_results['get_row'] ?? null;
    }

    public function get_var(string $query) {
        return $this->__mock_results['get_var'] ?? '0';
    }

    public function insert(string $table, array $data, $format = null): bool {
        $this->insert_id++;
        return true;
    }

    public function update(string $table, array $data, array $where, $format = null, $where_format = null): bool {
        return true;
    }

    public function delete(string $table, array $where, $format = null): bool {
        return true;
    }

    public function query(string $query): bool {
        return true;
    }
};

/**
 * Reset the in-memory option/transient stores between tests.
 */
function jetstrike_test_reset_wp_state(): void {
    $GLOBALS['__wp_options'] = [];
    $GLOBALS['__wp_transients'] = [];
    $GLOBALS['wpdb']->__mock_results = [];
}
