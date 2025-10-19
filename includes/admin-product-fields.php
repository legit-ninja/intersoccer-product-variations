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
        return;
    }

    // Get variation attributes
    $attributes = $product->get_attributes();

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
            if (isset($parent_attributes['pa_activity-type'])) {
                $term = get_term_by('id', $parent_attributes['pa_activity-type']['options'][0], 'pa_activity-type');
                if ($term && $term->slug === 'course') {
                    $is_course = true;
                }
            }
        }
    }

    if (!$is_course) {
        return;
    }

    woocommerce_wp_text_input([
        'id' => '_course_start_date[' . $loop . ']',
        'label' => __('Course Start Date (MM-DD-YYYY)', 'intersoccer-product-variations'),
        'value' => get_post_meta($variation_id, '_course_start_date', true),
        'wrapper_class' => 'form-row form-row-full',
        'type' => 'date',
    ]);

    woocommerce_wp_text_input([
        'id' => '_course_total_weeks[' . $loop . ']',
        'label' => __('Total Weeks Duration', 'intersoccer-product-variations'),
        'value' => get_post_meta($variation_id, '_course_total_weeks', true),
        'wrapper_class' => 'form-row form-row-first',
        'type' => 'number',
        'custom_attributes' => ['min' => 1],
    ]);

    woocommerce_wp_text_input([
        'id' => '_course_weekly_discount[' . $loop . ']',
        'label' => __('Session Rate (CHF per day/session)', 'intersoccer-product-variations'),
        'value' => get_post_meta($variation_id, '_course_weekly_discount', true),
        'wrapper_class' => 'form-row form-row-last',
        'type' => 'number',
        'custom_attributes' => ['step' => '0.01', 'min' => 0],
    ]);

    // Holiday dates repeater
    $holiday_dates = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
    ?>
    <div class="form-row form-row-full">
        <label><?php esc_html_e('Holiday/Skip Dates', 'intersoccer-product-variations'); ?></label>
        <div id="intersoccer-holiday-dates-container-<?php echo esc_attr($loop); ?>">
            <?php foreach ($holiday_dates as $index => $date) : ?>
                <div class="intersoccer-holiday-row" style="margin-bottom: 10px;">
                    <input type="date" name="intersoccer_holiday_dates[<?php echo esc_attr($loop); ?>][<?php echo esc_attr($index); ?>]" value="<?php echo esc_attr($date); ?>" style="margin-right: 10px;">
                    <button type="button" class="button intersoccer-remove-holiday" data-variation-loop="<?php echo esc_attr($loop); ?>"><?php esc_html_e('Remove', 'intersoccer-product-variations'); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button intersoccer-add-holiday" data-variation-loop="<?php echo esc_attr($loop); ?>"><?php esc_html_e('Add Holiday Date', 'intersoccer-product-variations'); ?></button>
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
                        <button type="button" class="button intersoccer-remove-holiday" data-variation-loop="${loop}"><?php esc_html_e('Remove', 'intersoccer-product-variations'); ?></button>
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
        return;
    }

    if (isset($_POST['_course_start_date'][$loop])) {
        $start_date = sanitize_text_field($_POST['_course_start_date'][$loop]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && strtotime($start_date)) {
            update_post_meta($variation_id, '_course_start_date', $start_date);
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
            }
        }
    }
    update_post_meta($variation_id, '_course_holiday_dates', array_unique($holiday_dates)); // Unique to avoid duplicates

    // Get course days (from pa_days-of-week or pa_course-day)
    $parent_id = wp_get_post_parent_id($variation_id);
    $course_days = wc_get_product_terms($parent_id, 'pa_days-of-week', ['fields' => 'names']) ?: 
                wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names']) ?: ['Monday']; // Fallback

    $end_date = calculate_course_end_date($variation_id, $start_date, $total_weeks, $holiday_dates, $course_days);
    update_post_meta($variation_id, '_end_date', $end_date);
}

