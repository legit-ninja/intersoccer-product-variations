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

if (!function_exists('doing_action')) {
    function doing_action($hook = '') {
        $stack = $GLOBALS['intersoccer_test_doing_action_stack'] ?? [];
        if ($hook === '' || $hook === null) {
            return $stack !== [];
        }

        return in_array((string) $hook, $stack, true);
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

if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label($name, $product = null) {
        $label = (string) $name;
        if (function_exists('intersoccer_attr_order_meta_label_map')) {
            $map = intersoccer_attr_order_meta_label_map();
            if (isset($map[$name])) {
                $label = (string) $map[$name];
            }
        }

        return apply_filters('woocommerce_attribute_label', $label, $name, $product);
    }
}

if (!function_exists('intersoccer_get_parent_product_attributes')) {
    function intersoccer_get_parent_product_attributes($product_id, $variation_id = null) {
        unset($product_id, $variation_id);
        $taxonomies = $GLOBALS['intersoccer_pv_test_parent_attribute_taxonomies'] ?? null;
        if (is_array($taxonomies) && $taxonomies !== []) {
            $label_map = intersoccer_attr_order_meta_label_map();
            $attributes = [];
            foreach ($taxonomies as $taxonomy) {
                $label = wc_attribute_label((string) $taxonomy);
                $english_label = $label_map[$taxonomy] ?? $label;
                if ($english_label !== '') {
                    $attributes[$english_label] = 'fixture-value';
                }
            }

            return $attributes;
        }
        if (class_exists('OrderMetaContractTest')) {
            $mock = OrderMetaContractTest::$mock_parent_attributes ?? null;
            if (is_array($mock)) {
                return $mock;
            }
        }

        return [];
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        /** @var array<int,array{key:string,value:mixed}> */
        private $meta = [];
        private $product_id = 0;
        private $variation_id = 0;

        public function __construct(array $meta = []) {
            foreach ($meta as $key => $value) {
                $this->meta[] = ['key' => (string) $key, 'value' => $value];
            }
        }

        public function get_meta($key, $single = true) {
            unset($single);
            $values = [];
            foreach ($this->meta as $row) {
                if ($row['key'] === $key) {
                    $values[] = $row['value'];
                }
            }

            return $values[0] ?? '';
        }

        public function get_meta_data() {
            $out = [];
            foreach ($this->meta as $row) {
                $out[] = (object) ['key' => $row['key'], 'value' => $row['value']];
            }

            return $out;
        }

        public function add_meta_data($key, $value, $unique = false) {
            if ($unique) {
                foreach ($this->meta as $index => $row) {
                    if ($row['key'] === $key) {
                        $this->meta[$index]['value'] = $value;
                        return;
                    }
                }
            }
            $this->meta[] = ['key' => (string) $key, 'value' => $value];
        }

        public function update_meta_data($key, $value) {
            $this->delete_meta_data($key);
            $this->meta[] = ['key' => (string) $key, 'value' => $value];
        }

        public function delete_meta_data($key) {
            $this->meta = array_values(array_filter(
                $this->meta,
                static fn($row) => $row['key'] !== $key
            ));
        }

        public function set_product_id($id) {
            $this->product_id = (int) $id;
        }

        public function set_variation_id($id) {
            $this->variation_id = (int) $id;
        }

        public function get_product_id() {
            return $this->product_id;
        }

        public function get_variation_id() {
            return $this->variation_id;
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
            $GLOBALS['intersoccer_test_doing_action_stack'],
            $GLOBALS['intersoccer_sensitive_order_meta_email'],
            $GLOBALS['intersoccer_pv_test_textdomain_strings'],
            $GLOBALS['intersoccer_pv_test_textdomain_loaded'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_products'],
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_pv_test_parent_attribute_taxonomies']
        );
        unset($_REQUEST['wc-ajax']);
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
            $GLOBALS['intersoccer_sensitive_order_meta_email'],
            $GLOBALS['intersoccer_test_doing_ajax']
        );
        unset($_REQUEST['wc-ajax']);
    }

    private function setCheckoutOrderCreationContext(): void {
        $GLOBALS['intersoccer_test_doing_action_stack'] = ['woocommerce_checkout_create_order_line_item'];
        $GLOBALS['intersoccer_test_is_checkout'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $_REQUEST['wc-ajax'] = 'checkout';
        unset(
            $GLOBALS['intersoccer_test_is_cart'],
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_test_wc_endpoint'],
            $GLOBALS['intersoccer_sensitive_order_meta_email']
        );
    }

    private function applyCustomerDisplaySurface(string $surface): void {
        $this->clearCustomerSurface();
        unset($GLOBALS['intersoccer_test_doing_action_stack']);
        switch ($surface) {
            case 'checkout':
                $GLOBALS['intersoccer_test_is_checkout'] = true;
                break;
            case 'update_order_review':
                $GLOBALS['intersoccer_test_doing_ajax'] = true;
                $_REQUEST['wc-ajax'] = 'update_order_review';
                break;
            case 'order_received':
                $GLOBALS['intersoccer_test_is_order_received_page'] = true;
                break;
            case 'view_order':
                $GLOBALS['intersoccer_test_wc_endpoint'] = 'view-order';
                break;
            case 'email':
                $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
                break;
            default:
                $this->fail('Unknown surface: ' . $surface);
        }
    }

    private function seedRegistryAttributeProductFixtures(): void {
        $GLOBALS['intersoccer_pv_test_parent_attribute_taxonomies'] = ['pa_age-group', 'pa_camp-terms'];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
    }

    /**
     * @return list<string>
     */
    private function metaKeysFromItem(WC_Order_Item_Product $item): array {
        return array_map(
            static fn($meta) => (string) $meta->key,
            $item->get_meta_data()
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

    /**
     * @return array<string,array{0:string}>
     */
    public static function frDeLanguageProvider(): array {
        return [
            'fr' => ['fr'],
            'de' => ['de'],
        ];
    }

    #[DataProvider('frDeLanguageProvider')]
    public function test_wc_attribute_label_stays_english_during_order_line_item_creation(string $lang): void {
        $this->setCheckoutOrderCreationContext();
        $this->setWpmlLanguage($lang);
        $this->bindLocale($lang === 'fr' ? 'fr_FR' : 'de_DE');
        $this->assertSame(
            'Age Group',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Age Group', 'pa_age-group')
        );
        $this->assertSame(
            'Camp Terms',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Camp Terms', 'pa_camp-terms')
        );
    }

    #[DataProvider('frDeLanguageProvider')]
    public function test_checkout_creation_stores_english_registry_meta_keys(string $lang): void {
        // intersoccer_add_order_item_metadata lives in checkout-calculations.php, which cannot be
        // required in this harness (duplicate helpers vs bootstrap). This exercises the same
        // write_order_line_meta checkout path it calls, with a stub get_parent_product_attributes
        // that mirrors wc_attribute_label() → English registry keys (checkout-calculations.php ~274-287).
        $this->seedRegistryAttributeProductFixtures();
        $this->setCheckoutOrderCreationContext();
        $this->setWpmlLanguage($lang);
        $this->bindLocale($lang === 'fr' ? 'fr_FR' : 'de_DE');

        $item = new WC_Order_Item_Product();

        intersoccer_write_order_line_meta($item, [
            'mode' => 'checkout',
            'product_type' => 'camp',
            'product_id' => 88001,
            'variation_id' => 88002,
            'cart_values' => [],
        ]);

        $keys = $this->metaKeysFromItem($item);
        $this->assertContains('Age Group', $keys);
        $this->assertContains('Camp Terms', $keys);
        $this->assertNotContains('Groupe d\'âge', $keys);
        $this->assertNotContains('Altersgruppe', $keys);
        $this->assertNotContains('Semaine de camp', $keys);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function frDeCustomerDisplaySurfacesProvider(): array {
        $cases = [];
        foreach (['fr' => 'Groupe d\'âge', 'de' => 'Altersgruppe'] as $lang => $expected) {
            foreach (['checkout', 'update_order_review', 'order_received', 'view_order', 'email'] as $surface) {
                $cases[$lang . ' ' . $surface] = [$lang, $expected, $surface];
            }
        }

        return $cases;
    }

    #[DataProvider('frDeCustomerDisplaySurfacesProvider')]
    public function test_age_group_label_translated_on_fr_de_customer_surfaces(
        string $lang,
        string $expected,
        string $surface
    ): void {
        $this->bindLocale($lang === 'fr' ? 'fr_FR' : 'de_DE');
        $this->setWpmlLanguage($lang);
        $this->applyCustomerDisplaySurface($surface);
        $this->assertSame(
            $expected,
            intersoccer_pv_filter_wc_attribute_label_for_customer('Age Group', 'pa_age-group')
        );
    }

    public function test_update_order_review_still_translates_attribute_labels(): void {
        unset($GLOBALS['intersoccer_test_doing_action_stack']);
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $_REQUEST['wc-ajax'] = 'update_order_review';
        $this->assertSame(
            'Groupe d\'âge',
            intersoccer_pv_filter_wc_attribute_label_for_customer('Age Group', 'pa_age-group')
        );
        unset($_REQUEST['wc-ajax'], $GLOBALS['intersoccer_test_doing_ajax']);
    }

    public function test_de_medical_none_in_catalog_and_customer_display(): void {
        $mo = $this->loadMoCatalog('de');
        $this->assertArrayHasKey('None', $mo);
        $this->assertSame('Keine', $mo['None']);
        $this->assertSame('Keine', $mo["feminine noun\4None"] ?? '');

        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $display = intersoccer_translate_stored_order_meta_display_value('None', 'Medical Conditions');
        $this->assertSame('Keine', $display);
    }

    public function test_fr_medical_none_uses_feminine_aucune(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('none', 'Medical Conditions');
        $this->assertSame('Aucune', $display);
    }

    public function test_de_medical_none_uses_keine(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('None', 'Medical Conditions');
        $this->assertSame('Keine', $display);
    }

    public function test_fr_note_none_uses_feminine_aucune(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('none', 'Note');
        $this->assertSame('Aucune', $display);
    }

    public function test_de_note_none_uses_keine(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('none', 'Note');
        $this->assertSame('Keine', $display);
    }

    public function test_fr_masculine_none_default_aucun_for_season(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('none', 'Season');
        $this->assertSame('Aucun', $display);
    }

    public function test_de_masculine_none_default_keine_for_season(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('none', 'Season');
        $this->assertSame('Keine', $display);
    }

    public function test_fr_discount_condition_none_label_feminine_aucune(): void {
        $this->bindLocale('fr_FR');
        $this->assertSame('Aucune', intersoccer_pv_translate_none_feminine_label());
    }

    public function test_de_discount_condition_none_label_keine(): void {
        $this->bindLocale('de_DE');
        $this->assertSame('Keine', intersoccer_pv_translate_none_feminine_label());
    }

    public function test_fr_wcml_activity_type_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            701 => [
                'taxonomy' => 'pa_activity-type',
                'slug' => 'course',
                'name' => 'Cours',
            ],
        ];
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('course', 'Activity Type');
        $this->assertSame('Cours', $display);
    }

    public function test_de_wcml_activity_type_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            711 => [
                'taxonomy' => 'pa_activity-type',
                'slug' => 'camp',
                'name' => 'Camp',
            ],
        ];
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('camp', 'Activity Type');
        $this->assertSame('Camp', $display);
    }

    public function test_fr_wcml_booking_type_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            712 => [
                'taxonomy' => 'pa_booking-type',
                'slug' => 'full-term',
                'name' => 'Semestre complet',
            ],
        ];
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('full-term', 'Booking Type');
        $this->assertSame('Semestre complet', $display);
    }

    public function test_de_wcml_booking_type_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            702 => [
                'taxonomy' => 'pa_booking-type',
                'slug' => 'full-term',
                'name' => 'Ganzes Semester',
            ],
        ];
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('full-term', 'Booking Type');
        $this->assertSame('Ganzes Semester', $display);
    }

    public function test_fr_wcml_note_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            703 => [
                'taxonomy' => 'pa_note',
                'slug' => 'join-anytime',
                'name' => 'Inscription à tout moment',
            ],
        ];
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $display = intersoccer_translate_stored_order_meta_display_value('join-anytime', 'Note');
        $this->assertSame('Inscription à tout moment', $display);
    }

    public function test_de_wcml_note_slug_resolves_to_term_name(): void {
        $GLOBALS['intersoccer_test_terms'] = [
            713 => [
                'taxonomy' => 'pa_note',
                'slug' => 'join-anytime',
                'name' => 'Jederzeit einsteigen',
            ],
        ];
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $display = intersoccer_translate_stored_order_meta_display_value('join-anytime', 'Note');
        $this->assertSame('Jederzeit einsteigen', $display);
    }

    /**
     * @return array<int,object>
     */
    private function courseLineFormattedMetaWrongDayFirst(): array {
        return [
            (object) ['key' => 'Course Day', 'display_key' => 'Course Day', 'value' => 'Monday', 'display_value' => 'Monday'],
            (object) ['key' => 'Activity Type', 'display_key' => 'Activity Type', 'value' => 'Course', 'display_value' => 'Course'],
            (object) ['key' => 'Start Date', 'display_key' => 'Start Date', 'value' => 'September 1, 2026', 'display_value' => 'September 1, 2026'],
            (object) ['key' => 'Course Times', 'display_key' => 'Course Times', 'value' => '1000-1700', 'display_value' => '1000-1700'],
        ];
    }

    /**
     * EN checkout meta_id order: Activity Type and dates before Course Day, then Course Times (~949–950, ~548–555).
     *
     * @return list<string>
     */
    private function expectedEnCourseCustomerMetaKeyOrder(): array {
        return ['Activity Type', 'Start Date', 'Course Day', 'Course Times'];
    }

    public function test_en_course_customer_meta_preserves_checkout_meta_id_order(): void {
        global $intersoccer_pv_test_determine_locale;
        $intersoccer_pv_test_determine_locale = 'en_US';
        MockFilters::$filters['wpml_current_language'] = static function () {
            return 'en';
        };
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $meta = $this->courseLineFormattedMetaWrongDayFirst();
        usort($meta, static function ($a, $b) {
            $order = ['Activity Type' => 0, 'Start Date' => 1, 'Course Day' => 2, 'Course Times' => 3];

            return ($order[$a->key] ?? 99) <=> ($order[$b->key] ?? 99);
        });
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $keys = array_map(static fn($m) => (string) $m->key, $filtered);
        $this->assertSame($this->expectedEnCourseCustomerMetaKeyOrder(), $keys);
        unset($GLOBALS['intersoccer_test_product_type']);
        MockFilters::reset();
    }

    public function test_fr_course_customer_meta_matches_en_row_order(): void {
        $this->bindLocale('fr_FR');
        $this->setWpmlLanguage('fr');
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->courseLineFormattedMetaWrongDayFirst(), $item);
        $keys = array_map(static fn($m) => (string) $m->key, $filtered);
        $this->assertSame($this->expectedEnCourseCustomerMetaKeyOrder(), $keys);
        unset($GLOBALS['intersoccer_test_product_type']);
    }

    public function test_de_course_customer_meta_matches_en_row_order(): void {
        $this->bindLocale('de_DE');
        $this->setWpmlLanguage('de');
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($this->courseLineFormattedMetaWrongDayFirst(), $item);
        $keys = array_map(static fn($m) => (string) $m->key, $filtered);
        $this->assertSame($this->expectedEnCourseCustomerMetaKeyOrder(), $keys);
        unset($GLOBALS['intersoccer_test_product_type']);
    }
}
