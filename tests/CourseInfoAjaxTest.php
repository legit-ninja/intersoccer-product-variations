<?php
/**
 * Unit tests for Course Info AJAX Handler
 * Ensures the AJAX handler always returns all required fields including holidays and end_date
 * 
 * Run with: vendor/bin/phpunit tests/CourseInfoAjaxTest.php
 */

use PHPUnit\Framework\TestCase;

// Mock data storage
class CourseInfoMockData {
    public static $post_meta = [];
    public static $ajax_response = null;
}

// Mock InterSoccer_Course class at global scope
if (!class_exists('InterSoccer_Course')) {
    class InterSoccer_Course {
        public static function calculate_remaining_sessions($variation_id, $total_weeks) {
            // Simple mock: return half the weeks
            return (int)ceil($total_weeks / 2);
        }

        public static function calculate_end_date($variation_id, $total_weeks) {
            $start_date = get_post_meta($variation_id, '_course_start_date', true);
            if (!$start_date || !$total_weeks) {
                return '';
            }
            $date = new DateTime($start_date);
            $date->add(new DateInterval('P' . ($total_weeks * 7) . 'D'));
            return $date->format('Y-m-d');
        }
    }
}

class CourseInfoAjaxTest extends TestCase {

    protected function setUp(): void {
        // Reset mock data
        CourseInfoMockData::$post_meta = [];
        CourseInfoMockData::$ajax_response = null;

        // Mock WordPress functions
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                if (isset(CourseInfoMockData::$post_meta[$post_id][$key])) {
                    return CourseInfoMockData::$post_meta[$post_id][$key];
                }
                return $single ? '' : [];
            }
        }

        if (!function_exists('date_i18n')) {
            function date_i18n($format, $timestamp) {
                return date($format, $timestamp);
            }
        }

        if (!function_exists('wp_send_json_success')) {
            function wp_send_json_success($data) {
                CourseInfoMockData::$ajax_response = ['success' => true, 'data' => $data];
                throw new Exception('AJAX_EXIT'); // Simulate exit
            }
        }

        if (!function_exists('wp_send_json_error')) {
            function wp_send_json_error($data, $status_code = null) {
                CourseInfoMockData::$ajax_response = ['success' => false, 'data' => $data];
                throw new Exception('AJAX_EXIT'); // Simulate exit
            }
        }

        if (!function_exists('check_ajax_referer')) {
            function check_ajax_referer($action, $query_arg = false) {
                return true;
            }
        }

        if (!function_exists('absint')) {
            function absint($maybeint) {
                return abs((int)$maybeint);
            }
        }

        if (!function_exists('__')) {
            function __($text, $domain = 'default') {
                return $text;
            }
        }

        // Mock intersoccer_get_product_type
        if (!function_exists('intersoccer_get_product_type')) {
            function intersoccer_get_product_type($product_id) {
                return 'course';
            }
        }

        // Mock ob_get_length and ob_clean
        if (!function_exists('ob_get_length')) {
            function ob_get_length() {
                return 0;
            }
        }

        if (!function_exists('ob_clean')) {
            function ob_clean() {
                return true;
            }
        }

        // Load the AJAX handler
        require_once __DIR__ . '/../includes/ajax-handlers.php';
    }

    /**
     * Test that AJAX handler returns all required fields for a course with holidays
     */
    public function testAjaxHandlerReturnsAllFieldsWithHolidays() {
        $variation_id = 123;
        $product_id = 100;

        // Setup course data with holidays
        CourseInfoMockData::$post_meta[$variation_id] = [
            '_course_start_date' => '2025-01-06',
            '_course_total_weeks' => 12,
            '_course_holiday_dates' => ['2025-02-17', '2025-03-24', '2025-04-21']
        ];

        // Setup POST data
        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        // Call AJAX handler
        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected - simulates wp_send_json_success exit
        }

        // Verify response
        $this->assertNotNull(CourseInfoMockData::$ajax_response);
        $this->assertTrue(CourseInfoMockData::$ajax_response['success']);

        $data = CourseInfoMockData::$ajax_response['data'];

        // CRITICAL: Verify all required fields are present
        $this->assertArrayHasKey('is_course', $data, 'Response must include is_course');
        $this->assertArrayHasKey('start_date', $data, 'Response must include start_date');
        $this->assertArrayHasKey('end_date', $data, 'Response must include end_date');
        $this->assertArrayHasKey('total_weeks', $data, 'Response must include total_weeks');
        $this->assertArrayHasKey('remaining_sessions', $data, 'Response must include remaining_sessions');
        $this->assertArrayHasKey('holidays', $data, 'Response must include holidays array');

        // Verify values
        $this->assertTrue($data['is_course']);
        $this->assertEquals('January 6, 2025', $data['start_date']);
        $this->assertNotEmpty($data['end_date'], 'End date should not be empty');
        $this->assertEquals(12, $data['total_weeks']);
        $this->assertEquals(6, $data['remaining_sessions']); // Mock returns half
        $this->assertIsArray($data['holidays']);
        $this->assertCount(3, $data['holidays'], 'Should return 3 formatted holidays');
        
        // Verify holidays are formatted correctly
        $this->assertEquals('February 17, 2025', $data['holidays'][0]);
        $this->assertEquals('March 24, 2025', $data['holidays'][1]);
        $this->assertEquals('April 21, 2025', $data['holidays'][2]);
    }

    /**
     * Test that AJAX handler returns empty holidays array when no holidays exist
     */
    public function testAjaxHandlerReturnsEmptyHolidaysArray() {
        $variation_id = 456;
        $product_id = 100;

        // Setup course data WITHOUT holidays
        CourseInfoMockData::$post_meta[$variation_id] = [
            '_course_start_date' => '2025-01-06',
            '_course_total_weeks' => 10,
            '_course_holiday_dates' => [] // Empty array
        ];

        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected
        }

        $data = CourseInfoMockData::$ajax_response['data'];

        // Should still have holidays key, but empty array
        $this->assertArrayHasKey('holidays', $data);
        $this->assertIsArray($data['holidays']);
        $this->assertEmpty($data['holidays']);
    }

    /**
     * Test that AJAX handler handles missing holiday dates meta gracefully
     */
    public function testAjaxHandlerHandlesMissingHolidaysMeta() {
        $variation_id = 789;
        $product_id = 100;

        // Setup course data without holiday dates meta at all
        CourseInfoMockData::$post_meta[$variation_id] = [
            '_course_start_date' => '2025-01-06',
            '_course_total_weeks' => 8
            // No _course_holiday_dates key
        ];

        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected
        }

        $data = CourseInfoMockData::$ajax_response['data'];

        // Should have holidays key with empty array (graceful handling)
        $this->assertArrayHasKey('holidays', $data);
        $this->assertIsArray($data['holidays']);
        $this->assertEmpty($data['holidays']);
    }

    /**
     * Test that end_date is calculated correctly
     */
    public function testAjaxHandlerCalculatesEndDate() {
        $variation_id = 111;
        $product_id = 100;

        CourseInfoMockData::$post_meta[$variation_id] = [
            '_course_start_date' => '2025-01-06', // Monday
            '_course_total_weeks' => 10
        ];

        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected
        }

        $data = CourseInfoMockData::$ajax_response['data'];

        // Verify end_date is calculated (10 weeks from start)
        $this->assertArrayHasKey('end_date', $data);
        $this->assertNotEmpty($data['end_date']);
        
        // Expected: 2025-01-06 + (10 * 7) days = 2025-03-17
        $this->assertEquals('March 17, 2025', $data['end_date']);
    }

    /**
     * Test that end_date is empty when start_date is missing
     */
    public function testAjaxHandlerEndDateEmptyWithoutStartDate() {
        $variation_id = 222;
        $product_id = 100;

        CourseInfoMockData::$post_meta[$variation_id] = [
            // No start_date
            '_course_total_weeks' => 10
        ];

        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected
        }

        $data = CourseInfoMockData::$ajax_response['data'];

        // end_date should be empty
        $this->assertArrayHasKey('end_date', $data);
        $this->assertEmpty($data['end_date']);
    }

    /**
     * Test that holidays with invalid date format are handled
     */
    public function testAjaxHandlerHandlesInvalidHolidayDates() {
        $variation_id = 333;
        $product_id = 100;

        CourseInfoMockData::$post_meta[$variation_id] = [
            '_course_start_date' => '2025-01-06',
            '_course_total_weeks' => 10,
            '_course_holiday_dates' => [
                '2025-02-17',    // Valid
                'invalid-date',  // Invalid
                '2025-03-24'     // Valid
            ]
        ];

        $_POST['product_id'] = $product_id;
        $_POST['variation_id'] = $variation_id;
        $_POST['nonce'] = 'test_nonce';

        try {
            intersoccer_get_course_info();
        } catch (Exception $e) {
            // Expected
        }

        $data = CourseInfoMockData::$ajax_response['data'];

        // Should still process valid dates
        $this->assertArrayHasKey('holidays', $data);
        $this->assertIsArray($data['holidays']);
        // All dates should be formatted (invalid one returns as-is per current logic)
        $this->assertCount(3, $data['holidays']);
    }

    /**
     * Test non-course product returns correct response
     */
    public function testAjaxHandlerNonCourseProduct() {
        // Override the product type function for this test
        if (function_exists('intersoccer_get_product_type')) {
            // We'll need to redefine it - skip this test or use reflection
            $this->markTestSkipped('Cannot override intersoccer_get_product_type in current test setup');
        }
    }

    /**
     * Regression test: Ensure all fields are present in every response
     * This is the critical test that would have caught the original bug
     */
    public function testRegressionAllFieldsAlwaysPresent() {
        $test_cases = [
            // Course with everything
            [
                'variation_id' => 1001,
                'meta' => [
                    '_course_start_date' => '2025-01-06',
                    '_course_total_weeks' => 12,
                    '_course_holiday_dates' => ['2025-02-17', '2025-03-24']
                ],
                'description' => 'Full course data'
            ],
            // Course without holidays
            [
                'variation_id' => 1002,
                'meta' => [
                    '_course_start_date' => '2025-01-06',
                    '_course_total_weeks' => 8,
                    '_course_holiday_dates' => []
                ],
                'description' => 'Course without holidays'
            ],
            // Course with minimal data
            [
                'variation_id' => 1003,
                'meta' => [
                    '_course_start_date' => '2025-01-06',
                    '_course_total_weeks' => 5
                ],
                'description' => 'Minimal course data'
            ]
        ];

        $required_fields = ['is_course', 'start_date', 'end_date', 'total_weeks', 'remaining_sessions', 'holidays'];

        foreach ($test_cases as $test_case) {
            // Setup
            CourseInfoMockData::$post_meta[$test_case['variation_id']] = $test_case['meta'];
            $_POST['product_id'] = 100;
            $_POST['variation_id'] = $test_case['variation_id'];
            $_POST['nonce'] = 'test_nonce';

            // Execute
            try {
                intersoccer_get_course_info();
            } catch (Exception $e) {
                // Expected
            }

            // Verify ALL required fields are present
            $data = CourseInfoMockData::$ajax_response['data'];
            foreach ($required_fields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $data,
                    "REGRESSION: Field '{$field}' missing in response for test case: {$test_case['description']}"
                );
            }

            // Verify holidays is always an array
            $this->assertIsArray(
                $data['holidays'],
                "REGRESSION: Holidays must always be an array for test case: {$test_case['description']}"
            );
        }
    }
}

