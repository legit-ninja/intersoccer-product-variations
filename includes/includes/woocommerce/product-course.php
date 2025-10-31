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
     * Get the course day name from variation attribute.
     *
     * @param int $variation_id Variation ID.
     * @return string Course day name (e.g., 'Wednesday') or empty.
     */
    public static function get_course_day($variation_id) {
        $attribute_slug = get_post_meta($variation_id, 'attribute_pa_course-day', true);
        if (!$attribute_slug) {
            error_log('InterSoccer: Missing attribute_pa_course-day for variation ' . $variation_id);
            return '';
        }
        error_log('InterSoccer: Attribute slug for pa_course-day: ' . $attribute_slug . ' for variation ' . $variation_id);

        $term = get_term_by('slug', $attribute_slug, 'pa_course-day');
        if ($term && !is_wp_error($term)) {
            $course_day = $term->name;
            error_log('InterSoccer: Resolved course_day from term name: ' . $course_day . ' for variation ' . $variation_id);
        } else {
            $course_day = ucfirst($attribute_slug); // Fallback capitalize slug (e.g., 'wednesday' -> 'Wednesday')
            error_log('InterSoccer: Fallback to capitalized slug for course_day: ' . $course_day . ' for variation ' . $variation_id . ' (term not found)');
        }
        return $course_day;
    }

    /**
     * Calculate the end date for a course, accounting for holidays.
     *
     * IMPORTANT: The "needed sessions" are the actual number of events the customer
     * will participate in. Holidays are ADDED TO (not subtracted from) the sessions needed
     * in order to determine the end date. This ensures the customer receives the full
     * number of paid sessions even when holidays occur.
     *
     * @param int $variation_id Variation ID.
     * @param int $total_weeks Total sessions the customer should receive.
     * @return string End date in 'Y-m-d' or empty.
     */
    public static function calculate_end_date($variation_id, $total_weeks) {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
            error_log('InterSoccer: Invalid/missing _course_start_date for variation ' . $variation_id . ': ' . ($start_date ?: 'not set'));
            return '';
        }

        $course_day = self::get_course_day($variation_id);
        if (empty($course_day)) {
            return '';
        }

        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $holiday_set = array_flip($holidays);

        // Count holidays on the course day to determine how much to extend the duration
        $holiday_count_on_course_day = 0;
        foreach ($holidays as $holiday) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                error_log('InterSoccer: Invalid holiday date format for variation ' . $variation_id . ': ' . $holiday);
                continue;
            }
            $holiday_date = new DateTime($holiday);
            if ($holiday_date->format('l') === $course_day) {
                $holiday_count_on_course_day++;
                error_log('InterSoccer: Holiday on course day ' . $course_day . ' for variation ' . $variation_id . ': ' . $holiday);
            }
        }

        try {
            $start = new DateTime($start_date);

            // Needed sessions = actual events customer participates in
            $sessions_needed = $total_weeks;

            // Total occurrences needed = sessions_needed + holidays
            // This is the key fix: holidays are ADDED to extend the duration
            $total_occurrences_needed = $sessions_needed + $holiday_count_on_course_day;

            error_log('InterSoccer: Calculating end date for variation ' . $variation_id . ': sessions_needed=' . $sessions_needed . ', holidays_on_course_day=' . $holiday_count_on_course_day . ', total_occurrences_needed=' . $total_occurrences_needed);

            $current_date = clone $start;
            $occurrences_counted = 0;
            $sessions_counted = 0;
            $days_checked = 0;
            $max_days = $total_occurrences_needed * 10; // Safe buffer
            $end_date = '';

            while ($occurrences_counted < $total_occurrences_needed && $days_checked < $max_days) {
                $day = $current_date->format('Y-m-d');

                if ($current_date->format('l') === $course_day) {
                    $occurrences_counted++;
                    $is_holiday = isset($holiday_set[$day]);

                    if (!$is_holiday) {
                        $sessions_counted++;
                        error_log('InterSoccer: Session #' . $sessions_counted . ' on ' . $day . ' (occurrence ' . $occurrences_counted . '/' . $total_occurrences_needed . ')');
                    } else {
                        error_log('InterSoccer: Holiday on ' . $day . ' (occurrence ' . $occurrences_counted . '/' . $total_occurrences_needed . ') - extends duration');
                    }

                    // End date is set when we've counted all needed occurrences (sessions + holidays)
                    if ($occurrences_counted === $total_occurrences_needed) {
                        $end_date = $day;
                        error_log('InterSoccer: Final end_date for variation ' . $variation_id . ': ' . $end_date . ' (sessions: ' . $sessions_counted . ', holidays: ' . $holiday_count_on_course_day . ', total occurrences: ' . $total_occurrences_needed . ')');
                        break;
                    }
                }

                $current_date->add(new DateInterval('P1D'));
                $days_checked++;
            }

            if ($end_date && $sessions_counted == $sessions_needed) {
                update_post_meta($variation_id, '_end_date', $end_date);
                return $end_date;
            } else {
                error_log('InterSoccer: Failed to calculate end_date for variation ' . $variation_id . ' - sessions_counted: ' . $sessions_counted . ', sessions_needed: ' . $sessions_needed . ', days_checked: ' . $days_checked);
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

        $course_day = self::get_course_day($variation_id);
        if (empty($course_day)) {
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

        $course_day = self::get_course_day($variation_id);
        if (empty($course_day)) {
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
        $start_log = $date->format('Y-m-d');
        error_log('InterSoccer: Calculating remaining sessions from ' . $start_log . ' (current: ' . $current->format('Y-m-d') . ', start: ' . $start_date . ') to ' . $end_date . ' for variation ' . $variation_id);

        while ($date <= $end) {
            $day = $date->format('Y-m-d');
            if ($date->format('l') === $course_day) {
                if (isset($holiday_set[$day])) {
                    $skipped_holidays++;
                    error_log('InterSoccer: Skipped holiday on course day: ' . $day . ' for variation ' . $variation_id);
                } else {
                    $remaining++;
                    error_log('InterSoccer: Counted remaining session on ' . $day . ' for variation ' . $variation_id);
                }
            }
            $date->add(new DateInterval('P1D'));
        }

        error_log('InterSoccer: Remaining sessions for variation ' . $variation_id . ': ' . $remaining . ' (skipped holidays: ' . $skipped_holidays . ', reduction applied if current > start)');
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
            error_log('InterSoccer: Invalid product for course price calculation: ' . ($variation_id ?: $product_id));
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
    $flag = 'intersoccer_end_date_recalc_20250825'; // Updated flag to force recalc
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