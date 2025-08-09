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

                // Send AJAX to update session
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId,
                        camp_days: campDays,
                        remaining_weeks: remainingWeeks
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

    WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
    WC()->session->set('intersoccer_remaining_weeks_' . $product_id, $remaining_weeks);
    error_log('InterSoccer: Updated session data for product ' . $product_id . ': days=' . print_r($camp_days, true) . ', weeks=' . $remaining_weeks);

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
    error_log('InterSoccer: Cleared session data for product ' . $product_id);

    wp_send_json_success(['message' => 'Session cleared']);
    wp_die();
}

/**
 * Add custom data to cart item, including assigned attendee, selected days, and remaining sessions.
 *
 * @param array $cart_item_data Existing cart item data.
 * @param int $product_id Product ID.
 * @param int $variation_id Variation ID.
 * @return array Updated cart item data.
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    $product_type = intersoccer_get_product_type($product_id);

    // Capture assigned attendee if provided (stores index for now)
    if (isset($_POST['assigned_attendee']) && !empty($_POST['assigned_attendee'])) {
        $assigned_attendee = sanitize_text_field($_POST['assigned_attendee']);
        $cart_item_data['assigned_attendee'] = $assigned_attendee;
        error_log('InterSoccer: Added assigned_attendee (index) to cart item for product ' . $product_id . ': ' . $assigned_attendee . ' - POST data: ' . print_r($_POST, true));
    } else {
        error_log('InterSoccer: No assigned_attendee provided for product ' . $product_id . ' - POST data: ' . print_r($_POST, true));
    }

    // Capture camp days for camps
    if ($product_type === 'camp' && isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $camp_days = array_map('sanitize_text_field', $_POST['camp_days']);
        $cart_item_data['camp_days'] = $camp_days;
        $cart_item_data['intersoccer_base_price'] = intersoccer_calculate_camp_price($product_id, $variation_id, $camp_days);
        error_log('InterSoccer: Added camp_days to cart item for product ' . $product_id . ': ' . json_encode($camp_days));
    }

    // Capture remaining weeks for courses
    if ($product_type === 'course' && isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks'])) {
        $remaining_weeks = absint($_POST['remaining_weeks']);
        $cart_item_data['remaining_weeks'] = $remaining_weeks;
        $cart_item_data['intersoccer_base_price'] = intersoccer_calculate_course_price($product_id, $variation_id, $remaining_weeks);
        error_log('InterSoccer: Added remaining_weeks to cart item for product ' . $product_id . ': ' . $remaining_weeks);
    }

    // Add course-specific details if applicable
    if ($product_type === 'course') {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        $end_date = get_post_meta($variation_id, '_end_date', true);
        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        
        $cart_item_data['course_start_date'] = $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '';
        $cart_item_data['course_end_date'] = $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '';
        $cart_item_data['course_holidays'] = implode(', ', array_map(function($date) {
            return date_i18n('F j, Y', strtotime($date));
        }, $holidays));
        
        error_log('InterSoccer: Added course details to cart item for product ' . $product_id . ': Start=' . $cart_item_data['course_start_date'] . ', End=' . $cart_item_data['course_end_date'] . ', Holidays=' . $cart_item_data['course_holidays']);
    }

    return $cart_item_data;
}

/**
 * Recalculate cart item price based on custom data (e.g., days or weeks).
 *
 * @param array $cart_item Cart item.
 * @return array Updated cart item.
 */
add_filter('woocommerce_get_cart_item_from_session', 'intersoccer_get_cart_item_from_session', 10, 3);
function intersoccer_get_cart_item_from_session($cart_item, $values, $cart_item_key) {
    $product_id = $cart_item['product_id'];
    $variation_id = $cart_item['variation_id'];
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type === 'camp' && isset($values['camp_days'])) {
        $cart_item['data']->set_price(intersoccer_calculate_camp_price($product_id, $variation_id, $values['camp_days']));
        error_log('InterSoccer: Restored camp price from session for item ' . $cart_item_key . ': ' . $cart_item['data']->get_price());
    } elseif ($product_type === 'course' && isset($values['remaining_weeks'])) {
        $cart_item['data']->set_price(intersoccer_calculate_course_price($product_id, $variation_id, $values['remaining_weeks']));
        error_log('InterSoccer: Restored course price from session for item ' . $cart_item_key . ': ' . $cart_item['data']->get_price());
    }

    return $cart_item;
}

/**
 * Display custom cart item data, resolving player index to name and adding course details.
 *
 * @param array $item_data Existing item data.
 * @param array $cart_item Cart item.
 * @return array Updated item data.
 */
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 10, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('InterSoccer: User not logged in for cart display - cannot resolve player name');
        return $item_data;
    }

    // Resolve assigned attendee from index to name
    if (isset($cart_item['assigned_attendee'])) {
        $assigned_index = $cart_item['assigned_attendee'];
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        if (isset($players[$assigned_index])) {
            $player = $players[$assigned_index];
            $assigned_name = $player['first_name'] . ' ' . $player['last_name'];
            $item_data[] = [
                'key' => __('Assigned Attendee', 'intersoccer-product-variations'),
                'value' => esc_html($assigned_name),
            ];
            error_log('InterSoccer: Resolved player name for cart item: Index ' . $assigned_index . ' -> ' . $assigned_name);
        } else {
            $item_data[] = [
                'key' => __('Assigned Attendee', 'intersoccer-product-variations'),
                'value' => __('Unknown Player (Index: ' . esc_html($assigned_index) . ')'),
            ];
            error_log('InterSoccer: Failed to resolve player index ' . $assigned_index . ' for user ' . $user_id . ' - Players count: ' . count($players));
        }
    }

    // Add camp days if present
    if (isset($cart_item['camp_days'])) {
        $item_data[] = [
            'key' => __('Days Selected', 'intersoccer-product-variations'),
            'value' => esc_html(implode(', ', $cart_item['camp_days'])),
        ];
    }

    // Add course details if present
    if (isset($cart_item['course_start_date']) && $cart_item['course_start_date']) {
        $item_data[] = [
            'key' => __('Start Date', 'intersoccer-product-variations'),
            'value' => esc_html($cart_item['course_start_date']),
        ];
    }
    if (isset($cart_item['course_end_date']) && $cart_item['course_end_date']) {
        $item_data[] = [
            'key' => __('End Date', 'intersoccer-product-variations'),
            'value' => esc_html($cart_item['course_end_date']),
        ];
    }
    if (isset($cart_item['course_holidays']) && $cart_item['course_holidays']) {
        $item_data[] = [
            'key' => __('Holidays', 'intersoccer-product-variations'),
            'value' => esc_html($cart_item['course_holidays']),
        ];
    }

    // Add remaining weeks or discount note if present
    if (isset($cart_item['remaining_weeks'])) {
        $item_data[] = [
            'key' => __('Remaining Weeks', 'intersoccer-product-variations'),
            'value' => esc_html($cart_item['remaining_weeks']),
        ];
    }
    if (isset($cart_item['discount_note'])) {
        $item_data[] = [
            'key' => __('Discount', 'intersoccer-product-variations'),
            'value' => esc_html($cart_item['discount_note']),
        ];
    }

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
    }

    error_log('InterSoccer: Saved custom metadata to order item for cart key ' . $cart_key);
}

error_log('InterSoccer: Loaded cart-calculations.php');
?>