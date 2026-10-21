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
    function unload_textdomain($domain) {
        unset($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated, $path) {
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = [
            'path' => $path,
            'locale' => determine_locale(),
        ];
        return true;
    }
}

if (!function_exists('switch_to_locale')) {
    function switch_to_locale($locale) {
        $GLOBALS['intersoccer_test_locale'] = $locale;
        return true;
    }
}

if (!function_exists('restore_previous_locale')) {
    function restore_previous_locale() {
        $GLOBALS['intersoccer_test_locale'] = $GLOBALS['intersoccer_test_locale_restore'] ?? 'en_US';
        return true;
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_meta($key) {
            return '';
        }
    }
}

class PvTextdomainTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_pv_load_textdomain_for_current_locale')) {
            require_once dirname(__DIR__) . '/includes/pv-textdomain.php';
        }
        if (!function_exists('intersoccer_pv_filter_gettext')) {
            require_once dirname(__DIR__) . '/includes/admin-english-locale.php';
        }
        $GLOBALS['intersoccer_test_textdomain_loaded'] = [];
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        unset($GLOBALS['intersoccer_pv_textdomain_locale']);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $GLOBALS['intersoccer_test_locale_switched'] = false;
    }

    public function test_main_plugin_defers_textdomain_to_pv_textdomain_helper(): void {
        $main = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
        $this->assertStringContainsString("require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'includes/pv-textdomain.php';", $main);
        $this->assertStringNotContainsString("add_action('plugins_loaded', function () {\n    load_plugin_textdomain", $main);
    }

    public function test_textdomain_loader_registered_on_init_not_plugins_loaded(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/pv-textdomain.php');
        $this->assertStringContainsString("add_action('init', 'intersoccer_pv_load_textdomain_for_current_locale', 20);", $src);
        $this->assertStringContainsString("add_action('wpml_language_has_switched', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);", $src);
        $this->assertStringContainsString("add_action('change_locale', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);", $src);
        $this->assertStringNotContainsString('plugins_loaded', $src);
    }

    public function test_helper_loads_domain_for_current_locale(): void {
        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale());
        $this->assertSame('de_DE', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $this->assertNotEmpty($GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations']);
    }

    public function test_helper_reloads_when_locale_changes_after_wpml_switch(): void {
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        $GLOBALS['intersoccer_test_locale'] = 'fr_FR';
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'Start Date') {
                    return 'Date de début';
                }
                return $translation;
            };
        }
        intersoccer_pv_load_textdomain_for_current_locale();
        $this->assertSame('Date de début', __('Start Date', 'intersoccer-product-variations'));

        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'Start Date') {
                    return 'Startdatum';
                }
                return $translation;
            };
        }
        intersoccer_pv_load_textdomain_for_current_locale();
        $this->assertSame('de_DE', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $this->assertSame('Startdatum', __('Start Date', 'intersoccer-product-variations'));
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_admin_english_still_forces_english_after_reload(): void {
        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        intersoccer_pv_load_textdomain_for_current_locale();
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->assertSame(
            'Start Date',
            intersoccer_pv_filter_gettext('Startdatum', 'Start Date', 'intersoccer-product-variations')
        );
    }

    public function test_email_locale_path_reloads_textdomain_via_helper(): void {
        if (!function_exists('intersoccer_sensitive_order_meta_begin_email_context')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
        }
        $order = new class extends WC_Order {
            public function get_meta($key, $single = true) {
                return $key === 'wpml_language' ? 'fr' : '';
            }
        };
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $GLOBALS['intersoccer_test_locale_restore'] = 'en_US';
        intersoccer_pv_load_textdomain_for_current_locale();

        intersoccer_sensitive_order_meta_begin_email_context($order, false, true, null);
        $this->assertSame('fr_FR', $GLOBALS['intersoccer_pv_textdomain_locale']);
        intersoccer_sensitive_order_meta_end_email_context($order, false, true, null);
        $this->assertSame('en_US', $GLOBALS['intersoccer_pv_textdomain_locale']);
    }
}
