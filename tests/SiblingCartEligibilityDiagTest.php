<?php
/**
 * Sibling discount eligibility: same-variation siblings + PM player index + season parent fallback.
 *
 * Reproduces Jeremy's Geneva Autumn Camps 2026 cart (two children, same parent/variation)
 * where season/year live on the parent only.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

class SiblingCartEligibilityDiagTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_wc_get_product_callback']
        );
        if (class_exists('MockMetaData')) {
            MockMetaData::$data = [];
        }
        parent::tearDown();
    }

    public function testDiscountPlayerKeyAcceptsPmIntersoccerPlayerIndex()
    {
        $this->assertSame(
            '0',
            intersoccer_discount_player_key(['intersoccer_player_index' => 0])
        );
        $this->assertSame(
            '1',
            intersoccer_discount_player_key(['intersoccer_player_index' => '1', 'product_id' => 9])
        );
        $this->assertSame(
            'uuid-a',
            intersoccer_discount_player_key([
                'assigned_player_id' => 'uuid-a',
                'intersoccer_player_index' => 0,
            ])
        );
    }

    public function testDiscountSeasonKeyFallsBackToParentForVariation()
    {
        // Parent 51723 holds season + year; variation 51724 has neither (live legit.ninja shape).
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
                public function get_attribute($n) { return ''; }
                public function is_type($t) { return false; }
                public function get_name() { return 'Geneva Autumn Camps 2026'; }
            };
        };

        $this->assertSame(
            'autumn camps 2026|2026',
            intersoccer_discount_season_key(51723),
            'Parent lookup must compose season|year'
        );
        $this->assertSame(
            'autumn camps 2026|2026',
            intersoccer_discount_season_key(51724),
            'Variation must inherit parent season/year for sibling season buckets'
        );
    }

    public function testSameVariationTwoSiblingsEnterSeasonBucketAndRank()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
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
                public function get_parent_id() { return in_array($this->id, [51724, 51723], true) ? ($this->id === 51724 ? 51723 : 0) : 0; }
                public function get_price() { return 525.0; }
                public function get_attributes() { return []; }
                public function get_attribute($n) { return $n === 'pa_booking-type' ? 'full-week' : ''; }
                public function is_type($t) { return false; }
                public function get_name() { return 'Geneva Autumn Camps 2026'; }
            };
        };

        MockMetaData::$data[51724]['attribute_pa_booking-type'] = 'full-week';

        $cart = [
            'line-sasha' => [
                'product_id' => 51723,
                'variation_id' => 51724,
                'quantity' => 1,
                'assigned_player' => 0,
                'assigned_player_id' => 'uuid-sasha',
                'assigned_attendee' => 'Sasha',
                'data' => new class {
                    public function get_price() { return 525.0; }
                },
            ],
            'line-ralphie' => [
                'product_id' => 51723,
                'variation_id' => 51724,
                'quantity' => 1,
                'assigned_player' => 1,
                'assigned_player_id' => 'uuid-ralphie',
                'assigned_attendee' => 'Ralphie',
                'data' => new class {
                    public function get_price() { return 525.0; }
                },
            ],
        ];

        $ctx = intersoccer_build_cart_context($cart);

        $this->assertCount(
            2,
            $ctx['camps_by_child'],
            'Two distinct children on the same variation must not collapse: ' . json_encode(array_keys($ctx['camps_by_child']))
        );

        $season_key = 'autumn camps 2026|2026';
        $this->assertArrayHasKey(
            $season_key,
            $ctx['camps_by_season_child'],
            'Season bucket missing. keys=' . json_encode(array_keys($ctx['camps_by_season_child']))
            . ' camps_by_child seasons=' . json_encode(array_map(static function ($items) {
                return array_column($items, 'season');
            }, $ctx['camps_by_child']))
        );
        $this->assertCount(
            2,
            $ctx['camps_by_season_child'][$season_key],
            'Same-variation siblings must both land in the season bucket for multi-child rates'
        );

        $merged = intersoccer_merge_sibling_child_totals($ctx['camps_by_season_child'][$season_key], []);
        $this->assertCount(2, $merged['totals']);
        $ranked = intersoccer_rank_sibling_children_for_rates($merged['totals'], $merged['cart_by_child'], []);
        $this->assertCount(2, $ranked, 'Ranking must see two children (index 1 gets 2nd-child rate)');
    }

    public function testBuildCartContextGroupsPmOnlyPlayerLines()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        MockMetaData::$data[200]['attribute_pa_booking-type'] = 'full-week';
        MockMetaData::$data[201]['attribute_pa_booking-type'] = 'full-week';

        $cart = [
            'line-a' => [
                'product_id' => 100,
                'variation_id' => 200,
                'quantity' => 1,
                'intersoccer_player_index' => 0,
                'data' => new class {
                    public function get_price() { return 400.0; }
                },
            ],
            'line-b' => [
                'product_id' => 101,
                'variation_id' => 201,
                'quantity' => 1,
                'intersoccer_player_index' => 1,
                'data' => new class {
                    public function get_price() { return 350.0; }
                },
            ],
        ];

        $ctx = intersoccer_build_cart_context($cart);
        $this->assertCount(2, $ctx['camps_by_child'], 'PM-only indexes must group two siblings');
    }

    public function testSingleDayBookingExcludedFromCampSiblingBuckets()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        MockMetaData::$data[51725]['attribute_pa_booking-type'] = 'single-days';

        $cart = [
            'line-a' => [
                'product_id' => 51723,
                'variation_id' => 51725,
                'quantity' => 1,
                'assigned_player' => 0,
                'assigned_player_id' => 'uuid-sasha',
                'data' => new class {
                    public function get_price() { return 130.0; }
                },
            ],
            'line-b' => [
                'product_id' => 51723,
                'variation_id' => 51725,
                'quantity' => 1,
                'assigned_player' => 1,
                'assigned_player_id' => 'uuid-ralphie',
                'data' => new class {
                    public function get_price() { return 130.0; }
                },
            ],
        ];

        $ctx = intersoccer_build_cart_context($cart);
        $this->assertSame([], $ctx['camps_by_child'], 'Single-day bookings are not sibling-eligible under shipped rules');
    }
}
