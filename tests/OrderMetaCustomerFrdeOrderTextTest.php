<?php
/**
 * FR/DE customer order text: Year, medical dedupe, WCML slug values, cart attribute labels.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
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

if (!function_exists('is_cart')) {
    function is_cart() {
        return !empty($GLOBALS['intersoccer_test_is_cart']);
    }
}

if (!function_exists('is_checkout')) {
    function is_checkout() {
        return !empty($GLOBALS['intersoccer_test_is_checkout']);
    }
}

if (!function_exists('is_order_received_page')) {
    function is_order_received_page() {
        return !empty($GLOBALS['intersoccer_test_is_order_received_page']);
    }
}

if (!function_exists('is_wc_endpoint_url')) {
    function is_wc_endpoint_url($endpoint) {
        return !empty($GLOBALS['intersoccer_test_wc_endpoint'])
            && (string) $GLOBALS['intersoccer_test_wc_endpoint'] === (string) $endpoint;
    }
}

if (!function_exists('translate')) {
    function translate($text, $domain = 'default') {
        global $intersoccer_pv_test_textdomain_strings;
        if (is_array($intersoccer_pv_test_textdomain_strings) && isset($intersoccer_pv_test_textdomain_strings[$domain][$text])) {
            return $intersoccer_pv_test_textdomain_strings[$domain][$text];
        }

        return $text;
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        /** @var array<string,string> */
        private $meta = [];

        public function __construct(array $meta = []) {
            $this->meta = $meta;
        }

        public function get_meta($key, $single = true) {
            unset($single);
            return $this->meta[$key] ?? '';
        }

        public function get_product_id() {
            return 0;
        }

        public function get_variation_id() {
            return 0;
        }

        public function get_meta_data() {
            return [];
        }

        public function get_order() {
            return null;
        }
    }
}

class OrderMetaCustomerFrdeOrderTextTest extends TestCase {

    protected function tearDown(): void {
        MockFilters::reset();
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_doing_ajax'],
            $GLOBALS['intersoccer_test_is_cart'],
            $GLOBALS['intersoccer_test_is_checkout'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_test_wc_endpoint'],
            $GLOBALS['intersoccer_sensitive_order_meta_email'],
            $GLOBALS['intersoccer_pv_test_textdomain_strings'],
            $GLOBALS['intersoccer_pv_test_textdomain_loaded'],
            $GLOBALS['intersoccer_test_terms']
        );
        parent::tearDown();
    }

    private function setWpmlLanguage(string $lang): void {
        MockFilters::$filters['wpml_current_language'] = static function () use ($lang) {
            return $lang;
        };
    }

    private function setFrCartSurface(): void {
        $GLOBALS['intersoccer_test_is_cart'] = true;
        unset(
            $GLOBALS['intersoccer_test_is_checkout'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_test_wc_endpoint'],
            $GLOBALS['intersoccer_sensitive_order_meta_email']
        );
    }

    private function clearCustomerSurface(): void {
        unset(
            $GLOBALS['intersoccer_test_is_cart'],
            $GLOBALS['intersoccer_test_is_checkout'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_test_wc_endpoint'],
            $GLOBALS['intersoccer_sensitive_order_meta_email']
        );
    }

    /**
     * @return array<string,string>
     */
    private function loadMoCatalog(string $locale): array {
        $path = dirname(__DIR__) . '/lang/intersoccer-product-variations-' . $locale . '.mo';
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $magic = unpack('V', substr($data, 0, 4))[1];
        $unpack = $magic === 0xde120495 ? 'N' : 'V';
        $count = unpack($unpack, substr($data, 8, 4))[1];
        $orig_off = unpack($unpack, substr($data, 12, 4))[1];
        $trans_off = unpack($unpack, substr($data, 16, 4))[1];
        $read = static function (int $offset) use ($data, $unpack): string {
            $len = unpack($unpack, substr($data, $offset, 4))[1];
            $str_off = unpack($unpack, substr($data, $offset + 4, 4))[1];
            return substr($data, $str_off, $len);
        };
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $orig = $read($orig_off + $i * 8);
            $trans = $read($trans_off + $i * 8);
            if ($orig !== '' && $trans !== '') {
                $entries[$orig] = $trans;
            }
        }

        return $entries;
    }

    private function bindLocale(string $locale): void {
        $short = str_starts_with($locale, 'fr') ? 'fr' : 'de';
        $GLOBALS['intersoccer_pv_test_textdomain_loaded'] = ['intersoccer-product-variations' => true];
        $GLOBALS['intersoccer_pv_test_textdomain_strings'] = [
            'intersoccer-product-variations' => $this->loadMoCatalog($short),
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function yearLocaleCases(): array {
        return [
            'fr_FR' => ['fr_FR', 'Année'],
            'fr_CH' => ['fr_CH', 'Année'],
            'de_DE' => ['de_DE', 'Jahr'],
            'de_CH' => ['de_CH', 'Jahr'],
        ];
    }

    #[DataProvider('yearLocaleCases')]
    public function test_year_label_uses_catalog_in_customer_formatted_meta(string $locale, string $expected): void {
        $this->bindLocale($locale);
        $meta = (object) [
            'key' => 'Year',
            'display_key' => 'Year',
            'value' => '2027',
            'display_value' => '2027',
        ];
        $out = intersoccer_translate_order_item_formatted_meta_object($meta);
        $this->assertSame($expected, $out->display_key);
    }

    /**
     * @return array<int,object>
     */
    private function dual_medical_meta(): array {
        return [
            (object) [
                'key' => 'Medical Conditions',
                'display_key' => 'Medical Conditions',
                'value' => 'Peanut allergy',
                'display_value' => 'Peanut allergy',
            ],
            (object) [
                'key' => 'Player Medical',
                'display_key' => 'Player Medical',
                'value' => 'Peanut allergy',
                'display_value' => 'Peanut allergy',
            ],
        ];
    }

    public function test_dual_medical_rows_collapse_to_one_in_my_account(): void {
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'Peanut allergy']);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_medical_meta(), $item);
        $keys = array_map(static fn($m) => (string) $m->key, $filtered);
        $this->assertSame(['Medical Conditions'], $keys);
        $this->assertSame('Peanut allergy', $filtered[0]->value);
    }

