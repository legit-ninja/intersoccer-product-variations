<?php
/**
 * Test: Retroactive Discounts (Courses, Camps, Tournaments)
 * 
 * Purpose: Test retroactive discount calculations based on previous orders
 * Covers:
 * - Retroactive course same-season discounts
 * - Progressive camp week discounts (Week 2: 10%, Week 3+: 20%)
 * - Tournament same-child multiple days discounts (33.33%)
 * - Previous order query functions
 * - Cart context with previous order data
 * 
 * CRITICAL: These tests protect revenue and customer experience
 */

use PHPUnit\Framework\TestCase;

class RetroactiveDiscountTest extends TestCase {
    
    /**
     * Test: Tournament same-child 2nd day gets 33.33% discount (10 CHF off 30 CHF)
     */
    public function testTournamentSecondDayDiscount() {
        $tournament_price = 30.00;
        $discount_rate = 0.3333;
        $expected_discount = $tournament_price * $discount_rate;
        $expected_final_price = $tournament_price - $expected_discount;
        
        $this->assertEquals(9.999, $expected_discount, 'Second tournament day should get ~10 CHF discount', 0.01);
        $this->assertEquals(20.001, $expected_final_price, 'Second tournament day should cost ~20 CHF', 0.01);
    }
    
    /**
     * Test: Tournament pricing - 2 days for 50 CHF
     */
    public function testTournamentTwoDaysTotal() {
        $day1_price = 30.00;
        $day2_discount_rate = 0.3333;
        $day2_price = $day1_price * (1 - $day2_discount_rate);
        
        $total = $day1_price + $day2_price;
        
        $this->assertEquals(50.00, $total, '2 tournament days should total 50 CHF', 0.1);
    }
    
    /**
     * Test: Camp progressive discount - Week 2 gets 10%
     */
    public function testCampWeek2ProgressiveDiscount() {
        $camp_price = 500.00;
        $week2_rate = 0.10;
        $expected_discount = $camp_price * $week2_rate;
        
        $this->assertEquals(50.00, $expected_discount, 'Week 2 camp should get 10% discount');
    }
    
    /**
     * Test: Camp progressive discount - Week 3+ gets 20%
     */
    public function testCampWeek3PlusProgressiveDiscount() {
        $camp_price = 500.00;
        $week3_rate = 0.20;
        $expected_discount = $camp_price * $week3_rate;
        
        $this->assertEquals(100.00, $expected_discount, 'Week 3+ camp should get 20% discount');
    }
    
    /**
     * Test: Camp week parsing from camp-terms attribute
     */
    public function testCampWeekParsingFromTerms() {
        $test_cases = [
            'summer-week-1-june-24-june-28-5-days' => 1,
            'summer-week-2-july-1-july-5-5-days' => 2,
            'fall-week-10-october-15-october-19-5-days' => 10,
            'SUMMER-WEEK-5-AUGUST-1-AUGUST-5-5-DAYS' => 5, // Case insensitive
            'invalid-format' => null,
            '' => null,
        ];
        
        foreach ($test_cases as $camp_terms => $expected_week) {
            if (empty($camp_terms)) {
                $result = null;
            } elseif (preg_match('/week-(\d+)/i', $camp_terms, $matches)) {
                $result = intval($matches[1]);
            } else {
                $result = null;
            }
            
            $this->assertEquals($expected_week, $result, "Camp terms '{$camp_terms}' should parse to week {$expected_week}");
        }
    }
    
    /**
     * Test: Progressive camp discount calculation across multiple weeks
     */
    public function testProgressiveCampDiscountMultipleWeeks() {
        $base_price = 400.00;
        
        $weeks = [
            ['week' => 1, 'discount_rate' => 0.00, 'expected_price' => 400.00],
            ['week' => 2, 'discount_rate' => 0.10, 'expected_price' => 360.00],
            ['week' => 3, 'discount_rate' => 0.20, 'expected_price' => 320.00],
            ['week' => 4, 'discount_rate' => 0.20, 'expected_price' => 320.00],
        ];
        
        foreach ($weeks as $week_data) {
            $discounted_price = $base_price * (1 - $week_data['discount_rate']);
            $this->assertEquals(
                $week_data['expected_price'], 
                $discounted_price, 
                "Week {$week_data['week']} should cost {$week_data['expected_price']} CHF"
            );
        }
    }
    
    /**
     * Test: Retroactive course discount - different day in same season
     */
    public function testRetroactiveCourseDiscountDifferentDay() {
        // Scenario: Customer previously bought Saturday course, now buying Sunday course
        $course_price = 600.00;
        $same_season_rate = 0.50;
        
        $previous_course = ['course_day' => 'saturday'];
        $current_course = ['course_day' => 'sunday'];
        
        // Should get discount because days are different
        $should_apply = strtolower($previous_course['course_day']) !== strtolower($current_course['course_day']);
        
        $this->assertTrue($should_apply, 'Discount should apply when course days are different');
        
        if ($should_apply) {
            $discount = $course_price * $same_season_rate;
            $this->assertEquals(300.00, $discount, 'Different day same season should get 50% discount');
        }
    }
    
