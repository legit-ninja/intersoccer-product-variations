<?php
/**
 * Prevent WCML from rewriting order line item meta when an admin views an order.
 *
 * WCML hooks backend_before/after_order_itemmeta (priority 100) and calls
 * force_update_itemmeta(), which writes term slugs over stored readable values.
 * Disable via filter intersoccer_pv_block_wcml_order_itemmeta_rewrite (return false).
 *
 * @package InterSoccer\ProductVariations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remove WCML order-itemmeta rewrite callbacks on admin load.
 */
function intersoccer_pv_block_wcml_order_itemmeta_rewrite() {
    if (!apply_filters('intersoccer_pv_block_wcml_order_itemmeta_rewrite', true)) {
        return;
    }

    if (!isset($GLOBALS['woocommerce_wpml']) || !is_object($GLOBALS['woocommerce_wpml'])) {
        return;
    }

    if (!isset($GLOBALS['woocommerce_wpml']->orders) || !is_object($GLOBALS['woocommerce_wpml']->orders)) {
        return;
    }

    $orders = $GLOBALS['woocommerce_wpml']->orders;

    if (method_exists($orders, 'backend_before_order_itemmeta')) {
        remove_action('woocommerce_before_order_itemmeta', [$orders, 'backend_before_order_itemmeta'], 100);
    }

    if (method_exists($orders, 'backend_after_order_itemmeta')) {
        remove_action('woocommerce_after_order_itemmeta', [$orders, 'backend_after_order_itemmeta'], 100);
    }
}

add_action('admin_init', 'intersoccer_pv_block_wcml_order_itemmeta_rewrite', 1);
