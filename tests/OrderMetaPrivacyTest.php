<?php
/**
 * Privacy redaction for attendee DOB and medical conditions on emails and thank-you page.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        if ($domain === 'intersoccer-product-variations' && function_exists('gettext') && function_exists('dgettext')) {
            $loaded = !empty($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
            if ($loaded) {
                return dgettext($domain, $text);
            }
        }
        return $text;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        $value = array_shift($args);
        if ($hook === 'wpml_current_language' && isset($GLOBALS['intersoccer_test_wpml_language'])) {
            return $GLOBALS['intersoccer_test_wpml_language'];
        }
        if ($hook === 'wpml_translate_single_string') {
            return $value;
        }
        return $value;
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

if (!function_exists('is_order_received_page')) {
    function is_order_received_page() {
        return !empty($GLOBALS['intersoccer_test_is_order_received_page']);
    }
}

if (!function_exists('has_action')) {
    function has_action($hook) {
        return false;
    }
}

if (!function_exists('unload_textdomain')) {
    function unload_textdomain($domain) {
        unset($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated, $path) {
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = true;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return 'intersoccer-product-variations/intersoccer-product-variations.php';
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        private $meta = [];

        public function __construct(array $meta = []) {
            foreach ($meta as $key => $value) {
                $this->meta[] = ['key' => $key, 'value' => $value];
            }
        }

        public function get_meta($key, $single = true) {
            $values = [];
            foreach ($this->meta as $row) {
                if ($row['key'] === $key) {
                    $values[] = $row['value'];
                }
            }
            return $values[0] ?? '';
        }

        public function get_product_id() {
            return 0;
        }

        public function get_variation_id() {
            return 0;
        }

        public function get_order() {
            return null;
        }
    }
}

class OrderMetaPrivacyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_doing_ajax'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_sensitive_order_meta_email'],
            $GLOBALS['intersoccer_sensitive_order_meta_email_depth'],
            $GLOBALS['intersoccer_test_wpml_language']
        );
    }

    /**
     * @param array<int,object> $formatted_meta
     * @return array<string,string>
     */
    private function meta_map(array $formatted_meta): array {
        $map = [];
        foreach ($formatted_meta as $meta) {
            $map[(string) $meta->key] = (string) ($meta->display_value ?? $meta->value ?? '');
        }
        return $map;
    }

    /**
     * @return array<int,object>
     */
    private function dual_naming_meta(bool $plain_text = false): array {
        $rows = [
            ['key' => 'Medical Conditions', 'value' => 'Peanut allergy'],
            ['key' => 'Player Medical', 'value' => 'Peanut allergy'],
            ['key' => 'Attendee DOB', 'value' => '2015-06-20'],
            ['key' => 'Player DOB', 'value' => '2016-03-15'],
        ];
        $out = [];
        foreach ($rows as $row) {
            $obj = (object) [
                'key' => $row['key'],
                'display_key' => $row['key'],
                'value' => $row['value'],
                'display_value' => $row['value'],
            ];
            $out[] = $obj;
        }
        return $out;
    }

    private function begin_plain_email(): void {
        intersoccer_sensitive_order_meta_begin_email_context(false, false, true, null);
    }

    private function end_plain_email(): void {
        intersoccer_sensitive_order_meta_end_email_context(false, false, true, null);
    }

    public function test_sensitive_key_lists_include_pv_and_pm_keys(): void {
        $dob = intersoccer_sensitive_order_meta_dob_keys();
        $med = intersoccer_sensitive_order_meta_medical_keys();
        $this->assertContains('Attendee DOB', $dob);
        $this->assertContains('Player DOB', $dob);
        $this->assertContains('player_dob', $dob);
        $this->assertContains('Medical Conditions', $med);
        $this->assertContains('Player Medical', $med);
        $this->assertContains('medical_conditions', $med);
    }

    public function test_admin_order_screen_keeps_full_medical_and_dob(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-07-06',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
            'Medical Conditions' => 'Peanut allergy',
            'Player Medical' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Peanut allergy', $map['Medical Conditions']);
        $this->assertSame('2015-06-20', $map['Attendee DOB']);
        $this->assertSame('2016-03-15', $map['Player DOB']);
    }

    public function test_my_account_order_view_keeps_full_values(): void {
        $item = new WC_Order_Item_Product([
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
            'Medical Conditions' => 'Peanut allergy',
            'Player Medical' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Peanut allergy', $map['Player Medical']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_thank_you_page_redacts_pv_and_pm_meta_keys(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $map = $this->meta_map($filtered);
        foreach (['Medical Conditions', 'Player Medical'] as $k) {
            $this->assertStringContainsString('Medical information: provided', $map[$k]);
            $this->assertStringNotContainsString('Peanut', $map[$k]);
        }
        $this->assertStringNotContainsString('2015-06-20', $map['Attendee DOB']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_html_email_redacts_player_dob_and_player_medical(): void {
        intersoccer_sensitive_order_meta_begin_email_context(false, false, false, null);
        $item = new WC_Order_Item_Product(['Camp Start Date' => '2026-10-19']);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(false), $item);
        intersoccer_sensitive_order_meta_end_email_context(false, false, false, null);
        $map = $this->meta_map($filtered);
        $this->assertStringNotContainsString('Peanut', $map['Player Medical']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_plain_text_customer_email_redacts_both_naming_sets(): void {
        $this->begin_plain_email();
        $item = new WC_Order_Item_Product(['Camp Start Date' => '2026-10-19']);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(true), $item);
        $this->end_plain_email();
        $map = $this->meta_map($filtered);
        $this->assertStringNotContainsString('Peanut allergy', implode(' ', $map));
        $this->assertStringNotContainsString('2015-06-20', implode(' ', $map));
        $this->assertStringNotContainsString('2016-03-15', implode(' ', $map));
    }

    public function test_admin_resend_email_redacts_in_admin_context(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $this->begin_plain_email();
        $item = new WC_Order_Item_Product(['Camp Start Date' => '2026-10-19']);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(true), $item);
        $this->end_plain_email();
        $map = $this->meta_map($filtered);
        $this->assertStringNotContainsString('Peanut', $map['Medical Conditions']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_birth_year_when_camp_start_unknown(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product(['Attendee DOB' => '2012-09-01']);
        $meta = [
            (object) [
                'key' => 'Attendee DOB',
                'display_key' => 'Attendee DOB',
                'value' => '2012-09-01',
                'display_value' => '2012-09-01',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Birth year: 2012', $map['Attendee DOB']);
    }

    public function test_medical_row_omitted_when_placeholder_none(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Medical Conditions' => 'None',
            'Attendee DOB' => '2015-01-01',
            'Camp Start Date' => '2026-07-06',
        ]);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'display_key' => 'Medical Conditions', 'value' => 'None', 'display_value' => 'None'],
            (object) ['key' => 'Attendee DOB', 'display_key' => 'Attendee DOB', 'value' => '2015-01-01', 'display_value' => '2015-01-01'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertNotContains('Medical Conditions', $keys);
    }

    public function test_french_privacy_strings_via_wpml_language_fallback(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'fr';
        };
        $medical = intersoccer_pv_privacy_string('Medical information: provided (view or update in My Account).');
        $this->assertStringContainsString('Informations médicales', $medical);
        $age = intersoccer_pv_privacy_string('Age at camp start: %d', [10]);
        $this->assertStringContainsString('Âge au début du camp', $age);
    }

    public function test_german_privacy_strings_via_wpml_language_fallback(): void {
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'de';
        };
        $medical = intersoccer_pv_privacy_string('Medical information: provided (view or update in My Account).');
        $this->assertStringContainsString('Medizinische Informationen', $medical);
        $year = intersoccer_pv_privacy_string('Birth year: %s', ['2012']);
        $this->assertStringContainsString('Geburtsjahr', $year);
    }

    public function test_compiled_mo_files_ship_for_fr_and_de_locales(): void {
        $this->assertFileExists(dirname(__DIR__) . '/lang/intersoccer-product-variations-fr_FR.mo');
        $this->assertFileExists(dirname(__DIR__) . '/lang/intersoccer-product-variations-de_DE.mo');
        $this->assertGreaterThan(1000, filesize(dirname(__DIR__) . '/lang/intersoccer-product-variations-fr_FR.mo'));
    }

    public function test_display_key_match_redacts_when_storage_key_differs(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product(['Camp Start Date' => '2026-10-19']);
        $meta = [
            (object) [
                'key' => 'player_dob',
                'display_key' => 'Player DOB',
                'value' => '2016-03-15',
                'display_value' => '2016-03-15',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertStringNotContainsString('2016-03-15', $map['player_dob']);
    }
}
