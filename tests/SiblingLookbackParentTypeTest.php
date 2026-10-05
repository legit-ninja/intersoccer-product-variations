<?php
/**
 * Cross-order camp sibling lookback must type lines via the parent when the variation
 * has no _intersoccer_product_type (Tess: #62118 Sophie → #62119 Noah alone, same var 51724).
 */

use PHPUnit\Framework\TestCase;

if (!class_exists('TestProductTypeRegistry')) {
    class TestProductTypeRegistry
    {
        /** @var array<int,string|null> */
        private static $types = [];

        public static function set($product_id, $type)
        {
            self::$types[(int) $product_id] = $type;
        }

        public static function get($product_id)
        {
            $id = (int) $product_id;
            return array_key_exists($id, self::$types) ? self::$types[$id] : null;
        }

        public static function reset()
        {
            self::$types = [];
        }
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($rule_id, $message_type = 'cart_message', $fallback = '') {
        return $fallback !== '' ? $fallback : (string) $rule_id;
    }
}
if (!function_exists('intersoccer_translate_string')) {
    function intersoccer_translate_string($string, $context = 'intersoccer-product-variations', $name = '') {
        return $string;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

class SiblingLookbackParentTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestProductTypeRegistry::reset();
        if (class_exists('MockMetaData')) {
            MockMetaData::$data = [];
        }
        unset($GLOBALS['intersoccer_test_product_terms'], $GLOBALS['intersoccer_wc_get_product_callback']);
    }

    protected function tearDown(): void
    {
        TestProductTypeRegistry::reset();
        if (class_exists('MockMetaData')) {
            MockMetaData::$data = [];
        }
        unset($GLOBALS['intersoccer_test_product_terms'], $GLOBALS['intersoccer_wc_get_product_callback']);
        parent::tearDown();
    }

    public function testResolveLineProductTypeFallsBackToParentWhenVariationEmpty()
    {
        // Variation 51724 has no type; parent 51723 is camp (live Geneva Autumn Camps 2026).
        TestProductTypeRegistry::set(51724, null);
        TestProductTypeRegistry::set(51723, 'camp');

        $this->assertNull(intersoccer_get_product_type(51724));
        $this->assertSame('camp', intersoccer_get_product_type(51723));
        $this->assertSame(
            'camp',
            intersoccer_discount_resolve_line_product_type(51723, 51724),
            'Lookback must see camp via parent when variation type is empty'
        );
    }

    public function testExtractCampItemsIncludesLineWhenVariationTypeIsNull()
    {
        TestProductTypeRegistry::set(51724, null);
        TestProductTypeRegistry::set(51723, 'camp');
        MockMetaData::$data[51724]['attribute_pa_booking-type'] = 'full-week';

        $GLOBALS['intersoccer_test_product_terms'] = [
            51723 => [
                'pa_program-season' => ['Autumn Camps 2026'],
                'pa_program-year' => ['2026'],
            ],
            51724 => [],
        ];
        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) {
            $id = (int) $id;
            return new class($id) {
                private $id;
                public function __construct($id) { $this->id = $id; }
                public function get_id() { return $this->id; }
                public function get_parent_id() { return $this->id === 51724 ? 51723 : 0; }
                public function get_price() { return 525.0; }
                public function get_attributes() { return []; }
                public function get_attribute($n) {
                    return $n === 'pa_booking-type' ? 'full-week' : '';
                }
                public function is_type($t) { return false; }
                public function get_name() { return 'Geneva Autumn Camps 2026'; }
            };
        };

        $item = new class {
            public function get_product_id() { return 51723; }
            public function get_variation_id() { return 51724; }
            public function get_meta($key, $single = true) {
                $map = [
                    'assigned_player_id' => 'uuid-sophie',
                    'assigned_attendee' => 'Sophie Schneider',
                    'assigned_player' => 0,
                    '_intersoccer_discount_season_key' => 'autumn camps 2026|2026',
                ];
                return $map[$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };

        $order = new class($item) {
            private $item;
            public function __construct($item) { $this->item = $item; }
            public function get_id() { return 62118; }
            public function get_items() { return [101 => $this->item]; }
            public function get_date_created() {
                return new class {
                    public function format($f) { return '2026-10-05 12:00:00'; }
                };
            }
        };

        // Old bug: excluding on variation-only type would skip this line.
        $this->assertTrue(
            intersoccer_discount_exclude_product_from_camp_sibling_baseline(51724),
            'Sanity: variation-only type NULL is excluded if no parent fallback is used'
        );
        $this->assertFalse(
            intersoccer_discount_exclude_product_from_camp_sibling_baseline(
                51724,
                intersoccer_discount_resolve_line_product_type(51723, 51724)
            ),
            'With parent type camp, the line must stay in the baseline'
        );

        $camps = intersoccer_extract_camp_items_from_order($order);
        $this->assertCount(1, $camps, 'Prior order camp line must be collected for lookback');
        $this->assertSame(51723, (int) $camps[0]['parent_product_id']);
        $this->assertSame(51724, (int) $camps[0]['variation_id']);
        $this->assertSame('uuid-sophie', $camps[0]['assigned_player_id']);
        $this->assertSame('autumn camps 2026|2026', $camps[0]['season']);
    }

    public function testCrossOrderSameVariationSecondKidGetsTwentyPercent()
    {
        // Prior order A: Sophie spent 525. Cart B: Noah alone on same variation → 20% sibling.
        $cart_by_child = [
            'uuid-noah' => [
                [
                    'cart_key' => 'line-noah',
                    'assigned_player_id' => 'uuid-noah',
                    'assigned_attendee' => 'Noah Dubois',
                    'price' => 525.0,
                    'quantity' => 1,
                    'product_id' => 51723,
                    'variation_id' => 51724,
                    'season' => 'autumn camps 2026|2026',
                ],
            ],
        ];
        $prior_totals = [
            'uuid-sophie' => 525.0,
        ];

        $merged = intersoccer_merge_sibling_child_totals($cart_by_child, $prior_totals);
        $sorted = intersoccer_rank_sibling_children_for_rates(
            $merged['totals'],
            $merged['cart_by_child'],
            $merged['prior_by_key'] ?? []
        );

        $this->assertSame(
            ['uuid-sophie', 'uuid-noah'],
            $sorted,
            'Sophie (prior only) keeps first rank; Noah in cart is second child'
        );

        $mock_cart = new class {
            public $cart_contents = [];
            public function __construct()
            {
                $this->cart_contents['line-noah'] = [
                    'base_price' => 525.0,
                    'data' => new class {
                        public $price = 525.0;
                        public function set_price($p) { $this->price = $p; }
                        public function get_price() { return $this->price; }
                    },
                ];
            }
        };

        $template = '%s%% Camp Sibling Discount';
        intersoccer_apply_sibling_rates_to_cart(
            $mock_cart,
            $sorted,
            $merged['cart_by_child'],
            0.20,
            0.30,
            'camp_multi_child_',
            $template,
            'camp'
        );

        $this->assertEqualsWithDelta(420.0, $mock_cart->cart_contents['line-noah']['data']->get_price(), 0.01);
        $this->assertEqualsWithDelta(105.0, $mock_cart->cart_contents['line-noah']['discount_amount'], 0.01);
        $this->assertStringContainsString('20%', $mock_cart->cart_contents['line-noah']['discount_note']);
    }

    public function testCourseAndTournamentExtractorsAlsoUseParentTypeFallback()
    {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringContainsString(
            'intersoccer_discount_resolve_line_product_type($product_id, $variation_id)',
            $src
        );
        // No remaining variation-only type checks in the three extractors.
        $this->assertDoesNotMatchRegularExpression(
            '/function intersoccer_extract_course_items_from_order[\s\S]*?intersoccer_get_product_type\(\$variation_id \?: \$product_id\)/',
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function intersoccer_extract_tournament_items_from_order[\s\S]*?intersoccer_get_product_type\(\$variation_id \?: \$product_id\)/',
            $src
        );
    }
}
