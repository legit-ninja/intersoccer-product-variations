<?php
/**
 * File: product-course.php
 * Description: Course-specific logic for InterSoccer WooCommerce products, including date/session calculations.
 * Dependencies: WooCommerce, product-types.php (for type detection)
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle course-specific calculations.
 */
class InterSoccer_Course {

    /**
     * Calculate the end date for a course, accounting for holidays.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total sessions.
     * @return string End date in 'Y-m-d' or empty.
     */
    public static function calculate_end_date($variation_id, $total_weeks) {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
            error_log('InterSoccer: Invalid/missing _course_start_date for variation ' . $variation_id . ': ' . ($start_date ?: 'not set'));
            return '';
        }

        $parent_id = wp_get_post_parent_id($variation_id) ?: $variation_id;
        $course_day = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names'])[0] ?? '';
        if (empty($course_day)) {
            error_log('InterSoccer: Missing pa_course-day for variation ' . $variation_id);
            return '';
        }
        error_log('InterSoccer: Using pa_course-day for variation ' . $variation_id . ': ' . $course_day);

        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $holiday_set = array_flip($holidays);
        $holiday_count_on_course_day = 0;
        foreach ($holidays as $holiday) {
            $holiday_date = new DateTime($holiday);
            if ($holiday_date->format('l') === $course_day) {
                $holiday_count_on_course_day++;
                error_log('InterSoccer: Holiday on course day ' . $course_day . ': ' . $holiday);
            }
        }

