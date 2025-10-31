<?php

/**
 * Admin Product Fields
 * Purpose: Adds custom fields for Course metadata in WooCommerce product admin.
 */

defined('ABSPATH') or die('No script kiddies please!');

function calculate_course_end_date($variation_id, $start_date, $total_weeks, $holidays, $course_days) {
    if (empty($start_date) || $total_weeks < 1 || empty($course_days)) return ''; // Invalid, log error

    $start = new DateTime($start_date);
    $end = clone $start;
    $sessions_counted = 0;
    $holiday_set = array_flip($holidays);

    while ($sessions_counted < $total_weeks) {
        $end->add(new DateInterval('P1D')); // Day-by-day to check each potential session
        $day_name = $end->format('l');
        if (in_array($day_name, $course_days) && !isset($holiday_set[$end->format('Y-m-d')])) {
            $sessions_counted++;
        }
    }

    return $end->format('Y-m-d'); // Return YYYY-MM-DD
}

// Add custom fields to variation settings
add_action('woocommerce_variation_options_pricing', 'intersoccer_add_course_variation_fields', 10, 3);
function intersoccer_add_course_variation_fields($loop, $variation_data, $variation)
{
    $variation_id = $variation->ID;
    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer: No product found for variation ID ' . $variation_id);
        return;
    }

    // Get variation attributes
    $attributes = $product->get_attributes();
    error_log('InterSoccer: Variation attributes for ID ' . $variation_id . ': ' . print_r($attributes, true));

    // Check if pa_activity-type is set to 'course'
    $is_course = false;
    if (isset($attributes['pa_activity-type'])) {
        $term = get_term_by('slug', $attributes['pa_activity-type'], 'pa_activity-type');
        if ($term && $term->slug === 'course') {
            $is_course = true;
        }
    }

    // Fallback: Check parent product attributes
    if (!$is_course) {
        $parent_product = wc_get_product($product->get_parent_id());
        if ($parent_product) {
            $parent_attributes = $parent_product->get_attributes();
            error_log('InterSoccer: Parent product attributes for ID ' . $product->get_parent_id() . ': ' . print_r($parent_attributes, true));
            if (isset($parent_attributes['pa_activity-type'])) {
                $term = get_term_by('id', $parent_attributes['pa_activity-type']['options'][0], 'pa_activity-type');
                if ($term && $term->slug === 'course') {
                    $is_course = true;
                } else {
                    error_log('InterSoccer: Parent pa_activity-type term ID ' . $parent_attributes['pa_activity-type']['options'][0] . ' is not course (slug: ' . ($term ? $term->slug : 'not found') . ')');
                }
            } else {
                error_log('InterSoccer: No pa_activity-type found in parent attributes for ID ' . $product->get_parent_id());
            }
        }
    }

    if (!$is_course) {
        error_log('InterSoccer: Variation ID ' . $variation_id . ' is not a Course (pa_activity-type != course)');
        return;
    }

    error_log('InterSoccer: Displaying custom fields for Course variation ID ' . $variation_id);

    woocommerce_wp_text_input([
        'id' => '_course_start_date[' . $loop . ']',
        'label' => __('Course Start Date (MM-DD-YYYY)', 'intersoccer-player-management'),
        'value' => get_post_meta($variation_id, '_course_start_date', true),
        'wrapper_class' => 'form-row form-row-full',
        'type' => 'date',
    ]);

    woocommerce_wp_text_input([
        'id' => '_course_total_weeks[' . $loop . ']',
        'label' => __('Total Sessions', 'intersoccer-player-management'),
        'description' => __('Number of sessions the customer will participate in (holidays will extend the end date)', 'intersoccer-player-management'),
        'desc_tip' => true,
        'value' => get_post_meta($variation_id, '_course_total_weeks', true),
        'wrapper_class' => 'form-row form-row-first',
        'type' => 'number',
        'custom_attributes' => ['min' => 1],
    ]);

    woocommerce_wp_text_input([
        'id' => '_course_weekly_discount[' . $loop . ']',
        'label' => __('Session Rate (CHF per day/session)', 'intersoccer-player-management'),
        'value' => get_post_meta($variation_id, '_course_weekly_discount', true),
        'wrapper_class' => 'form-row form-row-last',
        'type' => 'number',
        'custom_attributes' => ['step' => '0.01', 'min' => 0],
    ]);

    // Holiday dates repeater
    $holiday_dates = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
    ?>
    <div class="form-row form-row-full">
        <label><?php esc_html_e('Holiday/Skip Dates', 'intersoccer-player-management'); ?></label>
        <div id="intersoccer-holiday-dates-container-<?php echo esc_attr($loop); ?>">
            <?php foreach ($holiday_dates as $index => $date) : ?>
                <div class="intersoccer-holiday-row" style="margin-bottom: 10px;">
                    <input type="date" name="intersoccer_holiday_dates[<?php echo esc_attr($loop); ?>][<?php echo esc_attr($index); ?>]" value="<?php echo esc_attr($date); ?>" style="margin-right: 10px;">
                    <button type="button" class="button intersoccer-remove-holiday" data-variation-loop="<?php echo esc_attr($loop); ?>"><?php esc_html_e('Remove', 'intersoccer-player-management'); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button intersoccer-add-holiday" data-variation-loop="<?php echo esc_attr($loop); ?>"><?php esc_html_e('Add Holiday Date', 'intersoccer-player-management'); ?></button>
    </div>
    <script>
        jQuery(document).ready(function($) {
            // Initialize holiday index per variation
            var holidayIndices = {};
            holidayIndices[<?php echo esc_js($loop); ?>] = <?php echo count($holiday_dates); ?>;

            // Add holiday date for specific variation
            $('.intersoccer-add-holiday[data-variation-loop="<?php echo esc_js($loop); ?>"]').on('click', function() {
                var loop = $(this).data('variation-loop');
                var index = holidayIndices[loop] || 0;
                $('#intersoccer-holiday-dates-container-' + loop).append(`
                    <div class="intersoccer-holiday-row" style="margin-bottom: 10px;">
                        <input type="date" name="intersoccer_holiday_dates[${loop}][${index}]" style="margin-right: 10px;">
                        <button type="button" class="button intersoccer-remove-holiday" data-variation-loop="${loop}"><?php esc_html_e('Remove', 'intersoccer-player-management'); ?></button>
                    </div>
                `);
                holidayIndices[loop] = index + 1;
            });

            // Remove holiday date for specific variation
            $(document).on('click', '.intersoccer-remove-holiday[data-variation-loop="<?php echo esc_js($loop); ?>"]', function() {
                $(this).closest('.intersoccer-holiday-row').remove();
            });
        });
    </script>
    <?php
}

