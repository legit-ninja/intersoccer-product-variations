<?php
/**
 * Display-only dedupe of pa_* / attribute_pa_* order line twins (stored meta unchanged).
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
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

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        /** @var array<int,array{key:string,value:mixed}> */
        private $meta = [];

        public function __construct(array $meta = []) {
            foreach ($meta as $key => $value) {
                $this->meta[] = ['key' => $key, 'value' => $value];
            }
        }

        public function get_meta_data() {
            $result = [];
            foreach ($this->meta as $row) {
                $result[] = (object) ['key' => $row['key'], 'value' => $row['value']];
            }

            return $result;
        }

        public function get_meta($key, $single = true) {
            $values = [];
            foreach ($this->meta as $row) {
                if ($row['key'] === $key) {
                    $values[] = $row['value'];
                }
            }

            if (!$single) {
                return $values;
            }

            return $values[0] ?? '';
        }

        public function get_product_id() {
            return 0;
        }

        public function get_variation_id() {
            return 0;
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

            $this->meta[] = ['key' => $key, 'value' => $value];
        }

        public function update_meta_data($key, $value) {
            $this->delete_meta_data($key);
            $this->meta[] = ['key' => $key, 'value' => $value];
        }

        public function delete_meta_data($key) {
            $this->meta = array_values(array_filter($this->meta, static function ($row) use ($key) {
                return $row['key'] !== $key;
            }));
        }

        public function get_order() {
            return null;
        }
    }
}

class OrderItemAttributeTwinDisplayTest extends TestCase {

    /** @var array<string,mixed> */
    private $globals_backup = [];

    protected function setUp(): void {
        parent::setUp();
        $this->globals_backup = [
            'intersoccer_test_is_admin' => $GLOBALS['intersoccer_test_is_admin'] ?? null,
            'intersoccer_sensitive_order_meta_email' => $GLOBALS['intersoccer_sensitive_order_meta_email'] ?? null,
            'intersoccer_test_doing_ajax' => $GLOBALS['intersoccer_test_doing_ajax'] ?? null,
        ];
        unset($GLOBALS['intersoccer_test_doing_ajax']);
    }