        try {
            $start = new DateTime($start_date);
            $sessions_needed = $total_weeks + $holiday_count_on_course_day;
            $current_date = clone $start;
            $sessions_counted = 0;
            $days_checked = 0;
            $max_days = ($total_weeks + $holiday_count_on_course_day) * 7 + 7;

            while ($sessions_counted < $sessions_needed && $days_checked < $max_days) {
                $day = $current_date->format('Y-m-d');
                error_log('InterSoccer: Checking date ' . $day . ' for course day ' . $course_day . ', holiday: ' . (isset($holiday_set[$day]) ? 'yes' : 'no'));
                if ($current_date->format('l') === $course_day && !isset($holiday_set[$day])) {
                    $sessions_counted++;
                    error_log('InterSoccer: Counted session ' . $sessions_counted . ' on ' . $day);
                }
                $current_date->add(new DateInterval('P1D'));
                $days_checked++;
            }

            if ($sessions_counted == $sessions_needed) {
                $end_date_obj = clone $current_date;
                $end_date_obj->sub(new DateInterval('P1D'));
                while ($end_date_obj->format('l') !== $course_day) {
                    $end_date_obj->sub(new DateInterval('P1D'));
                }
                $end_date = $end_date_obj->format('Y-m-d');
                error_log('InterSoccer: Calculated end_date for variation ' . $variation_id . ': ' . $end_date . ', sessions: ' . $sessions_counted . ', holidays: ' . $holiday_count_on_course_day);
                update_post_meta($variation_id, '_end_date', $end_date);
                return $end_date;
            } else {
                error_log('InterSoccer: Failed to calculate end_date for variation ' . $variation_id . ' - insufficient sessions: ' . $sessions_counted . '/' . $sessions_needed);
                return '';
            }
        } catch (Exception $e) {
            error_log('InterSoccer: DateTime exception in calculate_end_date for variation ' . $variation_id . ': ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Calculate total sessions for a course.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total weeks.
     * @return int Total sessions.
     */
    public static function calculate_total_sessions($variation_id, $total_weeks) {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        if (!$start_date) {
            error_log('InterSoccer: Missing start_date in calculate_total_sessions for variation ' . $variation_id);
            return 0;
        }

        $end_date = self::calculate_end_date($variation_id, $total_weeks);
        if (!$end_date) {
            return 0;
        }

        $parent_id = wp_get_post_parent_id($variation_id) ?: $variation_id;
        $course_day = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names'])[0] ?? '';
        if (empty($course_day)) {
            error_log('InterSoccer: Missing pa_course-day in calculate_total_sessions for variation ' . $variation_id);
            return 0;
        }

        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $holiday_set = array_flip($holidays);

        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $total = 0;
        $date = clone $start;
        while ($date <= $end) {
            if ($date->format('l') === $course_day && !isset($holiday_set[$date->format('Y-m-d')])) {
                $total++;
            }
            $date->add(new DateInterval('P1D'));
        }

        error_log('InterSoccer: Total sessions for variation ' . $variation_id . ': ' . $total . ' (should match total_weeks: ' . $total_weeks . ')');
        return $total;
    }

    /**
     * Calculate remaining sessions from current date, skipping holidays.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total weeks.
     * @return int Remaining sessions.
     */
    public static function calculate_remaining_sessions($variation_id, $total_weeks) {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        if (!$start_date) {
            error_log('InterSoccer: Missing start_date in calculate_remaining_sessions for variation ' . $variation_id);
            return 0;
        }

        $end_date = self::calculate_end_date($variation_id, $total_weeks);
        if (!$end_date) {
            return 0;
        }

        $parent_id = wp_get_post_parent_id($variation_id) ?: $variation_id;
        $course_day = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names'])[0] ?? '';
        if (empty($course_day)) {
            error_log('InterSoccer: Missing pa_course-day in calculate_remaining_sessions for variation ' . $variation_id);
            return 0;
        }

        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $holiday_set = array_flip($holidays);

        $start = new DateTime($start_date);
        $current = new DateTime(current_time('Y-m-d'));
        $end = new DateTime($end_date);
        $remaining = 0;
        $date = max($current, $start);
        $skipped_holidays = 0;
        while ($date <= $end) {
            $day = $date->format('Y-m-d');
            if ($date->format('l') === $course_day) {
                if (isset($holiday_set[$day])) {
                    $skipped_holidays++;
                    error_log('InterSoccer: Skipped holiday on course day: ' . $day);
                } else {
                    $remaining++;
                }
            }
            $date->add(new DateInterval('P1D'));
        }

        error_log('InterSoccer: Remaining sessions for variation ' . $variation_id . ': ' . $remaining . ' (skipped holidays: ' . $skipped_holidays . ', from ' . $current->format('Y-m-d') . ' to ' . $end_date . ')');
        return $remaining;
    }

    /**
     * Calculate course price (prorated based on remaining sessions).
     *
     * @param int $product_id Product ID.
     * @param int $variation_id Variation ID.
     * @param int $remaining_sessions Remaining sessions (calculated if null).
     * @return float Calculated price.
     */
    public static function calculate_price($product_id, $variation_id, $remaining_sessions = null) {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            error_log('InterSoccer: Invalid product for course price calculation: ' . $variation_id ?: $product_id);
            return 0;
        }

        $price = floatval($product->get_price());
        $total_weeks = (int) get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
        $session_rate = (float) get_post_meta($variation_id ?: $product_id, '_course_weekly_discount', true);
        $total_sessions = self::calculate_total_sessions($variation_id, $total_weeks);
        if (is_null($remaining_sessions)) {
            $remaining_sessions = self::calculate_remaining_sessions($variation_id, $total_weeks);
        }

        if ($total_sessions > 0 && $remaining_sessions > 0 && $remaining_sessions <= $total_sessions) {
            if ($remaining_sessions < $total_sessions && $session_rate > 0) {
                $price = $session_rate * $remaining_sessions;
            }
            $price = max(0, $price);
            error_log('InterSoccer: Course price for variation ' . $variation_id . ': ' . $price . ' (remaining: ' . $remaining_sessions . ', total: ' . $total_sessions . ')');
        } else {
            error_log('InterSoccer: Invalid sessions for price - remaining: ' . $remaining_sessions . ', total: ' . $total_sessions);
        }

        return $price;
    }

    /**
     * Calculate discount note for course.
     *
     * @param int $variation_id Variation ID.
     * @param int $remaining_sessions Remaining sessions.
     * @return string Discount note.
     */
    public static function calculate_discount_note($variation_id, $remaining_sessions) {
        $discount_note = '';
        // Logic for combo discounts, to be expanded when discounts.php is implemented
        // For now, placeholder based on remaining sessions
        if ($remaining_sessions > 0) {
            $discount_note = ($remaining_sessions . ' Weeks Remaining');
        }
        error_log('InterSoccer: Calculated discount_note for variation ' . $variation_id . ': ' . $discount_note);
        return $discount_note;
    }
}

// Procedural wrappers for backward compatibility
function calculate_end_date($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
}

function calculate_total_sessions($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_total_sessions($variation_id, $total_weeks);
}

function calculate_remaining_sessions($variation_id, $total_weeks) {
    return InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
}

error_log('InterSoccer: Defined course functions in product-course.php');

/**
 * One-time recalc of course end dates.
 */
add_action('init', 'intersoccer_recalculate_course_end_dates');
function intersoccer_recalculate_course_end_dates() {
    $flag = 'intersoccer_end_date_recalc_20250805';
    if (get_option($flag, false)) {
        return;
    }

    wp_schedule_single_event(time(), 'intersoccer_run_course_end_date_update');
    update_option($flag, true);
    error_log('InterSoccer: Scheduled one-time course end date recalc');
}

/**
 * Action to run course end date update.
 */
add_action('intersoccer_run_course_end_date_update', 'intersoccer_run_course_end_date_update_callback');
function intersoccer_run_course_end_date_update_callback() {
    $products = wc_get_products(['type' => 'variable', 'limit' => -1]);
    foreach ($products as $product) {
        if (InterSoccer_Product_Types::get_product_type($product->get_id()) === 'course') {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
                InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
            }
        }
    }
    error_log('InterSoccer: Completed one-time recalc of course end dates');
}
?>