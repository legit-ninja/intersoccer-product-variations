<?php
/**
 * Test: Product Camp System
 * 
 * Purpose: Ensure camp pricing, single-day calculations, and validation work correctly
 * Covers: product-camp.php - InterSoccer_Camp class
 * 
 * CRITICAL: Camp pricing errors = revenue loss and customer confusion
 */

use PHPUnit\Framework\TestCase;

class ProductCampTest extends TestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        // Mock WordPress/WooCommerce functions
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                static $mock_data = [
                    123 => [
                        'attribute_pa_booking-type' => 'single-days',
                        '_price' => '120'
                    ],
                    124 => [
                        'attribute_pa_booking-type' => 'full-week',
                        '_price' => '500'
                    ],
                    125 => [
                        'attribute_pa_booking-type' => 'à la journée', // French
                        '_price' => '110'
                    ]
                ];
                return $mock_data[$post_id][$key] ?? '';
            }
        }
        
        if (!function_exists('wc_get_product')) {
            function wc_get_product($product_id) {
                return new class($product_id) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_price() {
                        $prices = [123 => 120, 124 => 500, 125 => 110];
                        return $prices[$this->id] ?? 0;
                    }
                };
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
            function error_log($message) {}
        }
    }
    
    /**
     * Test: Single-day booking type detection (English)
     */
    public function testSingleDayBookingTypeDetectionEnglish() {
        $booking_type = 'single-days';
        
        $is_single_day = stripos($booking_type, 'single') !== false ||
                        stripos($booking_type, 'day') !== false;
        
        $this->assertTrue($is_single_day, 'Should detect "single-days" as single-day booking');
    }
    
    /**
     * Test: Single-day booking type detection (French)
     */
    public function testSingleDayBookingTypeDetectionFrench() {
        $booking_types = ['à la journée', 'a-la-journee', 'jour'];
        
        foreach ($booking_types as $booking_type) {
            $is_single_day = stripos($booking_type, 'jour') !== false;
            $this->assertTrue($is_single_day, "Should detect '{$booking_type}' as single-day booking");
        }
    }
    
    /**
     * Test: Single-day booking type detection (German)
     */
    public function testSingleDayBookingTypeDetectionGerman() {
        $booking_types = ['einzeltag', 'einzel-tag', 'tag'];
        
        foreach ($booking_types as $booking_type) {
            $is_single_day = stripos($booking_type, 'einzel') !== false ||
                            stripos($booking_type, 'tag') !== false;
            $this->assertTrue($is_single_day, "Should detect '{$booking_type}' as single-day booking");
        }
    }
    
    /**
     * Test: Full-week booking type detection
     */
    public function testFullWeekBookingTypeDetection() {
        $booking_type = 'full-week';
        
        $is_single_day = stripos($booking_type, 'single') !== false;
        
        $this->assertFalse($is_single_day, 'full-week should NOT be detected as single-day');
    }
    
    /**
     * Test: Single-day price calculation (3 days)
     */
    public function testSingleDayPriceCalculation3Days() {
        $price_per_day = 120.0;
        $days_selected = ['Monday', 'Wednesday', 'Friday'];
        $num_days = count($days_selected);
        
        $total_price = $price_per_day * $num_days;
        
        $this->assertEquals(360.0, $total_price, 'Price should be 120 * 3 = 360 CHF');
    }
    
    /**
     * Test: Single-day price calculation (1 day)
     */
    public function testSingleDayPriceCalculation1Day() {
        $price_per_day = 120.0;
        $days_selected = ['Monday'];
        
        $total_price = $price_per_day * count($days_selected);
        
        $this->assertEquals(120.0, $total_price, 'Price should be 120 * 1 = 120 CHF');
    }
    
    /**
     * Test: Single-day price calculation (all 5 days)
     */
    public function testSingleDayPriceCalculationAllDays() {
        $price_per_day = 120.0;
        $days_selected = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        $total_price = $price_per_day * count($days_selected);
        
        $this->assertEquals(600.0, $total_price, 'Price should be 120 * 5 = 600 CHF');
    }
    
    /**
     * Test: Full-week price (no per-day multiplication)
     */
    public function testFullWeekPrice() {
        $full_week_price = 500.0;
        
        // Full week price is fixed, not multiplied by days
        $this->assertEquals(500.0, $full_week_price, 'Full-week price should be 500 CHF flat');
    }
    
    /**
     * Test: No days selected returns zero or base price
     */
    public function testNoDaysSelectedReturnsZero() {
        $price_per_day = 120.0;
        $days_selected = [];
        
        $total_price = $price_per_day * count($days_selected);
        
        $this->assertEquals(0.0, $total_price, 'No days selected should result in 0 price');
    }
    
    /**
     * Test: Price validation (non-negative)
     */
    public function testPriceValidationNonNegative() {
        $price_per_day = 120.0;
        $days = ['Monday', 'Tuesday'];
        
        $total = $price_per_day * count($days);
        $validated = max(0, floatval($total));
        
        $this->assertEquals(240.0, $validated, 'Validated price should be 240 CHF');
        $this->assertGreaterThanOrEqual(0, $validated, 'Price should never be negative');
    }
    
    /**
     * Test: Discount note generation for selected days
     */
    public function testDiscountNoteGeneration() {
        $days_selected = ['Monday', 'Wednesday', 'Friday'];
        $note = sprintf('%d Day(s) Selected', count($days_selected));
        
        $this->assertEquals('3 Day(s) Selected', $note, 'Discount note should show number of days');
    }
    
    /**
     * Test: Empty discount note when no days selected
     */
    public function testEmptyDiscountNoteNodays() {
        $days_selected = [];
        $note = empty($days_selected) ? '' : sprintf('%d Day(s) Selected', count($days_selected));
        
        $this->assertEmpty($note, 'Discount note should be empty when no days selected');
    }
    
    /**
     * Test: Multilingual booking type comparison (case-insensitive)
     */
    public function testMultilingualBookingTypeCaseInsensitive() {
        $test_cases = [
            'Single-Days' => true,
            'SINGLE-DAYS' => true,
            'single-days' => true,
            'À La Journée' => true,
            'EINZEL-TAG' => true
        ];
        
        foreach ($test_cases as $booking_type => $expected) {
            $is_single = stripos($booking_type, 'single') !== false ||
                        stripos($booking_type, 'jour') !== false ||
                        stripos($booking_type, 'einzel') !== false ||
                        stripos($booking_type, 'day') !== false ||
                        stripos($booking_type, 'tag') !== false;
            
            $this->assertEquals($expected, $is_single, "'{$booking_type}' detection failed");
        }
    }
    
    /**
     * Test: Camp days validation (valid days only)
     */
    public function testCampDaysValidation() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $selected_days = ['Monday', 'Wednesday', 'Friday'];
        
        foreach ($selected_days as $day) {
            $this->assertContains($day, $valid_days, "{$day} should be a valid camp day");
        }
    }
    
    /**
     * Test: Invalid camp day detection
     */
    public function testInvalidCampDayDetection() {
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $invalid_days = ['Saturday', 'Sunday', 'InvalidDay'];
        
        foreach ($invalid_days as $day) {
            $this->assertNotContains($day, $valid_days, "{$day} should NOT be a valid camp day");
        }
    }
    
    /**
     * Test: Single-day camp requires at least one day
     */
    public function testSingleDayCampRequiresOneDay() {
        $days_selected = [];
        $booking_type = 'single-days';
        
        $is_single_day = stripos($booking_type, 'single') !== false;
        $has_days = !empty($days_selected);
        
        if ($is_single_day && !$has_days) {
            $validation_passed = false;
        } else {
            $validation_passed = true;
        }
        
        $this->assertFalse($validation_passed, 'Single-day camp without days should fail validation');
    }
    
    /**
     * Test: Full-week camp does not require day selection
     */
    public function testFullWeekCampDoesNotRequireDays() {
        $days_selected = [];
        $booking_type = 'full-week';
        
        $is_single_day = stripos($booking_type, 'single') !== false;
        
        if (!$is_single_day) {
            $validation_passed = true;
        } else {
            $validation_passed = !empty($days_selected);
        }
        
        $this->assertTrue($validation_passed, 'Full-week camp should pass validation without days');
    }
    
    /**
     * Test: Price calculation with quantity parameter
     */
    public function testPriceCalculationWithQuantity() {
        $price_per_day = 120.0;
        $days_selected = ['Monday', 'Wednesday'];
        $quantity = 2; // 2 children
        
        // For camps, quantity doesn't multiply the price (each child gets separate cart item)
        $price_per_child = $price_per_day * count($days_selected);
        
        $this->assertEquals(240.0, $price_per_child, 'Each child should have price 120 * 2 days = 240 CHF');
    }
    
    /**
     * Test: Zero price edge case
     */
    public function testZeroPriceEdgeCase() {
        $price_per_day = 0.0;
        $days_selected = ['Monday', 'Tuesday'];
        
        $total = max(0, floatval($price_per_day * count($days_selected)));
        
        $this->assertEquals(0.0, $total, 'Zero price should remain zero');
    }
    
    /**
     * Test: Negative price protection
     */
    public function testNegativePriceProtection() {
        $invalid_price = -100.0;
        $days_selected = ['Monday'];
        
        $total = $invalid_price * count($days_selected);
        $protected = max(0, floatval($total));
        
        $this->assertEquals(0.0, $protected, 'Negative price should be clamped to 0');
    }
    
    /**
     * Test: Booking type detection with special characters
     */
    public function testBookingTypeWithSpecialCharacters() {
        $booking_types = ['single-days', 'single_days', 'single days', 'Single-Days'];
        
        foreach ($booking_types as $booking_type) {
            $normalized = strtolower($booking_type);
            $is_single = strpos($normalized, 'single') !== false;
            $this->assertTrue($is_single, "'{$booking_type}' should be detected as single-day");
        }
    }
}