    public function test_dual_medical_rows_collapse_in_email_context(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
        $item = new WC_Order_Item_Product(['Medical Conditions' => 'Peanut allergy']);
        $filtered = intersoccer_filter_customer_order_item_meta($this->dual_medical_meta(), $item);
        $keys = array_map(static fn($m) => (string) $m->key, $filtered);
        $this->assertSame(['Medical Conditions'], $keys);
    }

    public function test_pm_only_medical_uses_medical_conditions_label_fr(): void {
        $this->bindLocale('fr_FR');
        $meta = [
            (object) [
                'key' => 'Player Medical',
                'display_key' => 'Player Medical',
                'value' => 'Asthma',
                'display_value' => 'Asthma',
            ],
        ];
        $item = new WC_Order_Item_Product();
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $this->assertCount(1, $filtered);
        $this->assertSame('Conditions médicales', $filtered[0]->display_key);
        $this->assertSame('Asthma', $filtered[0]->value);
    }

    public function test_wcml_slug_on_human_age_group_row_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            601 => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13-ans-journee-complete',
                'name' => '5-13 ans (journée complète)',
            ],
        ];
        $this->bindLocale('fr_FR');
        $meta = (object) [
            'key' => 'Age Group',
            'display_key' => 'Age Group',
            'value' => '5-13-ans-journee-complete',
            'display_value' => '5-13-ans-journee-complete',
        ];
        $out = intersoccer_translate_order_item_formatted_meta_object($meta);
        $this->assertSame('Groupe d\'âge', $out->display_key);
        $this->assertSame('5-13 ans (journée complète)', $out->value);
    }

    public function test_readable_age_group_value_unchanged_when_not_a_slug(): void {
        $this->bindLocale('fr_FR');
        $readable = '5-13 ans (Journée complète)';
        $meta = (object) [
            'key' => 'Age Group',
            'display_key' => 'Age Group',
            'value' => $readable,
            'display_value' => $readable,
        ];
        $out = intersoccer_translate_order_item_formatted_meta_object($meta);
        $this->assertSame($readable, $out->value);
    }

    public function test_cart_attribute_label_interSoccer_venues_fr(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $this->setFrCartSurface();
        $label = intersoccer_pv_filter_wc_attribute_label_for_customer('InterSoccer Venues', 'pa_intersoccer-venues');
        $this->assertSame('Sites InterSoccer', $label);
    }

    public function test_cart_attribute_label_age_group_de(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $this->setFrCartSurface();
        $label = intersoccer_pv_filter_wc_attribute_label_for_customer('Age Group', 'pa_age-group');
        $this->assertSame('Altersgruppe', $label);
    }

    public function test_cart_item_data_keys_translated(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $this->setFrCartSurface();
        $rows = intersoccer_pv_translate_cart_item_data_keys(
            [
                ['key' => 'Camp Terms', 'value' => 'Week 1'],
                ['key' => 'Booking Type', 'value' => 'Full Week'],
            ],
            []
        );
        $this->assertSame('Camp-Woche', $rows[0]['key']);
        $this->assertSame('Buchungstyp', $rows[1]['key']);
    }

    public function test_en_product_page_wc_attribute_labels_unchanged(): void {
        $this->setWpmlLanguage('en');
        $this->clearCustomerSurface();
        $this->assertSame(
            'InterSoccer Venues',
            intersoccer_pv_filter_wc_attribute_label_for_customer('InterSoccer Venues', 'pa_intersoccer-venues')
        );
        $this->assertSame(
            'Program Year',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Program Year', 'pa_program-year')
        );
        $this->assertSame(
            'Program Season',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Program Season', 'pa_program-season')
        );
    }

    public function test_fr_product_page_keeps_wpml_and_english_incoming_labels(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $this->clearCustomerSurface();
        $this->assertSame(
            'Duree du camp',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Duree du camp', 'pa_camp-terms')
        );
        $this->assertSame(
            'Camp Terms',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Camp Terms', 'pa_camp-terms')
        );
    }

    public function test_fr_cart_translates_english_registry_labels(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $this->setFrCartSurface();
        $this->assertSame(
            'Groupe d\'âge',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Age Group', 'pa_age-group')
        );
        $this->assertSame(
            'Semaine de camp',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Camp Terms', 'pa_camp-terms')
        );
    }

    public function test_fr_cart_item_data_keeps_non_english_key(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $this->setFrCartSurface();
        $rows = intersoccer_pv_translate_cart_item_data_keys(
            [['key' => 'Duree du camp', 'value' => 'Semaine 1']],
            []
        );
        $this->assertSame('Duree du camp', $rows[0]['key']);
    }

    public function test_fr_cart_item_data_translates_english_key(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $this->setFrCartSurface();
        $rows = intersoccer_pv_translate_cart_item_data_keys(
            [['key' => 'Camp Terms', 'value' => 'Week 1']],
            []
        );
        $this->assertSame('Semaine de camp', $rows[0]['key']);
    }
}
