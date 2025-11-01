<?php
// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Load the plugin files we need for testing
require_once dirname(__DIR__) . '/includes/woocommerce/product-course.php';

// Define basic WordPress functions that our tests need
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        // Mock implementation - in real tests, this would be mocked
        return null;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        // Mock implementation
        return true;
    }
}