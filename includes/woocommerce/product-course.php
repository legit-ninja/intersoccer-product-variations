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
 * Get course metadata with WPML fallback to original language
 *
 * @param int $variation_id Variation ID
 * @param string $meta_key Metadata key
 * @param mixed $default Default value
 * @return mixed Metadata value
 */
function intersoccer_get_course_meta($variation_id, $meta_key, $default = null) {
    // First try to get from current variation
    $value = get_post_meta($variation_id, $meta_key, true);

    // If value exists and is not empty, return it
    if ($value !== '' && $value !== null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Found ' . $meta_key . ' directly on variation ' . $variation_id . ': ' . (is_array($value) ? json_encode($value) : $value));
        }
        return $value;
    }

    // If WPML is active, try to get from original language variation
    if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_current_language')) {
        // Get the default language
        $default_lang = apply_filters('wpml_default_language', null) ?: 'en';

        $original_variation_id = apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);

        if ($original_variation_id && $original_variation_id !== $variation_id) {
            $original_value = get_post_meta($original_variation_id, $meta_key, true);
            if ($original_value !== '' && $original_value !== null) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Using metadata from original variation ' . $original_variation_id . ' for ' . $meta_key . ' on translated variation ' . $variation_id . ': ' . (is_array($original_value) ? json_encode($original_value) : $original_value));
                }
                return $original_value;
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Original variation ' . $original_variation_id . ' also has empty ' . $meta_key);
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Could not find original variation for ' . $variation_id . ' (original_id: ' . ($original_variation_id ?: 'null') . ')');
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: WPML not detected for metadata fallback');
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Returning default for ' . $meta_key . ' on variation ' . $variation_id . ': ' . (is_array($default) ? json_encode($default) : $default));
    }
    return $default;
}

/**
 * Class to handle course-specific calculations.
 */
class InterSoccer_Course {

