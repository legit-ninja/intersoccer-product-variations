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
        $course_day = (int) $course_day; // Ensure it's an integer

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
                $current_day_num = $current_date->format('N');

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Checking day ' . $day . ' (day num: ' . $current_day_num . ') against course_day ' . $course_day . ' for variation ' . $variation_id);
                }

                if ((int) $current_day_num === (int) $course_day) {
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
        error_log('InterSoccer: calculate_remaining_sessions called for variation ' . $variation_id . ' with total_weeks: ' . $total_weeks);

        $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
        if (!$start_date) {
            error_log('InterSoccer: Missing start_date in calculate_remaining_sessions for variation ' . $variation_id . ' - returning all sessions: ' . $total_weeks);
            return $total_weeks; // If no start date, assume all sessions remaining
        }

        error_log('InterSoccer: Course start_date: ' . $start_date);

        $course_day = self::get_course_day($variation_id);
        if (empty($course_day)) {
            error_log('InterSoccer: No course_day found for variation ' . $variation_id . ' - returning all sessions: ' . $total_weeks);
            return $total_weeks;
        }

        error_log('InterSoccer: Course day number: ' . $course_day);

        $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
        $holiday_set = array_flip($holidays);

        error_log('InterSoccer: Course holidays: ' . json_encode($holidays));

        $current = new DateTime(current_time('Y-m-d'));
        $start = new DateTime($start_date);

        error_log('InterSoccer: Current date: ' . $current->format('Y-m-d') . ', Start date: ' . $start->format('Y-m-d'));

        // If course hasn't started yet, all sessions remain
        if ($current < $start) {
            error_log('InterSoccer: Course hasn\'t started yet for variation ' . $variation_id . ' - all ' . $total_weeks . ' sessions remaining');
            return $total_weeks;
        }

        // Calculate end date to know when course finishes
        $end_date = self::calculate_end_date($variation_id, $total_weeks);
        if (!$end_date) {
            error_log('InterSoccer: Could not calculate end date for variation ' . $variation_id . ' - assuming all sessions remaining');
            return $total_weeks;
        }

        $end = new DateTime($end_date);
        error_log('InterSoccer: Calculated end date: ' . $end->format('Y-m-d'));

        // If course has ended, no sessions remain
        if ($current > $end) {
            error_log('InterSoccer: Course has ended for variation ' . $variation_id . ' - 0 sessions remaining');
            return 0;
        }

        // Count remaining sessions from current date to end date
        $remaining = 0;
        $date = clone $current;

        error_log('InterSoccer: Counting remaining sessions from ' . $current->format('Y-m-d') . ' to ' . $end->format('Y-m-d'));

        while ($date <= $end) {
            $day = $date->format('Y-m-d');
            $day_of_week = $date->format('N');

            if ($day_of_week == $course_day) {
                if (isset($holiday_set[$day])) {
                    error_log('InterSoccer: Skipping holiday on course day: ' . $day);
                } else {
                    $remaining++;
                    error_log('InterSoccer: Counted remaining session #' . $remaining . ' on ' . $day . ' (day ' . $day_of_week . ')');
                }
            }
            $date->add(new DateInterval('P1D'));
        }

        $remaining = min($remaining, $total_weeks); // Don't exceed total weeks
        error_log('InterSoccer: Final remaining sessions for variation ' . $variation_id . ': ' . $remaining . ' (out of ' . $total_weeks . ' total)');
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
            error_log('InterSoccer: Calculated remaining_sessions: ' . $remaining_sessions);
        } else {
            error_log('InterSoccer: Using provided remaining_sessions: ' . $remaining_sessions);
        }

        $price = $base_price; // Default to full price

        // Use session rate calculation if available
        if ($session_rate > 0 && $remaining_sessions > 0) {
            $price = $session_rate * $remaining_sessions;
            $price = round($price, 2); // Round to 2 decimal places
            error_log('InterSoccer: Course price calculated using session rate for variation ' . $variation_id . ': ' . $price . ' (rate: ' . $session_rate . ' × remaining sessions: ' . $remaining_sessions . ')');
        } else {
            // Fallback to old prorated calculation for backwards compatibility
            $total_sessions = self::calculate_total_sessions($variation_id, $total_weeks);
            error_log('InterSoccer: Fallback calculation - total_sessions: ' . $total_sessions . ', remaining_sessions: ' . $remaining_sessions);

            if ($total_sessions > 0 && $remaining_sessions > 0 && $remaining_sessions <= $total_sessions) {
                if ($remaining_sessions < $total_sessions) {
                    // Prorate the price based on remaining sessions
                    $price = $base_price * ($remaining_sessions / $total_sessions);
                    $price = round($price, 2); // Round to 2 decimal places
                    error_log('InterSoccer: Course prorated price (fallback) for variation ' . $variation_id . ': ' . $price . ' (base: ' . $base_price . ' × ' . $remaining_sessions . '/' . $total_sessions . ')');
                } else {
                    error_log('InterSoccer: Course full price (fallback) for variation ' . $variation_id . ': ' . $price . ' (all sessions remaining: ' . $remaining_sessions . ')');
                }
            } else {
                error_log('InterSoccer: Invalid sessions for price calculation - remaining: ' . $remaining_sessions . ', total: ' . $total_sessions . ', base_price: ' . $base_price . ', session_rate: ' . $session_rate);
            }
        }

        error_log('InterSoccer: Final calculated price for variation ' . $variation_id . ': ' . $price);
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

