<?php
/**
 * File: woocommerce-modifications.php
 * Description: Customizes WooCommerce functionality for the InterSoccer Player Management plugin, including saving player and day selections to cart and order, adjusting cart prices, removing quantity from cart and checkout, and displaying custom cart item details.
 * Dependencies: None
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Save player and days to cart item
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id)
{
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

    $is_processing = false;
    return $cart_item_data;
}

// Redirect to checkout for Buy Now
add_action('woocommerce_add_to_cart', 'intersoccer_handle_buy_now', 20, 6);
function intersoccer_handle_buy_now($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
{
    if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}

// Adjust cart item price if a custom price is set
add_action('woocommerce_before_calculate_totals', 'intersoccer_adjust_cart_item_price', 30, 1);
function intersoccer_adjust_cart_item_price($cart)
{
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
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 20, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item)
{
    error_log('InterSoccer: Cart item data for display: ' . print_r($cart_item, true));

    if (isset($cart_item['player_assignment'])) {
        $player = get_player_details($cart_item['player_assignment']);
        if (!empty($player['first_name']) && !empty($player['last_name'])) {
            $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
            $item_data[] = [
                'key' => __('Assigned Player', 'intersoccer-player-management'),
                'value' => $player_name,
                'display' => $player_name
            ];
        } else {
            error_log('InterSoccer: Invalid player data for index ' . $cart_item['player_assignment']);
        }
    } else {
        error_log('InterSoccer: No player_assignment in cart item');
    }

    if (isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
        $days = array_map('esc_html', $cart_item['camp_days']);
        $days_display = implode(', ', $days);
        $item_data[] = [
            'key' => __('Selected Days', 'intersoccer-player-management'),
            'value' => $days_display,
            'display' => $days_display
        ];
    }

    $product = wc_get_product($cart_item['product_id']);
    $terms = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
    if (
        in_array('courses', $terms, true) &&
        isset($cart_item['custom_price']) &&
        floatval($cart_item['custom_price']) < floatval($cart_item['data']->get_regular_price())
    ) {
        $item_data[] = [
            'key' => __('Pro-rated Discount', 'intersoccer-player-management'),
            'value' => __('Applied', 'intersoccer-player-management'),
            'display' => __('Applied', 'intersoccer-player-management')
        ];
        if (isset($cart_item['remaining_weeks']) && $cart_item['remaining_weeks'] > 0) {
            $weeks_display = esc_html($cart_item['remaining_weeks'] . ' Weeks remaining');
            $item_data[] = [
                'key' => __('Discount', 'intersoccer-player-management'),
                'value' => $weeks_display,
                'display' => $weeks_display
            ];
        }
    }

    return $item_data;
}

// Save player, days, and discount to order
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_data', 10, 4);
function intersoccer_save_order_item_data($item, $cart_item_key, $values, $order)
{
    if (isset($values['player_assignment'])) {
        $user_id = get_current_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        $player_index = $values['player_assignment'];
        if (isset($players[$player_index])) {
            $player = $players[$player_index];
            $item->add_meta_data(__('Assigned Player', 'intersoccer-player-management'), $player['first_name'] . ' ' . $player['last_name']);
            error_log('InterSoccer: Saved player to order item: ' . $player['first_name'] . ' ' . $player['last_name']);
        }
    }

    if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
        $days = array_map('sanitize_text_field', $values['camp_days']);
        $days_display = implode(', ', $days);
        $item->add_meta_data(__('Selected Days', 'intersoccer-player-management'), $days_display);
        error_log('InterSoccer: Saved selected days to order item: ' . $days_display);
    }

    if (isset($values['remaining_weeks']) && $values['remaining_weeks'] > 0) {
        $weeks_display = $values['remaining_weeks'] . ' Weeks remaining';
        $item->add_meta_data(__('Discount', 'intersoccer-player-management'), $weeks_display);
        error_log('InterSoccer: Saved discount weeks to order item: ' . $weeks_display);
    }
}

// Prevent quantity changes in cart for all products
add_filter('woocommerce_cart_item_quantity', 'intersoccer_cart_item_quantity', 10, 3);
function intersoccer_cart_item_quantity($quantity_html, $cart_item_key, $cart_item)
{
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Prevent quantity changes in checkout
add_filter('woocommerce_checkout_cart_item_quantity', 'intersoccer_checkout_cart_item_quantity', 10, 3);
function intersoccer_checkout_cart_item_quantity($quantity_html, $cart_item, $cart_item_key)
{
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Helper function to retrieve player details
function get_player_details($player_index)
{
    $user_id = get_current_user_id();
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    return $players[$player_index] ?? ['first_name' => 'Unknown', 'last_name' => 'Player'];
}

