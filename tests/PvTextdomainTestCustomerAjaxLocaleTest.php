<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'intersoccer-product-variations/intersoccer-product-variations.php';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        $base = 'https://example.test/wp-admin/';
        return $base . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    /**
     * @param array<string, string>|string $args
     * @param string|null                  $url
     * @return string
     */
    function add_query_arg($args, $url = null) {
        if (func_num_args() >= 3) {
            $key = $args;
            $value = func_get_arg(1);
            $url = func_get_arg(2);
            $args = [$key => $value];
        }
        if (!is_array($args) || $url === null) {
            return (string) $url;
        }
        if ($args === []) {
            return $url;
        }
        $glue = strpos($url, '?') === false ? '?' : '&';
        return $url . $glue . http_build_query($args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        $value = array_shift($args);
        if (class_exists('MockFilters') && isset(MockFilters::$filters[$hook]) && is_callable(MockFilters::$filters[$hook])) {
            array_unshift($args, $value);
            return call_user_func_array(MockFilters::$filters[$hook], $args);
        }
        if ($hook === 'plugin_locale') {
            return $value;
        }
        return $value;
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return $GLOBALS['intersoccer_test_locale'] ?? 'en_US';
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return determine_locale();
    }
}

if (!function_exists('is_textdomain_loaded')) {
    function is_textdomain_loaded($domain) {
        return !empty($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
    }
}

if (!function_exists('unload_textdomain')) {
    function unload_textdomain($domain, $reloadable = false) {
        unset($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated, $path) {
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = true;
        return true;
    }
}

if (!function_exists('load_textdomain')) {
    function load_textdomain($domain, $mofile, $locale = null) {
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = true;
        return true;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['intersoccer_test_doing_ajax']);
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('has_action')) {
    function has_action($hook) {
        return !empty($GLOBALS['intersoccer_test_has_action'][$hook]);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if ($hook === 'wpml_switch_language' && isset($args[0])) {
            $GLOBALS['intersoccer_test_wpml_switched'] = $args[0];
        }
    }
}

class PvTextdomainTestCustomerAjaxLocaleTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_pv_customer_script_localize_base')) {
            require_once dirname(__DIR__) . '/includes/pv-textdomain.php';
        }
        if (!function_exists('intersoccer_pv_is_admin_english_context')) {
            require_once dirname(__DIR__) . '/includes/admin-english-locale.php';
        }
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        $GLOBALS['intersoccer_test_textdomain_loaded'] = [];
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_has_action'] = ['wpml_switch_language' => true];
        unset($GLOBALS['intersoccer_pv_textdomain_locale'], $GLOBALS['intersoccer_test_wpml_switched']);
        unset($_REQUEST['lang']);
    }

    protected function tearDown(): void {
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        parent::tearDown();
    }

    public function test_customer_localize_includes_lang_and_query_arg_when_wpml_active(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };

        $data = intersoccer_pv_customer_script_localize_base(true);

        $this->assertSame('fr', $data['lang']);
        $this->assertStringContainsString('lang=fr', $data['ajax_url']);
        $this->assertStringContainsString('lang=fr', $data['nonce_refresh_url']);
        $this->assertStringContainsString('action=intersoccer_refresh_nonce', $data['nonce_refresh_url']);
    }

    public function test_customer_localize_omits_lang_when_wpml_absent(): void {
        $data = intersoccer_pv_customer_script_localize_base(true);

        $this->assertArrayNotHasKey('lang', $data);
        $this->assertStringNotContainsString('lang=', $data['ajax_url']);
        $this->assertStringNotContainsString('lang=', $data['nonce_refresh_url']);
    }

    public function test_admin_localize_path_omits_wpml_lang(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'de';
        };

        $data = intersoccer_pv_customer_script_localize_base(false);

        $this->assertArrayNotHasKey('lang', $data);
        $this->assertStringNotContainsString('lang=', $data['ajax_url']);
    }

    public function test_main_plugin_product_localize_uses_customer_ajax_helper(): void {
        $main = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
        $this->assertStringContainsString('intersoccer_pv_customer_script_localize_base(true)', $main);
        $this->assertStringContainsString('intersoccer_pv_admin_ajax_url()', $main);
    }

    public function test_admin_program_manager_localize_uses_plain_admin_ajax(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/program-manager.php');
        $this->assertStringContainsString("'ajax_url'                 => admin_url('admin-ajax.php')", $src);
        $this->assertStringNotContainsString('intersoccer_pv_admin_ajax_url', $src);
    }

    public function test_bootstrap_switches_wpml_on_customer_ajax_with_valid_lang(): void {
        $_REQUEST['lang'] = 'fr';
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $_SERVER['HTTP_REFERER'] = 'https://example.test/fr/produit/';

        intersoccer_pv_bootstrap_customer_ajax_language();

        $this->assertSame('fr_FR', $GLOBALS['intersoccer_pv_textdomain_locale']);
    }

    public function test_bootstrap_ignores_invalid_lang_value(): void {
        $_REQUEST['lang'] = '../../../etc/passwd';
        $GLOBALS['intersoccer_test_doing_ajax'] = true;

        intersoccer_pv_bootstrap_customer_ajax_language();

        $this->assertFalse(isset($GLOBALS['intersoccer_test_wpml_switched']));
    }
}