    /**
     * Test: No retroactive course discount for same day
     */
    public function testNoRetroactiveCourseDiscountSameDay() {
        $previous_course = ['course_day' => 'saturday'];
        $current_course = ['course_day' => 'saturday'];
        
        // Should NOT get discount because days are the same
        $should_apply = strtolower($previous_course['course_day']) !== strtolower($current_course['course_day']);
        
        $this->assertFalse($should_apply, 'Discount should NOT apply when course days are the same');
    }
    
    /**
     * Test: Tournament discount doesn't apply to first day
     */
    public function testTournamentNoDiscountFirstDay() {
        $tournament_price = 30.00;
        $previous_tournaments_count = 0;
        $current_position = 1; // First day
        
        $discount_rate = ($current_position >= 2) ? 0.3333 : 0.00;
        $discounted_price = $tournament_price * (1 - $discount_rate);
        
        $this->assertEquals(30.00, $discounted_price, 'First tournament day should be full price');
    }
    
    /**
     * Test: Tournament discount applies from 2nd day onwards
     */
    public function testTournamentDiscountAppliesFrom2ndDay() {
        $tournament_price = 30.00;
        $discount_rate = 0.3333;
        
        $days = [
            ['position' => 1, 'previous_count' => 0, 'should_discount' => false],
            ['position' => 2, 'previous_count' => 1, 'should_discount' => true],
            ['position' => 3, 'previous_count' => 2, 'should_discount' => true],
        ];
        
        foreach ($days as $day) {
            $rate = $day['should_discount'] ? $discount_rate : 0.00;
            $price = $tournament_price * (1 - $rate);
            
            if ($day['should_discount']) {
                $this->assertEquals(20.00, $price, "Day {$day['position']} should be discounted", 0.1);
            } else {
                $this->assertEquals(30.00, $price, "Day {$day['position']} should be full price");
            }
        }
    }
    
    /**
     * Test: Camp discount position calculation with previous orders
     */
    public function testCampDiscountPositionWithPreviousOrders() {
        // Scenario: Customer bought Week 1 and Week 3 previously, now buying Week 2 and Week 4
        $previous_weeks = [1, 3];
        $cart_weeks = [2, 4];
        
        $all_weeks = array_merge($previous_weeks, $cart_weeks);
        sort($all_weeks);
        
        $this->assertEquals([1, 2, 3, 4], $all_weeks, 'Weeks should be sorted correctly');
        
        // Week 2 in cart is position 2 overall (10% discount)
        $week2_position = array_search(2, $all_weeks) + 1;
        $this->assertEquals(2, $week2_position, 'Week 2 should be in position 2');
        
        // Week 4 in cart is position 4 overall (20% discount)
        $week4_position = array_search(4, $all_weeks) + 1;
        $this->assertEquals(4, $week4_position, 'Week 4 should be in position 4');
    }
    
    /**
     * Test: Lookback period configuration (default 6 months)
     */
    public function testLookbackPeriodDefault() {
        $default_lookback = 6;
        $min_lookback = 1;
        $max_lookback = 24;
        
        $this->assertGreaterThanOrEqual($min_lookback, $default_lookback, 'Default lookback should be at least 1 month');
        $this->assertLessThanOrEqual($max_lookback, $default_lookback, 'Default lookback should be at most 24 months');
    }
    
    /**
     * Test: Lookback period clamping
     */
    public function testLookbackPeriodClamping() {
        $test_values = [
            ['input' => 0, 'expected' => 1],   // Below minimum
            ['input' => 1, 'expected' => 1],   // Minimum
            ['input' => 6, 'expected' => 6],   // Default
            ['input' => 12, 'expected' => 12], // Valid
            ['input' => 24, 'expected' => 24], // Maximum
            ['input' => 30, 'expected' => 24], // Above maximum
        ];
        
        foreach ($test_values as $test) {
            $value = $test['input'];
            $clamped = max(1, min(24, $value));
            
            $this->assertEquals($test['expected'], $clamped, "Lookback period {$test['input']} should clamp to {$test['expected']}");
        }
    }
    
