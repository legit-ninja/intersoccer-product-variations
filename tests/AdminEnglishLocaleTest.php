<?php
/**
 * Admin English locale helper tests.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return '[t]' . $text;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        $value = array_shift($args);
        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() {
        return !empty($GLOBALS['intersoccer_test_doing_ajax']);
    }
}

if (!function_exists('is_locale_switched')) {
    function is_locale_switched() {
        return !empty($GLOBALS['intersoccer_test_locale_switched']);
    }
}

if (!function_exists('wp_get_raw_referer')) {
    function wp_get_raw_referer() {
        return $GLOBALS['intersoccer_test_referer'] ?? '';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

require_once dirname(__DIR__) . '/includes/admin-english-locale.php';

class AdminEnglishLocaleTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $GLOBALS['intersoccer_test_locale_switched'] = false;
        $GLOBALS['intersoccer_test_referer'] = '';
    }

    public function test_admin_screen_forces_english_gettext() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertTrue(intersoccer_pv_is_admin_english_context());
        $this->assertSame('Start Date', intersoccer_pv_filter_gettext('[t]Start Date', 'Start Date', 'intersoccer-product-variations'));
    }

    public function test_frontend_ajax_keeps_translations() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $GLOBALS['intersoccer_test_referer'] = 'https://example.test/fr/product/camp/';
        $this->assertFalse(intersoccer_pv_is_admin_english_context());
        $this->assertSame('[t]Start Date', intersoccer_pv_filter_gettext('[t]Start Date', 'Start Date', 'intersoccer-product-variations'));
    }

    public function test_switched_locale_keeps_translations_in_admin() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_locale_switched'] = true;
        $this->assertFalse(intersoccer_pv_is_admin_english_context());
    }

    public function test_other_domains_untouched() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertSame('[t]Other', intersoccer_pv_filter_gettext('[t]Other', 'Other', 'woocommerce'));
    }
}