/**
 * Get pre-selected variation ID from URL parameters or session
 *
 * @param WC_Product $product Variable product
 * @return int Variation ID or 0 if none found
 */
function intersoccer_get_preselected_variation_id($product) {
    if (!$product || !$product->is_type('variable')) {
        return 0;
    }

    // Check URL parameters for variation attributes
    $attributes = $product->get_variation_attributes();
    $selected_attributes = [];

    foreach ($attributes as $attribute_name => $options) {
        $param_name = 'attribute_' . sanitize_title($attribute_name);
        if (isset($_GET[$param_name]) && !empty($_GET[$param_name])) {
            $selected_attributes[$attribute_name] = sanitize_text_field($_GET[$param_name]);
        }
    }

    // If we have selected attributes, find the matching variation
    if (!empty($selected_attributes)) {
        $variation_id = intersoccer_find_matching_variation($product, $selected_attributes);
        if ($variation_id) {
            return $variation_id;
        }
    }

    // Check session for pre-selected variation (could be set from cart links)
    $session_variation = WC()->session->get('intersoccer_preselected_variation');
    if ($session_variation && isset($session_variation[$product->get_id()])) {
        return absint($session_variation[$product->get_id()]);
    }

    return 0;
}

/**
 * Find variation that matches selected attributes
 *
 * @param WC_Product $product Variable product
 * @param array $selected_attributes Selected attribute values
 * @return int Variation ID or 0 if not found
 */
function intersoccer_find_matching_variation($product, $selected_attributes) {
    $variations = $product->get_available_variations();

    foreach ($variations as $variation) {
        $variation_attributes = $variation['attributes'];
        $matches = true;

        foreach ($selected_attributes as $attribute_name => $selected_value) {
            $variation_attr_key = 'attribute_' . sanitize_title($attribute_name);
            $variation_value = isset($variation_attributes[$variation_attr_key]) ? $variation_attributes[$variation_attr_key] : '';

            if ($variation_value !== $selected_value) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            return $variation['variation_id'];
        }
    }

    return 0;
}



/**
 * Automatically display course information on course product pages (fallback for non-variable products)
 */
add_filter('the_content', 'intersoccer_add_course_info_to_content', 20);
function intersoccer_add_course_info_to_content($content) {
    // Only modify single product pages
    if (!is_singular('product')) {
        return $content;
    }

    global $product;
    if (!$product || !is_a($product, 'WC_Product')) {
        return $content;
    }

    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type !== 'course') {
        return $content;
    }

    // For variable products, course info is handled by woocommerce_after_variations_table hook
    if ($product->is_type('variable')) {
        return $content;
    }

    // Get variation ID if this is a variation product
    $variation_id = 0;
    if ($product->is_type('variation')) {
        $variation_id = $product_id;
        $product_id = $product->get_parent_id();
    }

    // For variation and simple products, show the info directly
    ob_start();
    intersoccer_render_course_info($product_id, $variation_id, false, true); // plain = true
    $course_info_html = ob_get_clean();

    // Append to content
    return $content . $course_info_html;
}

/**
 * Shortcode to display course information (for manual use if needed)
 */
add_shortcode('intersoccer_course_info', 'intersoccer_course_info_shortcode');
function intersoccer_course_info_shortcode($atts) {
    global $product;

    if (!$product || !is_a($product, 'WC_Product')) {
        return '';
    }

    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type !== 'course') {
        return '';
    }

    // Get variation ID if this is a variable product
    $variation_id = 0;
    if ($product->is_type('variable')) {
        // Always show the course info container - JavaScript will populate it
        ob_start();
        echo '<div id="intersoccer-course-info" class="intersoccer-course-info" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
        echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
        echo '<div id="intersoccer-course-details"></div>';
        echo '</div>';
        return ob_get_clean();
    } elseif ($product->is_type('variation')) {
        $variation_id = $product_id;
        $product_id = $product->get_parent_id();
    }

    // Display course info for simple products or when variation is selected
    ob_start();
    intersoccer_render_course_info($product_id, $variation_id);
    return ob_get_clean();
}

