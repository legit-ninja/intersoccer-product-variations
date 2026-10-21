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
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';

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
        $GLOBALS['wp_locale_switcher'] = new stdClass();
        $this->assertFalse(intersoccer_pv_is_admin_english_context());
        unset($GLOBALS['wp_locale_switcher']);
    }

    public function test_other_domains_untouched() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertSame('[t]Other', intersoccer_pv_filter_gettext('[t]Other', 'Other', 'woocommerce'));
    }

    public function test_admin_player_gender_label_avoids_player_management_gettext() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertSame('Male', intersoccer_pv_admin_format_player_gender_label('male'));
        $this->assertSame('Male', intersoccer_pv_admin_format_player_gender_label('Garçon'));
        $this->assertSame('Female', intersoccer_pv_admin_format_player_gender_label('female'));
    }

    public function test_gettext_filter_safe_without_locale_switcher() {
        unset($GLOBALS['wp_locale_switcher']);
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertTrue(intersoccer_pv_is_admin_english_context());
        $this->assertSame('Assigned Attendee', intersoccer_pv_filter_gettext('Participant', 'Assigned Attendee', 'intersoccer-product-variations'));
    }
}
