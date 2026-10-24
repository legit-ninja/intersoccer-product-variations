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
    function unload_textdomain($domain, $reloadable = false) {
        $GLOBALS['intersoccer_test_textdomain_unload_reloadable'][$domain] = (bool) $reloadable;
        unset($GLOBALS['intersoccer_test_textdomain_loaded'][$domain]);
    }
}

if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated, $path) {
        $GLOBALS['intersoccer_test_textdomain_loaded'][$domain] = true;
        return true;
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

if (!class_exists('WC_Order')) {
    class WC_Order {
        private $meta = [];

        public function __construct(array $meta = []) {
            $this->meta = $meta;
        }

        public function get_meta($key, $single = true) {
            return $this->meta[$key] ?? '';
        }
    }
}

if (!function_exists('intersoccer_resolve_order_item_product_context')) {
    function intersoccer_resolve_order_item_product_context($item) {
        return [
            'product_type' => $GLOBALS['intersoccer_test_product_type'] ?? '',
            'variation_id' => 0,
            'resolved_product_id' => 1,
        ];
    }
}

if (!function_exists('intersoccer_age_on_date')) {
    function intersoccer_age_on_date($dob_ymd, $ref_ymd) {
        return 10;
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return $GLOBALS['intersoccer_test_locale'] ?? 'en_US';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return get_locale();
    }
}

if (!function_exists('is_locale_switched')) {
    function is_locale_switched() {
        return !empty($GLOBALS['intersoccer_test_locale_switched']);
    }
}

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

    /**
     * @return array<int,object>
     */
    private function sample_customer_email_meta_rows(): array {
        return [
            (object) [
                'key' => 'Activity Type',
                'display_key' => 'Activity Type',
                'value' => 'Course',
                'display_value' => 'Course',
            ],
            (object) [
                'key' => 'Camp Start Date',
                'display_key' => 'Camp Start Date',
                'value' => '2026-10-19',
                'display_value' => '2026-10-19',
            ],
            (object) [
                'key' => 'Booking Type',
                'display_key' => 'Booking Type',
                'value' => 'Full Week',
                'display_value' => 'Full Week',
            ],
            (object) [
                'key' => 'Days Selected',
                'display_key' => 'Days Selected',
                'value' => 'Monday',
                'display_value' => 'Monday',
            ],
        ];
    }

    /**
     * @param array<string,string> $gettext_map msgid => translation
     * @return array<string,string>
     */
    private function filter_customer_email_meta_for_locale(string $locale, array $gettext_map): array {
        $GLOBALS['intersoccer_test_locale'] = $locale;
        $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) use ($gettext_map) {
                return $gettext_map[$text] ?? $translation;
            };
        }
        $order = new WC_Order(['wpml_language' => substr($locale, 0, 2)]);
        intersoccer_sensitive_order_meta_begin_email_context($order, false, true, null);
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->sample_customer_email_meta_rows(), $item);
        intersoccer_sensitive_order_meta_end_email_context($order, false, true, null);
        unset($GLOBALS['intersoccer_sensitive_order_meta_email'], $GLOBALS['intersoccer_test_locale']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        return $this->meta_map($filtered);
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

    public function test_my_account_hides_duplicate_player_dob_when_attendee_dob_present(): void {
        $item = new WC_Order_Item_Product([
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
            'Medical Conditions' => 'Peanut allergy',
            'Player Medical' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('2015-06-20', $map['Attendee DOB']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_customer_view_hides_player_medical_when_medical_conditions_present(): void {
        $item = new WC_Order_Item_Product([
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertContains('Medical Conditions', $keys);
        $this->assertNotContains('Player Medical', $keys);
    }

    public function test_customer_view_shows_player_medical_when_medical_conditions_absent(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([]);
        $meta = [
            (object) [
                'key' => 'Player Medical',
                'display_key' => 'Player Medical',
                'value' => 'Peanut allergy',
                'display_value' => 'Peanut allergy',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Medical information: provided', $map['Player Medical']);
    }

    public function test_my_account_shows_player_dob_when_attendee_dob_missing(): void {
        $item = new WC_Order_Item_Product([
            'Player DOB' => '2016-03-15',
            'Player Medical' => 'Peanut allergy',
        ]);
        $meta = [
            (object) [
                'key' => 'Player DOB',
                'display_key' => 'Player DOB',
                'value' => '2016-03-15',
                'display_value' => '2016-03-15',
            ],
            (object) [
                'key' => 'Player Medical',
                'display_key' => 'Player Medical',
                'value' => 'Peanut allergy',
                'display_value' => 'Peanut allergy',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('2016-03-15', $map['Player DOB']);
    }

    public function test_thank_you_page_redacts_pv_and_pm_meta_keys(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(), $item);
        $map = $this->meta_map($filtered);
        $this->assertArrayHasKey('Medical Conditions', $map);
        $this->assertArrayNotHasKey('Player Medical', $map);
        $this->assertStringContainsString('Medical information: provided', $map['Medical Conditions']);
        $this->assertStringNotContainsString('Peanut', $map['Medical Conditions']);
        $this->assertStringNotContainsString('2015-06-20', $map['Attendee DOB']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_html_email_redacts_player_dob_and_player_medical(): void {
        intersoccer_sensitive_order_meta_begin_email_context(false, false, false, null);
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
            'Medical Conditions' => 'Peanut allergy',
        ]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_naming_meta(false), $item);
        intersoccer_sensitive_order_meta_end_email_context(false, false, false, null);
        $map = $this->meta_map($filtered);
        $this->assertArrayHasKey('Medical Conditions', $map);
        $this->assertArrayNotHasKey('Player Medical', $map);
        $this->assertStringNotContainsString('Peanut', $map['Medical Conditions']);
        $this->assertArrayNotHasKey('Player DOB', $map);
    }

    public function test_plain_text_customer_email_redacts_both_naming_sets(): void {
        $this->begin_plain_email();
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
        ]);
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
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
            'Player DOB' => '2016-03-15',
        ]);
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

    public function test_thank_you_medical_none_not_shown_as_provided_fr(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                $map = [
                    'None' => 'Aucune',
                    'Medical information: provided (view or update in My Account).' => 'Informations médicales : fournies (consulter ou mettre à jour dans Mon compte).',
                ];
                return $map[$text] ?? $translation;
            };
        }
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'None']);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'display_key' => 'Medical Conditions', 'value' => 'None', 'display_value' => 'None'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $this->assertSame([], $filtered);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_email_medical_none_not_shown_as_provided_de(): void {
        $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                $map = [
                    'None' => 'Keine',
                    'Medical information: provided (view or update in My Account).' => 'Medizinische Informationen: angegeben (in Mein Konto ansehen oder aktualisieren).',
                ];
                return $map[$text] ?? $translation;
            };
        }
        $order = new WC_Order([]);
        intersoccer_sensitive_order_meta_begin_email_context($order, false, true, null);
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'None']);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'display_key' => 'Medical Conditions', 'value' => 'None', 'display_value' => 'None'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        intersoccer_sensitive_order_meta_end_email_context($order, false, true, null);
        unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        $this->assertSame([], $filtered);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_thank_you_medical_with_value_still_redacted_as_provided(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'Peanut allergy']);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'display_key' => 'Medical Conditions', 'value' => 'Peanut allergy', 'display_value' => 'Peanut allergy'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Medical information: provided', $map['Medical Conditions']);
        $this->assertStringNotContainsString('Peanut', $map['Medical Conditions']);
    }

    public function test_my_account_medical_none_still_shows_translated_none(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = false;
        $GLOBALS['intersoccer_test_is_admin'] = false;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'None') {
                    return 'Aucune';
                }
                return $translation;
            };
        }
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'None']);
        $meta = [
            (object) ['key' => 'Medical Conditions', 'display_key' => 'Medical Conditions', 'value' => 'None', 'display_value' => 'None'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertSame('Aucune', $map['Medical Conditions']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
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

    public function test_customer_email_item_meta_translated_fr(): void {
        $map = $this->filter_customer_email_meta_for_locale('fr_FR', [
            'Activity Type' => "Type d'activité",
            'Camp Start Date' => 'Date de début du camp',
            'Booking Type' => 'Type de réservation',
            'Days Selected' => 'Jours sélectionnés',
            'Course' => 'Cours',
            'Monday' => 'Lundi',
            'Full Week' => 'Semaine complète',
        ]);
        $this->assertSame('Cours', $map['Activity Type']);
        $this->assertSame('Lundi', $map['Days Selected']);
        $this->assertSame('Semaine complète', $map['Booking Type']);
    }

    public function test_customer_email_item_meta_translated_de(): void {
        $map = $this->filter_customer_email_meta_for_locale('de_DE', [
            'Activity Type' => 'Aktivitätstyp',
            'Camp Start Date' => 'Camp-Startdatum',
            'Booking Type' => 'Buchungstyp',
            'Days Selected' => 'Ausgewählte Tage',
            'Course' => 'Kurs',
            'Monday' => 'Montag',
            'Full Week' => 'Ganze Woche',
        ]);
        $this->assertSame('Kurs', $map['Activity Type']);
        $this->assertSame('Montag', $map['Days Selected']);
        $this->assertSame('Ganze Woche', $map['Booking Type']);
    }

    public function test_admin_resend_email_translates_when_locale_switched(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_locale_switched'] = true;
        $GLOBALS['wp_locale_switcher'] = new stdClass();
        $map = $this->filter_customer_email_meta_for_locale('de_DE', [
            'Activity Type' => 'Aktivitätstyp',
            'Camp Start Date' => 'Camp-Startdatum',
            'Booking Type' => 'Buchungstyp',
            'Days Selected' => 'Ausgewählte Tage',
            'Course' => 'Kurs',
            'Monday' => 'Montag',
            'Full Week' => 'Ganze Woche',
        ]);
        $this->assertSame('Kurs', $map['Activity Type']);
        unset($GLOBALS['intersoccer_test_locale_switched'], $GLOBALS['wp_locale_switcher']);
    }

    public function test_admin_order_view_stays_english_while_email_context_translates(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'Activity Type') {
                    return 'Aktivitätstyp';
                }
                return $translation;
            };
        }
        $admin_label = intersoccer_translate_stored_order_meta_display_key('Activity Type', 'Activity Type');
        $this->assertSame('Activity Type', $admin_label);

        $email_map = $this->filter_customer_email_meta_for_locale('de_DE', [
            'Activity Type' => 'Aktivitätstyp',
            'Course' => 'Kurs',
            'Camp Start Date' => 'Camp-Startdatum',
            'Booking Type' => 'Buchungstyp',
            'Days Selected' => 'Ausgewählte Tage',
            'Monday' => 'Montag',
            'Full Week' => 'Ganze Woche',
        ]);
        $this->assertSame('Kurs', $email_map['Activity Type']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_camp_item_shows_age_at_camp_start_on_thank_you(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $item = new WC_Order_Item_Product([
            'Camp Start Date' => '2026-10-19',
            'Attendee DOB' => '2015-06-20',
        ]);
        $meta = [
            (object) [
                'key' => 'Attendee DOB',
                'display_key' => 'Attendee DOB',
                'value' => '2015-06-20',
                'display_value' => '2015-06-20',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertMatchesRegularExpression('/Age at camp start: \d+/', $map['Attendee DOB']);
        unset($GLOBALS['intersoccer_test_product_type']);
    }

    public function test_course_item_shows_birth_year_not_age_at_camp_start(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $item = new WC_Order_Item_Product([
            'Start Date' => 'September 27, 2026',
            'Attendee DOB' => '2015-06-20',
        ]);
        $meta = [
            (object) [
                'key' => 'Attendee DOB',
                'display_key' => 'Attendee DOB',
                'value' => '2015-06-20',
                'display_value' => '2015-06-20',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $map = $this->meta_map($filtered);
        $this->assertStringContainsString('Birth year: 2015', $map['Attendee DOB']);
        $this->assertStringNotContainsString('Age at camp start', $map['Attendee DOB']);
        unset($GLOBALS['intersoccer_test_product_type']);
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