    /**
     * Get the course day number from variation attribute (1=Monday, 7=Sunday).
     *
     * @param int $variation_id Variation ID.
     * @return int Course day number or 0 if invalid.
     */
    public static function get_course_day($variation_id) {
        $attribute_slug = get_post_meta($variation_id, 'attribute_pa_course-day', true);
        if (!$attribute_slug) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Missing attribute_pa_course-day for variation ' . $variation_id);
            }
            return 0;
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Attribute slug for pa_course-day: ' . $attribute_slug . ' for variation ' . $variation_id);
        }

        $day_map = [
            // English
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
            // French
            'lundi' => 1,
            'mardi' => 2,
            'mercredi' => 3,
            'jeudi' => 4,
            'vendredi' => 5,
            'samedi' => 6,
            'dimanche' => 7,
            // German
            'montag' => 1,
            'dienstag' => 2,
            'mittwoch' => 3,
            'donnerstag' => 4,
            'freitag' => 5,
            'samstag' => 6,
            'sonntag' => 7
        ];

        $course_day_num = $day_map[$attribute_slug] ?? 0;
        if ($course_day_num === 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Invalid course day slug: ' . $attribute_slug . ' for variation ' . $variation_id);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Resolved course_day number: ' . $course_day_num . ' for variation ' . $variation_id);
            }
        }
        return $course_day_num;
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
        $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
        if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Invalid/missing _course_start_date for variation ' . $variation_id . ': ' . ($start_date ?: 'not set'));
            }
            return '';
        }

        $course_day = self::get_course_day($variation_id);
        if (empty($course_day)) {
            return '';
        }

        $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
        $holiday_set = array_flip($holidays);

        // Count holidays on the course day to determine how much to extend the duration
        $holiday_count_on_course_day = 0;
        foreach ($holidays as $holiday) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Invalid holiday date format for variation ' . $variation_id . ': ' . $holiday);
                }
                continue;
            }
            $holiday_date = new DateTime($holiday);
            if ($holiday_date->format('N') == $course_day) {
                $holiday_count_on_course_day++;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Holiday on course day ' . $course_day . ' for variation ' . $variation_id . ': ' . $holiday);
                }
            }
        }

        try {
            $start = new DateTime($start_date);

            // Needed sessions = actual events customer participates in
            $sessions_needed = $total_weeks;

            // Total occurrences needed = sessions_needed + holidays
            // This is the key fix: holidays are ADDED to extend the duration
            $total_occurrences_needed = $sessions_needed + $holiday_count_on_course_day;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Calculating end date for variation ' . $variation_id . ': sessions_needed=' . $sessions_needed . ', holidays_on_course_day=' . $holiday_count_on_course_day . ', total_occurrences_needed=' . $total_occurrences_needed);
            }

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
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('InterSoccer: Session #' . $sessions_counted . ' on ' . $day . ' (occurrence ' . $occurrences_counted . '/' . $total_occurrences_needed . ')');
                            }
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('InterSoccer: Holiday on ' . $day . ' (occurrence ' . $occurrences_counted . '/' . $total_occurrences_needed . ') - extends duration');
                            }
                        }
                    }

                    // End date is set when we've counted all needed occurrences (sessions + holidays)
                    if ($occurrences_counted === $total_occurrences_needed) {
                        $end_date = $day;
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('InterSoccer: Final end_date for variation ' . $variation_id . ': ' . $end_date . ' (sessions: ' . $sessions_counted . ', holidays: ' . $holiday_count_on_course_day . ', total occurrences: ' . $total_occurrences_needed . ')');
                            }
                        }
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
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Failed to calculate end_date for variation ' . $variation_id . ' - sessions_counted: ' . $sessions_counted . ', sessions_needed: ' . $sessions_needed . ', days_checked: ' . $days_checked);
                }
                return '';
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: DateTime exception in calculate_end_date for variation ' . $variation_id . ': ' . $e->getMessage());
            }
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
        $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
        if (!$start_date) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Missing start_date in calculate_total_sessions for variation ' . $variation_id);
            }
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

        $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
        $holiday_set = array_flip($holidays);

        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $total = 0;
        $date = clone $start;
        while ($date <= $end) {
            if ($date->format('N') == $course_day && !isset($holiday_set[$date->format('Y-m-d')])) {
                $total++;
            }
            $date->add(new DateInterval('P1D'));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Total sessions for variation ' . $variation_id . ': ' . $total . ' (should match total_weeks: ' . $total_weeks . ')');
        }
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
        $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
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

        $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
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
            if ($date->format('N') == $course_day) {
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
     * Calculate course price (based on session rate multiplied by remaining sessions).
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

        $base_price = floatval($product->get_price());
        $total_weeks = (int) intersoccer_get_course_meta($variation_id ?: $product_id, '_course_total_weeks', 0);
        $session_rate = floatval(intersoccer_get_course_meta($variation_id ?: $product_id, '_course_weekly_discount', 0));

        error_log('InterSoccer: Course price calculation for variation ' . ($variation_id ?: $product_id) . ' - base_price: ' . $base_price . ', total_weeks: ' . $total_weeks . ', session_rate: ' . $session_rate);
        
        if (is_null($remaining_sessions)) {
            $remaining_sessions = self::calculate_remaining_sessions($variation_id, $total_weeks);
        }

        $price = $base_price; // Default to full price

        // Use session rate calculation if available
        if ($session_rate > 0 && $remaining_sessions > 0) {
            $price = $session_rate * $remaining_sessions;
            $price = round($price, 2); // Round to 2 decimal places
            error_log('InterSoccer: Course price calculated using session rate for variation ' . $variation_id . ': ' . $price . ' (rate: ' . $session_rate . ', remaining sessions: ' . $remaining_sessions . ')');
        } else {
            // Fallback to old prorated calculation for backwards compatibility
            $total_sessions = self::calculate_total_sessions($variation_id, $total_weeks);
            if ($total_sessions > 0 && $remaining_sessions > 0 && $remaining_sessions <= $total_sessions) {
                if ($remaining_sessions < $total_sessions) {
                    // Prorate the price based on remaining sessions
                    $price = $base_price * ($remaining_sessions / $total_sessions);
                    $price = round($price, 2); // Round to 2 decimal places
                    error_log('InterSoccer: Course prorated price (fallback) for variation ' . $variation_id . ': ' . $price . ' (base: ' . $base_price . ', remaining: ' . $remaining_sessions . ', total: ' . $total_sessions . ')');
                } else {
                    error_log('InterSoccer: Course full price (fallback) for variation ' . $variation_id . ': ' . $price . ' (all sessions remaining: ' . $remaining_sessions . ')');
                }
            } else {
                error_log('InterSoccer: Invalid sessions for price calculation - remaining: ' . $remaining_sessions . ', total: ' . $total_sessions . ', base_price: ' . $base_price . ', session_rate: ' . $session_rate);
            }
        }

        return max(0, $price);
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
            $discount_note = sprintf(__('%d Weeks Remaining', 'intersoccer-product-variations'), $remaining_sessions);
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
                $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
                InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
            }
        }
    }
    error_log('InterSoccer: Completed one-time recalc of course end dates');
}
?>