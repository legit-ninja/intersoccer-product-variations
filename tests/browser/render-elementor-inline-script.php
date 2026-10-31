<?php
/**
 * Render the real Elementor product-page inline script for a variable camp product (stdout).
 * Used by tests/browser/atc-stash-flow.mjs — not part of PHPUnit.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/language-helpers.php';

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = [])
    {
        return (string) $string;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        if (!is_array($args)) {
            $args = [];
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://pv-test.local/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'http://pv-test.local/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'test-nonce';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

if (!function_exists('esc_js')) {
    function esc_js($text)
    {
        return addslashes((string) $text);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default')
    {
        echo esc_html($text);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return $default;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        return $single ? '' : [];
    }
}

if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($product_id, $taxonomy, $args = [])
    {
        return [];
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product
    {
        protected $id;

        public function __construct($id = 0)
        {
            $this->id = (int) $id;
        }

        public function get_id()
        {
            return $this->id;
        }

        public function is_type($type)
        {
            return false;
        }

        public function get_attributes()
        {
            return [];
        }

        public function get_available_variations()
        {
            return [];
        }
    }
}

if (!class_exists('WC_Product_Variable')) {
    class WC_Product_Variable extends WC_Product
    {
        public function is_type($type)
        {
            return $type === 'variable';
        }

        public function get_available_variations()
        {
            return [
                [
                    'variation_id' => 501,
                    'attributes' => ['attribute_pa_booking-type' => 'full-week'],
                ],
            ];
        }
    }
}

if (!function_exists('intersoccer_variation_allows_late_pickup')) {
    function intersoccer_variation_allows_late_pickup($variation_id)
    {
        return false;
    }
}

$GLOBALS['intersoccer_test_product_type'] = 'camp';
$GLOBALS['intersoccer_test_user_id'] = 7;

global $product;
$product = new WC_Product_Variable(9001);

ob_start();
require dirname(__DIR__, 2) . '/includes/elementor-widgets.php';
if (!isset($intersoccer_elementor_product_page_cb) || !is_callable($intersoccer_elementor_product_page_cb)) {
    fwrite(STDERR, "Elementor callback not defined\n");
    exit(1);
}
call_user_func($intersoccer_elementor_product_page_cb);
$html = ob_get_clean();

if (!preg_match('#<script>\s*(.*?)\s*</script>#s', $html, $m)) {
    fwrite(STDERR, "No inline script found in rendered output\n");
    exit(1);
}

echo $m[1];
