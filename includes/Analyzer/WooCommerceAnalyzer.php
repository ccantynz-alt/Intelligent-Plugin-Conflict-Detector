<?php
/**
 * WooCommerce-specific conflict analyzer.
 *
 * Understands WooCommerce hooks, template overrides, payment gateways,
 * checkout flow, cart calculations, and HPOS compatibility.
 *
 * @package Jetstrike\ConflictDetector
 */

declare(strict_types=1);

namespace Jetstrike\ConflictDetector\Analyzer;

final class WooCommerceAnalyzer {

    /**
     * Critical WooCommerce hooks where conflicts cause revenue loss.
     *
     * @var array<string, string>
     */
    private const CRITICAL_WC_HOOKS = [
        'woocommerce_payment_gateways'              => 'Payment gateway registration',
        'woocommerce_checkout_process'               => 'Checkout validation',
        'woocommerce_checkout_order_processed'       => 'Order processing',
        'woocommerce_thankyou'                       => 'Thank you page',
        'woocommerce_cart_calculate_fees'             => 'Cart fee calculation',
        'woocommerce_before_calculate_totals'        => 'Cart total calculation',
        'woocommerce_calculated_total'               => 'Final cart total',
        'woocommerce_add_to_cart_validation'         => 'Add-to-cart validation',
        'woocommerce_checkout_fields'                => 'Checkout field modification',
        'woocommerce_order_status_changed'           => 'Order status transitions',
        'woocommerce_payment_complete'               => 'Payment completion',
        'woocommerce_new_order'                      => 'New order creation',
        'woocommerce_process_shop_order_meta'        => 'Order meta processing',
        'woocommerce_email_classes'                  => 'Email class registration',
        'woocommerce_shipping_methods'               => 'Shipping method registration',
        'woocommerce_product_data_tabs'              => 'Product data tabs',
        'woocommerce_product_data_panels'            => 'Product data panels',
        'woocommerce_rest_api_get_rest_namespaces'   => 'REST API namespaces',
    ];

    /**
     * WooCommerce template files that are commonly overridden.
     *
     * @var array<string>
     */
    private const TEMPLATE_FILES = [
        'cart/cart.php',
        'cart/mini-cart.php',
        'checkout/form-checkout.php',
        'checkout/form-billing.php',
        'checkout/form-shipping.php',
        'checkout/payment.php',
        'checkout/thankyou.php',
        'checkout/review-order.php',
        'single-product/add-to-cart/simple.php',
        'single-product/add-to-cart/variable.php',
        'single-product/price.php',
        'single-product/title.php',
        'loop/add-to-cart.php',
        'myaccount/my-account.php',
        'myaccount/orders.php',
        'emails/email-header.php',
        'emails/email-footer.php',
    ];

