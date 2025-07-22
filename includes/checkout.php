<?php
/**
 * Checkout Modifications
 * Purpose: Custom modifications to WooCommerce checkout for InterSoccer.
 * Author: Jeremy Lee
 * Changes:
 * - Removed inline CSS to allow inheritance of base widget formatting (2025-05-26).
 * - Removed player assignment section, added discount display under product price (2025-06-20).
 * - Updated course sessions display to show "Sessions Remaining: [number] of [total]" (2025-07-22).
 */

defined('ABSPATH') or die('No script kiddies please!');

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: checkout.php file loaded');

// Render discount and sessions section under product price on checkout
add_action('woocommerce_review_order_before_cart_item', 'intersoccer_render_discount_checkout', 10, 3);
function intersoccer_render_discount_checkout($cart_item, $cart_item_key, $value) {
    $product = $cart_item['data'];
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    $display_items = [];

    // Handle combo discount (e.g., Family or Combo Offer discounts)
    if (isset($cart_item['combo_discount_note']) && !empty($cart_item['combo_discount_note'])) {
        $display_items[] = $cart_item['combo_discount_note'];
        error_log('InterSoccer: Added combo discount display for checkout item ' . $cart_item_key . ': ' . $cart_item['combo_discount_note']);
    }

    // Handle course sessions remaining
    if ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $variation_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $total_sessions = calculate_total_sessions($variation_id, $total_weeks);
        $sessions_display = esc_html(sprintf(__('%d of %d', 'intersoccer-player-management'), $cart_item['remaining_weeks'], $total_sessions));
        $display_items[] = __('Sessions Remaining: ', 'intersoccer-player-management') . $sessions_display;
        error_log('InterSoccer: Added sessions remaining display for checkout item ' . $cart_item_key . ': ' . $sessions_display);
    }

    // Output all display items
    foreach ($display_items as $display_item) {
        echo '<tr class="intersoccer-discount-row"><td colspan="2"></td><td class="product-price" style="padding-top: 0;"><div class="intersoccer-discount">' . esc_html($display_item) . '</div></td></tr>';
    }
}
?>