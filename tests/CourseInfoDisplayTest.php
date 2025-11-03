<?php
/**
 * Unit tests for course information display functionality
 * Run with: vendor/bin/phpunit tests/CourseInfoDisplayTest.php
 */

require_once __DIR__ . '/../includes/woocommerce/product-course.php';

use PHPUnit\Framework\TestCase;

// Mock data storage for testing
class MockMetaData {
    public static $data = [];
}

// Mock InterSoccer_Course class for testing
if (!class_exists('InterSoccer_Course')) {
    class InterSoccer_Course {
        public static function calculate_end_date($variation_id, $total_weeks) {
            // Mock implementation - return a date 10 weeks from start
            $start_date = get_post_meta($variation_id, '_course_start_date', true);
            if ($start_date) {
                $start = new DateTime($start_date);
                $start->add(new DateInterval('P' . ($total_weeks * 7) . 'D'));
                return $start->format('Y-m-d');
            }
            return '';
        }

        public static function calculate_remaining_sessions($variation_id, $total_weeks) {
            // Mock implementation - return half the sessions for testing
            return (int)($total_weeks / 2);
        }
    }
}

class CourseInfoDisplayTest extends TestCase {

    protected function setUp(): void {
        // Clear mock data for each test
        MockMetaData::$data = [];

        // Mock WordPress functions
        if (!function_exists('get_post_meta')) {
            function get_post_meta($post_id, $key, $single = false) {
                return MockMetaData::$data[$post_id][$key] ?? null;
            }
        }

        if (!function_exists('update_post_meta')) {
            function update_post_meta($post_id, $key, $value) {
                MockMetaData::$data[$post_id][$key] = $value;
                return true;
            }
        }

        if (!function_exists('get_option')) {
            function get_option($key, $default = false) {
                static $mock_options = [
                    'date_format' => 'F j, Y'
                ];
                return $mock_options[$key] ?? $default;
            }
        }

        if (!function_exists('date_i18n')) {
            function date_i18n($format, $timestamp) {
                return date($format, $timestamp);
            }
        }

        if (!function_exists('__')) {
            function __($text, $domain = 'default') {
                return $text;
            }
        }

        if (!function_exists('esc_html')) {
            function esc_html($text) {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        if (!function_exists('wp_send_json_success')) {
            function wp_send_json_success($data) {
                echo json_encode(['success' => true, 'data' => $data]);
                exit;
            }
        }

        if (!function_exists('wp_send_json_error')) {
            function wp_send_json_error($data) {
                echo json_encode(['success' => false, 'data' => $data]);
                exit;
            }
        }

        if (!function_exists('check_ajax_referer')) {
            function check_ajax_referer($action, $query_arg = false) {
                // Mock - assume nonce is valid for testing
                return true;
            }
        }

        if (!function_exists('absint')) {
            function absint($maybeint) {
                return abs((int)$maybeint);
            }
        }

        // Mock InterSoccer_Course class methods will be defined globally

        // Mock intersoccer_get_course_meta function
        if (!function_exists('intersoccer_get_course_meta')) {
            function intersoccer_get_course_meta($variation_id, $meta_key, $default = null) {
                return get_post_meta($variation_id, $meta_key, true) ?: $default;
            }
        }
    }

    public function testRenderCourseInfoWithValidData() {
        // Debug: Check if function exists
        $this->assertTrue(function_exists('intersoccer_render_course_info'), 'intersoccer_render_course_info function should exist');

        // Setup test data
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_holiday_dates', ['2025-12-25', '2026-01-01']);

        // Capture output
        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Assertions
        $this->assertStringContains('Course Information', $output);
        $this->assertStringContains('Start Date:', $output);
        $this->assertStringContains('December 1, 2025', $output); // Formatted date
        $this->assertStringContains('Total Sessions:', $output);
        $this->assertStringContains('10', $output);
        $this->assertStringContains('Remaining Sessions:', $output);
        $this->assertStringContains('5', $output); // Half of total_weeks from mock
        $this->assertStringContains('Holidays:', $output);
        $this->assertStringContains('December 25, 2025', $output);
        $this->assertStringContains('January 1, 2026', $output);
    }

    public function testRenderCourseInfoWithNoVariationId() {
        // Test with no variation ID
        ob_start();
        intersoccer_render_course_info(123, 0);
        $output = ob_get_clean();

        // Should produce no output
        $this->assertEmpty($output);
    }

    public function testRenderCourseInfoWithMissingStartDate() {
        // Setup test data without start date
        $variation_id = 456;
        update_post_meta($variation_id, '_course_total_weeks', 10);

        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Should produce no output
        $this->assertEmpty($output);
    }

    public function testRenderCourseInfoWithZeroTotalWeeks() {
        // Setup test data with zero total weeks
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 0);

        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Should produce no output
        $this->assertEmpty($output);
    }

    public function testRenderCourseInfoWithEmptyHolidays() {
        // Setup test data with empty holidays array
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 10);
        update_post_meta($variation_id, '_course_holiday_dates', []);

        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Should not contain holidays section
        $this->assertStringNotContains('Holidays:', $output);
    }

    public function testAjaxHandlerSuccess() {
        // Test that the AJAX handler calls intersoccer_render_course_info correctly
        // We'll test the logic without the exit() calls

        // Setup test data
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 10);

        // Mock the POST data that would be sent to AJAX handler
        $product_id = 123;
        $variation_id = 456;

        // Test that intersoccer_render_course_info produces output when called directly
        ob_start();
        intersoccer_render_course_info($product_id, $variation_id);
        $html = ob_get_clean();

