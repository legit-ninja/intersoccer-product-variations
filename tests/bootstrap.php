<?php
// WordPress plugin files guard on ABSPATH; define before loading includes.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Load Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Shared meta store for tests (must include reset(); define before test files).
if (!class_exists('MockMetaData')) {
    class MockMetaData {
        public static $data = [];

        public static function reset(): void {
            self::$data = [];
        }
    }
}

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

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = (string) $string;
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        if (is_array($value)) {
            return array_map('sanitize_text_field', $value);
        }
        return is_string($value) ? trim(wp_strip_all_tags($value)) : $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

// Preserve underscores so attribute keys like pa_course-day match WooCommerce meta (attribute_pa_course-day).
if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback_title = '', $context = 'save') {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9_\-]+/', '-', $title);
        $title = trim($title, '-');
        if ($title === '' && $fallback_title !== '') {
            return $fallback_title;
        }
        return $title;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
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

if (!function_exists('intersoccer_info')) {
    function intersoccer_info($message, $context = []) {
        return;
    }
}

if (!function_exists('intersoccer_warning')) {
    function intersoccer_warning($message) {
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

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        $found = false;
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        return true;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        return false;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        return false;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        if ($nonce === '' || $nonce === null) {
            return false;
        }
        // Match AjaxHandlersTest + general test usage
        if ($nonce === 'valid_nonce' || $nonce === 'test-nonce' || $nonce === 'test_nonce') {
            return 1;
        }
        return false;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        if (!empty($GLOBALS['intersoccer_test_json_capture'])) {
            $GLOBALS['intersoccer_test_json'] = ['success' => true, 'data' => $data];
            if (class_exists('CourseInfoMockData')) {
                CourseInfoMockData::$ajax_response = $GLOBALS['intersoccer_test_json'];
            }
            throw new Exception('AJAX_EXIT');
        }
        return json_encode(['success' => true, 'data' => $data]);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        if (!empty($GLOBALS['intersoccer_test_json_capture'])) {
            $GLOBALS['intersoccer_test_json'] = ['success' => false, 'data' => $data];
            if (class_exists('CourseInfoMockData')) {
                CourseInfoMockData::$ajax_response = $GLOBALS['intersoccer_test_json'];
            }
            throw new Exception('AJAX_EXIT');
        }
        return json_encode(['success' => false, 'data' => $data]);
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return $GLOBALS['intersoccer_test_transients'][$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        $GLOBALS['intersoccer_test_transients'][$transient] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        unset($GLOBALS['intersoccer_test_transients'][$transient]);
        return true;
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false, $gmt = false) {
        if ($timestamp === false) {
            $timestamp = time();
        }
        return date($format, (int) $timestamp);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ($type === 'timestamp') {
            return time();
        }
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        // WordPress accepts PHP date format strings (e.g. 'Y-m-d').
        return date((string) $type);
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        $GLOBALS['intersoccer_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if (array_key_exists($option, $GLOBALS['intersoccer_test_options'] ?? [])) {
            return $GLOBALS['intersoccer_test_options'][$option];
        }
        // Match WordPress default when options table is absent in unit tests.
        if ($option === 'date_format') {
            return 'F j, Y';
        }
        return $default;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        unset($GLOBALS['intersoccer_test_options'][$option]);
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        return;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

// Test doubles for player enrichment (OrderMeta*). Prefer globals so suite load order cannot shadow mocks.
if (!function_exists('intersoccer_get_player_details')) {
    function intersoccer_get_player_details($user_id, $player_index) {
        if (isset($GLOBALS['intersoccer_test_player_details']) && is_array($GLOBALS['intersoccer_test_player_details'])) {
            return $GLOBALS['intersoccer_test_player_details'];
        }
        return [
            'first_name' => 'Unknown',
            'last_name' => 'Player',
            'name' => 'Unknown Player',
            'dob' => '',
            'gender' => '',
            'medical_conditions' => '',
            'player_id' => '',
        ];
    }
}

if (!function_exists('intersoccer_get_player_by_id')) {
    function intersoccer_get_player_by_id($user_id, $player_id) {
        if (isset($GLOBALS['intersoccer_test_player_by_id']) && is_callable($GLOBALS['intersoccer_test_player_by_id'])) {
            return call_user_func($GLOBALS['intersoccer_test_player_by_id'], $user_id, $player_id);
        }
        return null;
    }
}

if (!function_exists('intersoccer_get_player_by_index')) {
    function intersoccer_get_player_by_index($user_id, $player_index) {
        if (isset($GLOBALS['intersoccer_test_player_by_index']) && is_callable($GLOBALS['intersoccer_test_player_by_index'])) {
            return call_user_func($GLOBALS['intersoccer_test_player_by_index'], $user_id, $player_index);
        }
        return null;
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        $value = $GLOBALS['intersoccer_test_user_meta'][$user_id][$key] ?? ($single ? '' : []);
        if ($single) {
            return is_array($value) && $value === [] ? '' : $value;
        }
        return is_array($value) ? $value : [$value];
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) {
        $GLOBALS['intersoccer_test_user_meta'][$user_id][$key] = $value;
        return true;
    }
}

// Load the plugin files we need for testing
require_once dirname(__DIR__) . '/includes/woocommerce/product-course.php';

// Define basic WordPress functions that our tests need
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        if (class_exists('CourseInfoMockData') && isset(CourseInfoMockData::$post_meta[$post_id][$key])) {
            return CourseInfoMockData::$post_meta[$post_id][$key];
        }
        if (class_exists('MockMetaData') && isset(MockMetaData::$data[$post_id][$key])) {
            return MockMetaData::$data[$post_id][$key];
        }
        return $single ? '' : [];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        if (class_exists('MockMetaData')) {
            MockMetaData::$data[$post_id][$key] = $value;
            return true;
        }
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        if (class_exists('MockMetaData') && isset(MockMetaData::$data[$post_id][$key])) {
            unset(MockMetaData::$data[$post_id][$key]);
            return true;
        }
        return true;
    }
}

if (!function_exists('update_postmeta_cache')) {
    function update_postmeta_cache($post_ids, $force = true) {
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
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
