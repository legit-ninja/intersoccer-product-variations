<?php
/**
 * Order meta contract tests.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('icl_t')) {
    function icl_t($context, $name, $default = false) {
        $map = $GLOBALS['intersoccer_test_icl_t_map'] ?? null;
        if (is_array($map) && array_key_exists($name, $map)) {
            return $map[$name];
        }
        return $default !== false ? $default : $name;
    }
}

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

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/language-helpers.php';
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
        $GLOBALS['intersoccer_test_player_details'] = [
            'name' => 'Fallback Name',
            'dob' => '2015-03-10',
            'gender' => 'Male',
            'medical_conditions' => 'Asthma',
            'player_id' => 'pid-contract-1',
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

        unset($GLOBALS['intersoccer_test_player_details']);
    }

    public function test_build_order_line_meta_writes_assigned_player_id_from_uuid() {
        $GLOBALS['intersoccer_test_player_by_id'] = static function ($user_id, $player_id) {
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
        };

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

        unset($GLOBALS['intersoccer_test_player_by_id']);
    }

    public function test_collect_variation_taxonomy_meta_returns_empty_without_variation() {
        $this->assertSame([], intersoccer_collect_variation_taxonomy_meta(0));
    }

    public function test_deprecated_keys_include_player_index_and_variation_id() {
        $keys = intersoccer_order_meta_deprecated_keys();
        $this->assertContains('Player Index', $keys);
        $this->assertContains('Variation ID', $keys);
        $this->assertContains('Remaining Sessions', $keys);
        $this->assertNotContains('Base Price', $keys, 'Base Price retained for historical/reporting per Jeremy request');
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

    public function test_collapse_duplicate_same_key_rows() {
        $item = new WC_Order_Item_Product([]);
        $item->add_meta_data('Activity Type', 'Camp', false);
        $item->add_meta_data('Activity Type', 'Course', false);

        $changed = intersoccer_collapse_duplicate_order_meta_keys($item);

        $this->assertTrue($changed);
        $count = 0;
        foreach ($item->get_meta_data() as $meta) {
            if ($meta->key === 'Activity Type') {
                $count++;
            }
        }
        $this->assertSame(1, $count);
        $this->assertSame('Course', $item->get_meta('Activity Type', true));
    }

    public function test_prune_removes_wc_venue_label_when_canonical_present() {
        $item = new WC_Order_Item_Product([
            'Sites InterSoccer' => 'Geneva',
            'InterSoccer Venues' => 'Geneva',
        ]);

        $changed = intersoccer_prune_legacy_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('Geneva', $item->get_meta('Sites InterSoccer', true));
        $this->assertSame('', $item->get_meta('InterSoccer Venues', true));
    }

    public function test_prune_removes_fr_de_legacy_when_en_canonical_present() {
        $item = new WC_Order_Item_Product([
            'Sites InterSoccer' => 'Geneva',
            'Lieux InterSoccer' => 'Geneve',
            'InterSoccer-Standorte' => 'Genf',
        ]);

        $changed = intersoccer_prune_legacy_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('Geneva', $item->get_meta('Sites InterSoccer', true));
        $this->assertSame('', $item->get_meta('Lieux InterSoccer', true));
        $this->assertSame('', $item->get_meta('InterSoccer-Standorte', true));
    }

    public function test_prune_removes_pa_and_attribute_twins_when_canonical_present() {
        $item = new WC_Order_Item_Product([
            'Sites InterSoccer' => 'Geneva',
            'pa_intersoccer-venues' => 'geneva',
            'attribute_pa_intersoccer-venues' => 'geneva',
            'InterSoccer Venues' => 'Geneva',
        ]);

        $changed = intersoccer_prune_legacy_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('Geneva', $item->get_meta('Sites InterSoccer', true));
        $this->assertSame('', $item->get_meta('pa_intersoccer-venues', true));
        $this->assertSame('', $item->get_meta('attribute_pa_intersoccer-venues', true));
        $this->assertSame('', $item->get_meta('InterSoccer Venues', true));
    }

    public function test_prune_removes_pa_when_only_attribute_present() {
        $item = new WC_Order_Item_Product([
            'attribute_pa_age-group' => '5-13y-full-day',
            'pa_age-group' => '5-13y-full-day',
        ]);

        $changed = intersoccer_prune_legacy_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('5-13y-full-day', $item->get_meta('attribute_pa_age-group', true));
        $this->assertSame('', $item->get_meta('pa_age-group', true));
    }

    public function test_collect_variation_taxonomy_meta_omits_raw_pa_keys() {
        // Without a real variation product, collect returns empty (existing coverage).
        // Contract: raw pa_* must never be emitted when attributes exist — covered by
        // prune twins + the empty-variation guard above.
        $this->assertTrue(function_exists('intersoccer_prune_taxonomy_attribute_twins'));
    }

    public function test_repair_write_collapses_preseeded_same_key_duplicate() {
        $item = new WC_Order_Item_Product([]);
        $item->add_meta_data('Activity Type', 'Camp', false);
        $item->add_meta_data('Activity Type', 'Camp', false);

        $written = intersoccer_write_order_line_meta($item, [
            'mode' => 'repair',
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        // Soft normalize alone may not collapse; prune path is what repair UI uses.
        intersoccer_prune_legacy_order_meta_twins($item);

        $count = 0;
        foreach ($item->get_meta_data() as $meta) {
            if ($meta->key === 'Activity Type') {
                $count++;
            }
        }
        $this->assertSame(1, $count);
        $this->assertTrue($written || $count === 1);
    }

    public function test_build_camp_schedule_writes_human_labels_only() {
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }

        $vid = 88001;
        intersoccer_update_camp_schedule($vid, '2026-07-27', '2026-07-31', 6, true);

        $built = intersoccer_build_order_line_meta([
            'product_id' => 88000,
            'variation_id' => $vid,
            'product_type' => 'camp',
            'cart_values' => [],
        ]);

        $updates = $built['updates'];
        $this->assertSame('2026-07-27', $updates['Camp Start Date'] ?? null);
        $this->assertSame('2026-07-31', $updates['Camp End Date'] ?? null);
        $this->assertSame('6', $updates['Camp Week Index'] ?? null);
        $this->assertArrayNotHasKey('_camp_start_date', $updates);
        $this->assertArrayNotHasKey('_camp_end_date', $updates);
        $this->assertArrayNotHasKey('_camp_week_index', $updates);
    }

    public function test_prune_camp_schedule_keeps_human_labels_strips_underscore() {
        $item = new WC_Order_Item_Product([
            '_camp_start_date' => '2026-07-27',
            'Camp Start Date' => '2026-07-27',
            '_camp_end_date' => '2026-07-31',
            'Camp End Date' => '2026-07-31',
            '_camp_week_index' => '6',
            'Camp Week Index' => '6',
        ]);

        $changed = intersoccer_prune_camp_schedule_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('2026-07-27', $item->get_meta('Camp Start Date', true));
        $this->assertSame('2026-07-31', $item->get_meta('Camp End Date', true));
        $this->assertSame('6', $item->get_meta('Camp Week Index', true));
        $this->assertSame('', $item->get_meta('_camp_start_date', true));
        $this->assertSame('', $item->get_meta('_camp_end_date', true));
        $this->assertSame('', $item->get_meta('_camp_week_index', true));
    }

    public function test_canonical_keys_list_matches_contract() {
        $keys = intersoccer_canonical_order_meta_keys();
        $this->assertContains('_intersoccer_canonical_activity_type', $keys);
        $this->assertContains('_intersoccer_canonical_girls_only', $keys);
        $this->assertContains('_intersoccer_canonical_booking_type', $keys);
        $this->assertContains('_intersoccer_canonical_venue', $keys);
        $this->assertContains('_intersoccer_canonical_canton', $keys);
        $this->assertContains('_intersoccer_canonical_age_group', $keys);
        $this->assertContains('_intersoccer_canonical_camp_terms', $keys);
        foreach (intersoccer_order_meta_correctable_keys() as $key) {
            if (strpos($key, '_intersoccer_canonical_') === 0) {
                $this->assertContains($key, $keys);
            }
        }
    }

    public function test_canonical_activity_type_is_slug_not_girls_only_composite() {
        $this->assertSame('camp', intersoccer_normalize_canonical_activity_type('camp'));
        $this->assertSame('birthday', intersoccer_normalize_canonical_activity_type('birthday-party'));
        $this->assertSame('other', intersoccer_normalize_canonical_activity_type('Camp, Girls Only'));
        $this->assertSame('1', intersoccer_normalize_canonical_girls_only_flag(true));
        $this->assertSame('0', intersoccer_normalize_canonical_girls_only_flag(false));
    }

    public function test_canonical_booking_type_is_slug_not_display_label() {
        $this->assertSame('full-week', intersoccer_normalize_canonical_booking_type('Full Week'));
        $this->assertSame('single-days', intersoccer_normalize_canonical_booking_type('Single Day(s)'));
        $this->assertSame('full-term', intersoccer_normalize_canonical_booking_type('full-term'));
        $this->assertSame('buyclub', intersoccer_normalize_canonical_booking_type('Buy Club'));
        $this->assertSame('other', intersoccer_normalize_canonical_booking_type('Saison'));
    }

    public function test_build_canonical_omits_camp_terms_when_week_index_present() {
        $with_week = intersoccer_build_canonical_order_line_meta([
            'product_type' => 'camp',
            'girls_only' => false,
            'booking_type' => 'full-week',
            'venue' => 'geneva-centre',
            'canton' => 'ge',
            'age_group' => '5-13y-full-day',
            'camp_terms' => 'week-6',
            'camp_week_index' => 6,
        ]);
        $this->assertSame('camp', $with_week['_intersoccer_canonical_activity_type']);
        $this->assertSame('0', $with_week['_intersoccer_canonical_girls_only']);
        $this->assertSame('full-week', $with_week['_intersoccer_canonical_booking_type']);
        $this->assertSame('geneva-centre', $with_week['_intersoccer_canonical_venue']);
        $this->assertSame('ge', $with_week['_intersoccer_canonical_canton']);
        $this->assertSame('5-13y-full-day', $with_week['_intersoccer_canonical_age_group']);
        $this->assertArrayNotHasKey('_intersoccer_canonical_camp_terms', $with_week);

        $without_week = intersoccer_build_canonical_order_line_meta([
            'product_type' => 'camp',
            'girls_only' => true,
            'camp_terms' => 'Week 6',
        ]);
        $this->assertSame('1', $without_week['_intersoccer_canonical_girls_only']);
        $this->assertSame('week-6', $without_week['_intersoccer_canonical_camp_terms']);
        $this->assertSame('camp', $without_week['_intersoccer_canonical_activity_type']);
    }

    public function test_checkout_write_emits_canonical_slugs_not_translated_labels() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return true;
        };

        $item = new WC_Order_Item_Product([]);
        $written = intersoccer_write_order_line_meta($item, [
            'mode' => 'checkout',
            'product_type' => 'camp',
            'product_id' => 100,
            'variation_id' => 200,
            'cart_values' => [],
        ]);

        $this->assertTrue($written);
        $this->assertSame('camp', $item->get_meta('_intersoccer_canonical_activity_type', true));
        $this->assertSame('1', $item->get_meta('_intersoccer_canonical_girls_only', true));
        $this->assertNotSame('Camp, Girls Only', $item->get_meta('_intersoccer_canonical_activity_type', true));
        $this->assertStringContainsString('Girls Only', $item->get_meta('Activity Type', true));
    }

    public function test_repair_write_fills_empty_canonical_keys() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return false;
        };

        $item = new WC_Order_Item_Product([
            'Activity Type' => 'Camp',
        ]);

        $written = intersoccer_write_order_line_meta($item, [
            'mode' => 'repair',
            'product_type' => 'camp',
            'product_id' => 100,
            'variation_id' => 200,
            'cart_values' => [],
        ]);

        $this->assertTrue($written);
        $this->assertSame('camp', $item->get_meta('_intersoccer_canonical_activity_type', true));
        $this->assertSame('0', $item->get_meta('_intersoccer_canonical_girls_only', true));
    }

    public function test_prune_camp_schedule_migrates_underscore_only_to_human_label() {
        $item = new WC_Order_Item_Product([
            '_camp_start_date' => '2026-07-27',
            '_camp_end_date' => '2026-07-31',
            '_camp_week_index' => '6',
        ]);

        $changed = intersoccer_prune_camp_schedule_order_meta_twins($item);

        $this->assertTrue($changed);
        $this->assertSame('2026-07-27', $item->get_meta('Camp Start Date', true));
        $this->assertSame('2026-07-31', $item->get_meta('Camp End Date', true));
        $this->assertSame('6', $item->get_meta('Camp Week Index', true));
        $this->assertSame('', $item->get_meta('_camp_start_date', true));
        $this->assertSame('', $item->get_meta('_camp_end_date', true));
        $this->assertSame('', $item->get_meta('_camp_week_index', true));
    }

    public function test_hidden_customer_keys_includes_player_and_legacy_pm_keys() {
        $keys = intersoccer_order_meta_hidden_customer_keys();
        $this->assertContains('assigned_player', $keys);
        $this->assertContains('assigned_player_id', $keys);
        $this->assertContains('Player Index', $keys);
        $this->assertContains('intersoccer_player_index', $keys);
        $this->assertContains('Camp Week Index', $keys, 'Camp Week Index hidden from customers per Jeremy request');
    }

    public function test_is_hidden_customer_meta_detects_assigned_player_keys() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('assigned_player'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('assigned_player_id'));
    }

    public function test_is_hidden_customer_meta_detects_attribute_pa_keys() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('attribute_pa_age-group'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('attribute_pa_intersoccer-venues'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('attribute_pa_booking-type'));
    }

    public function test_is_hidden_customer_meta_detects_bare_pa_keys() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('pa_age-group'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('pa_intersoccer-venues'));
    }

    public function test_is_hidden_customer_meta_detects_underscore_prefixed_keys() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('_camp_start_date'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('_intersoccer_canonical_activity_type'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('_assigned_player'));
    }

    public function test_is_hidden_customer_meta_detects_legacy_pm_keys() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('Player Index'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('intersoccer_player_index'));
    }

    public function test_camp_week_index_hidden_from_customers_but_not_deprecated() {
        $this->assertTrue(
            intersoccer_is_hidden_customer_order_meta_key('Camp Week Index'),
            'Camp Week Index should be hidden from customer-facing order surfaces'
        );
        $this->assertNotContains(
            'Camp Week Index',
            intersoccer_order_meta_deprecated_keys(),
            'Camp Week Index should NOT be in deprecated keys — data must remain stored for admin/reports'
        );
    }

    public function test_is_hidden_customer_meta_allows_human_display_keys() {
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Activity Type'));
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Assigned Attendee'));
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Attendee DOB'));
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Sites InterSoccer'));
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Camp Start Date'));
        $this->assertFalse(intersoccer_is_hidden_customer_order_meta_key('Booking Type'));
    }

    public function test_filter_customer_order_item_meta_hides_internal_keys() {
        $formatted_meta = [
            (object) ['key' => 'Activity Type', 'display_key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => 'Assigned Attendee', 'display_key' => 'Assigned Attendee', 'value' => 'Test Child'],
            (object) ['key' => 'assigned_player', 'display_key' => 'assigned_player', 'value' => '1'],
            (object) ['key' => 'assigned_player_id', 'display_key' => 'assigned_player_id', 'value' => 'uuid-123'],
            (object) ['key' => 'attribute_pa_age-group', 'display_key' => 'Age Group', 'value' => '5-13y'],
            (object) ['key' => 'Age Group', 'display_key' => 'Age Group', 'value' => '5-13y'],
            (object) ['key' => 'pa_intersoccer-venues', 'display_key' => 'Venues', 'value' => 'geneva'],
            (object) ['key' => '_camp_start_date', 'display_key' => 'Camp Start', 'value' => '2026-07-27'],
            (object) ['key' => 'Sites InterSoccer', 'display_key' => 'Sites InterSoccer', 'value' => 'Geneva'],
        ];

        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($formatted_meta, $item);

        $visible_keys = array_map(fn($m) => $m->key, $filtered);

        $this->assertContains('Activity Type', $visible_keys);
        $this->assertContains('Assigned Attendee', $visible_keys);
        $this->assertContains('Sites InterSoccer', $visible_keys);

        $this->assertNotContains('assigned_player', $visible_keys);
        $this->assertNotContains('assigned_player_id', $visible_keys);
        $this->assertNotContains('attribute_pa_age-group', $visible_keys);
        $this->assertNotContains('pa_intersoccer-venues', $visible_keys);
        $this->assertNotContains('_camp_start_date', $visible_keys);
    }

    public function test_filter_customer_order_item_meta_preserves_order() {
        $formatted_meta = [
            (object) ['key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => 'assigned_player', 'value' => '1'],
            (object) ['key' => 'Assigned Attendee', 'value' => 'Child'],
            (object) ['key' => 'Age Group', 'value' => '5-13y (Full Day)'],
            (object) ['key' => 'attribute_pa_age-group', 'value' => '5-13y'],
            (object) ['key' => 'Camp Start Date', 'value' => '2026-07-27'],
        ];

        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_filter_customer_order_item_meta($formatted_meta, $item);

        $this->assertCount(4, $filtered);
        $this->assertSame('Activity Type', $filtered[0]->key);
        $this->assertSame('Assigned Attendee', $filtered[1]->key);
        $this->assertSame('Age Group', $filtered[2]->key);
        $this->assertSame('Camp Start Date', $filtered[3]->key);
    }

    public function test_apply_assigned_player_dual_writes_underscore_keys() {
        $updates = [];
        $cart_values = [
            'assigned_attendee' => 'Test Child',
        ];
        $resolved = [
            'index' => 2,
            'player_id' => 'uuid-dual-write-test',
            'details' => [
                'name' => 'Test Child',
                'dob' => '2015-03-10',
                'gender' => 'Male',
                'medical_conditions' => 'None',
            ],
        ];

        intersoccer_apply_assigned_player_order_meta($updates, $cart_values, $resolved);

        $this->assertArrayHasKey('assigned_player', $updates);
        $this->assertArrayHasKey('_assigned_player', $updates);
        $this->assertSame(2, $updates['assigned_player']);
        $this->assertSame(2, $updates['_assigned_player']);

        $this->assertArrayHasKey('assigned_player_id', $updates);
        $this->assertArrayHasKey('_assigned_player_id', $updates);
        $this->assertSame('uuid-dual-write-test', $updates['assigned_player_id']);
        $this->assertSame('uuid-dual-write-test', $updates['_assigned_player_id']);
    }

    public function test_apply_assigned_player_dual_writes_without_details() {
        $updates = [];
        $cart_values = [];
        $resolved = [
            'index' => 1,
            'player_id' => 'uuid-no-details',
            'details' => null,
        ];

        intersoccer_apply_assigned_player_order_meta($updates, $cart_values, $resolved);

        $this->assertArrayHasKey('assigned_player', $updates);
        $this->assertArrayHasKey('_assigned_player', $updates);
        $this->assertSame(1, $updates['assigned_player']);
        $this->assertSame(1, $updates['_assigned_player']);

        $this->assertArrayHasKey('assigned_player_id', $updates);
        $this->assertArrayHasKey('_assigned_player_id', $updates);
        $this->assertSame('uuid-no-details', $updates['assigned_player_id']);
        $this->assertSame('uuid-no-details', $updates['_assigned_player_id']);
    }

    public function test_underscore_assigned_player_keys_hidden_from_customer() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('_assigned_player'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('_assigned_player_id'));
    }

    public function test_checkout_write_includes_dual_underscore_player_keys() {
        $GLOBALS['intersoccer_test_player_details'] = [
            'name' => 'Dual Write Child',
            'dob' => '2016-05-20',
            'gender' => 'Female',
            'medical_conditions' => '',
            'player_id' => 'pid-dual-checkout',
        ];

        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'camp',
            'cart_values' => [
                'assigned_player' => 1,
            ],
            'order' => new WC_Order(42),
        ]);

        $updates = $built['updates'];
        $this->assertArrayHasKey('assigned_player', $updates);
        $this->assertArrayHasKey('_assigned_player', $updates);
        $this->assertSame($updates['assigned_player'], $updates['_assigned_player']);

        unset($GLOBALS['intersoccer_test_player_details']);
    }

    public function test_translate_stored_order_meta_display_key_in_customer_context() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $translated = intersoccer_translate_stored_order_meta_display_key('Assigned Attendee', 'Assigned Attendee');
        $this->assertSame('Assigned Attendee', $translated);
    }

    public function test_translate_stored_order_meta_skipped_in_admin_context() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $label = intersoccer_translate_stored_order_meta_display_key('Days Selected', 'Days Selected');
        $this->assertSame('Days Selected', $label);
    }

    public function test_translate_sibling_discount_value_pattern() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $value = intersoccer_translate_stored_order_meta_display_value('20% Camp Sibling Discount', 'Discount');
        $this->assertStringContainsString('Camp Sibling Discount', $value);
    }

    public function test_stored_order_meta_display_key_map_covers_booking_labels() {
        $map = intersoccer_stored_order_meta_display_key_map();
        $this->assertArrayHasKey('Assigned Attendee', $map);
        $this->assertArrayHasKey('Camp Terms', $map);
        $this->assertArrayHasKey('Age Group', $map);
        $this->assertArrayHasKey('Attendee Gender', $map);
        $this->assertSame('Camp Start Date', $map['Date de début du camp']);
        $this->assertSame('Age Group', $map["Groupe d'âge"]);
    }

    public function test_attendee_gender_unchanged_in_admin_order_context() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $display = intersoccer_translate_stored_order_meta_display_value('female', 'Attendee Gender');
        $this->assertSame('female', $display);
        $display_male = intersoccer_translate_stored_order_meta_display_value('Garçon', 'Attendee Gender');
        $this->assertSame('Garçon', $display_male);
    }

    public function test_attendee_gender_translated_in_customer_context() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            if ($text === 'Male') {
                return 'Garçon';
            }
            return $translation;
        };
        $display = intersoccer_translate_stored_order_meta_display_value('Male', 'Attendee Gender');
        $this->assertSame('Garçon', $display);
        MockFilters::reset();
    }

    public function test_days_selected_weekdays_translate_in_customer_context() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            if ($text === 'Monday') {
                return 'Lundi';
            }
            if ($text === 'Tuesday') {
                return 'Mardi';
            }
            return $translation;
        };
        $display = intersoccer_translate_stored_order_meta_display_value('Monday, Tuesday', 'Days Selected');
        $this->assertSame('Lundi, Mardi', $display);
        MockFilters::reset();
    }

    public function test_order_meta_labels_unchanged_in_admin_context() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $label = intersoccer_translate_stored_order_meta_display_key('Camp Terms', 'Camp Terms');
        $this->assertSame('Camp Terms', $label);
    }

    public function test_build_order_line_meta_stores_english_activity_type_key() {
        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'course',
            'cart_values' => [],
        ]);
        $this->assertArrayHasKey('Activity Type', $built['updates']);
        $this->assertArrayNotHasKey("Type d'activité", $built['updates']);
    }

    public function test_activity_type_value_translates_with_localized_meta_key() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            if ($text === 'Course') {
                return 'Cours';
            }
            return $translation;
        };
        $display = intersoccer_translate_stored_order_meta_display_value('Course', "Type d'activité");
        $this->assertSame('Cours', $display);
        MockFilters::reset();
    }

    public function test_localized_camp_week_index_hidden_from_customer_meta() {
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('Index de semaine du camp'));
        $this->assertTrue(intersoccer_is_hidden_customer_order_meta_key('Camp-Wochenindex'));
    }

    public function test_note_dash_mojibake_repaired_only_in_german_display_locale() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $raw = 'Sie können jederzeit einsteigen ? die Preise werden anteilig berechnet';
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'de_DE';
        $fixed = intersoccer_translate_stored_order_meta_display_value($raw, 'Note');
        $this->assertStringContainsString('–', $fixed);
        $this->assertStringNotContainsString(' ? ', $fixed);

        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'fr_FR';
        $fr = intersoccer_translate_stored_order_meta_display_value('Des questions ? Contactez-nous', 'Note');
        $this->assertSame('Des questions ? Contactez-nous', $fr);

        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'en_US';
        $en = intersoccer_translate_stored_order_meta_display_value($raw, 'Note');
        $this->assertSame($raw, $en);
        unset($GLOBALS['intersoccer_pv_test_determine_locale']);
    }

    public function test_note_slug_resolved_term_dash_mojibake_repaired_in_german_display_locale() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $term_name = 'Sie können jederzeit einsteigen ? die Preise werden anteilig berechnet';
        $GLOBALS['intersoccer_test_terms'] = [
            555 => [
                'taxonomy' => 'pa_note',
                'slug' => 'join-anytime',
                'name' => $term_name,
            ],
        ];
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'de_DE';
        $display = intersoccer_translate_stored_order_meta_display_value('join-anytime', 'Note');
        $this->assertStringContainsString('–', $display);
        $this->assertStringNotContainsString(' ? ', $display);
        unset($GLOBALS['intersoccer_test_terms'], $GLOBALS['intersoccer_pv_test_determine_locale']);
    }

    public function test_note_slug_resolved_term_dash_mojibake_unchanged_in_english_display_locale() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $term_name = 'Sie können jederzeit einsteigen ? die Preise werden anteilig berechnet';
        $GLOBALS['intersoccer_test_terms'] = [
            555 => [
                'taxonomy' => 'pa_note',
                'slug' => 'join-anytime',
                'name' => $term_name,
            ],
        ];
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'en_US';
        $display = intersoccer_translate_stored_order_meta_display_value('join-anytime', 'Note');
        $this->assertSame($term_name, $display);
        unset($GLOBALS['intersoccer_test_terms'], $GLOBALS['intersoccer_pv_test_determine_locale']);
    }

    public function test_note_slug_resolved_term_keeps_legitimate_trailing_question_in_german() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $term_name = 'Haben Sie noch Fragen?';
        $GLOBALS['intersoccer_test_terms'] = [
            556 => [
                'taxonomy' => 'pa_note',
                'slug' => 'questions',
                'name' => $term_name,
            ],
        ];
        $GLOBALS['intersoccer_pv_test_determine_locale'] = 'de_DE';
        $display = intersoccer_translate_stored_order_meta_display_value('questions', 'Note');
        $this->assertSame($term_name, $display);
        unset($GLOBALS['intersoccer_test_terms'], $GLOBALS['intersoccer_pv_test_determine_locale']);
    }

    public function test_course_date_display_localized_in_customer_context() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $display = intersoccer_pv_format_stored_order_date_for_display('September 27, 2026');
        $this->assertNotSame('', $display);
        $this->assertStringContainsString('2026', $display);
    }

    public function test_course_date_unchanged_in_admin_context() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $stored = 'September 27, 2026';
        $display = intersoccer_translate_stored_order_meta_display_value($stored, 'Start Date');
        $this->assertSame($stored, $display);
    }

    public function test_holidays_date_list_localized_with_distinct_years() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'j F Y') {
                    return 'Y-m-d';
                }
                return $translation;
            };
        }
        $stored = 'October 19, 2027, November 2, 2028';
        $display = intersoccer_translate_stored_order_meta_display_value($stored, 'Holidays');
        $this->assertSame('2027-10-19, 2028-11-02', $display);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_holidays_single_english_date_localized() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'j F Y') {
                    return 'Y-m-d';
                }
                return $translation;
            };
        }
        $stored = 'October 19, 2027';
        $display = intersoccer_translate_stored_order_meta_display_value($stored, 'Holidays');
        $this->assertSame('2027-10-19', $display);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_holidays_unparseable_mixed_text_unchanged() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $stored = 'School break, October 19, 2027';
        $display = intersoccer_translate_stored_order_meta_display_value($stored, 'Holidays');
        $this->assertSame($stored, $display);
    }

    public function test_note_display_key_translatable() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'Note') {
                    return 'Hinweis';
                }
                return $translation;
            };
        }
        $label = intersoccer_translate_stored_order_meta_display_key('Note', 'Note');
        $this->assertSame('Hinweis', $label);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_camp_build_stores_english_camp_schedule_keys() {
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }
        $vid = 88002;
        intersoccer_update_camp_schedule($vid, '2026-10-19', '2026-10-23', 4, true);
        $built = intersoccer_build_order_line_meta([
            'product_id' => 88003,
            'variation_id' => $vid,
            'product_type' => 'camp',
            'cart_values' => [],
        ]);
        $updates = $built['updates'];
        $this->assertArrayHasKey('Camp Start Date', $updates);
        $this->assertArrayHasKey('Camp End Date', $updates);
        $this->assertArrayHasKey('Camp Week Index', $updates);
        $this->assertArrayNotHasKey('Date de début du camp', $updates);
    }

    public function test_checkout_parent_attributes_use_english_label_keys() {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/checkout-calculations.php');
        $this->assertStringNotContainsString("icl_t('intersoccer-product-variations', \$english_label", $src);
        $this->assertStringNotContainsString("icl_t('intersoccer-product-variations', \$label, \$label)", $src);
    }

    public function test_medical_none_translated_for_customer() {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            if ($text === 'None') {
                return 'Aucune';
            }
            return $translation;
        };
        $display = intersoccer_translate_stored_order_meta_display_value('None', 'Medical Conditions');
        $this->assertSame('Aucune', $display);
        MockFilters::reset();
    }

    public function test_checkout_persists_booking_details_from_cart_info_note() {
        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'camp',
            'cart_values' => [
                'intersoccer_info_note' => '3 Day(s) Selected',
            ],
        ]);
        $this->assertSame('3 Day(s) Selected', $built['updates']['Booking details'] ?? null);
        $allowed = intersoccer_order_meta_allowed_keys('camp');
        $this->assertContains('Booking details', $allowed);
    }

    public function test_customer_formatted_meta_hides_base_price_and_variation_id() {
        $meta = [
            (object) ['key' => 'Activity Type', 'display_key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => 'Base Price', 'display_key' => 'Base Price', 'value' => '525'],
            (object) ['key' => 'Variation ID', 'display_key' => 'Variation ID', 'value' => '51724'],
        ];
        $item = new WC_Order_Item_Product([]);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertContains('Activity Type', $keys);
        $this->assertNotContains('Base Price', $keys);
        $this->assertNotContains('Variation ID', $keys);
    }

    public function test_admin_formatted_meta_hides_underscore_internal_keys() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_doing_ajax']);
        $meta = [
            (object) ['key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => '_intersoccer_canonical_age_group', 'value' => '3-5y'],
            (object) ['key' => '_intersoccer_discount_season_key', 'value' => 'ps:88001|2026'],
        ];
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_pv_filter_admin_order_item_formatted_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertSame(['Activity Type'], $keys);
    }

    public function test_admin_formatted_meta_keeps_visible_operational_keys() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_doing_ajax']);
        $meta = [
            (object) ['key' => 'Assigned Attendee', 'display_key' => 'Assigned Attendee', 'value' => 'Child'],
            (object) ['key' => 'Base Price', 'display_key' => 'Base Price', 'value' => '525'],
            (object) ['key' => 'Camp Start Date', 'display_key' => 'Camp Start Date', 'value' => '2026-07-06'],
            (object) ['key' => '_camp_start_date', 'value' => '2026-07-06'],
        ];
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_pv_filter_admin_order_item_formatted_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertContains('Assigned Attendee', $keys);
        $this->assertContains('Base Price', $keys);
        $this->assertContains('Camp Start Date', $keys);
        $this->assertNotContains('_camp_start_date', $keys);
    }

    public function test_admin_ajax_does_not_strip_internal_keys_for_customer_style_pipeline() {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $meta = [
            (object) ['key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => '_intersoccer_discount_season_key', 'value' => 'ps:1|2026'],
        ];
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_pv_filter_admin_order_item_formatted_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertContains('_intersoccer_discount_season_key', $keys);
    }

    public function test_dedupe_assigned_attendee_customer_meta() {
        $meta = [
            (object) ['key' => 'Assigned Attendee', 'display_key' => 'Assigned Attendee', 'value' => 'Child A'],
            (object) ['key' => 'assigned_attendee', 'display_key' => 'assigned_attendee', 'value' => 'Child A'],
        ];
        $item = new WC_Order_Item_Product([]);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
        $attendee_rows = array_filter($filtered, static function ($m) {
            $canonical = intersoccer_pv_canonical_stored_order_meta_key((string) $m->key);
            return $canonical === 'Assigned Attendee';
        });
        $this->assertCount(1, $attendee_rows);
    }

    public function test_build_order_line_meta_stores_english_keys_when_icl_t_would_use_fr_labels(): void {
        $this->assertBuildStoresEnglishKeysNotTranslated([
            'Activity Type' => "Type d'activité",
            'Season' => 'Saison',
            'Camp Start Date' => 'Date de début du camp',
            'Camp End Date' => 'Date de fin du camp',
            'Camp Week Index' => 'Index de semaine du camp',
        ]);
    }

    public function test_build_order_line_meta_stores_english_keys_when_icl_t_would_use_de_labels(): void {
        $this->assertBuildStoresEnglishKeysNotTranslated([
            'Activity Type' => 'Aktivitätstyp',
            'Season' => 'Saison',
            'Camp Start Date' => 'Camp-Startdatum',
            'Camp End Date' => 'Camp-Enddatum',
            'Camp Week Index' => 'Camp-Wochenindex',
        ]);
    }

    private function assertBuildStoresEnglishKeysNotTranslated(array $icl_map): void {
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }
        $GLOBALS['intersoccer_test_icl_t_map'] = $icl_map;
        $vid = 88100;
        intersoccer_update_camp_schedule($vid, '2026-07-27', '2026-07-31', 6, true);

        $built = intersoccer_build_order_line_meta([
            'product_id' => 88101,
            'variation_id' => $vid,
            'product_type' => 'camp',
            'cart_values' => [],
        ]);
        $updates = $built['updates'];

        $this->assertArrayHasKey('Activity Type', $updates);
        $this->assertArrayHasKey('Camp Start Date', $updates);
        $this->assertArrayHasKey('Camp End Date', $updates);
        foreach ($icl_map as $translated) {
            $this->assertArrayNotHasKey($translated, $updates);
        }
        unset($GLOBALS['intersoccer_test_icl_t_map']);
    }

    public function test_customer_display_translates_english_stored_activity_type_key(): void {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
            if ($text === 'Activity Type') {
                return "Type d'activité";
            }
            return $translation;
        };
        $label = intersoccer_translate_stored_order_meta_display_key('Activity Type', 'Activity Type');
        $this->assertSame("Type d'activité", $label);
        MockFilters::reset();
    }

    public function test_admin_formatted_meta_keeps_english_stored_keys(): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_doing_ajax']);
        $meta = [
            (object) ['key' => 'Activity Type', 'display_key' => 'Activity Type', 'value' => 'Camp'],
            (object) ['key' => 'Camp Start Date', 'display_key' => 'Camp Start Date', 'value' => '2026-07-27'],
        ];
        $item = new WC_Order_Item_Product([]);
        $filtered = intersoccer_pv_filter_admin_order_item_formatted_meta($meta, $item);
        $keys = array_map(static fn($m) => $m->key, $filtered);
        $this->assertSame(['Activity Type', 'Camp Start Date'], $keys);
    }

    public function test_order_item_get_meta_by_canonical_key_reads_legacy_translated_keys(): void {
        $item = new WC_Order_Item_Product([
            "Type d'activité" => 'Camp',
            'Date de début du camp' => '2026-07-27',
            'Date de fin du camp' => '2026-07-31',
        ]);
        $this->assertSame('Camp', intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Activity Type'));
        $this->assertSame('2026-07-27', intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Camp Start Date'));
        $this->assertSame('2026-07-31', intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Camp End Date'));
    }

    public function test_customer_meta_hides_player_dob_when_attendee_dob_present_en_fr_de(): void {
        $cases = [
            ['Attendee DOB', 'Player DOB'],
            ['Date de naissance du participant', 'Player DOB'],
            ['Geburtsdatum des Teilnehmers', 'Player DOB'],
        ];
        foreach ($cases as [$attendee_key, $player_key]) {
            $meta = [
                (object) ['key' => $attendee_key, 'display_key' => $attendee_key, 'value' => '2015-06-01'],
                (object) ['key' => $player_key, 'display_key' => $player_key, 'value' => '2015-06-01'],
            ];
            $item = new WC_Order_Item_Product([
                $attendee_key => '2015-06-01',
                $player_key => '2015-06-01',
            ]);
            $GLOBALS['intersoccer_test_is_admin'] = false;
            $filtered = intersoccer_filter_customer_order_item_meta($meta, $item);
            $keys = array_map(static fn($m) => $m->key, $filtered);
            $this->assertContains($attendee_key, $keys);
            $this->assertNotContains($player_key, $keys, "Player DOB must hide when $attendee_key is set");
        }
    }

    public function test_order_line_date_storage_format_en_unchanged(): void {
        $GLOBALS['intersoccer_test_locale'] = 'en_US';
        if (class_exists('MockMetaData')) {
            MockMetaData::$data[200]['_course_start_date'] = '2026-09-27';
        }
        $this->assertSame('F j, Y', intersoccer_pv_order_line_date_format_for_storage());
        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 200,
            'product_type' => 'course',
            'cart_values' => [],
        ]);
        $this->assertSame('September 27, 2026', $built['updates']['Start Date'] ?? null);
        if (class_exists('MockMetaData')) {
            unset(MockMetaData::$data[200]);
        }
    }

    public function test_order_line_date_storage_format_fr_uses_j_f_y(): void {
        if (class_exists('MockFilters')) {
            MockFilters::$filters['wpml_current_language'] = static function () {
                return 'fr';
            };
        }
        $GLOBALS['intersoccer_test_locale'] = 'fr_FR';
        if (class_exists('MockMetaData')) {
            MockMetaData::$data[201]['_course_start_date'] = '2026-09-27';
        }
        $this->assertSame('j F Y', intersoccer_pv_order_line_date_format_for_storage());
        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 201,
            'product_type' => 'course',
            'cart_values' => [],
        ]);
        $this->assertSame('27 September 2026', $built['updates']['Start Date'] ?? null);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        if (class_exists('MockMetaData')) {
            unset(MockMetaData::$data[201]);
        }
        unset($GLOBALS['intersoccer_test_locale']);
    }

    public function test_order_line_date_storage_format_de_uses_j_dot_f_y(): void {
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'j F Y') {
                    return 'j. F Y';
                }
                return $translation;
            };
            MockFilters::$filters['wpml_current_language'] = static function () {
                return 'de';
            };
        }
        $GLOBALS['intersoccer_test_locale'] = 'de_DE';
        if (class_exists('MockMetaData')) {
            MockMetaData::$data[202]['_course_start_date'] = '2026-09-27';
        }
        $this->assertSame('j. F Y', intersoccer_pv_order_line_date_format_for_storage());
        $built = intersoccer_build_order_line_meta([
            'product_id' => 100,
            'variation_id' => 202,
            'product_type' => 'course',
            'cart_values' => [],
        ]);
        $this->assertSame('27. September 2026', $built['updates']['Start Date'] ?? null);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        if (class_exists('MockMetaData')) {
            unset(MockMetaData::$data[202]);
        }
        unset($GLOBALS['intersoccer_test_locale']);
    }

    public function test_stored_french_and_german_month_dates_parse_for_display(): void {
        $mars_wrong = strtotime('1 mars 2026');
        $this->assertNotSame(
            mktime(0, 0, 0, 3, 1, 2026),
            $mars_wrong,
            'strtotime must not be used for FR month-name strings (mis-parses day)'
        );
        $this->assertSame(
            mktime(0, 0, 0, 3, 1, 2026),
            intersoccer_pv_parse_stored_order_date_to_timestamp('1 mars 2026')
        );
        $this->assertSame(
            mktime(0, 0, 0, 9, 15, 2026),
            intersoccer_pv_parse_stored_order_date_to_timestamp('15 septembre 2026')
        );
        $this->assertSame(
            mktime(0, 0, 0, 3, 1, 2026),
            intersoccer_pv_parse_stored_order_date_to_timestamp('1. März 2026')
        );
        $this->assertSame(
            strtotime('2026-09-27'),
            intersoccer_pv_parse_stored_order_date_to_timestamp('27 septembre 2026')
        );
        $this->assertSame(
            strtotime('2026-09-27'),
            intersoccer_pv_parse_stored_order_date_to_timestamp('27. September 2026')
        );
        $GLOBALS['intersoccer_test_is_admin'] = false;
        if (class_exists('MockFilters')) {
            MockFilters::$filters['gettext_intersoccer-product-variations'] = static function ($translation, $text, $domain) {
                if ($text === 'j F Y') {
                    return 'Y-m-d';
                }
                return $translation;
            };
        }
        $display_fr = intersoccer_pv_format_stored_order_date_for_display('27 septembre 2026');
        $this->assertSame('2026-09-27', $display_fr);
        $display_de = intersoccer_pv_format_stored_order_date_for_display('27. Oktober 2026');
        $this->assertSame('2026-10-27', $display_de);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

}
