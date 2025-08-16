<?php
/**
 * Discount System for InterSoccer
 * Handles combo offers and sibling discounts for camps and courses
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch and map discount rules from database.
 *
 * @return array Mapped rates by type and condition.
 */
function intersoccer_get_discount_rates() {
    $rules = get_option('intersoccer_discount_rules', []);
    
    $rates = [
        'camp' => [],
        'course' => []
    ];

    foreach ($rules as $rule) {
        if (!isset($rule['active']) || !$rule['active']) {
            continue;
        }
        $type = $rule['type'] ?? 'general';
        $condition = $rule['condition'] ?? 'none';
        $rate = floatval($rule['rate'] ?? 0) / 100;

        if (in_array($type, ['camp', 'course']) && $condition !== 'none') {
            $rates[$type][$condition] = $rate;
        }
    }

    // Add default rates if no rules exist
    if (empty($rates['camp'])) {
        $rates['camp'] = [
            '2nd_child' => 0.20,
            '3rd_plus_child' => 0.25
        ];
    }
    
    if (empty($rates['course'])) {
        $rates['course'] = [
            '2nd_child' => 0.20,
            '3rd_plus_child' => 0.30,
            'same_season_course' => 0.50
        ];
    }

    return $rates;
}

/**
 * Get applicable discounts for a cart item
 * @param array $cart_item
 * @return array
 */
function intersoccer_get_applicable_discounts($cart_item) {
    $discounts = [];
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
    
    if ($assigned_player === null || !$product_type) {
        return $discounts;
    }
    
    // Check for combo discounts
    $cart_items = WC()->cart->get_cart();
    
    if ($product_type === 'camp') {
        $camps_by_child = [];
        foreach ($cart_items as $key => $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'camp' && isset($item['assigned_attendee'])) {
                $child_id = $item['assigned_attendee'];
                if (!isset($camps_by_child[$child_id])) {
                    $camps_by_child[$child_id] = [];
                }
                $camps_by_child[$child_id][] = $item;
            }
        }
        
        $unique_children = count($camps_by_child);
        if ($unique_children >= 2) {
            $discounts[] = 'multi_child_camp';
        }
    } elseif ($product_type === 'course') {
        $courses_by_season_child = [];
        foreach ($cart_items as $key => $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'course' && isset($item['assigned_attendee'])) {
                $child_id = $item['assigned_attendee'];
                $season = intersoccer_get_product_season($item['product_id']);
                if (!isset($courses_by_season_child[$season][$child_id])) {
                    $courses_by_season_child[$season][$child_id] = [];
                }
                $courses_by_season_child[$season][$child_id][] = $item;
            }
        }
        
        // Check for same-season discounts
        foreach ($courses_by_season_child as $season => $children) {
            foreach ($children as $child_id => $courses) {
                if (count($courses) >= 2) {
                    $discounts[] = 'same_season_course';
                    break 2;
                }
            }
        }
        
        // Check for multi-child discounts
        $children_with_courses = [];
        foreach ($courses_by_season_child as $season => $children) {
            foreach ($children as $child_id => $courses) {
                if (!isset($children_with_courses[$child_id])) {
                    $children_with_courses[$child_id] = 0;
                }
                $children_with_courses[$child_id] += count($courses);
            }
        }
        
        if (count($children_with_courses) >= 2) {
            $discounts[] = 'multi_child_course';
        }
    }
    
    return $discounts;
}

/**
 * Clear cart fees before recalculation to prevent duplication
 * Note: WooCommerce doesn't have remove_fee() method, so we use session storage
 */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    static $fees_cleared = false;
    if (!$fees_cleared) {
        $existing_fees = $cart->get_fees();
        foreach ($existing_fees as $fee_key => $fee) {
            if (strpos($fee->name, 'Camp') !== false || 
                strpos($fee->name, 'Course') !== false || 
                strpos($fee->name, 'Sibling') !== false ||
                strpos($fee->name, 'Multi-Child') !== false ||
                strpos($fee->name, 'Season') !== false) {
                error_log('InterSoccer: Removing existing fee: ' . $fee->name);
            }
        }
        $fees_cleared = true;
    }
}, 5);

/**
 * Alternative approach to prevent duplicate fees - use a static flag
 */