/**
 * Render course information HTML
 *
 * @param int $product_id Product ID
 * @param int $variation_id Variation ID
 * @param bool $inner_only Return inner HTML only (for AJAX/pre-selected variations)
 * @param bool $plain Remove styling for plain text display
 */
function intersoccer_render_course_info($product_id, $variation_id, $inner_only = false, $plain = false) {
    if (!$variation_id) {
        return;
    }

    $start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
    $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
    $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);

    if (empty($start_date) || $total_weeks <= 0) {
        return;
    }

    // Calculate end date
    $end_date = '';
    if (class_exists('InterSoccer_Course')) {
        $end_date = InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
    }

    // Calculate remaining sessions
    $remaining_sessions = 0;
    if (class_exists('InterSoccer_Course')) {
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    }

    if (!$inner_only) {
        if ($plain) {
            echo '<div class="intersoccer-course-info">';
            echo '<strong>' . __('Course Information:', 'intersoccer-product-variations') . '</strong><br>';
        } else {
            echo '<div class="intersoccer-course-info" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
            echo '<h4>' . __('Course Information', 'intersoccer-product-variations') . '</h4>';
        }
        echo '<div class="intersoccer-course-details">';
    }

    // Start Date
    if ($start_date) {
        $formatted_start = date_i18n(get_option('date_format'), strtotime($start_date));
        echo '<p><strong>' . __('Start Date:', 'intersoccer-product-variations') . '</strong> ' . esc_html($formatted_start) . '</p>';
    }

    // End Date
    if ($end_date) {
        $formatted_end = date_i18n(get_option('date_format'), strtotime($end_date));
        echo '<p><strong>' . __('End Date:', 'intersoccer-product-variations') . '</strong> ' . esc_html($formatted_end) . '</p>';
    }

    // Total Weeks
    echo '<p><strong>' . __('Total Sessions:', 'intersoccer-product-variations') . '</strong> ' . esc_html($total_weeks) . '</p>';

    // Remaining Sessions
    if ($remaining_sessions !== $total_weeks) {
        echo '<p><strong>' . __('Remaining Sessions:', 'intersoccer-product-variations') . '</strong> ' . esc_html($remaining_sessions) . '</p>';
    }

    // Holidays
    if (!empty($holidays) && is_array($holidays)) {
        echo '<p><strong>' . __('Holidays:', 'intersoccer-product-variations') . '</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        foreach ($holidays as $holiday) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                $formatted_holiday = date_i18n(get_option('date_format'), strtotime($holiday));
                echo '<li>' . esc_html($formatted_holiday) . '</li>';
            }
        }
        echo '</ul>';
    }

    if (!$inner_only) {
        echo '</div>';
        if (!$plain) {
            echo '</div>';
        }
    }
}

/**
 * AJAX handler to get course info for selected variation
 */
add_action('wp_ajax_intersoccer_get_course_info_display', 'intersoccer_get_course_info_display');
add_action('wp_ajax_nopriv_intersoccer_get_course_info_display', 'intersoccer_get_course_info_display');
function intersoccer_get_course_info_display() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Missing product or variation ID']);
        return;
    }

    ob_start();
    intersoccer_render_course_info($product_id, $variation_id, true, true); // inner_only = true, plain = true for AJAX
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
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

/**
 * AJAX handler to get course price for selected variation
 */
add_action('wp_ajax_intersoccer_get_course_price', 'intersoccer_get_course_price');
add_action('wp_ajax_nopriv_intersoccer_get_course_price', 'intersoccer_get_course_price');
function intersoccer_get_course_price() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Missing product or variation ID']);
        return;
    }

    if (!class_exists('InterSoccer_Course')) {
        wp_send_json_error(['message' => 'Course calculation class not available']);
        return;
    }

    $price = InterSoccer_Course::calculate_price($product_id, $variation_id);

    wp_send_json_success(['price' => $price]);
}

/**
 * Filter variation prices for courses to show prorated price automatically
 * This ensures the correct price displays immediately without needing AJAX
 */
add_filter('woocommerce_product_variation_get_price', 'intersoccer_filter_course_variation_price', 10, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'intersoccer_filter_course_variation_price', 10, 2);
add_filter('woocommerce_product_variation_get_sale_price', 'intersoccer_filter_course_variation_price', 10, 2);

function intersoccer_filter_course_variation_price($price, $variation) {
    // Only apply to course products
    $parent_id = $variation->get_parent_id();
    if (intersoccer_get_product_type($parent_id) !== 'course') {
        return $price;
    }
    
    // Calculate prorated price
    $variation_id = $variation->get_id();
    $prorated_price = InterSoccer_Course::calculate_price($parent_id, $variation_id);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Filtering course variation price for ' . $variation_id . ' from ' . $price . ' to ' . $prorated_price);
    }
    
    return $prorated_price;
}

?>