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

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class OrderMetaContractTest extends TestCase {
    /** @var array<string,mixed> */
    public static $mock_player_details = [];

    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        self::$mock_player_details = [];
    }

    public function test_correctable_keys_include_attendee_fields() {
        $keys = intersoccer_order_meta_correctable_keys();
        $this->assertContains('Activity Type', $keys);
        $this->assertContains('Attendee DOB', $keys);
        $this->assertContains('Attendee Gender', $keys);
        $this->assertContains('Medical Conditions', $keys);
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
            'order' => new class {
                public function get_customer_id() {
                    return 42;
                }
            },
        ]);

        $updates = $built['updates'];
        $this->assertSame('Cart Name', $updates['Assigned Attendee']);
        $this->assertSame(1, $updates['assigned_player']);
        $this->assertSame('2015-03-10', $updates['Attendee DOB']);
        $this->assertSame('Male', $updates['Attendee Gender']);
        $this->assertSame('Asthma', $updates['Medical Conditions']);
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
}
