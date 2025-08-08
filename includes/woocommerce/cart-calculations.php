<?php
/**
 * File: cart-calculations.php
 * Description: Handles cart-level calculations, session updates, and price modifications for InterSoccer WooCommerce.
 * Dependencies: WooCommerce, product-types.php, product-camps.php, product-course.php, discounts.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate price based on product type (delegates to type-specific classes).
 *
 * @param int $product_id Product ID.
 * @param int $variation_id Variation ID.
 * @param array $camp_days Selected camp days (for camps).
 * @param int|null $remaining_weeks Remaining weeks (unused, calculated internally for courses).
 * @return float Calculated price.
 */
function intersoccer_calculate_price($product_id, $variation_id, $camp_days = [], $remaining_weeks = null) {
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);

    if ($product_type === 'camp') {
        return InterSoccer_Camp::calculate_price($product_id, $variation_id, $camp_days);
    } elseif ($product_type === 'course') {
        return InterSoccer_Course::calculate_price($product_id, $variation_id, $remaining_weeks);
    } else {
        $product = wc_get_product($variation_id ?: $product_id);
        $price = $product ? floatval($product->get_price()) : 0;
        error_log('InterSoccer: Calculated price for non-camp/course product ' . $product_id . ': ' . $price);
        return $price;
    }
}

/**
 * Modify variation prices for frontend display (avoids session during rendering).
 */
add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices', 10, 3);
function intersoccer_modify_variation_prices($prices, $product, $for_display) {
    $product_id = $product->get_id();
    error_log('InterSoccer: Modifying variation prices for product ' . $product_id . ' during rendering, using defaults');

    foreach ($prices['price'] as $variation_id => $price) {
        $calculated_price = intersoccer_calculate_price($product_id, $variation_id, [], null);
        $prices['price'][$variation_id] = $calculated_price;
        $prices['regular_price'][$variation_id] = $calculated_price;
        $prices['sale_price'][$variation_id] = $calculated_price;
    }

    return $prices;
}

/**
 * AJAX handler for dynamic price calculation.
 */
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
function intersoccer_calculate_dynamic_price_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');
    error_log('InterSoccer: AJAX dynamic price calculation called');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Invalid product or variation ID']);
        wp_die();
    }

    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);
    wp_send_json_success(['price' => wc_price($calculated_price), 'raw_price' => $calculated_price]);
    wp_die();
}

/**
 * Update session data on variation change.
 */
add_action('wp_footer', 'intersoccer_update_session_on_variation_change');
function intersoccer_update_session_on_variation_change() {
    if (!is_product()) {
        return;
    }

    global $post;
    $product_id = $post->ID;
    ?>
    <script>
        jQuery(document).ready(function($) {
            $('form.cart').on('found_variation', function(event, variation) {
                var productId = <?php echo json_encode($product_id); ?>;
                var campDays = $(this).find('input[name="camp_days[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                var remainingWeeks = $(this).find('input[name="remaining_weeks"]').val() || null;
                var assignedAttendee = $(this).find('input[name="assigned_attendee"]').val() || '';

                // Send AJAX to update session
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId,
                        camp_days: campDays,
                        remaining_weeks: remainingWeeks,
                        assigned_attendee: assignedAttendee
                    },
                    success: function(response) {
                        console.log('InterSoccer: Session data updated:', response);
                    },
                    error: function(xhr) {
                        console.error('InterSoccer: Failed to update session data:', xhr.status, xhr.responseText);
                    }
                });
            });

            $('form.cart').on('reset_data', function() {
                var productId = <?php echo json_encode($product_id); ?>;
                // Clear session data
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_clear_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId
                    },
                    success: function(response) {
                        console.log('InterSoccer: Session data cleared:', response);
                    },
                    error: function(xhr) {
                        console.error('InterSoccer: Failed to clear session data:', xhr.status, xhr.responseText);
                    }
                });
            });

            // Initial check on page load
            $('form.cart').trigger('check_variations');
        });
    </script>
    <?php
    error_log('InterSoccer: Added session update script to footer for product ' . $product_id);
}