// Save custom fields
add_action('woocommerce_save_product_variation', 'intersoccer_save_course_variation_fields', 10, 2);
function intersoccer_save_course_variation_fields($variation_id, $loop)
{
    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer: No product found for variation ID ' . $variation_id . ' during save');
        return;
    }

    $attributes = $product->get_attributes();
    $is_course = false;
    if (isset($attributes['pa_activity-type'])) {
        $term = get_term_by('slug', $attributes['pa_activity-type'], 'pa_activity-type');
        if ($term && $term->slug === 'course') {
            $is_course = true;
        }
    }

    if (!$is_course) {
        $parent_product = wc_get_product($product->get_parent_id());
        if ($parent_product) {
            $parent_attributes = $parent_product->get_attributes();
            if (isset($parent_attributes['pa_activity-type'])) {
                $term = get_term_by('id', $parent_attributes['pa_activity-type']['options'][0], 'pa_activity-type');
                if ($term && $term->slug === 'course') {
                    $is_course = true;
                }
            }
        }
    }

    if (!$is_course) {
        error_log('InterSoccer: Variation ID ' . $variation_id . ' is not a Course during save (pa_activity-type != course)');
        return;
    }

    error_log('InterSoccer: Saving custom fields for Course variation ID ' . $variation_id);

    if (isset($_POST['_course_start_date'][$loop])) {
        $start_date = sanitize_text_field($_POST['_course_start_date'][$loop]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && strtotime($start_date)) {
            update_post_meta($variation_id, '_course_start_date', $start_date);
        } else {
            error_log('InterSoccer: Invalid course start date for variation ID ' . $variation_id . ': ' . $start_date);
        }
    }

    if (isset($_POST['_course_total_weeks'][$loop])) {
        $total_weeks = absint($_POST['_course_total_weeks'][$loop]);
        update_post_meta($variation_id, '_course_total_weeks', $total_weeks);
    }

    if (isset($_POST['_course_weekly_discount'][$loop])) {
        $weekly_discount = floatval($_POST['_course_weekly_discount'][$loop]);
        update_post_meta($variation_id, '_course_weekly_discount', $weekly_discount);
    }

    // Save holiday dates
    $holiday_dates = [];
    if (isset($_POST['intersoccer_holiday_dates'][$loop]) && is_array($_POST['intersoccer_holiday_dates'][$loop])) {
        foreach ($_POST['intersoccer_holiday_dates'][$loop] as $date) {
            $sanitized_date = sanitize_text_field($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sanitized_date) && strtotime($sanitized_date)) {
                $holiday_dates[] = $sanitized_date;
            } else {
                error_log('InterSoccer: Invalid holiday date for variation ID ' . $variation_id . ': ' . $sanitized_date);
            }
        }
    }
    update_post_meta($variation_id, '_course_holiday_dates', array_unique($holiday_dates)); // Unique to avoid duplicates
    error_log('InterSoccer: Saved holiday dates for variation ID ' . $variation_id . ': ' . print_r($holiday_dates, true));

    // Get course days (from pa_days-of-week or pa_course-day)
    $parent_id = wp_get_post_parent_id($variation_id);
    $course_days = wc_get_product_terms($parent_id, 'pa_days-of-week', ['fields' => 'names']) ?: 
                wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names']) ?: ['Monday']; // Fallback

    $end_date = calculate_course_end_date($variation_id, $start_date, $total_weeks, $holiday_dates, $course_days);
    update_post_meta($variation_id, '_end_date', $end_date);
    error_log('Saved end_date for variation ' . $variation_id . ': ' . $end_date);
}
?>