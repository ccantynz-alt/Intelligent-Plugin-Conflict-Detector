<?php
/**
 * Plugin Name: Test Plugin A
 */

function jetstrike_test_duplicate_func() {
    return 'from plugin A';
}

class Jetstrike_Test_Shared_Class {
    public function run() {}
}

global $jetstrike_test_shared_global;
$jetstrike_test_shared_global = 'a';

add_action('init', 'jetstrike_test_init_a', 10);
add_action('wp_head', 'jetstrike_test_head_a', 10);
add_filter('the_content', 'jetstrike_test_filter_a', 10);

function jetstrike_test_init_a() {}
function jetstrike_test_head_a() {}
function jetstrike_test_filter_a($content) { return $content; }

wp_enqueue_script('shared-handle', '/js/a.js');
wp_enqueue_style('shared-style', '/css/a.css');
