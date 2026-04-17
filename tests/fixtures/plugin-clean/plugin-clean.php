<?php
/**
 * Plugin Name: Clean Plugin
 */

function jetstrike_test_unique_function_xyz() {
    return 'unique';
}

add_action('init', 'jetstrike_test_unique_init', 20);
function jetstrike_test_unique_init() {}

wp_enqueue_script('unique-handle-xyz', '/js/clean.js');
