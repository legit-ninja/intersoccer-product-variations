<?php
/**
 * Customer-facing readable rows when order lines store slug-only attribute meta.
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

if (!function_exists('translate')) {
    function translate($text, $domain = 'default') {
        global $intersoccer_pv_test_textdomain_strings;
        if (is_array($intersoccer_pv_test_textdomain_strings) && isset($intersoccer_pv_test_textdomain_strings[$domain][$text])) {
            return $intersoccer_pv_test_textdomain_strings[$domain][$text];
        }

        return $text;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
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

class OrderMetaCustomerSlugOnlyAttributeDisplayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_terms'] = [
            501 => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13y-full-day',
                'name' => '5-13y (Full Day)',
            ],
            502 => [
                'taxonomy' => 'pa_age-group',
                'slug' => '5-13y-full-day',
                'name' => '5-13 ans (journée complète)',
                'wpml_lang' => 'fr',
            ],
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        $GLOBALS['intersoccer_pv_test_textdomain_strings'] = [
            'intersoccer-product-variations' => [
                'Age Group' => 'Groupe d\'âge',
            ],
        ];
        $GLOBALS['intersoccer_pv_test_textdomain_loaded'] = ['intersoccer-product-variations' => true];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_sensitive_order_meta_email'],
            $GLOBALS['intersoccer_pv_test_textdomain_strings'],
            $GLOBALS['intersoccer_pv_test_textdomain_loaded']
        );
        parent::tearDown();
    }

    /**
     * @return array<int,object>
     */
    private function slug_only_age_group_meta(): array {
        return [
            (object) [
                'key' => 'pa_age-group',
                'display_key' => 'Age Group',
                'value' => '5-13y-full-day',
                'display_value' => '5-13y-full-day',
            ],
            (object) [
                'key' => 'attribute_pa_age-group',
                'display_key' => 'Age Group',
                'value' => '5-13y-full-day',
                'display_value' => '5-13y-full-day',
            ],
        ];
    }

    /**
     * @param array<int,object> $meta
     * @param string              $context
     * @return array<int,object>
     */
    private function run_customer_display_pipeline(array $meta, string $context): array {
        $item = new WC_Order_Item_Product();
        if ($context === 'email') {
            $GLOBALS['intersoccer_test_is_admin'] = true;
            $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
        } elseif ($context === 'my_account') {
            $GLOBALS['intersoccer_test_is_admin'] = false;
            unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        }

        $deduped = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta);

        return intersoccer_filter_customer_order_item_meta($deduped, $item);
    }

    #[DataProvider('customer_context_provider')]
    public function test_slug_only_line_shows_one_readable_row(string $context): void {
        $filtered = $this->run_customer_display_pipeline($this->slug_only_age_group_meta(), $context);
        $this->assertCount(1, $filtered);
        $this->assertSame('Groupe d\'âge', $filtered[0]->display_key, $context);
        $this->assertSame('5-13y (Full Day)', $filtered[0]->value, $context);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function customer_context_provider(): array {
        return [
            'email_html_or_plain' => ['email'],
            'my_account' => ['my_account'],
        ];
    }

    public function test_slug_only_term_not_found_falls_back_to_stored_slug(): void {
        unset($GLOBALS['intersoccer_test_terms']);
        $meta = [
            (object) [
                'key' => 'pa_age-group',
                'display_key' => 'Age Group',
                'value' => 'unknown-slug',
            ],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta(
            intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta),
            new WC_Order_Item_Product()
        );
        $this->assertCount(1, $filtered);
        $this->assertSame('unknown-slug', $filtered[0]->value);
    }

    public function test_readable_label_row_still_shows_once_without_extra_synthetic_row(): void {
        $meta = [
            (object) ['key' => 'Age Group', 'display_key' => 'Age Group', 'value' => '5-13y (Full Day)'],
            (object) ['key' => 'pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
            (object) ['key' => 'attribute_pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
        ];
        $filtered = intersoccer_filter_customer_order_item_meta(
            intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta),
            new WC_Order_Item_Product()
        );
        $age_rows = array_filter($filtered, static function ($row) {
            $canonical = intersoccer_pv_canonical_stored_order_meta_key((string) $row->key);

            return $canonical === 'Age Group';
        });
        $this->assertCount(1, $age_rows);
    }

    public function test_customer_synthesis_does_not_mutate_stored_order_item_meta(): void {
        $item = new class extends WC_Order_Item_Product {
            /** @var array<int,array{key:string,value:string}> */
            private $meta = [['key' => 'pa_age-group', 'value' => '5-13y-full-day']];

            public function get_meta_data() {
                return [(object) $this->meta[0]];
            }
        };
        $before = $item->get_meta_data();
        intersoccer_filter_customer_order_item_meta(
            intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($this->slug_only_age_group_meta()),
            $item
        );
        $after = $item->get_meta_data();
        $this->assertSame((string) $before[0]->key, (string) $after[0]->key);
        $this->assertSame($before[0]->value, $after[0]->value);
    }

    public function test_admin_pipeline_unchanged_for_slug_only_input(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        $item = new WC_Order_Item_Product();
        $meta = $this->slug_only_age_group_meta();
        $admin = intersoccer_pv_filter_admin_order_item_formatted_meta($meta, $item);
        $admin = intersoccer_pv_filter_dedupe_taxonomy_attribute_formatted_meta($admin, $item);
        $keys = array_map(static fn($m) => (string) $m->key, $admin);
        $this->assertSame(['pa_age-group'], $keys);
    }
}
