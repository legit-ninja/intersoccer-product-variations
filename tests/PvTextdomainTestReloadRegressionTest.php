<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Core-style textdomain reload regression (would fail d086a62 non-reloadable unload).
 *
 * Stubs in this file must load before other test files; @runInSeparateProcess runs this file alone.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

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
        if (class_exists('MockFilters') && isset(MockFilters::$filters[$hook]) && is_callable(MockFilters::$filters[$hook])) {
            array_unshift($args, $value);
            return call_user_func_array(MockFilters::$filters[$hook], $args);
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
        return !empty($GLOBALS['pv_td_l10n_loaded'][$domain])
            && empty($GLOBALS['pv_td_l10n_unloaded'][$domain]);
    }
}

if (!function_exists('unload_textdomain')) {
    function unload_textdomain($domain, $reloadable = false) {
        unset($GLOBALS['pv_td_l10n_loaded'][$domain]);
        if ($reloadable) {
            unset($GLOBALS['pv_td_l10n_unloaded'][$domain]);
        } else {
            $GLOBALS['pv_td_l10n_unloaded'][$domain] = true;
        }
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated, $path) {
        if (!empty($GLOBALS['pv_td_l10n_unloaded'][$domain])) {
            return false;
        }
        $GLOBALS['pv_td_l10n_loaded'][$domain] = ['via' => 'load_plugin_textdomain', 'path' => $path];
        return true;
    }
}

if (!function_exists('load_textdomain')) {
    function load_textdomain($domain, $mofile, $locale = null) {
        $entries = PvTextdomainTestReloadRegressionTest::readMoEntries($mofile);
        $GLOBALS['pv_td_translations'][$domain] = $entries;
        $GLOBALS['pv_td_l10n_loaded'][$domain] = [
            'via' => 'load_textdomain',
            'mofile' => $mofile,
            'locale' => $locale ?: determine_locale(),
        ];
        unset($GLOBALS['pv_td_l10n_unloaded'][$domain]);
        return true;
    }
}

if (!function_exists('pv_td_test_translate')) {
    function pv_td_test_translate($text, $domain = 'intersoccer-product-variations') {
        if (class_exists('MockFilters') && isset(MockFilters::$filters['gettext_' . $domain])) {
            return call_user_func(MockFilters::$filters['gettext_' . $domain], $text, $text, $domain);
        }
        $map = $GLOBALS['pv_td_translations'][$domain] ?? [];
        return $map[$text] ?? $text;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        if ($domain === 'intersoccer-product-variations') {
            if (!is_textdomain_loaded($domain)) {
                load_plugin_textdomain($domain, false, 'lang');
            }
            return pv_td_test_translate($text, $domain);
        }
        return $text;
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
        if (isset($GLOBALS['intersoccer_sensitive_order_meta_prev_locale'])) {
            $GLOBALS['intersoccer_test_locale'] = $GLOBALS['intersoccer_sensitive_order_meta_prev_locale'];
            $GLOBALS['intersoccer_pv_test_determine_locale'] = $GLOBALS['intersoccer_sensitive_order_meta_prev_locale'];
        } else {
            $GLOBALS['intersoccer_test_locale'] = $GLOBALS['intersoccer_test_locale_restore'] ?? 'en_US';
        }
        return true;
    }
}

if (!function_exists('has_action')) {
    function has_action($hook) {
        return !empty($GLOBALS['pv_td_test_actions'][$hook]);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (empty($GLOBALS['pv_td_test_actions'][$hook])) {
            return;
        }
        ksort($GLOBALS['pv_td_test_actions'][$hook]);
        foreach ($GLOBALS['pv_td_test_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $item) {
                call_user_func_array($item['cb'], array_slice($args, 0, (int) $item['accepted_args']));
            }
        }
    }
}

if (!function_exists('pv_td_test_add_action')) {
    function pv_td_test_add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['pv_td_test_actions'][$hook][$priority][] = [
            'cb' => $callback,
            'accepted_args' => $accepted_args,
        ];
    }
}

if (!function_exists('pv_td_test_do_action')) {
    function pv_td_test_do_action($hook, ...$args) {
        if (empty($GLOBALS['pv_td_test_actions'][$hook])) {
            return;
        }
        ksort($GLOBALS['pv_td_test_actions'][$hook]);
        foreach ($GLOBALS['pv_td_test_actions'][$hook] as $callbacks) {
            foreach ($callbacks as $item) {
                call_user_func_array($item['cb'], array_slice($args, 0, (int) $item['accepted_args']));
            }
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

class PvTextdomainTestReloadRegressionTest extends TestCase {
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_reloadable_unload_restores_fr_and_de_after_jit_and_wpml_switch(): void {
        $domain = 'intersoccer-product-variations';
        $GLOBALS['pv_td_l10n_loaded'] = [];
        $GLOBALS['pv_td_l10n_unloaded'] = [];
        $GLOBALS['pv_td_translations'] = [];
        $GLOBALS['pv_td_test_actions'] = [];
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $GLOBALS['intersoccer_test_locale_restore'] = 'en_US';
        unset($GLOBALS['intersoccer_pv_textdomain_locale']);

        require_once dirname(__DIR__) . '/includes/pv-textdomain.php';

        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $this->assertSame('Note', __('Note', $domain));

        unload_textdomain($domain, false);
        if (isset($GLOBALS['intersoccer_test_textdomain_unload_reloadable'][$domain])) {
            $this->assertFalse($GLOBALS['intersoccer_test_textdomain_unload_reloadable'][$domain]);
        } else {
            $this->assertTrue(!empty($GLOBALS['pv_td_l10n_unloaded'][$domain]));
        }

        $GLOBALS['intersoccer_test_locale'] = 'fr_FR';
        $this->assertTrue(intersoccer_pv_load_textdomain_for_current_locale('fr_FR'));
        $fr_mo = $GLOBALS['intersoccer_test_textdomain_loaded'][$domain]['mofile'] ?? '';
        $this->assertSame('Remarque', self::readMoEntries($fr_mo)['Note'] ?? '');

        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'de_DE';
        do_action('wpml_language_has_switched');
        $this->assertSame('de_DE', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $de_mo = $GLOBALS['intersoccer_test_textdomain_loaded'][$domain]['mofile'] ?? '';
        $this->assertSame('Hinweis', self::readMoEntries($de_mo)['Note'] ?? '');

        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'en_US';
        intersoccer_pv_load_textdomain_for_current_locale('en_US');

        require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
        $order = new class extends WC_Order {
            public function get_meta($key, $single = true) {
                return $key === 'wpml_language' ? 'fr' : '';
            }
        };
        $GLOBALS['intersoccer_sensitive_order_meta_email_depth'] = 0;
        intersoccer_sensitive_order_meta_begin_email_context($order, false, true, null);
        $this->assertSame('fr_FR', $GLOBALS['intersoccer_pv_textdomain_locale']);
        $email_fr_mo = $GLOBALS['intersoccer_test_textdomain_loaded'][$domain]['mofile'] ?? '';
        $this->assertSame('Remarque', self::readMoEntries($email_fr_mo)['Note'] ?? '');
        intersoccer_sensitive_order_meta_end_email_context($order, false, true, null);
        $this->assertSame('en_US', $GLOBALS['intersoccer_pv_textdomain_locale']);
    }
}
