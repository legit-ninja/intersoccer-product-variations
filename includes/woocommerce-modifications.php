<?php
/**
 * File: woocommerce-modifications.php
 * Description: Customizes WooCommerce functionality for the InterSoccer Player Management plugin.
 * Dependencies: None
 * Author: Jeremy Lee
 * Changes:
 * - Reinforced remaining_weeks persistence in cart and order (2025-05-26).
 * - Increased hook priorities to 300 (2025-05-26).
 * - Added detailed logging for cart and order data (2025-05-26).
 * - Fixed Course product discount message display in cart and checkout (2025-05-26).
 * - Added fallback for remaining_weeks in intersoccer_add_cart_item_data (2025-05-26).
 * - Enhanced intersoccer_display_cart_item_data with defensive coding and logging to debug 500 error (2025-05-26).
 * - Changed remaining_weeks display condition to > 0 (2025-05-26).
 * - Fixed fatal error from WC_Product_Attribute object in get_term_by (2025-05-26).
 * - Removed unnecessary Subtotal message for Course products (2025-05-26).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Save player and days to cart item
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    static $is_processing = false;
    if ($is_processing) {
        error_log('InterSoccer: Skipped recursive call in intersoccer_add_cart_item_data');
        return $cart_item_data;
    }
    $is_processing = true;

    if (isset($_POST['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($_POST['player_assignment']);
        error_log('InterSoccer: Added player to cart via POST: ' . $cart_item_data['player_assignment']);
    } elseif (isset($cart_item_data['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($cart_item_data['player_assignment']);
        error_log('InterSoccer: Added player to cart via cart_item_data: ' . $cart_item_data['player_assignment']);
    }

    if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $_POST['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via POST: ' . print_r($cart_item_data['camp_days'], true));
        unset($_POST['camp_days']);
    } elseif (isset($cart_item_data['camp_days']) && is_array($cart_item_data['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $cart_item_data['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via cart_item_data: ' . print_r($cart_item_data['camp_days'], true));
    }

    if (isset($_POST['adjusted_price']) && floatval($_POST['adjusted_price']) > 0) {
        $cart_item_data['custom_price'] = floatval($_POST['adjusted_price']);
        error_log('InterSoccer: Added custom price to cart via POST: ' . $cart_item_data['custom_price']);
    } elseif (isset($cart_item_data['custom_price']) && floatval($cart_item_data['custom_price']) > 0) {
        $cart_item_data['custom_price'] = floatval($cart_item_data['custom_price']);
        error_log('InterSoccer: Added custom price to cart via cart_item_data: ' . $cart_item_data['custom_price']);
    }

    if (isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($_POST['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via POST: ' . $cart_item_data['remaining_weeks']);
    } elseif (isset($cart_item_data['remaining_weeks']) && is_numeric($cart_item_data['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via cart_item_data: ' . $cart_item_data['remaining_weeks']);
    }

    // Fallback: Calculate remaining_weeks if not provided
    if (!isset($cart_item_data['remaining_weeks']) && !isset($_POST['remaining_weeks'])) {
        $product = wc_get_product($variation_id ?: $product_id);
        if ($product) {
            $attributes = $product->get_attributes();
            $is_course = false;
            if (isset($attributes['pa_activity-type'])) {
                $attribute = $attributes['pa_activity-type'];
                if ($attribute->is_taxonomy()) {
                    $terms = $attribute->get_terms();
                    foreach ($terms as $term) {
                        if ($term->slug === 'course') {
                            $is_course = true;
                            break;
                        }
                    }
                }
            }
            if ($is_course) {
                $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
                $total_weeks = get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
                if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                    $total_weeks = intval($total_weeks ?: 1);
                    $server_time = current_time('Y-m-d');
                    $start = new DateTime($start_date);
                    $current = new DateTime($server_time);
                    $weeks_passed = floor(($current->getTimestamp() - $start->getTimestamp()) / (7 * 24 * 60 * 60));
                    $cart_item_data['remaining_weeks'] = max(0, $total_weeks - $weeks_passed);
                    error_log('InterSoccer: Fallback calculated remaining_weeks for course item ' . ($variation_id ?: $product_id) . ': ' . $cart_item_data['remaining_weeks']);
                } else {
                    error_log('InterSoccer: Invalid or missing _course_start_date for course item ' . ($variation_id ?: $product_id));
                }
            }
        }
    }

    $is_processing = false;
    return $cart_item_data;
}

// Reinforce cart item data
add_filter('woocommerce_add_cart_item', 'intersoccer_reinforce_cart_item_data', 10, 2);
function intersoccer_reinforce_cart_item_data($cart_item_data, $cart_item_key) {
    if (isset($cart_item_data['remaining_weeks'])) {
        WC()->cart->cart_contents[$cart_item_key]['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Reinforced remaining weeks for cart item ' . $cart_item_key . ': ' . $cart_item_data['remaining_weeks']);
    }
    return $cart_item_data;
}

// Persist cart item meta
add_filter('woocommerce_cart_item_set_data', 'intersoccer_persist_cart_item_data', 10, 2);
function intersoccer_persist_cart_item_data($cart_item_data, $cart_item) {
    if (isset($cart_item['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item['remaining_weeks']);
        error_log('InterSoccer: Persisted remaining weeks in cart item: ' . $cart_item['remaining_weeks']);
    }
    return $cart_item_data;
}

// Redirect to checkout for Buy Now
add_action('woocommerce_add_to_cart', 'intersoccer_handle_buy_now', 20, 6);
function intersoccer_handle_buy_now($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}

// Adjust cart item price if a custom price is set
add_action('woocommerce_before_calculate_totals', 'intersoccer_adjust_cart_item_price', 30, 1);
function intersoccer_adjust_cart_item_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (did_action('woocommerce_before_calculate_totals') >= 2) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['custom_price']) && floatval($cart_item['custom_price']) > 0) {
            $cart_item['data']->set_price($cart_item['custom_price']);
            error_log('InterSoccer: Adjusted cart item price for item ' . $cart_item_key . ': ' . $cart_item['custom_price']);
        }
    }
}

// Display player, days, and discount in cart and checkout
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 300, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    error_log('InterSoccer: Entering intersoccer_display_cart_item_data for product ID: ' . ($cart_item['product_id'] ?? 'not set'));
    error_log('InterSoccer: Cart item data: ' . print_r($cart_item, true));

    // Handle Assigned Attendee
    if (isset($cart_item['player_assignment'])) {
        try {
            $player = get_player_details($cart_item['player_assignment']);
            if (!empty($player['first_name']) && !empty($player['last_name'])) {
                $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
                $item_data[] = [
                    'key' => __('Assigned Attendee', 'intersoccer-player-management'),
                    'value' => $player_name,
                    'display' => $player_name
                ];
                error_log('InterSoccer: Added Assigned Attendee for item ' . $cart_item['product_id'] . ': ' . $player_name);
            } else {
                error_log('InterSoccer: Invalid player data for index ' . $cart_item['player_assignment'] . ': ' . print_r($player, true));
            }
        } catch (Exception $e) {
            error_log('InterSoccer: Error in get_player_details for item ' . $cart_item['product_id'] . ': ' . $e->getMessage());
        }
    } else {
        error_log('InterSoccer: No player_assignment in cart item ' . $cart_item['product_id']);
    }

    // Handle Course-specific fields (Discount and Subtotal)
    $product = wc_get_product($cart_item['product_id']);
    if (!$product) {
        error_log('InterSoccer: Invalid product for item ' . $cart_item['product_id']);
        return $item_data;
    }

    $terms = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
    if (is_wp_error($terms)) {
        error_log('InterSoccer: Error fetching product categories for item ' . $cart_item['product_id'] . ': ' . $terms->get_error_message());
        return $item_data;
    }

    $attributes = $product->get_attributes();
    $is_course = in_array('courses', $terms, true);
    if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
        $attribute = $attributes['pa_activity-type'];
        if ($attribute->is_taxonomy()) {
            $attribute_terms = $attribute->get_terms();
            foreach ($attribute_terms as $term) {
                if ($term->slug === 'course') {
                    $is_course = true;
                    break;
                }
            }
        }
    }

    if ($is_course) {
        error_log('InterSoccer: Identified course item ' . $cart_item['product_id'] . ' with categories: ' . print_r($terms, true) . ', attributes: ' . print_r($attributes, true));
        if (isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
            $weeks_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
            $item_data[] = [
                'key' => __('Discount', 'intersoccer-player-management'),
                'value' => $weeks_display,
                'display' => $weeks_display
            ];
            error_log('InterSoccer: Added discount attribute for course item ' . $cart_item['product_id'] . ': ' . $weeks_display);
        } else {
            error_log('InterSoccer: No valid remaining_weeks for course item ' . $cart_item['product_id'] . ': ' . print_r($cart_item['remaining_weeks'] ?? 'not set', true));
        }

        if (isset($cart_item['custom_price']) && floatval($cart_item['custom_price']) > 0) {
            $custom_price = floatval($cart_item['custom_price']);
            $regular_price = floatval($product->get_regular_price());
            $item_data[] = [
                'key' => __('Pro-rated Price', 'intersoccer-player-management'),
                'value' => wc_price($custom_price),
                'display' => wc_price($custom_price)
            ];
            if ($custom_price < $regular_price) {
                $savings = $regular_price - $custom_price;
                $item_data[] = [
                    'key' => __('Savings', 'intersoccer-player-management'),
                    'value' => wc_price($savings),
                    'display' => wc_price($savings)
                ];
            }
            error_log('InterSoccer: Added pro-rated price and savings for course item ' . $cart_item['product_id'] . ': custom_price=' . $custom_price . ', regular_price=' . $regular_price);
        } else {
            error_log('InterSoccer: No custom price set for course item ' . $cart_item['product_id'] . ': regular_price=' . $product->get_regular_price());
        }
    } else {
        error_log('InterSoccer: Item ' . $cart_item['product_id'] . ' is not a course. Categories: ' . print_r($terms, true) . ', attributes: ' . print_r($attributes, true));
    }

    // Handle Camp-specific fields (Selected Days)
    if (isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
        $days = array_map('esc_html', $cart_item['camp_days']);
        $days_display = implode(', ', $days);
        $item_data[] = [
            'key' => __('Selected Days', 'intersoccer-player-management'),
            'value' => $days_display,
            'display' => $days_display
        ];
        error_log('InterSoccer: Added selected days for camp item ' . $cart_item['product_id'] . ': ' . $days_display);
    }

    return $item_data;
}

// Save player, days, and discount to order
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_data', 300, 4);
function intersoccer_save_order_item_data($item, $cart_item_key, $values, $order) {
    error_log('InterSoccer: Saving order item data for cart item ' . $cart_item_key . ': ' . print_r($values, true));

    if (isset($values['player_assignment'])) {
        $user_id = get_current_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        $player_index = sanitize_text_field($values['player_assignment']);
        if (isset($players[$player_index])) {
            $player = $players[$player_index];
            $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
            $item->add_meta_data(__('Assigned Attendee', 'intersoccer-player-management'), $player_name);
            error_log('InterSoccer: Saved player to order item ' . $cart_item_key . ': ' . $player_name);
        } else {
            error_log('InterSoccer: Invalid player index ' . $player_index . ' for order item ' . $cart_item_key);
        }
    }

    if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
        $days = array_map('sanitize_text_field', $values['camp_days']);
        $days_display = implode(', ', $days);
        $item->add_meta_data(__('Selected Days', 'intersoccer-player-management'), $days_display);
        error_log('InterSoccer: Saved selected days to order item ' . $cart_item_key . ': ' . $days_display);
    }

    // Check both $values and cart item for remaining_weeks
    $remaining_weeks = isset($values['remaining_weeks']) ? intval($values['remaining_weeks']) : (isset($cart_item['remaining_weeks']) ? intval($cart_item['remaining_weeks']) : 0);
    if ($remaining_weeks > 0) {
        $weeks_display = esc_html($remaining_weeks . ' Weeks Remaining');
        $item->add_meta_data(__('Discount', 'intersoccer-player-management'), $weeks_display);
        error_log('InterSoccer: Saved discount weeks to order item ' . $cart_item_key . ': ' . $weeks_display);
    } else {
        error_log('InterSoccer: No remaining_weeks for order item ' . $cart_item_key);
    }

    if (isset($values['custom_price']) && floatval($values['custom_price']) > 0) {
        $custom_price = floatval($values['custom_price']);
        $regular_price = floatval($values['data']->get_regular_price());
        if ($custom_price < $regular_price) {
            $item->add_meta_data(__('Pro-rated Discount', 'intersoccer-player-management'), __('Applied', 'intersoccer-player-management'));
            error_log('InterSoccer: Saved pro-rated discount to order item ' . $cart_item_key . ': Applied');
        }
    }
}

// Prevent quantity changes in cart for all products
add_filter('woocommerce_cart_item_quantity', 'intersoccer_cart_item_quantity', 10, 3);
function intersoccer_cart_item_quantity($quantity_html, $cart_item_key, $cart_item) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Prevent quantity changes in checkout
add_filter('woocommerce_checkout_cart_item_quantity', 'intersoccer_checkout_cart_item_quantity', 10, 3);
function intersoccer_checkout_cart_item_quantity($quantity_html, $cart_item, $cart_item_key) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Helper function to retrieve player details
function get_player_details($player_index) {
    $user_id = get_current_user_id();
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    return $players[$player_index] ?? ['first_name' => 'Unknown', 'last_name' => 'Player'];
}

// Debug: Log cart contents before rendering
add_action('woocommerce_before_cart', function () {
    error_log('InterSoccer: Cart contents before rendering: ' . print_r(WC()->cart->get_cart(), true));
});
?>