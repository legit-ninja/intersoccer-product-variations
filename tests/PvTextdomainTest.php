<?php

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'intersoccer-product-variations/intersoccer-product-variations.php';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        $value = array_shift($args);
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

if (!function_exists('pv_textdomain_test_add_action')) {
    function pv_textdomain_test_add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['intersoccer_pv_textdomain_test_actions'][$hook][$priority][] = [
            'cb' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('pv_textdomain_test_do_action')) {
    function pv_textdomain_test_do_action($hook, ...$args) {
        if (empty($GLOBALS['intersoccer_pv_textdomain_test_actions'][$hook])) {
            return;
        }
        ksort($GLOBALS['intersoccer_pv_textdomain_test_actions'][$hook]);
        foreach ($GLOBALS['intersoccer_pv_textdomain_test_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $item) {
                call_user_func_array($item['cb'], array_slice($args, 0, (int) $item['accepted_args']));
            }
        }
    }
}

if (!function_exists('has_action')) {
    function has_action($hook) {
        return !empty($GLOBALS['intersoccer_pv_textdomain_test_actions'][$hook]);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (function_exists('pv_textdomain_test_do_action')) {
            pv_textdomain_test_do_action($hook, ...$args);
        }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_meta($key, $single = true) {
            return '';
        }
    }
}

class PvTextdomainTest extends TestCase {
    /**
     * @return array<string,string>
     */
    public static function readMoEntries(string $path): array {
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 28) {
            return [];
        }
        $magic = unpack('V', substr($data, 0, 4))[1];
        $unpack = $magic === 0xde120495 ? 'N' : 'V';
        $count = unpack($unpack, substr($data, 4 + 4, 4))[1];
        $orig_off = unpack($unpack, substr($data, 4 + 4 + 4, 4))[1];
        $trans_off = unpack($unpack, substr($data, 4 + 4 + 4 + 4, 4))[1];
        $read_string = static function (int $offset) use ($data, $unpack): string {
            $len = unpack($unpack, substr($data, $offset, 4))[1];
            $str_off = unpack($unpack, substr($data, $offset + 4, 4))[1];
            return substr($data, $str_off, $len);
        };
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $orig = $read_string($orig_off + $i * 8);
            $trans = $read_string($trans_off + $i * 8);
            if ($orig !== '') {
                $entries[$orig] = $trans;
            }
        }
        return $entries;
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_pv_textdomain_locale'],
            $GLOBALS['intersoccer_pv_test_determine_locale'],
            $GLOBALS['intersoccer_sensitive_order_meta_prev_locale'],
            $GLOBALS['intersoccer_sensitive_order_meta_email_depth']
        );
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        parent::tearDown();
    }

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_pv_load_textdomain_for_current_locale')) {
            require_once dirname(__DIR__) . '/includes/pv-textdomain.php';
        }
        if (!function_exists('intersoccer_pv_filter_gettext')) {
            require_once dirname(__DIR__) . '/includes/admin-english-locale.php';
        }
        $GLOBALS['intersoccer_test_textdomain_loaded'] = [];
        $GLOBALS['intersoccer_test_textdomain_unload_reloadable'] = [];
        $GLOBALS['intersoccer_test_mo_translations'] = [];
        $GLOBALS['intersoccer_pv_textdomain_test_actions'] = [];
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
        $this->assertStringNotContainsString("add_action('plugins_loaded'", $src);
        $this->assertStringContainsString('unload_textdomain($domain, true)', $src);
        $this->assertStringContainsString('load_textdomain($domain, $mofile, $locale)', $src);
    }

    public function test_reloadable_unload_after_simulated_early_jit_load(): void {
        $domain = 'intersoccer-product-variations';
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = ['via' => 'jit'];
        $GLOBALS['intersoccer_pv_textdomain_locale'] = 'en_US';
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('fr_FR'));
        $this->assertTrue($GLOBALS['intersoccer_test_textdomain_unload_reloadable'][$domain]);
        $loaded = $GLOBALS['intersoccer_test_textdomain_loaded'][$domain];
        $this->assertSame('load_textdomain', $loaded['via']);
        $this->assertStringEndsWith('intersoccer-product-variations-fr_FR.mo', $loaded['mofile']);
    }

    public function test_french_mo_string_resolves_after_reload(): void {
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('fr_FR'));
        $loaded = $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations'];
        $entries = self::readMoEntries($loaded['mofile']);
        $this->assertSame('Remarque', $entries['Note']);
    }

    public function test_german_mo_string_resolves_after_reload(): void {
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('de_DE'));
        $loaded = $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations'];
        $entries = self::readMoEntries($loaded['mofile']);
        $this->assertSame('Hinweis', $entries['Note']);
    }

    public function test_wpml_language_has_switched_reloads_textdomain_for_de_de(): void {
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('fr_FR'));
        $this->assertStringEndsWith('fr_FR.mo', $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations']['mofile']);
        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'de_DE';
        do_action('wpml_language_has_switched');
        $this->assertSame('de_DE', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $this->assertStringEndsWith('de_DE.mo', $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations']['mofile']);
    }

    public function test_explicit_locale_argument_used_not_only_determine_locale(): void {
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('de_DE'));
        $this->assertSame('de_DE', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $this->assertStringEndsWith('de_DE.mo', $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations']['mofile']);
    }

    public function test_admin_english_still_forces_english_after_reload(): void {
        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        intersoccer_pv_load_textdomain_for_current_locale('de_DE');
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
        intersoccer_pv_load_textdomain_for_current_locale('en_US');

        intersoccer_sensitive_order_meta_begin_email_context($order, false, true, null);
        $this->assertSame('fr_FR', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $loaded = $GLOBALS['intersoccer_test_textdomain_loaded']['intersoccer-product-variations'];
        $this->assertStringEndsWith('fr_FR.mo', $loaded['mofile']);
        intersoccer_sensitive_order_meta_end_email_context($order, false, true, null);
        $this->assertSame('en_US', $GLOBALS['intersoccer_pv_textdomain_locale']);
    }
}
