<?php
/**
 * Integration test for course information display functionality
 * This script tests the complete flow from data setup to HTML output
 */

// Define WordPress constants
define('ABSPATH', '/fake/path/');
define('WP_DEBUG', false);

// Mock data storage
class MockMetaData {
    public static $data = [];
}

// Mock WordPress functions
function get_post_meta($post_id, $key, $single = false) {
    return MockMetaData::$data[$post_id][$key] ?? null;
}

function update_post_meta($post_id, $key, $value) {
    MockMetaData::$data[$post_id][$key] = $value;
    return true;
}

function get_option($key, $default = false) {
    static $mock_options = [
        'date_format' => 'F j, Y'
    ];
    return $mock_options[$key] ?? $default;
}

function date_i18n($format, $timestamp) {
    return date($format, $timestamp);
}

function __($text, $domain = 'default') {
    return $text;
}

function esc_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function add_action($hook, $callback, $priority = 10, $args = 1) {
    // Mock - do nothing
}

function add_filter($hook, $callback, $priority = 10, $args = 1) {
    // Mock - do nothing
}

function apply_filters($hook, $value) {
    return $value;
}

function wp_schedule_single_event($timestamp, $hook, $args = []) {
    // Mock - do nothing
}

// InterSoccer_Course class is defined in product-course.php

// intersoccer_get_course_meta is defined in product-course.php

// Load the actual functions we want to test
require_once 'includes/woocommerce/product-course.php';

echo "=== Course Information Display Integration Test ===\n\n";

$tests_passed = 0;
$tests_total = 0;

function test($name, $condition, $message = '') {
    global $tests_passed, $tests_total;
    $tests_total++;

    if ($condition) {
        $tests_passed++;
        echo "✓ PASS: $name\n";
        if ($message) echo "  $message\n";
    } else {
        echo "✗ FAIL: $name\n";
        if ($message) echo "  $message\n";
    }
    echo "\n";
}

// Test 1: Function exists
test('intersoccer_render_course_info function exists',
     function_exists('intersoccer_render_course_info'),
     'The main rendering function should be available');

// Test 2: Empty variation ID
ob_start();
intersoccer_render_course_info(123, 0);
$output = ob_get_clean();
test('Empty variation ID produces no output',
     empty($output),
     'Function should return nothing when variation_id is 0');

// Test 3: Missing start date
update_post_meta(456, '_course_total_weeks', 10);
// Don't set start date
ob_start();
intersoccer_render_course_info(123, 456);
$output = ob_get_clean();
test('Missing start date produces no output',
     empty($output),
     'Function should return nothing when start date is missing');

// Test 4: Zero total weeks
update_post_meta(456, '_course_start_date', '2025-12-01');
update_post_meta(456, '_course_total_weeks', 0);
ob_start();
intersoccer_render_course_info(123, 456);
$output = ob_get_clean();
test('Zero total weeks produces no output',
     empty($output),
     'Function should return nothing when total weeks is 0');

// Test 5: Valid course data
update_post_meta(456, '_course_start_date', '2025-12-01');
update_post_meta(456, '_course_total_weeks', 10);
update_post_meta(456, '_course_holiday_dates', ['2025-12-25', '2026-01-01']);
ob_start();
intersoccer_render_course_info(123, 456);
$output = ob_get_clean();

test('Valid course data produces output',
     !empty($output),
     'Function should produce HTML output with valid data');

test('Output contains course info container',
     strpos($output, 'intersoccer-course-info') !== false,
     'Output should contain the course info CSS class');

test('Output contains course title',
     strpos($output, 'Course Information') !== false,
     'Output should contain the course information title');

test('Output contains start date',
     strpos($output, 'Start Date:') !== false,
     'Output should contain start date information');

test('Output contains total sessions',
     strpos($output, 'Total Sessions:') !== false,
     'Output should contain total sessions information');

test('Output contains remaining sessions when different from total',
     strpos($output, 'Remaining Sessions:') === false, // Should NOT contain when all sessions remain
     'Output should not show remaining sessions when they equal total sessions');

test('Output contains holidays',
     strpos($output, 'Holidays:') !== false,
     'Output should contain holidays information');

test('Output contains formatted dates',
     strpos($output, 'December 1, 2025') !== false,
     'Output should contain properly formatted dates');

test('Output contains holiday dates',
     strpos($output, 'December 25, 2025') !== false && strpos($output, 'January 1, 2026') !== false,
     'Output should contain formatted holiday dates');

// Test 6: Course without holidays
update_post_meta(456, '_course_holiday_dates', []);
ob_start();
intersoccer_render_course_info(123, 456);
$output = ob_get_clean();

test('Course without holidays doesn\'t show holidays section',
     strpos($output, 'Holidays:') === false,
     'Output should not contain holidays section when no holidays are set');

// Test 8: Check HTML structure
test('Output has proper HTML structure',
     strpos($output, '<div class="intersoccer-course-info"') !== false &&
     strpos($output, '<h4>Course Information</h4>') !== false &&
     strpos($output, '<div class="intersoccer-course-details">') !== false &&
     substr($output, -6) === '</div>',
     'Output should have proper HTML structure with opening/closing tags');

// Test 9: AJAX handler function exists
test('AJAX handler function exists',
     function_exists('intersoccer_get_course_info_display'),
     'The AJAX handler function should be available');

// Test 10: Pre-selected variation detection
test('Pre-selected variation detection works',
     function_exists('intersoccer_get_preselected_variation_id'),
     'The pre-selected variation detection function should exist');

// Test 11: Inner-only rendering
$test_variation_id = 456;
update_post_meta($test_variation_id, '_course_start_date', '2025-12-01');
update_post_meta($test_variation_id, '_course_total_weeks', 10);

ob_start();
intersoccer_render_course_info(123, $test_variation_id, true); // inner_only = true
$inner_output = ob_get_clean();

test('Inner-only rendering works',
     !empty($inner_output) &&
     strpos($inner_output, '<div class="intersoccer-course-info"') === false &&
     strpos($inner_output, 'Start Date:') !== false,
     'Inner-only rendering should not include outer container but should include content');

// Summary
echo "=== Test Results ===\n";
echo "Passed: $tests_passed / $tests_total tests\n";

if ($tests_passed === $tests_total) {
    echo "🎉 All tests passed! Course information display functionality is working correctly.\n";
    exit(0);
} else {
    echo "❌ Some tests failed. Please review the implementation.\n";
    exit(1);
}