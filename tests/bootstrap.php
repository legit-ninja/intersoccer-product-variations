<?php
// WordPress plugin files guard on ABSPATH; define before loading includes.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Lightweight filter mock to support apply_filters in tests
if (!class_exists('MockFilters')) {
    class MockFilters {
        public static $filters = [];

        public static function reset() {
            self::$filters = [];
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        $hook = array_shift($args);
        $value = array_shift($args);

        if (class_exists('MockFilters') && isset(MockFilters::$filters[$hook]) && is_callable(MockFilters::$filters[$hook])) {
            array_unshift($args, $value);
            return call_user_func_array(MockFilters::$filters[$hook], $args);
        }

        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        if (class_exists('MockFilters')) {
            MockFilters::$filters[$hook] = $callback;
        }
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return is_string($value) ? trim(strip_tags($value)) : $value;
    }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('intersoccer_debug')) {
    function intersoccer_debug($message) {
        return;
    }
}

if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy, $args = []) {
        return [];
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return is_object($thing) && (is_a($thing, 'WP_Error') || (isset($thing->errors) && is_array($thing->errors)));
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                if ($data !== '') {
                    $this->error_data[$code] = $data;
                }
            }
        }
    }
}


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

if (!function_exists('update_postmeta_cache')) {
    function update_postmeta_cache($post_ids, $force = true) {
        // No-op for tests; metadata stored in MockMetaData already.
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        // Mock implementation
        return true;
    }
}
if (!function_exists('get_post_field')) {
    function get_post_field($field, $post = null, $context = 'display') {
        return '';
    }
}

if (!function_exists('get_the_terms')) {
    function get_the_terms($post, $taxonomy) {
        return false;
    }
}

if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field, $index_key = null) {
        return [];
    }
}