/**
 * AJAX handler to update session data.
 */
add_action('wp_ajax_intersoccer_update_session_data', 'intersoccer_update_session_data_callback');
add_action('wp_ajax_nopriv_intersoccer_update_session_data', 'intersoccer_update_session_data_callback');
function intersoccer_update_session_data_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;
    $assigned_attendee = isset($_POST['assigned_attendee']) ? sanitize_text_field($_POST['assigned_attendee']) : '';

    WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
    WC()->session->set('intersoccer_remaining_weeks_' . $product_id, $remaining_weeks);
    WC()->session->set('intersoccer_assigned_attendee_' . $product_id, $assigned_attendee);
    error_log('InterSoccer: Updated session data for product ' . $product_id . ': days=' . print_r($camp_days, true) . ', weeks=' . $remaining_weeks . ', attendee=' . $assigned_attendee);

    wp_send_json_success(['message' => 'Session updated']);
    wp_die();
}

/**
 * AJAX handler to clear session data.
 */
add_action('wp_ajax_intersoccer_clear_session_data', 'intersoccer_clear_session_data_callback');
add_action('wp_ajax_nopriv_intersoccer_clear_session_data', 'intersoccer_clear_session_data_callback');
function intersoccer_clear_session_data_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

    WC()->session->__unset('intersoccer_selected_days_' . $product_id);
    WC()->session->__unset('intersoccer_remaining_weeks_' . $product_id);
    WC()->session->__unset('intersoccer_assigned_attendee_' . $product_id);
    error_log('InterSoccer: Cleared session data for product ' . $product_id);

    wp_send_json_success(['message' => 'Session cleared']);
    wp_die();
}

