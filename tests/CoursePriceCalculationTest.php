<?php
/**
 * Unit tests for course price calculations
 * Run with: vendor/bin/phpunit tests/CoursePriceCalculationTest.php
 */

use PHPUnit\Framework\TestCase;

class CoursePriceCalculationTest extends TestCase {

    protected function setUp(): void {
        // Mock WordPress functions if needed
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                // Mock implementation for testing
                static $mock_data = [];
                return $mock_data[$post_id][$key] ?? null;
            }
        }

        if (!function_exists('update_post_meta')) {
            function update_post_meta($post_id, $key, $value) {
                // Mock implementation for testing
                static $mock_data = [];
                $mock_data[$post_id][$key] = $value;
                return true;
            }
        }

        if (!function_exists('wp_get_post_terms')) {
            function wp_get_post_terms($post_id, $taxonomy, $args = []) {
                // Mock course day
                return [(object)['slug' => 'monday']];
            }
        }

        if (!function_exists('current_time')) {
            function current_time($format) {
                // Return current date for testing
                return date($format);
            }
        }
    }

    public function testFullPriceCourse() {
        // Test a course that hasn't started yet (all sessions remaining)
        $product_id = 123;
        $variation_id = 456;

        // Mock course data
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday'); // Set course day
        update_post_meta($variation_id, '_course_start_date', '2025-12-01'); // Future date
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_weekly_discount', 0); // No session rate
        update_post_meta($variation_id, '_course_holiday_dates', []);

        // Mock product price
        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(500.00);

        // Mock wc_get_product
        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        // Calculate price
        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        // Should return full price since all sessions remain
        $this->assertEquals(500.00, $price);
    }

    public function testProratedCoursePrice() {
        // Test a course that's partially complete
        $product_id = 123;
        $variation_id = 456;

        // Mock course data - started 3 weeks ago
        $start_date = date('Y-m-d', strtotime('-3 weeks'));
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday'); // Set course day
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_weekly_discount', 0); // No session rate
        update_post_meta($variation_id, '_course_holiday_dates', []);

        // Mock product price
        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(500.00);

        // Mock wc_get_product
        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        // Calculate price
        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        // Should be prorated (7/10ths of full price = 350.00)
        $this->assertEquals(350.00, $price);
    }

    public function testSessionRateCoursePrice() {
        // Test a course with session rate pricing
        $product_id = 123;
        $variation_id = 456;

        // Mock course data
        $start_date = date('Y-m-d', strtotime('-3 weeks'));
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday'); // Set course day
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_weekly_discount', 45.00); // 45 CHF per session
        update_post_meta($variation_id, '_course_holiday_dates', []);

        // Mock product price (base price)
        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(500.00);

        // Mock wc_get_product
        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        // Calculate price
        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        // Should be 45 * 7 remaining sessions = 315.00
        $this->assertEquals(315.00, $price);
    }

    public function testCourseWithHolidays() {
        // Test a course with holidays that extend the duration
        $product_id = 123;
        $variation_id = 456;

        // Mock course data
        $start_date = date('Y-m-d', strtotime('-2 weeks'));
        $holiday_date = date('Y-m-d', strtotime('-1 week')); // One holiday last week
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday'); // Set course day
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_weekly_discount', 0);
        update_post_meta($variation_id, '_course_holiday_dates', [$holiday_date]);

        // Mock product price
        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(400.00);

        // Mock wc_get_product
        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        // Calculate price
        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        // With holiday, course extends, so fewer sessions have passed
        // Should have more than 8/10ths remaining
        $this->assertGreaterThan(320.00, $price); // More than 8/10ths of 400
        $this->assertLessThanOrEqual(400.00, $price);
    }
}

// The real InterSoccer_Course class should be loaded from the bootstrap

// Mock WC_Product class
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_price() {
            return 0;
        }
    }
}

// Mock wc_get_product function
if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        global $mock_wc_get_product;
        if (isset($mock_wc_get_product) && is_callable($mock_wc_get_product)) {
            return $mock_wc_get_product($product_id);
        }
        return null;
    }
}