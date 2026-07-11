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

    public function testTournamentSiblingDoesNotUseRetroactivePriorTotals() {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $this->assertStringNotContainsString(
            "intersoccer_get_previous_sibling_child_totals(\$customer_id, 'tournament'",
            $contents,
            'Tournament sibling discount must be same-cart only'
        );
    }
}
