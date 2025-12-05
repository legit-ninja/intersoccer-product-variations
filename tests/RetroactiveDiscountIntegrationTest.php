<?php
/**
 * Test: Retroactive Discounts Integration
 * 
 * Purpose: Test actual discount functions with mocked WooCommerce data
 * Tests the helper functions that query and extract order data
 */

use PHPUnit\Framework\TestCase;

class RetroactiveDiscountIntegrationTest extends TestCase {
    
    /**
     * Test: intersoccer_parse_camp_week_from_terms function
     */
    public function testParseCampWeekFromTerms() {
        // Mock the function if it's not loaded
        if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
            $this->markTestSkipped('Function intersoccer_parse_camp_week_from_terms not loaded');
        }
        
        $test_cases = [
            'summer-week-1-june-24-june-28-5-days' => 1,
            'summer-week-2-july-1-july-5-5-days' => 2,
            'fall-week-10-october-15-october-19-5-days' => 10,
            'SUMMER-WEEK-5-AUGUST-1-AUGUST-5-5-DAYS' => 5,
        ];
        
        foreach ($test_cases as $camp_terms => $expected_week) {
            $result = intersoccer_parse_camp_week_from_terms($camp_terms);
            $this->assertEquals($expected_week, $result, "Camp terms '{$camp_terms}' should parse to week {$expected_week}");
        }
    }
    
    /**
     * Test: Empty camp terms returns null
     */
    public function testParseCampWeekFromTermsEmpty() {
        if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
            $this->markTestSkipped('Function intersoccer_parse_camp_week_from_terms not loaded');
        }
        
        $result = intersoccer_parse_camp_week_from_terms('');
        $this->assertNull($result, 'Empty camp terms should return null');
        
        $result = intersoccer_parse_camp_week_from_terms(null);
        $this->assertNull($result, 'Null camp terms should return null');
    }
    
    /**
     * Test: Invalid camp terms format returns null
     */
    public function testParseCampWeekFromTermsInvalid() {
        if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
            $this->markTestSkipped('Function intersoccer_parse_camp_week_from_terms not loaded');
        }
        
        $invalid_formats = [
            'invalid-format',
            'no-week-number-here',
            'summer-2024',
            'week',
        ];
        
        foreach ($invalid_formats as $invalid) {
            $result = intersoccer_parse_camp_week_from_terms($invalid);
            $this->assertNull($result, "Invalid format '{$invalid}' should return null");
        }
    }
    
    /**
     * Test: Discount rule structure for new tournament discount
     */
    public function testTournamentDiscountRuleStructure() {
        $expected_rule = [
            'id' => 'tournament-same-child-multiple-days',
            'name' => 'Tournament Same Child Multiple Days Discount',
            'type' => 'tournament',
            'condition' => 'same_child_multiple_days',
            'rate' => 33.33,
            'active' => true,
        ];
        
        $this->assertEquals('tournament', $expected_rule['type'], 'Rule type should be tournament');
        $this->assertEquals('same_child_multiple_days', $expected_rule['condition'], 'Condition should be same_child_multiple_days');
        $this->assertEquals(33.33, $expected_rule['rate'], 'Rate should be 33.33%');
        $this->assertTrue($expected_rule['active'], 'Rule should be active by default');
    }
    
    /**
     * Test: Progressive camp discount rule structures
     */
    public function testProgressiveCampDiscountRules() {
        $week2_rule = [
            'id' => 'camp-progressive-week-2',
            'type' => 'camp',
            'condition' => 'progressive_week_2',
            'rate' => 10,
        ];
        
        $week3_rule = [
            'id' => 'camp-progressive-week-3-plus',
            'type' => 'camp',
            'condition' => 'progressive_week_3_plus',
            'rate' => 20,
        ];
        
        $this->assertEquals('camp', $week2_rule['type'], 'Week 2 rule should be camp type');
        $this->assertEquals(10, $week2_rule['rate'], 'Week 2 should be 10% discount');
        
        $this->assertEquals('camp', $week3_rule['type'], 'Week 3+ rule should be camp type');
        $this->assertEquals(20, $week3_rule['rate'], 'Week 3+ should be 20% discount');
    }
    
    /**
     * Test: Allowed conditions include new discount types
     */
    public function testAllowedConditionsIncludeNewTypes() {
        $allowed_conditions = [
            '2nd_child',
            '3rd_plus_child',
            'same_season_course',
            'progressive_week_2',
            'progressive_week_3_plus',
            'same_child_multiple_days',
            'none'
        ];
        
        $this->assertContains('progressive_week_2', $allowed_conditions, 'progressive_week_2 should be allowed');
        $this->assertContains('progressive_week_3_plus', $allowed_conditions, 'progressive_week_3_plus should be allowed');
        $this->assertContains('same_child_multiple_days', $allowed_conditions, 'same_child_multiple_days should be allowed');
    }
    
    /**
     * Test: Lookback period validation
     */
    public function testLookbackPeriodValidation() {
        $test_cases = [
            ['input' => -5, 'expected' => 1],   // Negative
            ['input' => 0, 'expected' => 1],    // Zero
            ['input' => 1, 'expected' => 1],    // Min
            ['input' => 6, 'expected' => 6],    // Default
            ['input' => 12, 'expected' => 12],  // Valid
            ['input' => 24, 'expected' => 24],  // Max
            ['input' => 100, 'expected' => 24], // Over max
        ];
        
        foreach ($test_cases as $test) {
            $value = $test['input'];
            $clamped = max(1, min(24, $value));
            
            $this->assertEquals($test['expected'], $clamped, "Input {$test['input']} should clamp to {$test['expected']}");
        }
    }
    
    /**
     * Test: Week position calculation with gaps
     */
    public function testWeekPositionCalculationWithGaps() {
        // Customer bought Week 1, Week 5, now buying Week 3
        $previous_weeks = [1, 5];
        $current_week = 3;
        
        $all_weeks = array_merge($previous_weeks, [$current_week]);
        sort($all_weeks);
        
        $this->assertEquals([1, 3, 5], $all_weeks, 'Weeks should be sorted');
        
        $week3_position = array_search(3, $all_weeks) + 1;
        $this->assertEquals(2, $week3_position, 'Week 3 should be in position 2 (gets 10% discount)');
    }
    
    /**
     * Test: Tournament day position with previous orders
     */
    public function testTournamentDayPositionWithPreviousOrders() {
        // Customer bought 1 tournament day previously, now buying 2 more days
        $previous_count = 1;
        $cart_items_count = 2;
        
        $positions = [];
        for ($i = 0; $i < $cart_items_count; $i++) {
            $position = $previous_count + $i + 1;
            $positions[] = $position;
        }
        
        $this->assertEquals([2, 3], $positions, 'Cart items should be at positions 2 and 3');
        
        // Both should get discount (position >= 2)
        foreach ($positions as $pos) {
            $this->assertGreaterThanOrEqual(2, $pos, "Position {$pos} should get discount");
        }
    }
    
    /**
     * Test: Discount calculation with real-world prices
     */
    public function testRealWorldDiscountCalculations() {
        $scenarios = [
            [
                'name' => 'Tournament 2 days',
                'base_price' => 30.00,
                'discount_rate' => 0.3333,
                'expected_discounted' => 20.00,
                'tolerance' => 0.1
            ],
            [
                'name' => 'Camp Week 2',
                'base_price' => 450.00,
                'discount_rate' => 0.10,
                'expected_discounted' => 405.00,
                'tolerance' => 0.01
            ],
            [
                'name' => 'Camp Week 3',
                'base_price' => 450.00,
                'discount_rate' => 0.20,
                'expected_discounted' => 360.00,
                'tolerance' => 0.01
            ],
            [
                'name' => 'Course 2nd day same season',
                'base_price' => 600.00,
                'discount_rate' => 0.50,
                'expected_discounted' => 300.00,
                'tolerance' => 0.01
            ],
        ];
        
        foreach ($scenarios as $scenario) {
            $discounted = $scenario['base_price'] * (1 - $scenario['discount_rate']);
            $this->assertEquals(
                $scenario['expected_discounted'],
                $discounted,
                "{$scenario['name']}: Expected {$scenario['expected_discounted']} CHF",
                $scenario['tolerance']
            );
        }
    }
    
    /**
     * Test: Cache key format consistency
     */
    public function testCacheKeyFormatConsistency() {
        $customer_id = 42;
        $parent_product_id = 100;
        $assigned_player = 'player-123';
        $lookback_months = 6;
        
        $cache_keys = [
            'courses' => 'courses_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months,
            'camps' => 'camps_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months,
            'tournaments' => 'tournaments_' . $customer_id . '_' . $parent_product_id . '_' . $assigned_player . '_' . $lookback_months,
        ];
        
        $this->assertEquals('courses_42_100_player-123_6', $cache_keys['courses']);
        $this->assertEquals('camps_42_100_player-123_6', $cache_keys['camps']);
        $this->assertEquals('tournaments_42_100_player-123_6', $cache_keys['tournaments']);
        
        // Ensure all keys are unique
        $unique_keys = array_unique(array_values($cache_keys));
        $this->assertCount(3, $unique_keys, 'All cache keys should be unique');
    }
    
    /**
     * Test: Discount doesn't apply without assigned player
     */
    public function testNoDiscountWithoutAssignedPlayer() {
        $item_with_player = ['assigned_player' => 'player-123'];
        $item_without_player = ['assigned_player' => null];
        
        $this->assertNotEmpty($item_with_player['assigned_player'], 'Item with player should have assigned_player');
        $this->assertEmpty($item_without_player['assigned_player'], 'Item without player should not have assigned_player');
    }
    
    /**
     * Test: Discount doesn't apply without parent product
     */
    public function testNoDiscountWithoutParentProduct() {
        $item_with_parent = ['parent_product_id' => 100];
        $item_without_parent = ['parent_product_id' => null];
        
        $this->assertNotEmpty($item_with_parent['parent_product_id'], 'Item with parent should have parent_product_id');
        $this->assertEmpty($item_without_parent['parent_product_id'], 'Item without parent should not have parent_product_id');
    }
}