add_action('woocommerce_cart_calculate_fees', function() {
    // Clear any existing InterSoccer fees by checking fee names
    // This runs before our discount function adds new fees
    static $fees_cleared = false;
    
    if (!$fees_cleared) {
        $existing_fees = WC()->cart->get_fees();
        $intersoccer_fee_found = false;
        
        foreach ($existing_fees as $fee) {
            if (strpos($fee->name, 'InterSoccer') !== false || 
                strpos($fee->name, 'Camp Combo') !== false || 
                strpos($fee->name, 'Course') !== false) {
                $intersoccer_fee_found = true;
                break;
            }
        }
        
        if ($intersoccer_fee_found) {
            error_log('InterSoccer: Found existing InterSoccer fees - cart may need refresh to clear duplicates');
        }
        
        $fees_cleared = true;
    }
}, 1); // Run before our discount calculation

/**
 * Apply InterSoccer discounts to cart - using WooCommerce fees
 * Uses a session-based approach to prevent duplicate fees
 */
add_action('woocommerce_cart_calculate_fees', 'intersoccer_apply_combo_discounts');
function intersoccer_apply_combo_discounts() {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    if (!WC()->cart || WC()->cart->is_empty()) {
        return;
    }
    
    $cart_items = WC()->cart->get_cart();
    $camps_by_child = [];
    $courses_by_child = [];
    $courses_by_season_child = [];
    
    // Group cart items by product type and child
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_type = intersoccer_get_product_type($product_id);
        
        $assigned_player = $cart_item['assigned_player'] ?? $cart_item['assigned_attendee'] ?? null;
        
        if ($assigned_player === null) {
            continue;
        }
        
        $price = (float) $cart_item['data']->get_price();
        
        if ($product_type === 'camp') {
            if (!isset($camps_by_child[$assigned_player])) {
                $camps_by_child[$assigned_player] = [];
            }
            $camps_by_child[$assigned_player][] = [
                'key' => $cart_item_key,
                'item' => $cart_item,
                'price' => $price
            ];
        } elseif ($product_type === 'course') {
            $season = intersoccer_get_product_season($product_id);
            
            if (!isset($courses_by_child[$assigned_player])) {
                $courses_by_child[$assigned_player] = [];
            }
            $courses_by_child[$assigned_player][] = [
                'key' => $cart_item_key,
                'item' => $cart_item,
                'price' => $price,
                'season' => $season
            ];
            
            // Group by season and child for same-season discount
            if (!isset($courses_by_season_child[$season])) {
                $courses_by_season_child[$season] = [];
            }
            if (!isset($courses_by_season_child[$season][$assigned_player])) {
                $courses_by_season_child[$season][$assigned_player] = [];
            }
            $courses_by_season_child[$season][$assigned_player][] = [
                'key' => $cart_item_key,
                'item' => $cart_item,
                'price' => $price
            ];
        }
    }
    
    // Apply discounts
    intersoccer_apply_camp_combo_discounts($camps_by_child);
    intersoccer_apply_course_combo_discounts($courses_by_child);
    intersoccer_apply_same_season_course_discounts($courses_by_season_child);
}

/**
 * Apply camp combo discounts for multiple children
 * 20% discount on 2nd camp, 25% on 3rd and additional camps
 * ONLY applies to full-week bookings, not single-day camps
 */
