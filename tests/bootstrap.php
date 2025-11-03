<?php
// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Load the plugin files we need for testing
require_once dirname(__DIR__) . '/includes/woocommerce/product-course.php';

// Define basic WordPress functions that our tests need
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        // Use shared mock data if available
        if (class_exists('MockMetaData')) {
            return MockMetaData::$data[$post_id][$key] ?? null;
        }
        // Mock implementation - in real tests, this would be mocked
        return null;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        // Use shared mock data if available
        if (class_exists('MockMetaData')) {
            MockMetaData::$data[$post_id][$key] = $value;
            return true;
        }
        // Mock implementation
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        // Mock implementation
        return true;
    }
}