    /**
     * Run WooCommerce-specific conflict analysis.
     *
     * @param array<string> $plugin_files Active plugin file paths.
     * @return array<array{type: string, plugin_a: string, plugin_b: string, severity: string, description: string, details: array}>
     */
    public function analyze(array $plugin_files): array {
        // Only run if WooCommerce is active.
        if (! class_exists('WooCommerce')) {
            return [];
        }

        $conflicts = [];
        $wc_data = [];

        foreach ($plugin_files as $plugin_file) {
            $plugin_dir = $this->get_plugin_directory($plugin_file);

            if ($plugin_dir === null) {
                continue;
            }

            $wc_data[$plugin_file] = $this->extract_wc_usage($plugin_dir);
        }

        $plugin_list = array_keys($wc_data);
        $count = count($plugin_list);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $plugin_list[$i];
                $b = $plugin_list[$j];

                $conflicts = array_merge(
                    $conflicts,
                    $this->find_wc_hook_conflicts($a, $wc_data[$a], $b, $wc_data[$b]),
                    $this->find_template_override_conflicts($a, $wc_data[$a], $b, $wc_data[$b]),
                    $this->find_checkout_field_conflicts($a, $wc_data[$a], $b, $wc_data[$b]),
                    $this->find_cart_calculation_conflicts($a, $wc_data[$a], $b, $wc_data[$b])
                );
            }
        }

        // HPOS compatibility check (per-plugin, not pairwise).
        foreach ($plugin_list as $plugin_file) {
            $hpos_conflicts = $this->check_hpos_compatibility($plugin_file, $wc_data[$plugin_file]);
            $conflicts = array_merge($conflicts, $hpos_conflicts);
        }

        return $conflicts;
    }

    /**
     * Extract WooCommerce-specific usage from a plugin.
     *
     * @param string $directory Plugin directory.
     * @return array WooCommerce usage data.
     */
    private function extract_wc_usage(string $directory): array {
        $result = [
            'wc_hooks'         => [],
            'template_overrides' => [],
            'checkout_fields'  => [],
            'cart_hooks'       => [],
            'payment_gateways' => [],
            'uses_direct_sql'  => false,
            'uses_order_meta'  => false,
            'uses_post_meta_orders' => false,
        ];

        $files = $this->get_php_files($directory);

        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false || strlen($source) > 1048576) {
                continue;
            }

            $relative = str_replace($directory . '/', '', $file);

            // WooCommerce hook registrations.
            if (preg_match_all(
                '/add_(?:action|filter)\s*\(\s*[\'"](' . implode('|', array_map('preg_quote', array_keys(self::CRITICAL_WC_HOOKS))) . ')[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['wc_hooks'][] = [
                        'hook'     => $match[1],
                        'callback' => trim($match[2], '\'" '),
                        'priority' => isset($match[3]) ? (int) $match[3] : 10,
                        'file'     => $relative,
                    ];
                }
            }

            // Cart calculation hooks.
            if (preg_match_all(
                '/add_(?:action|filter)\s*\(\s*[\'"](woocommerce_(?:before_calculate_totals|cart_calculate_fees|calculated_total|cart_totals_.*|after_calculate_totals))[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['cart_hooks'][] = [
                        'hook'     => $match[1],
                        'callback' => trim($match[2], '\'" '),
                        'priority' => isset($match[3]) ? (int) $match[3] : 10,
                        'file'     => $relative,
                    ];
                }
            }

            // Checkout field modifications.
            if (preg_match_all(
                '/add_filter\s*\(\s*[\'"](woocommerce_checkout_fields|woocommerce_billing_fields|woocommerce_shipping_fields)[\'"]\s*,\s*(.+?)(?:\s*,\s*(\d+))?\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $match) {
                    $result['checkout_fields'][] = [
                        'hook'     => $match[1],
                        'callback' => trim($match[2], '\'" '),
                        'priority' => isset($match[3]) ? (int) $match[3] : 10,
                        'file'     => $relative,
                    ];
                }
            }

            // Payment gateway registration.
            if (preg_match('/add_filter\s*\(\s*[\'"]woocommerce_payment_gateways[\'"]/', $source)) {
                $result['payment_gateways'][] = $relative;
            }

            // Direct SQL queries on orders (HPOS incompatible pattern).
            if (preg_match('/\$wpdb.*(?:posts|postmeta).*(?:shop_order|wc_order)/', $source) ||
                preg_match('/post_type\s*=\s*[\'"]shop_order[\'"]/', $source)) {
                $result['uses_direct_sql'] = true;
            }

            // get_post_meta for order data (HPOS incompatible).
            if (preg_match('/get_post_meta\s*\(.*(?:order|_billing|_shipping|_payment|_transaction)/', $source)) {
                $result['uses_post_meta_orders'] = true;
            }

            // Proper order meta API usage.
            if (preg_match('/\$order\s*->\s*(?:get_meta|update_meta_data|add_meta_data)/', $source)) {
                $result['uses_order_meta'] = true;
            }
        }

        // Check for WooCommerce template overrides in the plugin's templates directory.
        $template_dirs = [
            $directory . '/templates/woocommerce',
            $directory . '/woocommerce',
        ];

        foreach ($template_dirs as $template_dir) {
            if (is_dir($template_dir)) {
                $result['template_overrides'] = array_merge(
                    $result['template_overrides'],
                    $this->find_template_overrides($template_dir)
                );
            }
        }

        return $result;
    }

    /**
     * Find WooCommerce hook conflicts between two plugins.
     */
    private function find_wc_hook_conflicts(string $a, array $data_a, string $b, array $data_b): array {
        $conflicts = [];

        // Check for same critical hook at same priority.
        foreach ($data_a['wc_hooks'] as $hook_a) {
            foreach ($data_b['wc_hooks'] as $hook_b) {
                if ($hook_a['hook'] !== $hook_b['hook']) {
                    continue;
                }

                $hook_desc = self::CRITICAL_WC_HOOKS[$hook_a['hook']] ?? $hook_a['hook'];

                if ($hook_a['priority'] === $hook_b['priority']) {
                    $conflicts[] = [
                        'type'        => 'hook_conflict',
                        'plugin_a'    => $a,
                        'plugin_b'    => $b,
                        'severity'    => 'critical',
                        'description' => sprintf(
                            'Both plugins hook into WooCommerce "%s" (%s) at priority %d. This can corrupt checkout/payment flow.',
                            $hook_a['hook'],
                            $hook_desc,
                            $hook_a['priority']
                        ),
                        'details' => [
                            'hook_name'  => $hook_a['hook'],
                            'hook_desc'  => $hook_desc,
                            'priority'   => $hook_a['priority'],
                            'woocommerce' => true,
                        ],
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Find template override conflicts (both plugins override the same WC template).
     */
    private function find_template_override_conflicts(string $a, array $data_a, string $b, array $data_b): array {
        $conflicts = [];

        $templates_a = $data_a['template_overrides'];
        $templates_b = $data_b['template_overrides'];

        $collisions = array_intersect($templates_a, $templates_b);

        foreach ($collisions as $template) {
            $is_checkout = strpos($template, 'checkout/') === 0 || strpos($template, 'cart/') === 0;

            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $a,
                'plugin_b'    => $b,
                'severity'    => $is_checkout ? 'critical' : 'high',
                'description' => sprintf(
                    'Both plugins override the WooCommerce template "%s". Only one override will be loaded, causing unpredictable behavior.',
                    $template
                ),
                'details' => [
                    'template'     => $template,
                    'is_checkout'  => $is_checkout,
                    'woocommerce'  => true,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find checkout field modification conflicts.
     */
    private function find_checkout_field_conflicts(string $a, array $data_a, string $b, array $data_b): array {
        $conflicts = [];

        if (empty($data_a['checkout_fields']) || empty($data_b['checkout_fields'])) {
            return [];
        }

        // Both modify the same checkout field filter.
        foreach ($data_a['checkout_fields'] as $field_a) {
            foreach ($data_b['checkout_fields'] as $field_b) {
                if ($field_a['hook'] !== $field_b['hook']) {
                    continue;
                }

                $conflicts[] = [
                    'type'        => 'hook_conflict',
                    'plugin_a'    => $a,
                    'plugin_b'    => $b,
                    'severity'    => 'high',
                    'description' => sprintf(
                        'Both plugins modify WooCommerce checkout fields via "%s". Fields may be duplicated, removed, or corrupted.',
                        $field_a['hook']
                    ),
                    'details' => [
                        'hook_name'   => $field_a['hook'],
                        'priority_a'  => $field_a['priority'],
                        'priority_b'  => $field_b['priority'],
                        'woocommerce' => true,
                    ],
                ];

                break; // One conflict per hook pair is enough.
            }
        }

        return $conflicts;
    }

    /**
     * Find cart calculation conflicts.
     */
    private function find_cart_calculation_conflicts(string $a, array $data_a, string $b, array $data_b): array {
        $conflicts = [];

        if (empty($data_a['cart_hooks']) || empty($data_b['cart_hooks'])) {
            return [];
        }

        foreach ($data_a['cart_hooks'] as $hook_a) {
            foreach ($data_b['cart_hooks'] as $hook_b) {
                if ($hook_a['hook'] !== $hook_b['hook']) {
                    continue;
                }

                $conflicts[] = [
                    'type'        => 'hook_conflict',
                    'plugin_a'    => $a,
                    'plugin_b'    => $b,
                    'severity'    => 'critical',
                    'description' => sprintf(
                        'Both plugins modify WooCommerce cart calculations via "%s". Cart totals may be incorrect.',
                        $hook_a['hook']
                    ),
                    'details' => [
                        'hook_name'   => $hook_a['hook'],
                        'priority_a'  => $hook_a['priority'],
                        'priority_b'  => $hook_b['priority'],
                        'woocommerce' => true,
                        'revenue_risk' => true,
                    ],
                ];

                break;
            }
        }

        return $conflicts;
    }

    /**
     * Check HPOS (High-Performance Order Storage) compatibility.
     */
    private function check_hpos_compatibility(string $plugin_file, array $data): array {
        $conflicts = [];

        if ($data['uses_direct_sql'] || $data['uses_post_meta_orders']) {
            $severity = $data['uses_direct_sql'] ? 'high' : 'medium';

            $conflicts[] = [
                'type'        => 'resource_collision',
                'plugin_a'    => $plugin_file,
                'plugin_b'    => '',
                'severity'    => $severity,
                'description' => sprintf(
                    'Plugin uses %s to access order data, which is incompatible with WooCommerce HPOS (High-Performance Order Storage). Orders may be lost or corrupted when HPOS is enabled.',
                    $data['uses_direct_sql'] ? 'direct SQL queries on wp_posts/wp_postmeta' : 'get_post_meta() for order data'
                ),
                'details' => [
                    'uses_direct_sql'       => $data['uses_direct_sql'],
                    'uses_post_meta_orders' => $data['uses_post_meta_orders'],
                    'uses_order_meta_api'   => $data['uses_order_meta'],
                    'woocommerce'           => true,
                    'hpos'                  => true,
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * Find WooCommerce template overrides in a directory.
     */
    private function find_template_overrides(string $directory): array {
        $overrides = [];

        foreach (self::TEMPLATE_FILES as $template) {
            if (file_exists($directory . '/' . $template)) {
                $overrides[] = $template;
            }
        }

        return $overrides;
    }

    /**
     * Get plugin directory path.
     */
    private function get_plugin_directory(string $plugin_file): ?string {
        $dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Get all PHP files recursively.
     */
    private function get_php_files(string $directory): array {
        $files = [];
        $skip = ['vendor', 'node_modules', '.git', 'tests'];

        try {
            $iterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new \RecursiveCallbackFilterIterator(
                $iterator,
                fn(\SplFileInfo $current) => $current->isDir()
                    ? ! in_array($current->getFilename(), $skip, true)
                    : $current->getExtension() === 'php'
            );

            foreach (new \RecursiveIteratorIterator($filter) as $file) {
                $files[] = $file->getPathname();
            }
        } catch (\Exception $e) {
            // Skip on error.
        }

        return $files;
    }
}
