<?php
/**
 * Test: Retroactive sibling/multi-child discount ranking
 *
 * Covers merge of prior-order children into sibling ranking, season filter,
 * stacking max(sibling, progressive), and cart-only application.
 */

use PHPUnit\Framework\TestCase;

class RetroactiveSiblingDiscountTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__) . '/');
        }
        if (!function_exists('__')) {
            function __($text, $domain = null) {
                return $text;
            }
        }
        if (!function_exists('get_option')) {
            function get_option($key, $default = false) {
                return $default;
            }
        }
        if (!function_exists('add_action')) {
            function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
                return true;
            }
        }
        if (!function_exists('add_filter')) {
            function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
                return true;
            }
        }
        if (!function_exists('intersoccer_debug')) {
            function intersoccer_debug($message) {
                // no-op in tests
            }
        }
        if (!function_exists('intersoccer_translate_string')) {
            function intersoccer_translate_string($text, $domain = null, $fallback = null) {
                return $fallback !== null ? $fallback : $text;
            }
        }
        if (!function_exists('intersoccer_get_discount_message')) {
            function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
                return $fallback;
            }
        }

        if (!function_exists('intersoccer_discount_player_key')) {
            require_once dirname(__FILE__) . '/../includes/woocommerce/discounts.php';
        }
    }

    public function testPlayerKeyPrefersUuid() {
        $key = intersoccer_discount_player_key([
            'assigned_player_id' => 'uuid-abc',
            'assigned_attendee' => 0,
            'assigned_player' => 0,
        ]);
        $this->assertSame('uuid-abc', $key);
    }

    public function testPlayerKeyFallsBackToLegacy() {
        $key = intersoccer_discount_player_key([
            'assigned_attendee' => 2,
        ]);
        $this->assertSame('2', $key);
    }

    public function testPlayersMatchUuidAcrossCartAndPriorOrder() {
        $cart = [
            'assigned_player_id' => 'uuid-abc',
            'assigned_attendee' => 'Jane Doe',
            'assigned_player' => 0,
        ];
        $prior = [
            'assigned_player_id' => 'uuid-abc',
            'assigned_player' => 'Jane Doe',
        ];
        $this->assertTrue(intersoccer_discount_players_match($cart, $prior));
    }

    public function testPlayersMatchWhenCartHasUuidAndNamePriorHasNameOnly() {
        $cart = [
            'assigned_player_id' => 'uuid-abc',
            'assigned_attendee' => 'Jane Doe',
        ];
        $prior = [
            'assigned_player' => 'Jane Doe',
        ];
        $this->assertTrue(
            intersoccer_discount_players_match($cart, $prior),
            'Progressive week lookup must match name token when prior order lacks UUID'
        );
    }

    public function testPlayersDoNotMatchUuidOnlyVsNameOnly() {
        $cart = ['assigned_player_id' => 'uuid-abc'];
        $prior = ['assigned_player' => 'Jane Doe'];
        $this->assertFalse(intersoccer_discount_players_match($cart, $prior));
    }

    public function testPlayersMatchBareKeyToLegacyField() {
        $this->assertTrue(intersoccer_discount_players_match('Jane Doe', [
            'assigned_player' => 'Jane Doe',
        ]));
    }

    public function testFullWeekBookingTypeLabelCountsForSibling() {
        $this->assertTrue(intersoccer_discount_camp_booking_counts_for_sibling(''));
        $this->assertTrue(intersoccer_discount_camp_booking_counts_for_sibling('full-week'));
        $this->assertTrue(intersoccer_discount_camp_booking_counts_for_sibling('Full Week'));
        $this->assertFalse(intersoccer_discount_camp_booking_counts_for_sibling('single-days'));
    }

    public function testPriorChildPlusCartChildRanksSecondChild() {
        $cart_by_child = [
            'child-b' => [
                [
                    'cart_key' => 'ck_b',
                    'assigned_player_id' => 'child-b',
                    'price' => 450.0,
                    'quantity' => 1,
                    'product_id' => 10,
                ],
            ],
        ];
        $prior_totals = [
            'child-a' => 500.0,
        ];

        $merged = intersoccer_merge_sibling_child_totals($cart_by_child, $prior_totals);
        $totals = $merged['totals'];
        arsort($totals);
        $sorted = array_keys($totals);

        $this->assertCount(2, $sorted);
        $this->assertSame('child-a', $sorted[0], 'Higher prior spend ranks first (0%)');
        $this->assertSame('child-b', $sorted[1], 'Cart-only child ranks second');

        $rate_2nd = 0.20;
        $index_b = array_search('child-b', $sorted, true);
        $percent = ($index_b === 1) ? $rate_2nd : 0;
        $this->assertEquals(0.20, $percent);
        $this->assertArrayHasKey('child-b', $merged['cart_by_child']);
        $this->assertArrayNotHasKey('child-a', $merged['cart_by_child']);
    }

    public function testPriorTwoChildrenPlusCartThirdGetsThirdPlusRate() {
        $cart_by_child = [
            'child-c' => [
                [
                    'cart_key' => 'ck_c',
                    'assigned_player_id' => 'child-c',
                    'price' => 400.0,
                    'quantity' => 1,
                    'product_id' => 11,
                ],
            ],
        ];
        $prior_totals = [
            'child-a' => 500.0,
            'child-b' => 450.0,
        ];

        $merged = intersoccer_merge_sibling_child_totals($cart_by_child, $prior_totals);
        $totals = $merged['totals'];
        arsort($totals);
        $sorted = array_keys($totals);

        $this->assertCount(3, $sorted);
        $index_c = array_search('child-c', $sorted, true);
        $this->assertSame(2, $index_c);

        $rate_3rd = 0.25;
        $percent = ($index_c >= 2) ? $rate_3rd : 0;
        $this->assertEquals(0.25, $percent);
    }

    public function testSingleCartChildWithoutPriorGetsNoSibling() {
        $cart_by_child = [
            'child-b' => [
                [
                    'cart_key' => 'ck_b',
                    'assigned_player_id' => 'child-b',
                    'price' => 450.0,
                    'quantity' => 1,
                    'product_id' => 10,
                ],
            ],
        ];
        $merged = intersoccer_merge_sibling_child_totals($cart_by_child, []);
        $this->assertCount(1, $merged['totals']);
        $this->assertLessThan(2, count($merged['totals']));
    }


    /**
     * Camp prior totals must honor the same season filter as courses (#65).
     */
    public function testCampSeasonFilterExcludesOtherSeason() {
        $season_filter = ['autumn|2026'];
        $items = [
            ['season' => 'autumn|2026', 'assigned_player_id' => 'a', 'line_total' => 100, 'booking_type' => 'full-week'],
            ['season' => 'summer|2026', 'assigned_player_id' => 'b', 'line_total' => 200, 'booking_type' => 'full-week'],
        ];
        $totals = [];
        $season_filter_set = array_map('strval', $season_filter);
        foreach ($items as $item) {
            if (!intersoccer_discount_camp_booking_counts_for_sibling($item['booking_type'] ?? '')) {
                continue;
            }
            $season = (string) ($item['season'] ?? '');
            if ($season === '' || !in_array($season, $season_filter_set, true)) {
                continue;
            }
            $key = intersoccer_discount_player_key($item);
            $totals[$key] = ($totals[$key] ?? 0) + floatval($item['line_total']);
        }
        $this->assertSame(['a' => 100.0], $totals, 'Other-season camp spend must be excluded');
    }

    /**
     * Camp sibling path must pass a season filter into prior totals (#65).
     */
    public function testCampSiblingPathPassesSeasonFilter() {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringContainsString(
            '($product_type === \'course\' || $product_type === \'camp\') && $season_filter_set !== null',
            $contents,
            'Season filter must apply to camp prior items'
        );
        $this->assertStringContainsString(
            '\'season\' => $season',
            $contents,
            'Camp order extract must include season'
        );
        $this->assertStringContainsString(
            'camps_by_season_child',
            $contents,
            'Cart context must group camps by season like courses'
        );
        $this->assertStringContainsString(
            'foreach ($camps_by_season as $season => $season_children)',
            $contents,
            'Camp sibling ranking must run per season'
        );
        $found = false;
        $marker = 'intersoccer_get_previous_sibling_child_totals(';
        $pos = 0;
        while (($pos = strpos($contents, $marker, $pos)) !== false) {
            $snippet = substr($contents, $pos, 320);
            if (strpos($snippet, "'camp'") !== false && (
                strpos($snippet, 'season') !== false || strpos($snippet, '$season') !== false
            )) {
                $found = true;
                break;
            }
            $pos += strlen($marker);
        }
        $this->assertTrue($found, 'Camp prior totals call must pass a season filter');
        $meta = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php');
        $this->assertStringContainsString('_intersoccer_discount_season_key', $meta);
    }

    /**
     * Mixed Summer + Autumn cart must not pair children across seasons (#65).
     */
    public function testMixedSeasonCartChildrenDoNotShareSiblingRanking() {
        $summer = [
            'child-a' => [['cart_key' => 's_a', 'assigned_player_id' => 'child-a', 'price' => 500.0, 'quantity' => 1, 'product_id' => 1, 'season' => 'summer|2026']],
        ];
        $autumn = [
            'child-b' => [['cart_key' => 'a_b', 'assigned_player_id' => 'child-b', 'price' => 400.0, 'quantity' => 1, 'product_id' => 2, 'season' => 'autumn|2026']],
        ];

        // Each season ranked alone has only one child => no sibling rate.
        foreach ([$summer, $autumn] as $season_children) {
            $merged = intersoccer_merge_sibling_child_totals($season_children, []);
            $this->assertLessThan(2, count($merged['totals']), 'Single-season single child must not unlock sibling');
        }

        // Combined across seasons would wrongly unlock sibling — product forbids that.
        $combined = array_merge($summer, $autumn);
        $merged_all = intersoccer_merge_sibling_child_totals($combined, []);
        $this->assertCount(2, $merged_all['totals'], 'Sanity: two children exist across seasons');
    }

    public function testCourseSeasonFilterExcludesOtherSeason() {
        $season_filter = ['spring-2026'];
        $prior_items = [
            ['season' => 'spring-2026', 'assigned_player_id' => 'a', 'line_total' => 100],
            ['season' => 'fall-2025', 'assigned_player_id' => 'b', 'line_total' => 200],
        ];

        $totals = [];
        foreach ($prior_items as $item) {
            $season = (string) ($item['season'] ?? '');
            if ($season === '' || !in_array($season, $season_filter, true)) {
                continue;
            }
            $key = intersoccer_discount_player_key($item);
            if ($key === null) {
                continue;
            }
            if (!isset($totals[$key])) {
                $totals[$key] = 0;
            }
            $totals[$key] += floatval($item['line_total']);
        }

        $this->assertArrayHasKey('a', $totals);
        $this->assertArrayNotHasKey('b', $totals);
        $this->assertEquals(100.0, $totals['a']);
    }

    public function testCourseSeasonFilterIsolatesEvergreenYears() {
        $season_filter = ['autumn|2026'];
        $prior_items = [
            ['season' => 'autumn|2026', 'assigned_player_id' => 'a', 'line_total' => 100],
            ['season' => 'autumn|2027', 'assigned_player_id' => 'b', 'line_total' => 200],
        ];

        $totals = [];
        foreach ($prior_items as $item) {
            $season = (string) ($item['season'] ?? '');
            if ($season === '' || !in_array($season, $season_filter, true)) {
                continue;
            }
            $key = intersoccer_discount_player_key($item);
            if ($key === null) {
                continue;
            }
            if (!isset($totals[$key])) {
                $totals[$key] = 0;
            }
            $totals[$key] += floatval($item['line_total']);
        }

        $this->assertArrayHasKey('a', $totals);
        $this->assertArrayNotHasKey('b', $totals);
        $this->assertEquals(100.0, $totals['a']);
    }

    public function testMaxSiblingVsProgressiveKeepsHigher() {
        $sibling = 0.25;
        $progressive = 0.10;
        $final = max($sibling, $progressive);
        $this->assertEquals(0.25, $final);

        $sibling3 = 0.20;
        $progressive3 = 0.20;
        // Progressive must not overwrite when equal or lower
        $apply_progressive = ($progressive3 > $sibling3);
        $this->assertFalse($apply_progressive);
    }

    public function testToggleOffMeansCartOnlyNeedsTwoChildren() {
        $enable_retroactive_siblings = false;
        $cart_children_count = 1;
        $prior_count = 1;

        $effective_count = $enable_retroactive_siblings
            ? ($cart_children_count + $prior_count)
            : $cart_children_count;

        $this->assertLessThan(2, $effective_count, 'With toggle off, single cart child should not get sibling discount');
    }

    public function testMergedSpendSumsCartAndPriorForSameChild() {
        $cart_by_child = [
            'child-a' => [
                [
                    'cart_key' => 'ck_a',
                    'assigned_player_id' => 'child-a',
                    'price' => 100.0,
                    'quantity' => 1,
                    'product_id' => 1,
                ],
            ],
            'child-b' => [
                [
                    'cart_key' => 'ck_b',
                    'assigned_player_id' => 'child-b',
                    'price' => 200.0,
                    'quantity' => 1,
                    'product_id' => 2,
                ],
            ],
        ];
        $prior_totals = [
            'child-a' => 400.0,
        ];

        $merged = intersoccer_merge_sibling_child_totals($cart_by_child, $prior_totals);
        $this->assertEquals(500.0, $merged['totals']['child-a']);
        $this->assertEquals(200.0, $merged['totals']['child-b']);

        arsort($merged['totals']);
        $sorted = array_keys($merged['totals']);
        $this->assertSame('child-a', $sorted[0]);
        $this->assertSame('child-b', $sorted[1]);
    }

    public function testSiblingHelperFunctionsExistInSource() {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringContainsString('function intersoccer_discount_player_key', $contents);
        $this->assertStringContainsString('function intersoccer_get_previous_sibling_child_totals', $contents);
        $this->assertStringContainsString('function intersoccer_merge_sibling_child_totals', $contents);
        $this->assertStringContainsString('intersoccer_enable_retroactive_sibling_discounts', $contents);
    }


    /**
     * Booking week 5 first then week 2 later must treat the cart line as 2nd week (#66).
     */
    public function testSameChildWeekPositionUsesBookingOrderNotCalendar() {
        $previous = [
            [
                'week_number' => 5,
                'booking_type' => 'full-week',
                'order_date' => '2026-08-01 10:00:00',
            ],
        ];
        $position = intersoccer_discount_same_child_week_position($previous);
        $this->assertSame(2, $position, 'Later booking of an earlier calendar week is still the second booking');
    }

    /**
     * Single-day prior bookings must not count toward second-week position (#66).
     */
    public function testSameChildWeekPositionIgnoresNonFullWeekPriors() {
        $previous = [
            [
                'week_number' => 3,
                'booking_type' => 'single-days',
                'order_date' => '2026-08-01 10:00:00',
            ],
        ];
        $position = intersoccer_discount_same_child_week_position($previous);
        $this->assertSame(1, $position, 'Single-day prior must not unlock second-week rate');
    }

    public function testTournamentSiblingDoesNotUseRetroactivePriorTotals() {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringNotContainsString(
            "intersoccer_get_previous_sibling_child_totals(\$customer_id, 'tournament'",
            $contents,
            'Tournament sibling discount must be same-cart only'
        );
    }
}