function intersoccer_apply_camp_combo_discounts($camps_by_child) {
    $rates = intersoccer_get_discount_rates()['camp'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.25;
    
    $all_camps = [];
    
    // Flatten all camps across children, but only include full-week bookings
    foreach ($camps_by_child as $child_id => $camps) {
        foreach ($camps as $camp) {
            $product_id = $camp['item']['product_id'];
            $variation_id = $camp['item']['variation_id'] ?? 0;
            
            // Check booking type - only apply discounts to full-week camps
            $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);
            
            if ($booking_type === 'full-week' || empty($booking_type)) {
                $camp['child_id'] = $child_id;
                $all_camps[] = $camp;
            }
        }
    }
    
    if (count($all_camps) < 2) {
        return;
    }
    
    // Sort by price (descending) to apply discounts to cheaper items
    usort($all_camps, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    for ($i = 1; $i < count($all_camps); $i++) {
        $camp = $all_camps[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        $discount_amount = $camp['price'] * $discount_rate;
        
        // Improved discount label without child index
        if ($i === 1) {
            $discount_label = sprintf(__('%d%% Sibling Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        } else {
            $discount_label = sprintf(__('%d%% Multi-Child Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        }
        
        WC()->cart->add_fee($discount_label, -$discount_amount);
        
        error_log(sprintf(
            'InterSoccer: Applied %s: -CHF %.2f',
            $discount_label,
            $discount_amount
        ));
    }
}

/**
 * Apply course combo discounts for multiple children
 * 20% discount for 2nd child, 30% for 3rd and additional children
 * Applied AFTER proration calculations
 */
function intersoccer_apply_course_combo_discounts($courses_by_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.30;
    
    $children_with_courses = array_keys($courses_by_child);
    
    if (count($children_with_courses) < 2) {
        return;
    }
    
    // Sort children by total course value (descending)
    $children_totals = [];
    foreach ($courses_by_child as $child_id => $courses) {
        $total = array_sum(array_column($courses, 'price'));
        $children_totals[$child_id] = $total;
    }
    arsort($children_totals);
    
    $sorted_children = array_keys($children_totals);
    
    for ($i = 1; $i < count($sorted_children); $i++) {
        $child_id = $sorted_children[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        
        foreach ($courses_by_child[$child_id] as $course) {
            $discount_amount = $course['price'] * $discount_rate;
            
            // Improved discount label without child index
            if ($i === 1) {
                $discount_label = sprintf(__('%d%% Sibling Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            } else {
                $discount_label = sprintf(__('%d%% Multi-Child Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            }
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            error_log(sprintf(
                'InterSoccer: Applied %s: -CHF %.2f',
                $discount_label,
                $discount_amount
            ));
        }
    }
}

/**
 * Apply same-season course discounts (same child, multiple courses)
 * 50% discount for 2nd course in the same season
 * Applied AFTER proration calculations
 */
function intersoccer_apply_same_season_course_discounts($courses_by_season_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $combo_rate = $rates['same_season_course'] ?? 0.50;
    
    foreach ($courses_by_season_child as $season => $children_courses) {
        foreach ($children_courses as $child_id => $courses) {
            if (count($courses) < 2) {
                continue;
            }
            
            // Sort by price (descending) to apply discount to cheaper course
            usort($courses, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });
            
            // Apply 50% discount to 2nd course (and any additional courses)
            for ($i = 1; $i < count($courses); $i++) {
                $course = $courses[$i];
                $discount_amount = $course['price'] * $combo_rate;
                
                // Improved discount label
                $discount_label = sprintf(
                    __('50%% Same Season Course Discount (%s)', 'intersoccer-product-variations'),
                    $season
                );
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                error_log(sprintf(
                    'InterSoccer: Applied %s: -CHF %.2f',
                    $discount_label,
                    $discount_amount
                ));
            }
        }
    }
}


/**
 * Add discount information to cart item data
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    // Check if this item has any applicable discounts
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
    
    if ($assigned_player === null) {
        return $item_data;
    }
    
    // Get all applicable discounts for this item
    $discounts = intersoccer_get_applicable_discounts($cart_item);
    
    if (!empty($discounts)) {
        $discount_text = implode(', ', $discounts);
        $item_data[] = [
            'key' => __('Applicable Discounts', 'intersoccer-product-variations'),
            'value' => esc_html($discount_text),
        ];
    }
    
    return $item_data;
}, 10, 2);

/**
 * Clear discount session when cart contents change
 */
add_action('woocommerce_cart_item_removed', function() {
    intersoccer_clear_discount_session();
});

add_action('woocommerce_add_to_cart', function() {
    intersoccer_clear_discount_session();
});

add_action('woocommerce_cart_item_restored', function() {
    intersoccer_clear_discount_session();
});

/**
 * Helper function to clear discount session data
 */
function intersoccer_clear_discount_session() {
    if (WC()->session) {
        // Clear all intersoccer fee session data
        $session_data = WC()->session->get_session_data();
        foreach ($session_data as $key => $value) {
            if (strpos($key, 'intersoccer_fees_applied_') === 0) {
                WC()->session->__unset($key);
                error_log('InterSoccer: Cleared discount session: ' . $key);
            }
        }
    }
}


/**
 * Debug function to log cart state for discount troubleshooting
 */
function intersoccer_debug_discount_cart() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    error_log('=== InterSoccer Discount Debug ===');
    
    if (WC()->cart && !WC()->cart->is_empty()) {
        $cart_items = WC()->cart->get_cart();
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product_type = intersoccer_get_product_type($product_id);
            $assigned_player = $cart_item['assigned_player'] ?? $cart_item['assigned_attendee'] ?? 'not set';
            $price = $cart_item['data']->get_price();
            
            error_log("Cart Item: {$cart_item_key}");
            error_log("  Product ID: {$product_id}");
            error_log("  Product Type: {$product_type}");
            error_log("  Assigned Player: {$assigned_player}");
            error_log("  Price: {$price}");
        }
        
        $fees = WC()->cart->get_fees();
        error_log('Current fees: ' . print_r($fees, true));
    }
    
    error_log('=== End Discount Debug ===');
}

// Add debug hook
add_action('woocommerce_cart_calculate_fees', 'intersoccer_debug_discount_cart', 999);

error_log('InterSoccer: Loaded FIXED discounts.php with improved multi-child and same-season logic');
?>