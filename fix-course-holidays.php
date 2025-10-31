<?php
/**
 * One-time fix for existing courses that were using the old holiday logic.
 *
 * This script identifies courses that were created with inflated total_weeks
 * to work around the old buggy holiday calculation logic, and corrects them
 * by subtracting the number of holidays from the total sessions.
 *
 * Run this script once after deploying the fixed course calculation logic.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fix existing courses that were using the old holiday logic
 */
function intersoccer_fix_course_holidays() {
    global $wpdb;

    echo "<h2>InterSoccer Course Holiday Fix</h2>";
    echo "<p>This script will identify and fix existing courses that were using the old holiday calculation logic.</p>";

    // Get all product variations that have holiday dates
    $query = $wpdb->prepare("
        SELECT pm.post_id as variation_id, pm.meta_value as holidays
        FROM {$wpdb->postmeta} pm
        WHERE pm.meta_key = '_course_holiday_dates'
        AND pm.meta_value != ''
        AND pm.meta_value != 'a:0:{}'
    ");

    $results = $wpdb->get_results($query);

    if (empty($results)) {
        echo "<p>No courses with holidays found.</p>";
        return;
    }

    echo "<p>Found " . count($results) . " course variations with holidays.</p>";

    $fixed_count = 0;
    $skipped_count = 0;

    foreach ($results as $result) {
        $variation_id = $result->variation_id;
        $holidays = maybe_unserialize($result->holidays);

        if (!is_array($holidays)) {
            echo "<p>Warning: Invalid holiday data for variation {$variation_id}</p>";
            continue;
        }

        // Get current total_weeks
        $current_total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        if (!$current_total_weeks) {
            echo "<p>Warning: No total_weeks found for variation {$variation_id}</p>";
            continue;
        }

        // Get course day to count holidays on course days
        $course_day = InterSoccer_Course::get_course_day($variation_id);
        if (!$course_day) {
            echo "<p>Warning: Could not determine course day for variation {$variation_id}</p>";
            continue;
        }

        // Count holidays that fall on the course day
        $holidays_on_course_day = 0;
        foreach ($holidays as $holiday) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                continue; // Skip invalid dates
            }

            try {
                $holiday_date = new DateTime($holiday);
                if ($holiday_date->format('N') == $course_day) {
                    $holidays_on_course_day++;
                }
            } catch (Exception $e) {
                continue; // Skip invalid dates
            }
        }

        if ($holidays_on_course_day === 0) {
            $skipped_count++;
            echo "<p>Skipped variation {$variation_id}: No holidays on course day</p>";
            continue;
        }

        // Check if this course might be using old logic
        // We'll be conservative and only fix courses where total_weeks > holidays_on_course_day
        // and the corrected total would still be reasonable (> 0)
        $corrected_total_weeks = $current_total_weeks - $holidays_on_course_day;

        if ($corrected_total_weeks <= 0) {
            $skipped_count++;
            echo "<p>Skipped variation {$variation_id}: Corrected total would be {$corrected_total_weeks} (current: {$current_total_weeks}, holidays: {$holidays_on_course_day})</p>";
            continue;
        }

        // Additional check: only fix if the current total seems inflated
        // We'll assume old logic if total_weeks >= holidays_on_course_day + some reasonable minimum
        $minimum_expected_sessions = 1; // At least 1 session
        if ($current_total_weeks < $holidays_on_course_day + $minimum_expected_sessions) {
            $skipped_count++;
            echo "<p>Skipped variation {$variation_id}: Total weeks doesn't appear inflated (current: {$current_total_weeks}, holidays: {$holidays_on_course_day})</p>";
            continue;
        }

        // Fix the course
        update_post_meta($variation_id, '_course_total_weeks', $corrected_total_weeks);

        // Recalculate and update the end date
        $end_date = InterSoccer_Course::calculate_end_date($variation_id, $corrected_total_weeks);
        if ($end_date) {
            update_post_meta($variation_id, '_end_date', $end_date);
        }

        $fixed_count++;
        echo "<p>Fixed variation {$variation_id}: {$current_total_weeks} → {$corrected_total_weeks} weeks (subtracted {$holidays_on_course_day} holidays)</p>";
    }

    echo "<hr>";
    echo "<p><strong>Summary:</strong></p>";
    echo "<ul>";
    echo "<li>Fixed: {$fixed_count} courses</li>";
    echo "<li>Skipped: {$skipped_count} courses</li>";
    echo "</ul>";

    if ($fixed_count > 0) {
        echo "<p style='color: green;'><strong>✅ Course holiday fix completed successfully!</strong></p>";
        echo "<p><em>Note: This script should only be run once. Running it again may cause incorrect calculations.</em></p>";
    } else {
        echo "<p style='color: blue;'>ℹ️ No courses needed fixing, or they were already corrected.</p>";
    }
}

/**
 * Check if the course holiday fix has already been run
 */
function intersoccer_course_holiday_fix_has_run() {
    return get_option('intersoccer_course_holiday_fix_completed', false);
}

/**
 * Mark the course holiday fix as completed
 */
function intersoccer_course_holiday_fix_mark_completed() {
    update_option('intersoccer_course_holiday_fix_completed', true);
}

/**
 * AJAX handler for running the course holiday fix
 */
function intersoccer_run_course_holiday_fix_ajax() {
    check_ajax_referer('intersoccer_course_holiday_fix_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    if (intersoccer_course_holiday_fix_has_run()) {
        wp_send_json_error(['message' => 'Course holiday fix has already been completed']);
        return;
    }

    // Run the fix
    ob_start();
    intersoccer_fix_course_holidays();
    $output = ob_get_clean();

    // Mark as completed
    intersoccer_course_holiday_fix_mark_completed();

    wp_send_json_success([
        'message' => 'Course holiday fix completed successfully',
        'output' => $output
    ]);
}
add_action('wp_ajax_intersoccer_run_course_holiday_fix', 'intersoccer_run_course_holiday_fix_ajax');