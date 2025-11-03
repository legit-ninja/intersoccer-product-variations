<?php
/**
 * File: late-pickup.php
 * Description: Handles late pickup functionality for camp products in InterSoccer plugin.
 * Dependencies: WooCommerce, product-types.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================================
 * LATE PICKUP FRONTEND RENDERING - DISABLED
 * ============================================================================
 * The frontend HTML rendering and JavaScript for late pickup has been moved to:
 * includes/elementor-widgets.php
 * 
 * This ensures the late pickup options appear as a table row within the 
 * variations table, maintaining consistent styling with other variation attributes.
 * 
 * This file now only contains:
 * - Cart data handlers
 * - Price adjustment handlers
 * - Validation handlers
 * ============================================================================
 */

/**
 * Add late pickup data to cart item.
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_late_pickup_data', 10, 3);
function intersoccer_add_late_pickup_data($cart_item_data, $product_id, $variation_id) {
    error_log('InterSoccer Late Pickup: add_cart_item_data called for variation ' . $variation_id);
    
    // Check if late pickup is enabled for this variation
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    if ($enable_late_pickup !== 'yes') {
        error_log('InterSoccer Late Pickup: Late pickup NOT enabled for this variation, skipping');
        return $cart_item_data;
    }

    error_log('InterSoccer Late Pickup: Late pickup IS enabled for this variation');
    error_log('InterSoccer Late Pickup: POST data - late_pickup_cost: ' . (isset($_POST['late_pickup_cost']) ? $_POST['late_pickup_cost'] : 'NOT SET'));
    error_log('InterSoccer Late Pickup: POST data - late_pickup_days: ' . (isset($_POST['late_pickup_days']) ? json_encode($_POST['late_pickup_days']) : 'NOT SET'));

    if (isset($_POST['late_pickup_cost'])) {
        $cart_item_data['late_pickup_cost'] = floatval($_POST['late_pickup_cost']);
        error_log('InterSoccer Late Pickup: Added cost to cart data: ' . $cart_item_data['late_pickup_cost']);
    }
    if (isset($_POST['late_pickup_type'])) {
        $cart_item_data['late_pickup_type'] = sanitize_text_field($_POST['late_pickup_type']);
    }
    if (isset($_POST['late_pickup_days']) && is_array($_POST['late_pickup_days'])) {
        $cart_item_data['late_pickup_days'] = array_map('sanitize_text_field', $_POST['late_pickup_days']);
        error_log('InterSoccer Late Pickup: Added days to cart data: ' . json_encode($cart_item_data['late_pickup_days']));
    }
    if (isset($_POST['base_price'])) {
        $cart_item_data['base_price'] = floatval($_POST['base_price']);
    }
    return $cart_item_data;
}

/**
 * Adjust cart item price to include late pickup cost.
 */
add_filter('woocommerce_add_cart_item', 'intersoccer_add_late_pickup_to_price', 10, 2);
function intersoccer_add_late_pickup_to_price($cart_item, $cart_item_key) {
    // Check if late pickup is enabled for this variation
    $variation_id = $cart_item['variation_id'];
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    if ($enable_late_pickup !== 'yes') {
        return $cart_item;
    }

    if (isset($cart_item['late_pickup_cost']) && $cart_item['late_pickup_cost'] > 0) {
        $original_price = $cart_item['data']->get_price();
        $cart_item['data']->set_price($original_price + $cart_item['late_pickup_cost']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Adjusted cart item price for ' . $cart_item_key . ' from ' . $original_price . ' to ' . ($original_price + $cart_item['late_pickup_cost']) . ' including late pickup cost ' . $cart_item['late_pickup_cost']);
        }
    }
    return $cart_item;
}

/**
 * Validate late pickup (optional - no validation needed as late pickup is optional).
 * 
 * Note: Late pickup is now optional, so we don't require any days to be selected.
 * We only validate that IF days are selected, they are valid days of the week.
 */
add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_late_pickup', 10, 5);
function intersoccer_validate_late_pickup($passed, $product_id, $quantity, $variation_id = '', $variations = []) {
    error_log('===== InterSoccer Late Pickup Validation START =====');
    error_log('InterSoccer Late Pickup: Product ID: ' . $product_id . ', Variation ID: ' . $variation_id);
    
    // Check if late pickup is enabled for this variation
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    error_log('InterSoccer Late Pickup: Enable late pickup meta: ' . $enable_late_pickup);
    
    if ($enable_late_pickup !== 'yes') {
        error_log('InterSoccer Late Pickup: Late pickup NOT enabled for this variation, skipping validation');
        return $passed;
    }

    error_log('InterSoccer Late Pickup: Late pickup IS enabled, checking POST data...');
    error_log('InterSoccer Late Pickup: isset($_POST[late_pickup_days]): ' . (isset($_POST['late_pickup_days']) ? 'YES' : 'NO'));

    // Only validate IF late pickup days are provided (optional feature)
    if (isset($_POST['late_pickup_days']) && is_array($_POST['late_pickup_days'])) {
        $late_pickup_days = array_map('sanitize_text_field', $_POST['late_pickup_days']);
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        
        error_log('InterSoccer Late Pickup: Days submitted: ' . print_r($late_pickup_days, true));
        
        foreach ($late_pickup_days as $day) {
            if (!in_array($day, $valid_days, true)) {
                wc_add_notice(__('Invalid day selected for late pick up.', 'intersoccer-product-variations'), 'error');
                error_log('InterSoccer Late Pickup: ❌ VALIDATION FAILED - Invalid day: ' . $day);
            return false;
            }
        }
        
        error_log('InterSoccer Late Pickup: ✅ VALIDATION PASSED - ' . count($late_pickup_days) . ' days selected');
    } else {
        error_log('InterSoccer Late Pickup: No late pickup days selected (optional)');
    }
    
    error_log('InterSoccer Late Pickup: Returning passed = ' . ($passed ? 'true' : 'false'));
    error_log('===== InterSoccer Late Pickup Validation END =====');
    return $passed;
}
