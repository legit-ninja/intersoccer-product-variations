<?php
/**
 * Unit tests for course price calculations
 * Run with: vendor/bin/phpunit tests/CoursePriceCalculationTest.php
 */

use PHPUnit\Framework\TestCase;

if (!class_exists('MockMetaData')) {
    class MockMetaData {
        public static $data = [];

        public static function reset(): void {
            self::$data = [];
        }
    }
}

if (!class_exists('TestProductTypeRegistry')) {
    class TestProductTypeRegistry {
        public static $types = [];

        public static function set($product_id, $type): void {
            self::$types[$product_id] = $type;
        }

        public static function get($product_id) {
            return self::$types[$product_id] ?? null;
        }

        public static function reset(): void {
            self::$types = [];
        }
    }
}

if (!function_exists('intersoccer_get_product_type')) {
    function intersoccer_get_product_type($product_id) {
        return TestProductTypeRegistry::get($product_id);
    }
}

class CoursePriceCalculationTest extends TestCase {

    protected function setUp(): void {
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }

        if (class_exists('InterSoccer_Course')) {
            InterSoccer_Course::reset_runtime_cache();
        }

        if (class_exists('InterSoccer_Course_Meta_Repository')) {
            InterSoccer_Course_Meta_Repository::reset_runtime_state();
        }

        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }

        if (class_exists('TestProductTypeRegistry')) {
            TestProductTypeRegistry::reset();
        }

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
        // CRITICAL: Future courses should ALWAYS return base_price, not session_rate * total_weeks
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

        // Should return full base price since course hasn't started
        $this->assertEquals(500.00, $price, 'Future course should return base_price');
        
        // REGRESSION TEST: Verify that even if we set a session_rate, future courses use base_price
        update_post_meta($variation_id, '_course_weekly_discount', 45.00); // 45 CHF per session
        $price_with_rate = InterSoccer_Course::calculate_price($product_id, $variation_id);
        
        // Should still return base price of 500, NOT 450 (45 * 10 sessions)
        $this->assertEquals(500.00, $price_with_rate, 'Future course with session_rate should still return base_price, not session_rate * total_weeks');
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

    public function testFutureCourseWithSessionRate() {
        // REGRESSION TEST: Ensure session_rate is completely bypassed for future courses
        // Bug: Customers booking early were seeing session_rate * total_weeks instead of base_price
        $product_id = 123;
        $variation_id = 789;

        // Mock course data with future start date and session rate
        $future_date = date('Y-m-d', strtotime('+2 months')); // 2 months in the future
        update_post_meta($variation_id, 'attribute_pa_course-day', 'wednesday');
        update_post_meta($variation_id, '_course_start_date', $future_date);
        update_post_meta($variation_id, '_course_total_weeks', 12);
        update_post_meta($variation_id, '_course_weekly_discount', 50.00); // 50 CHF per session
        update_post_meta($variation_id, '_course_holiday_dates', []);

        // Mock product with base price
        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(550.00); // Base price

        // Mock wc_get_product
        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        // Calculate price
        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        // CRITICAL: Should return base_price (550.00), NOT session_rate * total_weeks (50 * 12 = 600.00)
        $this->assertEquals(550.00, $price, 'Future course must return base_price, not session_rate * total_weeks');
        $this->assertNotEquals(600.00, $price, 'Future course should not calculate using session_rate');
    }

    public function testStaleRemainingSessionsHintsAreIgnored() {
        $product_id = 321;
        $variation_id = 654;

        $start_date = date('Y-m-d', strtotime('-4 weeks'));
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday');
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 12);
        update_post_meta($variation_id, '_course_weekly_discount', 40.00);
        update_post_meta($variation_id, '_course_holiday_dates', []);

        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(600.00);

        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        $authoritative_price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        $stale_hint = 12; // Equivalent to course not yet started
        $price_with_stale_hint = InterSoccer_Course::calculate_price($product_id, $variation_id, $stale_hint);

        $this->assertEquals($authoritative_price, $price_with_stale_hint, 'Stale remaining_sessions hints must be ignored in favour of freshly calculated values');
    }

    public function testMissingTotalWeeksFallsBackToBasePrice() {
        $product_id = 555;
        $variation_id = 556;

        TestProductTypeRegistry::set($product_id, 'course');

        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday');
        update_post_meta($variation_id, '_course_start_date', date('Y-m-d', strtotime('-2 weeks')));
        update_post_meta($variation_id, '_course_total_weeks', 0);
        update_post_meta($variation_id, '_course_weekly_discount', 40.00);
        update_post_meta($variation_id, '_course_holiday_dates', []);

        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(520.00);

        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

        $this->assertEquals(520.00, $price, 'Courses missing total_weeks should fall back to the regular price');

        $stats = InterSoccer_Course::get_runtime_stats();
        $this->assertEquals(1, $stats['guard_base_price'][$variation_id] ?? 0, 'Guard should record base price fallback once');
    }

    public function testRepeatedPriceRequestsUseRuntimeCache() {
        $product_id = 602;
        $variation_id = 603;

        TestProductTypeRegistry::set($product_id, 'course');

        $start_date = date('Y-m-d', strtotime('-1 week'));
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday');
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 8);
        update_post_meta($variation_id, '_course_weekly_discount', 30.00);
        update_post_meta($variation_id, '_course_holiday_dates', []);

        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(400.00);

        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        $first = InterSoccer_Course::calculate_price($product_id, $variation_id);
        $second = InterSoccer_Course::calculate_price($product_id, $variation_id);

        $this->assertEquals($first, $second, 'Cached price should be reused for repeated requests');

        $stats = InterSoccer_Course::get_runtime_stats();
        $this->assertEquals(1, $stats['price_computations'][$variation_id] ?? 0, 'Only one computation should be performed');
        $this->assertEquals(1, $stats['cache_hits'][$variation_id] ?? 0, 'Subsequent call should be served from cache');
    }

    public function testWpmlTranslationsShareCachedPrice() {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            define('ICL_SITEPRESS_VERSION', 'tests');
        }

        $product_id = 987;
        $original_variation_id = 4321;
        $translated_variation_id = 8765;

        $start_date = date('Y-m-d', strtotime('-2 weeks'));
        update_post_meta($original_variation_id, 'attribute_pa_course-day', 'monday');
        update_post_meta($original_variation_id, '_course_start_date', $start_date);
        update_post_meta($original_variation_id, '_course_total_weeks', 8);
        update_post_meta($original_variation_id, '_course_weekly_discount', 35.00);
        update_post_meta($original_variation_id, '_course_holiday_dates', []);

        // Translated variation relies on fallback metadata but has localized course day slug.
        update_post_meta($translated_variation_id, 'attribute_pa_course-day', 'lundi');

        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(480.00);

        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        if (class_exists('MockFilters')) {
            MockFilters::$filters['wpml_default_language'] = function($value) {
                return 'en';
            };

            MockFilters::$filters['wpml_object_id'] = function($value, $type, $return_original, $lang) use ($original_variation_id, $translated_variation_id) {
                if ($type === 'product_variation' && $value === $translated_variation_id) {
                    return $original_variation_id;
                }
                return $value;
            };
        }

        $original_price = InterSoccer_Course::calculate_price($product_id, $original_variation_id);

        $reflection = new ReflectionClass(InterSoccer_Course::class);
        $cache_property = $reflection->getProperty('price_cache');
        $cache_property->setAccessible(true);
        $cache_after_original = $cache_property->getValue();
        $this->assertCount(1, $cache_after_original, 'Original variation should seed a single cache entry');

        $translation_price = InterSoccer_Course::calculate_price($product_id, $translated_variation_id);
        $this->assertEquals($original_price, $translation_price, 'Translated variation must reuse the original course price');

        $cache_after_translation = $cache_property->getValue();
        $this->assertCount(1, $cache_after_translation, 'Translated variation should reuse the cached entry instead of creating duplicates');

        $stats = InterSoccer_Course::get_runtime_stats();
        $this->assertEquals(1, $stats['price_computations'][$original_variation_id] ?? 0, 'Original variation should compute price only once');
        $this->assertEquals(1, $stats['cache_hits'][$original_variation_id] ?? 0, 'Translated variation should hit cache instead of recomputing');
    }

    public function testVariationPricesHashIncludesDailySaltForCourses() {
        $product_id = 777;
        TestProductTypeRegistry::set($product_id, 'course');

        $product = new class($product_id) {
            private $id;
            public function __construct($id) {
                $this->id = $id;
            }
            public function get_id() {
                return $this->id;
            }
        };

        $hash = ['base' => 'value'];
        $filtered = InterSoccer_Course::filter_variation_prices_hash($hash, $product, true);

        $this->assertArrayHasKey('intersoccer_course_pricing_day', $filtered);
        $this->assertEquals(date('Y-m-d'), $filtered['intersoccer_course_pricing_day']);
        $this->assertArrayHasKey('intersoccer_course_pricing_version', $filtered);
    }

    public function testVariationPricesHashUnchangedForNonCourses() {
        $product_id = 778;
        TestProductTypeRegistry::set($product_id, 'camp');

        $product = new class($product_id) {
            private $id;
            public function __construct($id) {
                $this->id = $id;
            }
            public function get_id() {
                return $this->id;
            }
        };

        $hash = ['base' => 'value'];
        $filtered = InterSoccer_Course::filter_variation_prices_hash($hash, $product, true);

        $this->assertEquals($hash, $filtered, 'Non-course products should not be modified');
    }

    public function testMetaCachePrimedDuringPriceCalculation() {
        $product_id = 901;
        $variation_id = 902;

        TestProductTypeRegistry::set($product_id, 'course');

        $start_date = date('Y-m-d', strtotime('-1 week'));
        update_post_meta($variation_id, 'attribute_pa_course-day', 'monday');
        update_post_meta($variation_id, '_course_start_date', $start_date);
        update_post_meta($variation_id, '_course_total_weeks', 6);
        update_post_meta($variation_id, '_course_weekly_discount', 25.00);
        update_post_meta($variation_id, '_course_holiday_dates', []);

        $mock_product = $this->createMock(WC_Product::class);
        $mock_product->method('get_price')->willReturn(300.00);
        $mock_product->method('get_children')->willReturn([$variation_id]);
        $mock_product->method('is_type')->willReturn(false);

        global $mock_wc_get_product;
        $mock_wc_get_product = function($id) use ($mock_product) {
            return $mock_product;
        };

        InterSoccer_Course::calculate_price($product_id, $variation_id);

        $primed_ids = InterSoccer_Course_Meta_Repository::get_primed_post_ids();
        $this->assertContains($product_id, $primed_ids, 'Parent product meta should be primed');
        $this->assertContains($variation_id, $primed_ids, 'Variation meta should be primed');
    }
 
 
 }

// The real InterSoccer_Course class should be loaded from the bootstrap

// Mock WC_Product class
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id() {
            return 0;
        }
        public function is_type($type) {
            return false;
        }
        public function get_parent_id() {
            return 0;
        }
        public function get_children() {
            return [];
        }
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