/**
 * Add Late Pick Up option to camp variation options
 */
add_action('woocommerce_variation_options', 'intersoccer_add_camp_variation_fields', 20, 3);
add_action('woocommerce_product_after_variable_attributes', 'intersoccer_add_camp_variation_fields_after_attributes', 20, 3);
function intersoccer_add_camp_variation_fields($loop, $variation_data, $variation) {
    error_log('InterSoccer Admin: intersoccer_add_camp_variation_fields called for loop ' . $loop . ', variation ID ' . $variation->ID);
    intersoccer_add_camp_late_pickup_field($variation->ID, $loop);
}

function intersoccer_add_camp_variation_fields_after_attributes($loop, $variation_data, $variation) {
    error_log('InterSoccer Admin: intersoccer_add_camp_variation_fields_after_attributes called for loop ' . $loop . ', variation ID ' . $variation->ID);
    intersoccer_add_camp_late_pickup_field($variation->ID, $loop);
}

function intersoccer_add_camp_late_pickup_field($variation_id, $loop) {
    error_log('InterSoccer Admin: Checking camp late pickup field for variation ' . $variation_id . ', loop ' . $loop);
    
    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer Admin: Invalid product for variation ' . $variation_id);
        return;
    }

    // Check if this is a camp variation
    $parent_product = wc_get_product($product->get_parent_id());
    if (!$parent_product) {
        error_log('InterSoccer Admin: No parent product found for variation ' . $variation_id);
        return;
    }
    
    error_log('InterSoccer Admin: Parent product ID: ' . $parent_product->get_id());
    $is_camp = intersoccer_is_camp($parent_product->get_id());
    error_log('InterSoccer Admin: Is parent product a camp? ' . ($is_camp ? 'true' : 'false'));
    
    if (!$is_camp) {
        error_log('InterSoccer Admin: Parent product is not a camp, skipping late pickup field');
        return;
    }

    error_log('InterSoccer Admin: Adding late pickup field for camp variation ' . $variation_id);

    echo '<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">';
    echo '<p style="margin: 0 0 5px 0; font-weight: bold;">' . __('Late Pick Up Options', 'intersoccer-product-variations') . '</p>';
    
    $checked = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    // Enable by default for new variations, respect saved setting
    $checked = ($checked === 'yes' || $checked === '') ? 'checked' : '';
    echo '<p class="form-field _intersoccer_enable_late_pickup_field" style="margin: 5px 0;">
        <label for="_intersoccer_enable_late_pickup_' . $loop . '" style="display: inline-block; margin-right: 10px;">' . __('Enable Late Pick Up', 'intersoccer-product-variations') . '</label>
        <input type="checkbox" class="checkbox" name="_intersoccer_enable_late_pickup[' . $loop . ']" id="_intersoccer_enable_late_pickup_' . $loop . '" value="yes" ' . $checked . ' />
        <span class="description" style="display: block; margin-top: 5px; color: #666;">' . __('Allow customers to add late pick up options for this camp variation.', 'intersoccer-product-variations') . '</span>
    </p>';
    
    echo '</div>';
}

/**
 * Save Late Pick Up option for variations
 */
add_action('woocommerce_save_product_variation', 'intersoccer_save_camp_variation_fields', 10, 2);
function intersoccer_save_camp_variation_fields($variation_id, $loop) {
    error_log('InterSoccer Save: Saving late pickup for variation ' . $variation_id . ', loop ' . $loop);
    error_log('InterSoccer Save: POST data: ' . json_encode($_POST));
    
    $enable_late_pickup = isset($_POST['_intersoccer_enable_late_pickup'][$loop]) ? 'yes' : 'no';
    error_log('InterSoccer Save: enable_late_pickup = ' . $enable_late_pickup);
    
    update_post_meta($variation_id, '_intersoccer_enable_late_pickup', $enable_late_pickup);
    error_log('InterSoccer Save: Updated meta for variation ' . $variation_id . ' to ' . $enable_late_pickup);
}