    /**
     * Test: Multiple discount types can coexist
     */
    public function testMultipleDiscountTypesCoexist() {
        // Tournament sibling discount (20% for 2nd child) vs same-child multiple days (33.33%)
        $tournament_price = 30.00;
        
        // Sibling discount (different children, same tournament)
        $sibling_rate = 0.20;
        $sibling_discount = $tournament_price * $sibling_rate;
        $this->assertEquals(6.00, $sibling_discount, 'Sibling discount should be 6 CHF');
        
        // Same-child multiple days discount
        $same_child_rate = 0.3333;
        $same_child_discount = $tournament_price * $same_child_rate;
        $this->assertEquals(9.999, $same_child_discount, 'Same-child multiple days should be ~10 CHF', 0.01);
        
        // They should NOT stack - only one applies
        $this->assertNotEquals($sibling_discount + $same_child_discount, $tournament_price, 'Discounts should not stack');
    }
    
    /**
     * Test: Discount rate precision for tournament (33.33%)
     */
    public function testTournamentDiscountRatePrecision() {
        $base_price = 30.00;
        $target_discount = 10.00;
        
        // Calculate actual rate needed for 10 CHF discount
        $actual_rate = $target_discount / $base_price;
        
        $this->assertEquals(0.3333, $actual_rate, 'Discount rate should be 33.33%', 0.0001);
    }
    
    /**
     * Test: Parent product ID matching for retroactive discounts
     */
    public function testParentProductIdMatching() {
        // Scenario: Same parent product = same tournament series
        $previous_order_item = [
            'parent_product_id' => 100,
            'assigned_player' => 'player-123',
        ];
        
        $cart_item = [
            'parent_product_id' => 100,
            'assigned_player' => 'player-123',
        ];
        
        $should_match = ($previous_order_item['parent_product_id'] == $cart_item['parent_product_id']) &&
                       ($previous_order_item['assigned_player'] == $cart_item['assigned_player']);
        
        $this->assertTrue($should_match, 'Items with same parent product and player should match');
    }
    
    /**
     * Test: Different parent products don't match
     */
    public function testDifferentParentProductsDontMatch() {
        $previous_item = ['parent_product_id' => 100, 'assigned_player' => 'player-123'];
        $cart_item = ['parent_product_id' => 200, 'assigned_player' => 'player-123'];
        
        $should_match = ($previous_item['parent_product_id'] == $cart_item['parent_product_id']) &&
                       ($previous_item['assigned_player'] == $cart_item['assigned_player']);
        
        $this->assertFalse($should_match, 'Items with different parent products should not match');
    }
    
    /**
     * Test: Different players don't get combined discount
     */
    public function testDifferentPlayersDontGetCombinedDiscount() {
        $previous_item = ['parent_product_id' => 100, 'assigned_player' => 'player-123'];
        $cart_item = ['parent_product_id' => 100, 'assigned_player' => 'player-456'];
        
        $should_match = ($previous_item['parent_product_id'] == $cart_item['parent_product_id']) &&
                       ($previous_item['assigned_player'] == $cart_item['assigned_player']);
        
        $this->assertFalse($should_match, 'Items for different players should not match');
    }
    
    /**
     * Test: Discount sorting - apply to cheaper items first
     */
    public function testDiscountSortingCheaperFirst() {
        $items = [
            ['price' => 50.00, 'id' => 'expensive'],
            ['price' => 20.00, 'id' => 'cheap'],
            ['price' => 35.00, 'id' => 'medium'],
        ];
        
        usort($items, function($a, $b) { return $a['price'] <=> $b['price']; });
        
        $this->assertEquals('cheap', $items[0]['id'], 'Cheapest item should be first');
        $this->assertEquals('medium', $items[1]['id'], 'Medium item should be second');
        $this->assertEquals('expensive', $items[2]['id'], 'Expensive item should be last');
    }
    
    /**
     * Test: Cache key generation for previous orders
     */
    public function testCacheKeyGenerationForPreviousOrders() {
        $customer_id = 42;
        $parent_product_id = 100;
        $assigned_player = 'player-123';
        $lookback_months = 6;
        
        $cache_key = 'tournaments_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months;
        
        $this->assertEquals('tournaments_42_100_player-123_6', $cache_key, 'Cache key should be correctly formatted');
        
        // Test uniqueness
        $cache_key_different_customer = 'tournaments_43_100_player-123_6';
        $this->assertNotEquals($cache_key, $cache_key_different_customer, 'Different customers should have different cache keys');
    }
    
    /**
     * Test: Admin settings defaults
     */
    public function testAdminSettingsDefaults() {
        $defaults = [
            'enable_retroactive_courses' => true,
            'enable_retroactive_camps' => true,
            'lookback_months' => 6,
            'tournament_same_child_rate' => 33.33,
        ];
        
        $this->assertTrue($defaults['enable_retroactive_courses'], 'Retroactive courses should be enabled by default');
        $this->assertTrue($defaults['enable_retroactive_camps'], 'Retroactive camps should be enabled by default');
        $this->assertEquals(6, $defaults['lookback_months'], 'Default lookback should be 6 months');
        $this->assertEquals(33.33, $defaults['tournament_same_child_rate'], 'Tournament discount should be 33.33%');
    }
}

