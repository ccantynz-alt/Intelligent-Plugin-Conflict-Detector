<?php
/**
 * Plugin Name: Test Plugin B
 */

function jetstrike_test_duplicate_func() {
    return 'from plugin B';
}

class Jetstrike_Test_Shared_Class {
    public function execute() {}
}

global $jetstrike_test_shared_global;
$jetstrike_test_shared_global = 'b';

add_action('init', 'jetstrike_test_init_b', 10);
add_action('wp_head', 'jetstrike_test_head_b', 10);
add_filter('the_content', 'jetstrike_test_filter_b', 10);

function jetstrike_test_init_b() {}
function jetstrike_test_head_b() {}
function jetstrike_test_filter_b($content) { return $content; }

wp_enqueue_script('shared-handle', '/js/b.js');
wp_enqueue_style('shared-style', '/css/b.css');
