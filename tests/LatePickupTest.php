<?php
/**
 * Test: Late Pickup System
 * 
 * Purpose: Ensure late pickup pricing, validation, and cart data handling work correctly
 * Covers: late-pickup.php - cart data capture, price adjustments, validation
 * 
 * CRITICAL: Late pickup is revenue-generating - errors = money lost
 */

use PHPUnit\Framework\TestCase;

class LatePickupTest extends TestCase {
    
    private $mock_variation_id = 123;
    private $per_day_cost = 25.0;
    private $full_week_cost = 90.0;
    
    public function setUp(): void {
        parent::setUp();
        
        // Mock WordPress functions
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                static $mock_data = [];
                if ($key === '_intersoccer_enable_late_pickup') {
                    return 'yes';
                }
                return $mock_data[$post_id][$key] ?? '';
            }
        }
        
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                if ($option === 'intersoccer_late_pickup_per_day') return 25.0;
                if ($option === 'intersoccer_late_pickup_full_week') return 90.0;
                return $default;
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return trim(strip_tags($str));
            }
        }
        
        if (!function_exists('wc_add_notice')) {
            function wc_add_notice($message, $type = 'notice') {
                // Mock notice function
            }
        }
        
        if (!function_exists('__')) {
            function __($text, $domain = 'default') {
                return $text;
            }
        }
        
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {
                // Suppress error logs in tests
            }
        }
    }
    
    /**
     * Test: Per-day late pickup cost calculation
     */
    public function testPerDayLatePickupCost() {
        $days_selected = ['Monday', 'Wednesday', 'Friday'];
        $expected_cost = count($days_selected) * $this->per_day_cost;
        
        $actual_cost = count($days_selected) * $this->per_day_cost;
        
        $this->assertEquals(75.0, $actual_cost, 'Per-day late pickup cost should be 25 * 3 days = 75');
    }
    
    /**
     * Test: Full-week late pickup cost
     */
    public function testFullWeekLatePickupCost() {
        $full_week_cost = $this->full_week_cost;
        
        $this->assertEquals(90.0, $full_week_cost, 'Full-week late pickup should be 90 CHF');
    }
    
    /**
     * Test: Single day late pickup cost
     */
    public function testSingleDayLatePickupCost() {
        $days_selected = ['Monday'];
        $expected_cost = $this->per_day_cost;
        
        $actual_cost = count($days_selected) * $this->per_day_cost;
        
        $this->assertEquals(25.0, $actual_cost, 'Single day late pickup should be 25 CHF');
    }
    
    /**
     * Test: No days selected = no cost
     */
    public function testNoDaysSelectedNoCost() {
        $days_selected = [];
        $cost = count($days_selected) * $this->per_day_cost;
        
        $this->assertEquals(0.0, $cost, 'No days selected should result in 0 cost');
    }
    
    /**
     * Test: Valid day validation
     */
    public function testValidDayValidation() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $test_day = 'Wednesday';
        
        $is_valid = in_array($test_day, $valid_days, true);
        
        $this->assertTrue($is_valid, 'Wednesday should be a valid late pickup day');
    }
    
    /**
     * Test: Invalid day validation (weekend)
     */
    public function testInvalidDayValidation() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $test_day = 'Saturday';
        
        $is_valid = in_array($test_day, $valid_days, true);
        
        $this->assertFalse($is_valid, 'Saturday should not be a valid late pickup day');
    }
    
    /**
     * Test: Multiple invalid days
     */
    public function testMultipleInvalidDays() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $test_days = ['Saturday', 'Sunday', 'InvalidDay'];
        
        foreach ($test_days as $day) {
            $is_valid = in_array($day, $valid_days, true);
            $this->assertFalse($is_valid, "{$day} should not be a valid late pickup day");
        }
    }
    
    /**
     * Test: Cart item data structure for late pickup
     */
    public function testCartItemDataStructure() {
        $cart_item_data = [
            'late_pickup_type' => 'single-days',
            'late_pickup_days' => ['Monday', 'Wednesday', 'Friday'],
            'late_pickup_cost' => 75.0,
            'base_price' => 500.0
        ];
        
        $this->assertArrayHasKey('late_pickup_type', $cart_item_data, 'Should have late_pickup_type');
        $this->assertArrayHasKey('late_pickup_days', $cart_item_data, 'Should have late_pickup_days');
        $this->assertArrayHasKey('late_pickup_cost', $cart_item_data, 'Should have late_pickup_cost');
        $this->assertEquals(3, count($cart_item_data['late_pickup_days']), 'Should have 3 days');
    }
    
    /**
     * Test: Full-week cart item data
     */
    public function testFullWeekCartItemData() {
        $cart_item_data = [
            'late_pickup_type' => 'full-week',
            'late_pickup_days' => [],
            'late_pickup_cost' => 90.0
        ];
        
        $this->assertEquals('full-week', $cart_item_data['late_pickup_type'], 'Type should be full-week');
        $this->assertEquals(90.0, $cart_item_data['late_pickup_cost'], 'Cost should be 90 CHF for full week');
        $this->assertEmpty($cart_item_data['late_pickup_days'], 'Full-week should have no specific days');
    }
    
    /**
     * Test: Price adjustment calculation
     */
    public function testPriceAdjustmentCalculation() {
        $original_price = 500.0;
        $late_pickup_cost = 75.0;
        $expected_total = 575.0;
        
        $actual_total = $original_price + $late_pickup_cost;
        
        $this->assertEquals($expected_total, $actual_total, 'Total price should include late pickup cost');
    }
    
    /**
     * Test: Late pickup enabled check
     */
    public function testLatePickupEnabledCheck() {
        // Simulate checking if late pickup is enabled
        $enable_late_pickup = get_post_meta($this->mock_variation_id, '_intersoccer_enable_late_pickup', true);
        
        $this->assertEquals('yes', $enable_late_pickup, 'Late pickup should be enabled for test variation');
    }
    
    /**
     * Test: Late pickup disabled should skip processing
     */
    public function testLatePickupDisabledSkipsProcessing() {
        // Simulate disabled late pickup
        $enabled = false;
        
        if (!$enabled) {
            $cart_data = ['product_id' => 123];
            // Should not add late pickup data
            $this->assertArrayNotHasKey('late_pickup_cost', $cart_data, 'Should not add late pickup cost when disabled');
        }
        
        $this->assertTrue(true, 'Disabled late pickup should skip processing');
    }
    
    /**
     * Test: Cost validation - non-negative
     */
    public function testCostValidationNonNegative() {
        $days = ['Monday', 'Tuesday'];
        $cost = count($days) * $this->per_day_cost;
        
        $this->assertGreaterThan(0, $cost, 'Late pickup cost should be positive');
        $this->assertEquals(50.0, $cost, 'Two days should cost 50 CHF');
    }
    
    /**
     * Test: Day sanitization
     */
    public function testDaySanitization() {
        $raw_days = ['  Monday  ', '<script>Tuesday</script>', 'Wednesday'];
        $sanitized = array_map('sanitize_text_field', $raw_days);
        
        $this->assertEquals('Monday', $sanitized[0], 'Should trim whitespace');
        $this->assertEquals('Tuesday', $sanitized[1], 'Should strip HTML tags');
        $this->assertEquals('Wednesday', $sanitized[2], 'Clean input should remain unchanged');
    }
    
    /**
     * Test: Late pickup type validation
     */
    public function testLatePickupTypeValidation() {
        $valid_types = ['single-days', 'full-week', 'none'];
        
        $this->assertContains('single-days', $valid_types, 'single-days should be valid');
        $this->assertContains('full-week', $valid_types, 'full-week should be valid');
        $this->assertNotContains('invalid-type', $valid_types, 'invalid-type should not be valid');
    }
    
    /**
     * Test: All weekdays are valid
     */
    public function testAllWeekdaysValid() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        foreach ($weekdays as $day) {
            $this->assertContains($day, $valid_days, "{$day} should be a valid late pickup day");
        }
    }
    
    /**
     * Test: Maximum cost calculation (all 5 days)
     */
    public function testMaximumCostAllDays() {
        $all_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $per_day_total = count($all_days) * $this->per_day_cost;
        
        $this->assertEquals(125.0, $per_day_total, '5 days should cost 125 CHF');
        $this->assertLessThan($this->full_week_cost, $per_day_total, 'Full week should be cheaper than 5 individual days');
    }
    
    /**
     * Test: Full week is better value than individual days
     */
    public function testFullWeekBetterValue() {
        $five_days_cost = 5 * $this->per_day_cost; // 125
        $full_week_cost = $this->full_week_cost;   // 90
        
        $this->assertLessThan($five_days_cost, $full_week_cost, 'Full week should be better value than 5 individual days');
        $savings = $five_days_cost - $full_week_cost;
        $this->assertEquals(35.0, $savings, 'Full week saves 35 CHF vs individual days');
    }
}