    protected function tearDown(): void {
        foreach ($this->globals_backup as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /**
     * @return array<int,object>
     */
    private function fresh_checkout_formatted_meta_triplets(bool $use_legacy_venue_label = false): array {
        $venue_human_key = $use_legacy_venue_label ? 'InterSoccer Venues' : 'Sites InterSoccer';

        return [
            (object) [
                'key' => 'Activity Type',
                'display_key' => 'Activity Type',
                'value' => 'Camp',
            ],
            (object) [
                'key' => 'pa_activity-type',
                'display_key' => 'Activity Type',
                'value' => 'camp',
            ],
            (object) [
                'key' => 'attribute_pa_activity-type',
                'display_key' => 'Activity Type',
                'value' => 'camp',
            ],
            (object) [
                'key' => $venue_human_key,
                'display_key' => 'Sites InterSoccer',
                'value' => 'Geneva',
            ],
            (object) [
                'key' => 'pa_intersoccer-venues',
                'display_key' => 'InterSoccer Venues',
                'value' => 'geneva-stade',
            ],
            (object) [
                'key' => 'attribute_pa_intersoccer-venues',
                'display_key' => 'InterSoccer Venues',
                'value' => 'geneva-stade',
            ],
            (object) [
                'key' => 'Age Group',
                'display_key' => 'Age Group',
                'value' => '5-13y (Full Day)',
            ],
            (object) [
                'key' => 'pa_age-group',
                'display_key' => 'Age Group',
                'value' => '5-13y-full-day',
            ],
            (object) [
                'key' => 'attribute_pa_age-group',
                'display_key' => 'Age Group',
                'value' => '5-13y-full-day',
            ],
        ];
    }

    /**
     * @param array<int,object> $formatted_meta
     * @return array<string,int>
     */
    private function count_canonical_attribute_rows(array $formatted_meta): array {
        $counts = [];
        foreach ($formatted_meta as $meta) {
            if (!is_object($meta)) {
                continue;
            }
            $key = (string) ($meta->key ?? '');
            if ($key === '' || intersoccer_pv_is_hidden_slug_attribute_meta_key($key)) {
                continue;
            }
            $canonical = intersoccer_pv_canonical_stored_order_meta_key($key);
            if ($canonical === '' || !function_exists('intersoccer_attr_order_meta_label_map')) {
                continue;
            }
            $labels = array_values(intersoccer_attr_order_meta_label_map());
            if (!in_array($canonical, $labels, true)) {
                continue;
            }
            $counts[$canonical] = ($counts[$canonical] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Mirrors WooCommerce filter order: admin underscore strip (5), twin dedupe (10), customer meta (10).
     *
     * @param array<int,object>     $formatted_meta
     * @param WC_Order_Item_Product $item
     * @param string                $context admin|customer|email|my_account
     * @return array<int,object>
     */
    private function run_formatted_meta_display_pipeline(array $formatted_meta, WC_Order_Item_Product $item, string $context): array {
        if ($context === 'admin') {
            $GLOBALS['intersoccer_test_is_admin'] = true;
            unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        } elseif ($context === 'customer' || $context === 'my_account') {
            $GLOBALS['intersoccer_test_is_admin'] = false;
            unset($GLOBALS['intersoccer_sensitive_order_meta_email']);
        } elseif ($context === 'email') {
            $GLOBALS['intersoccer_test_is_admin'] = true;
            $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;
        }

        $formatted_meta = intersoccer_pv_filter_admin_order_item_formatted_meta($formatted_meta, $item);
        $formatted_meta = intersoccer_pv_filter_dedupe_taxonomy_attribute_formatted_meta($formatted_meta, $item);
        $formatted_meta = intersoccer_filter_customer_order_item_meta($formatted_meta, $item);

        return $formatted_meta;
    }

    /**
     * Minimal stand-in for reports-rosters priority-20 dedupe (admin documented rule).
     *
     * @param array<int|mixed,object> $formatted_meta
     * @return array<int|mixed,object>
     */
    private function rr_standalone_dedupe_formatted_meta(array $formatted_meta): array {
        if ($formatted_meta === []) {
            return $formatted_meta;
        }

        $keys_present = [];
        foreach ($formatted_meta as $meta) {
            if (is_object($meta)) {
                $keys_present[(string) ($meta->key ?? '')] = true;
            }
        }

        $taxonomies = [
            'pa_intersoccer-venues' => 'intersoccer-venues',
            'pa_age-group' => 'age-group',
            'pa_activity-type' => 'activity-type',
        ];

        foreach ($taxonomies as $taxonomy => $slug) {
            if (!intersoccer_pv_formatted_meta_has_human_facet_key($keys_present, $slug)) {
                continue;
            }
            foreach ($formatted_meta as $meta_id => $meta) {
                if (!is_object($meta)) {
                    continue;
                }
                $key = (string) ($meta->key ?? '');
                if ($key === $taxonomy || $key === 'attribute_' . $taxonomy) {
                    unset($formatted_meta[$meta_id]);
                }
            }
        }

        return intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($formatted_meta);
    }

    public function test_dedupe_direct_output_is_context_independent(): void {
        $meta = $this->fresh_checkout_formatted_meta_triplets();
        $once = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta);
        $counts = $this->count_canonical_attribute_rows($once);
        $this->assertSame(1, $counts['Activity Type'] ?? 0);
        $this->assertSame(1, $counts['Sites InterSoccer'] ?? 0);
        $this->assertSame(1, $counts['Age Group'] ?? 0);
        $this->assertSame($once, intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta));
    }

    /**
     * @dataProvider display_context_provider
     */
    public function test_fresh_checkout_shows_each_attribute_once(string $context): void {
        $item = new WC_Order_Item_Product([]);
        $meta = $this->fresh_checkout_formatted_meta_triplets();
        $filtered = $this->run_formatted_meta_display_pipeline($meta, $item, $context);
        $counts = $this->count_canonical_attribute_rows($filtered);
        $this->assertSame(1, $counts['Activity Type'] ?? 0, $context);
        $this->assertSame(1, $counts['Sites InterSoccer'] ?? 0, $context);
        $this->assertSame(1, $counts['Age Group'] ?? 0, $context);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function display_context_provider(): array {
        return [
            'admin' => ['admin'],
            'customer' => ['customer'],
            'email' => ['email'],
            'my_account' => ['my_account'],
        ];
    }

    public function test_french_and_german_readable_labels_dedupe_like_english(): void {
        $meta = [
            (object) [
                'key' => "Type d'activité",
                'display_key' => 'Activity Type',
                'value' => 'Camp',
            ],
            (object) ['key' => 'pa_activity-type', 'display_key' => 'Activity Type', 'value' => 'camp'],
            (object) ['key' => 'attribute_pa_activity-type', 'display_key' => 'Activity Type', 'value' => 'camp'],
            (object) [
                'key' => 'Altersgruppe',
                'display_key' => 'Age Group',
                'value' => '5-13y',
            ],
            (object) ['key' => 'pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
            (object) ['key' => 'attribute_pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
        ];
        $deduped = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta);
        $counts = $this->count_canonical_attribute_rows($deduped);
        $this->assertSame(1, $counts['Activity Type'] ?? 0);
        $this->assertSame(1, $counts['Age Group'] ?? 0);

        $legacy_venue = $this->fresh_checkout_formatted_meta_triplets(true);
        $deduped_venue = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($legacy_venue);
        $venue_counts = $this->count_canonical_attribute_rows($deduped_venue);
        $this->assertSame(1, $venue_counts['Sites InterSoccer'] ?? 0);
    }

    public function test_slug_only_meta_shows_one_row_per_attribute(): void {
        $meta = [
            (object) ['key' => 'pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
            (object) ['key' => 'attribute_pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
            (object) ['key' => 'pa_intersoccer-venues', 'display_key' => 'InterSoccer Venues', 'value' => 'geneva'],
            (object) ['key' => 'attribute_pa_intersoccer-venues', 'display_key' => 'InterSoccer Venues', 'value' => 'geneva'],
        ];
        $deduped = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($meta);
        $keys = array_map(static fn($m) => (string) $m->key, $deduped);
        $this->assertContains('pa_age-group', $keys);
        $this->assertNotContains('attribute_pa_age-group', $keys);
        $this->assertContains('pa_intersoccer-venues', $keys);
        $this->assertNotContains('attribute_pa_intersoccer-venues', $keys);
    }

    public function test_dedupe_does_not_mutate_stored_order_item_meta(): void {
        $item = new WC_Order_Item_Product([]);
        $stored = [
            'Sites InterSoccer' => 'Geneva',
            'pa_intersoccer-venues' => 'geneva-stade',
            'attribute_pa_intersoccer-venues' => 'geneva-stade',
            'Age Group' => '5-13y (Full Day)',
            'pa_age-group' => '5-13y-full-day',
            'attribute_pa_age-group' => '5-13y-full-day',
        ];
        foreach ($stored as $key => $value) {
            $item->add_meta_data($key, $value, true);
        }
        $before = [];
        foreach ($item->get_meta_data() as $meta) {
            $before[(string) $meta->key] = $meta->value;
        }

        intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($this->fresh_checkout_formatted_meta_triplets());

        $after = [];
        foreach ($item->get_meta_data() as $meta) {
            $after[(string) $meta->key] = $meta->value;
        }
        $this->assertSame($before, $after);
    }

    public function test_pv_and_rr_standalone_coexistence_never_hides_sole_attribute(): void {
        $meta = $this->fresh_checkout_formatted_meta_triplets();
        $orders = [
            ['pv', 'rr'],
            ['rr', 'pv'],
        ];
        foreach ($orders as $order) {
            $working = $meta;
            foreach ($order as $step) {
                if ($step === 'pv') {
                    $working = intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($working);
                } else {
                    $working = $this->rr_standalone_dedupe_formatted_meta($working);
                }
            }
            $counts = $this->count_canonical_attribute_rows($working);
            $this->assertSame(1, $counts['Activity Type'] ?? 0, implode(',', $order));
            $this->assertSame(1, $counts['Sites InterSoccer'] ?? 0, implode(',', $order));
            $this->assertSame(1, $counts['Age Group'] ?? 0, implode(',', $order));
        }
    }

    public function test_rr_standalone_alone_on_slug_only_keeps_one_row(): void {
        $meta = [
            (object) ['key' => 'pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
            (object) ['key' => 'attribute_pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y-full-day'],
        ];
        $deduped = $this->rr_standalone_dedupe_formatted_meta($meta);
        $this->assertCount(1, $deduped);
    }

    public function test_production_registers_dedupe_filter_at_priority_ten(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php');
        $this->assertMatchesRegularExpression(
            "/add_filter\\s*\\(\\s*'woocommerce_order_item_get_formatted_meta_data'\\s*,\\s*'intersoccer_pv_filter_dedupe_taxonomy_attribute_formatted_meta'\\s*,\\s*10\\s*,/",
            $source
        );
    }
}