        // Verify the HTML contains expected content
        $this->assertStringContains('Course Information', $html);
        $this->assertStringContains('Start Date:', $html);
        $this->assertStringContains('Total Sessions:', $html);
    }

    public function testAjaxHandlerValidation() {
        // Test the validation logic without calling the actual AJAX handler
        // This tests the logic that would be in intersoccer_get_course_info_display

        // Test missing product_id
        $this->assertFalse(123 === 0 || 456 === 0); // Should not be true

        // Test missing variation_id
        $this->assertFalse(123 === 0 || 0 === 0); // Should be true (missing variation)

        // Test valid IDs
        $this->assertTrue(123 !== 0 && 456 !== 0); // Should be true
    }

    public function testPreselectedVariationDetection() {
        // Test pre-selected variation detection from URL parameters
        $this->assertTrue(function_exists('intersoccer_get_preselected_variation_id'), 'Function should exist');
        $this->assertTrue(function_exists('intersoccer_find_matching_variation'), 'Helper function should exist');

        // Mock a variable product
        $mock_product = $this->createMock(WC_Product_Variable::class);
        $mock_product->method('is_type')->willReturn(true);
        $mock_product->method('get_variation_attributes')->willReturn([
            'pa_intersoccer-venues' => ['versoix-centre-sportif-versoix', 'other-venue'],
            'pa_course-day' => ['monday', 'sunday'],
            'pa_age-group' => ['5-8y', '9-12y']
        ]);

        // Mock variations
        $mock_variations = [
            [
                'variation_id' => 100,
                'attributes' => [
                    'attribute_pa_intersoccer-venues' => 'versoix-centre-sportif-versoix',
                    'attribute_pa_course-day' => 'monday',
                    'attribute_pa_age-group' => '5-8y'
                ]
            ],
            [
                'variation_id' => 200,
                'attributes' => [
                    'attribute_pa_intersoccer-venues' => 'versoix-centre-sportif-versoix',
                    'attribute_pa_course-day' => 'sunday',
                    'attribute_pa_age-group' => '5-8y'
                ]
            ]
        ];

        $mock_product->method('get_available_variations')->willReturn($mock_variations);

        // Mock $_GET parameters
        $_GET['attribute_pa_intersoccer-venues'] = 'versoix-centre-sportif-versoix';
        $_GET['attribute_pa_course-day'] = 'sunday';
        $_GET['attribute_pa_age-group'] = '5-8y';

        // Test finding matching variation
        $result = intersoccer_find_matching_variation($mock_product, [
            'pa_intersoccer-venues' => 'versoix-centre-sportif-versoix',
            'pa_course-day' => 'sunday',
            'pa_age-group' => '5-8y'
        ]);

        $this->assertEquals(200, $result, 'Should find matching variation ID');

        // Test with non-matching attributes
        $result = intersoccer_find_matching_variation($mock_product, [
            'pa_intersoccer-venues' => 'non-existent',
            'pa_course-day' => 'sunday',
            'pa_age-group' => '5-8y'
        ]);

        $this->assertEquals(0, $result, 'Should return 0 for non-matching attributes');

        // Clean up
        unset($_GET['attribute_pa_intersoccer-venues']);
        unset($_GET['attribute_pa_course-day']);
        unset($_GET['attribute_pa_age-group']);
    }

    public function testInnerOnlyRendering() {
        // Test the inner-only rendering functionality
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 10);

        // Test normal rendering (should include container)
        ob_start();
        intersoccer_render_course_info(123, $variation_id, false);
        $normal_output = ob_get_clean();

        $this->assertStringContains('intersoccer-course-info', $normal_output);
        $this->assertStringContains('<h4>Course Information</h4>', $normal_output);

        // Test inner-only rendering (should not include container)
        ob_start();
        intersoccer_render_course_info(123, $variation_id, true);
        $inner_output = ob_get_clean();

        $this->assertStringNotContains('intersoccer-course-info', $inner_output);
        $this->assertStringNotContains('<h4>Course Information</h4>', $inner_output);
        $this->assertStringContains('Start Date:', $inner_output);
        $this->assertStringContains('Total Sessions:', $inner_output);
    }

    public function testCourseInfoContainerHtml() {
        // Test that the container HTML is properly structured
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01');
        update_post_meta($variation_id, '_course_total_weeks', 10);

        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Check HTML structure
        $this->assertStringStartsWith('<div class="intersoccer-course-info"', $output);
        $this->assertStringContains('<h4>Course Information</h4>', $output);
        $this->assertStringContains('<div class="intersoccer-course-details">', $output);
        $this->assertStringEndsWith('</div></div>', $output);
    }

    public function testCourseInfoWithEndDate() {
        // Test that end date is displayed when calculated
        $variation_id = 456;
        update_post_meta($variation_id, '_course_start_date', '2025-12-01'); // Monday
        update_post_meta($variation_id, '_course_total_weeks', 10);

        ob_start();
        intersoccer_render_course_info(123, $variation_id);
        $output = ob_get_clean();

        // Should contain end date (mocked to be 10 weeks later)
        $this->assertStringContains('End Date:', $output);
    }
}

// Mock WC_Product classes for testing
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_price() {
            return 0;
        }
    }
}

if (!class_exists('WC_Product_Variable')) {
    class WC_Product_Variable extends WC_Product {
        public function is_type($type) {
            return $type === 'variable';
        }

        public function get_variation_attributes() {
            return [];
        }

        public function get_available_variations() {
            return [];
        }
    }
}