/**
 * Add custom data to cart item (e.g., attributes, attendee).
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);
    $parent_id = wp_get_post_parent_id($variation_id) ?: $product_id;

    // Add parent attributes
    $parent_attributes = wc_get_product_terms($parent_id, ['pa_intersoccer-venues', 'pa_age-group', 'pa_camp-terms', 'pa_course-day', 'pa_camp-times', 'pa_course-times', 'pa_season', 'pa_canton-region', 'pa_city', 'pa_activity-type'], ['fields' => 'names']);
    $cart_item_data['parent_attributes'] = $parent_attributes;
    error_log('InterSoccer: Added parent attributes to cart item for variation ' . $variation_id . ': ' . print_r($parent_attributes, true));

    // Add type-specific metadata
    if ($product_type === 'camp') {
        $camp_days = isset($_POST['camp_days']) ? array_map('sanitize_text_field', (array) $_POST['camp_days']) : [];
        $cart_item_data['camp_days'] = $camp_days;
        $cart_item_data['days_selected'] = implode(', ', $camp_days);
        error_log('InterSoccer: Added camp days to cart item: ' . print_r($camp_days, true));
    } elseif ($product_type === 'course') {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        $end_date = get_post_meta($variation_id, '_end_date', true);
        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true);
        $cart_item_data['start_date'] = date_i18n('d/m/y', strtotime($start_date));
        $cart_item_data['end_date'] = date_i18n('d/m/y', strtotime($end_date));
        $cart_item_data['holidays'] = implode(', ', array_map(function($date) { return date_i18n('d/m/y', strtotime($date)); }, $holidays));
        error_log('InterSoccer: Added course dates/holidays to cart item for variation ' . $variation_id);
    }

    // Add assigned attendee from session or POST
    $assigned_attendee = isset($_POST['assigned_attendee']) ? sanitize_text_field($_POST['assigned_attendee']) : WC()->session->get('intersoccer_assigned_attendee_' . $product_id, '');
    error_log('InterSoccer: add_cart_item_data - POST assigned_attendee: ' . (isset($_POST['assigned_attendee']) ? $_POST['assigned_attendee'] : 'not set'));
    error_log('InterSoccer: add_cart_item_data - Session attendee for product ' . $product_id . ': ' . WC()->session->get('intersoccer_assigned_attendee_' . $product_id, 'none'));
    error_log('InterSoccer: add_cart_item_data - Final assigned_attendee: ' . ($assigned_attendee ?: 'none'));
    if ($assigned_attendee) {
        $cart_item_data['assigned_attendee'] = $assigned_attendee;
        error_log('InterSoccer: Added assigned attendee to cart item: ' . $assigned_attendee);
    } else {
        // Fallback: Add error notice and prevent cart addition
        wc_add_notice(__('Please select an attendee for this product.', 'intersoccer-product-variations'), 'error');
        error_log('InterSoccer: No assigned attendee for product ' . $product_id . ', validation failed');
        throw new Exception(__('Please select an attendee for this product.', 'intersoccer-product-variations'));
    }

    return $cart_item_data;
}

/**
 * Display custom metadata in cart.
 */
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 10, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    if (isset($cart_item['parent_attributes'])) {
        foreach ($cart_item['parent_attributes'] as $key => $value) {
            $item_data[] = [
                'key' => ucwords(str_replace('-', ' ', $key)),
                'value' => esc_html($value)
            ];
        }
    }

    if (isset($cart_item['camp_days'])) {
        $item_data[] = [
            'key' => __('Days Selected', 'intersoccer-player-management'),
            'value' => esc_html(implode(', ', $cart_item['camp_days']))
        ];
    }

    if (isset($cart_item['start_date'])) {
        $item_data[] = [
            'key' => __('Start Date', 'intersoccer-player-management'),
            'value' => esc_html($cart_item['start_date'])
        ];
    }

    if (isset($cart_item['end_date'])) {
        $item_data[] = [
            'key' => __('End Date', 'intersoccer-player-management'),
            'value' => esc_html($cart_item['end_date'])
        ];
    }

    if (isset($cart_item['holidays'])) {
        $item_data[] = [
            'key' => __('Holidays', 'intersoccer-player-management'),
            'value' => esc_html($cart_item['holidays'])
        ];
    }

    if (isset($cart_item['assigned_attendee'])) {
        $item_data[] = [
            'key' => __('Assigned Attendee', 'intersoccer-player-management'),
            'value' => esc_html($cart_item['assigned_attendee'])
        ];
    }

    error_log('InterSoccer: Displayed custom metadata in cart for item ' . $cart_item['key']);
    return $item_data;
}

/**
 * Save custom metadata to order item.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_metadata', 10, 4);
function intersoccer_save_order_item_metadata($item, $cart_key, $values, $order) {
    if (isset($values['parent_attributes'])) {
        foreach ($values['parent_attributes'] as $key => $value) {
            $item->add_meta_data(ucwords(str_replace('-', ' ', $key)), $value, true);
        }
    }

    if (isset($values['camp_days'])) {
        $item->add_meta_data(__('Days Selected', 'intersoccer-player-management'), implode(', ', $values['camp_days']), true);
    }

    if (isset($values['start_date'])) {
        $item->add_meta_data(__('Start Date', 'intersoccer-player-management'), $values['start_date'], true);
    }

    if (isset($values['end_date'])) {
        $item->add_meta_data(__('End Date', 'intersoccer-player-management'), $values['end_date'], true);
    }

    if (isset($values['holidays'])) {
        $item->add_meta_data(__('Holidays', 'intersoccer-player-management'), $values['holidays'], true);
    }

    if (isset($values['assigned_attendee'])) {
        $item->add_meta_data(__('Assigned Attendee', 'intersoccer-player-management'), $values['assigned_attendee'], true);
    } else {
        $item->add_meta_data(__('Assigned Attendee', 'intersoccer-player-management'), 'Unknown Attendee', true);
        error_log('InterSoccer: No assigned attendee for cart key ' . $cart_key . ', defaulted to Unknown Attendee');
    }

    error_log('InterSoccer: Saved custom metadata to order item for cart key ' . $cart_key);
}

error_log('InterSoccer: Loaded cart-calculations.php');
?>