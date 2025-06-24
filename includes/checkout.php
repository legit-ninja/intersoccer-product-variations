<?php
/**
 * Checkout Modifications
 * Purpose: Custom modifications to WooCommerce checkout for InterSoccer.
 * Author: Jeremy Lee
 * Changes:
 * - Removed inline CSS to allow inheritance of base widget formatting (2025-05-26).
 * - Removed player assignment section, added discount display under product price (2025-06-20).
 */

defined('ABSPATH') or die('No script kiddies please!');

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: checkout.php file loaded');

// Render discount section under product price on checkout
add_action('woocommerce_review_order_before_cart_item', 'intersoccer_render_discount_checkout', 10, 3);
function intersoccer_render_discount_checkout($cart_item, $cart_item_key, $value) {
    $product = $cart_item['data'];
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    $discount_display = '';
    if (isset($cart_item['combo_discount_note']) && !empty($cart_item['combo_discount_note'])) {
        $discount_display = $cart_item['combo_discount_note'];
    } elseif ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $discount_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
    }

    if ($discount_display) {
        echo '<tr class="intersoccer-discount-row"><td colspan="2"></td><td class="product-price" style="padding-top: 0;"><div class="intersoccer-discount">' . esc_html($discount_display) . '</div></td></tr>';
        error_log('InterSoccer: Added discount display for checkout item ' . $cart_item_key . ': ' . $discount_display);
    }
}
?>
