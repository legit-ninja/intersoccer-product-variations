<?php
/**
 * Order meta contract tests.
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

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!class_exists('MockFilters')) {
    class MockFilters {
        public static $filters = [];

        public static function reset() {
            self::$filters = [];
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        $hook = array_shift($args);
        $value = array_shift($args);

        if (isset(MockFilters::$filters[$hook]) && is_callable(MockFilters::$filters[$hook])) {
            array_unshift($args, $value);
            return call_user_func_array(MockFilters::$filters[$hook], $args);
        }

        return $value;
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) {
        return false;
    }
}

if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($product_id, $taxonomy, $args = []) {
        return [];
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_scalar($str) ? (string) $str : '';
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

if (!class_exists('WC_Order')) {
    class WC_Order {
        private $customer_id;
        public function __construct($customer_id = 0) {
            $this->customer_id = (int) $customer_id;
        }
        public function get_customer_id() {
            return $this->customer_id;
        }
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

class OrderMetaContractTest extends TestCase {
    /** @var array<string,mixed> */
    public static $mock_player_details = [];

    /** @var array<string,mixed>|null */
    public static $mock_parent_attributes = null;

    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        self::$mock_player_details = [];
        self::$mock_parent_attributes = null;
    }

    public function test_correctable_keys_include_attendee_fields() {
        $keys = intersoccer_order_meta_correctable_keys();
        $this->assertContains('Activity Type', $keys);
        $this->assertContains('Attendee DOB', $keys);
        $this->assertContains('Attendee Gender', $keys);
        $this->assertContains('Medical Conditions', $keys);
        $this->assertContains('assigned_player_id', $keys);
    }

    public function test_build_order_line_meta_enriches_attendee_when_cart_has_name_and_player_index() {
        if (!function_exists('intersoccer_get_player_details')) {
            function intersoccer_get_player_details($user_id, $player_index) {
                return OrderMetaContractTest::$mock_player_details;
            }
        }

        self::$mock_player_details = [
            'name' => 'Fallback Name',
            'dob' => '2015-03-10',
            'gender' => 'Male',
            'medical_conditions' => 'Asthma',
        ];

        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'camp',
            'cart_values' => [
                'assigned_attendee' => 'Cart Name',
                'assigned_player' => 1,
            ],
            'order' => new WC_Order(42),
        ]);

        $updates = $built['updates'];
        $this->assertSame('Cart Name', $updates['Assigned Attendee']);
        $this->assertSame(1, $updates['assigned_player']);
        $this->assertSame('2015-03-10', $updates['Attendee DOB']);
        $this->assertSame('Male', $updates['Attendee Gender']);
        $this->assertSame('Asthma', $updates['Medical Conditions']);
    }

    public function test_build_order_line_meta_writes_assigned_player_id_from_uuid() {
        if (!function_exists('intersoccer_get_player_by_id')) {
            function intersoccer_get_player_by_id($user_id, $player_id) {
                if ($player_id === 'uuid-test-1234-5678-90ab-cdef12345678') {
                    return [
                        'key' => 2,
                        'player' => [
                            'player_id' => $player_id,
                            'first_name' => 'Uuid',
                            'last_name' => 'Child',
                            'dob' => '2014-06-01',
                            'gender' => 'Female',
                            'medical_conditions' => '',
                        ],
                    ];
                }
                return null;
            }
        }

        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'camp',
            'cart_values' => [
                'assigned_player_id' => 'uuid-test-1234-5678-90ab-cdef12345678',
            ],
            'order' => new WC_Order(42),
        ]);

        $updates = $built['updates'];
        $this->assertSame('uuid-test-1234-5678-90ab-cdef12345678', $updates['assigned_player_id']);
        $this->assertSame(2, $updates['assigned_player']);
        $this->assertSame('Uuid Child', $updates['Assigned Attendee']);
    }

    public function test_collect_variation_taxonomy_meta_returns_empty_without_variation() {
        $this->assertSame([], intersoccer_collect_variation_taxonomy_meta(0));
    }

    public function test_deprecated_keys_include_player_index_and_variation_id() {
        $keys = intersoccer_order_meta_deprecated_keys();
        $this->assertContains('Player Index', $keys);
        $this->assertContains('Variation ID', $keys);
        $this->assertContains('Base Price', $keys);
        $this->assertContains('Remaining Sessions', $keys);
    }

    public function test_allowed_keys_include_girls_only_for_camp() {
        $keys = intersoccer_order_meta_allowed_keys('camp');
        $this->assertContains('Girls Only', $keys);
        $this->assertContains('assigned_player', $keys);
        $this->assertNotContains('Player Index', $keys);
    }

    public function test_resolve_order_activity_type_composite_when_filtered_girls_only() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return true;
        };

        $value = intersoccer_resolve_order_activity_type(100, 200, 'camp', '');
        $this->assertStringContainsString('Camp', $value);
        $this->assertStringContainsString('Girls Only', $value);
    }

    public function test_resolve_order_activity_type_plain_when_not_girls_only() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return false;
        };

        $this->assertSame('Camp', intersoccer_resolve_order_activity_type(100, 200, 'camp', ''));
    }

    public function test_activity_type_girls_only_suffix_localized() {
        $this->assertSame('Girls Only', intersoccer_order_activity_type_girls_only_suffix('en'));
        $this->assertSame('Filles uniquement', intersoccer_order_activity_type_girls_only_suffix('fr'));
    }

    public function test_checkout_write_does_not_duplicate_existing_attribute_keys() {
        $item = new WC_Order_Item_Product([
            'Activity Type' => 'Camp',
        ]);

        $written = intersoccer_write_order_line_meta($item, [
            'mode' => 'checkout',
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        $this->assertTrue($written);

        $activity_type_count = 0;
        foreach ($item->get_meta_data() as $meta) {
            if ($meta->key === 'Activity Type') {
                $activity_type_count++;
            }
        }

        $this->assertSame(1, $activity_type_count);
        $this->assertSame('Camp', $item->get_meta('Activity Type', true));
    }

    public function test_repair_mode_normalizes_french_meta_key_to_english() {
        $item = new WC_Order_Item_Product([
            'Lieux InterSoccer' => 'geneve-centre-sportif',
        ]);

        $changed = intersoccer_normalize_legacy_order_meta_keys($item);

        $this->assertTrue($changed);
        $this->assertSame('geneve-centre-sportif', $item->get_meta('Sites InterSoccer', true));
        $this->assertSame('', $item->get_meta('Lieux InterSoccer', true));
    }

    public function test_repair_mode_normalizes_german_meta_key_to_english() {
        $item = new WC_Order_Item_Product([
            'Buchungstyp' => 'Full Week',
        ]);

        $changed = intersoccer_normalize_legacy_order_meta_keys($item);

        $this->assertTrue($changed);
        $this->assertSame('Full Week', $item->get_meta('Booking Type', true));
        $this->assertSame('', $item->get_meta('Buchungstyp', true));
    }

    public function test_repair_mode_does_not_overwrite_existing_canonical_keys() {
        $item = new WC_Order_Item_Product([
            'Booking Type' => 'Full Week',
            'Buchungstyp' => 'Ganze Woche',
        ]);

        $changed = intersoccer_normalize_legacy_order_meta_keys($item);

        $this->assertFalse($changed);
        $this->assertSame('Full Week', $item->get_meta('Booking Type', true));
    }

    public function test_checkout_write_removes_legacy_venue_label_when_writing_canonical() {
        if (!function_exists('intersoccer_get_parent_product_attributes')) {
            function intersoccer_get_parent_product_attributes($product_id, $variation_id) {
                return OrderMetaContractTest::$mock_parent_attributes ?? [];
            }
        }

        self::$mock_parent_attributes = [
            'Sites InterSoccer' => 'Geneva',
        ];

        $item = new WC_Order_Item_Product([
            'InterSoccer Venues' => 'Geneva',
        ]);

        $written = intersoccer_write_order_line_meta($item, [
            'mode' => 'checkout',
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        $this->assertTrue($written);

        $keys = [];
        foreach ($item->get_meta_data() as $meta) {
            $keys[] = $meta->key;
        }

        $this->assertNotContains('InterSoccer Venues', $keys);
        $this->assertContains('Sites InterSoccer', $keys);
        $this->assertSame('Geneva', $item->get_meta('Sites InterSoccer', true));
    }

}
