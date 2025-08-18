<?php
/**
 * Enhanced Discount System for InterSoccer
 * Handles combo offers and sibling discounts for camps and courses with precise allocation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build cart context for precise discount allocation
 */
function intersoccer_build_cart_context($cart_items) {
    $context = array(
        'camps_by_child' => array(),
        'courses_by_child' => array(),
        'courses_by_season_child' => array(),
        'all_items' => array()
    );
    
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $variation_id = $cart_item['variation_id'] ?? 0;
        $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
        $product_type = intersoccer_get_product_type($product_id);
        $price = floatval($cart_item['data']->get_price());
        
        $item_data = array(
            'cart_key' => $cart_item_key,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'assigned_player' => $assigned_player,
            'product_type' => $product_type,
            'price' => $price,
            'quantity' => $cart_item['quantity']
        );
        
        $context['all_items'][] = $item_data;
        
        if ($assigned_player !== null) {
            if ($product_type === 'camp') {
                // Check if it's full-week (combo discounts only apply to full-week)
                $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);
                if ($booking_type === 'full-week' || empty($booking_type)) {
                    if (!isset($context['camps_by_child'][$assigned_player])) {
                        $context['camps_by_child'][$assigned_player] = array();
                    }
                    $context['camps_by_child'][$assigned_player][] = $item_data;
                }
            } elseif ($product_type === 'course') {
                $season = intersoccer_get_product_season($product_id);
                
                if (!isset($context['courses_by_child'][$assigned_player])) {
                    $context['courses_by_child'][$assigned_player] = array();
                }
                $context['courses_by_child'][$assigned_player][] = $item_data;
                
                // Group by season for same-season discounts
                if (!isset($context['courses_by_season_child'][$season][$assigned_player])) {
                    $context['courses_by_season_child'][$season][$assigned_player] = array();
                }
                $context['courses_by_season_child'][$season][$assigned_player][] = $item_data;
            }
        }
    }
    
    error_log("InterSoccer Precise: Built cart context with " . count($context['all_items']) . " items, " . 
              count($context['camps_by_child']) . " children with camps, " . 
              count($context['courses_by_child']) . " children with courses");
    
    return $context;
}

/**
 * Determine precise discount type with better logic
 */
function intersoccer_determine_precise_discount_type($fee_name) {
    $fee_name_lower = strtolower($fee_name);
    
    // Camp discounts
    if (strpos($fee_name_lower, 'camp') !== false) {
        if (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false) {
            return 'camp_sibling';
        }
        return 'camp_other';
    }
    
    // Course discounts
    if (strpos($fee_name_lower, 'course') !== false) {
        if (strpos($fee_name_lower, 'same season') !== false) {
            return 'course_same_season';
        }
        if (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false) {
            return 'course_multi_child';
        }
        return 'course_other';
    }
    
    // Percentage-based discounts
    if (preg_match('/(\d+)%/', $fee_name)) {
        return 'percentage_discount';
    }
    
    return 'other';
}

/**
 * Allocate combo discount to specific items based on discount rules
 */
function intersoccer_allocate_combo_discount($combo_discount, $cart_context, $order) {
    $allocations = array();
    
    switch ($combo_discount['type']) {
        case 'camp_sibling':
            $allocations = intersoccer_allocate_camp_sibling_discount($combo_discount, $cart_context, $order);
            break;
            
        case 'course_multi_child':
            $allocations = intersoccer_allocate_course_multi_child_discount($combo_discount, $cart_context, $order);
            break;
            
        case 'course_same_season':
            $allocations = intersoccer_allocate_course_same_season_discount($combo_discount, $cart_context, $order);
            break;
            
        default:
            // For other discount types, try to distribute proportionally
            $allocations = intersoccer_allocate_proportional_discount($combo_discount, $order);
            break;
    }
    
    return $allocations;
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
 * Clear cart fees before recalculation to prevent duplication
 */
add_action('woocommerce_before_calculate_totals', function($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    static $fees_cleared = false;
    if (!$fees_cleared) {
        $fees_cleared = true;
    }
}, 5);

/**
 * Apply InterSoccer discounts to cart
 */
add_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discounts_to_items', 20);

function intersoccer_apply_combo_discounts_to_items($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;  // Prevent loops

    // Reset all items to base price
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (isset($cart_item['base_price'])) {
            $cart_item['data']->set_price((float) $cart_item['base_price']);
            $cart_item['discount_note'] = '';  // Reset note for reapply
            $cart_item['discount_amount'] = 0;
        }
    }

    $context = intersoccer_build_cart_context($cart->get_cart());

    // Apply Camp Multi-Child Discounts
    $camp_children = $context['camps_by_child'];
    if (count($camp_children) >= 2) {
        // Calculate per-child totals
        $child_totals = [];
        foreach ($camp_children as $child => $items) {
            $total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
            $child_totals[$child] = $total;
        }
        // Sort children by total DESC (highest first gets 0%)
        arsort($child_totals);
        $sorted_children = array_keys($child_totals);
        
        foreach ($sorted_children as $index => $child) {
            $percent = 0;
            if ($index == 1) $percent = 0.20;  // 20% for 2nd
            elseif ($index >= 2) $percent = 0.25;  // 25% for 3rd+
            
            if ($percent > 0) {
                $message = intersoccer_get_discount_message('camp_multi_child_' . ($index + 1), 'cart_message', ($percent * 100) . '% Camp Sibling Discount');
                foreach ($camp_children[$child] as $item) {
                    $cart_key = $item['cart_key'];
                    $base_price = $cart->cart_contents[$cart_key]['base_price'];
                    $discounted_price = $base_price * (1 - $percent);
                    $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                    $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                    $cart->cart_contents[$cart_key]['discount_note'] = $message;
                    error_log('InterSoccer: Applied ' . ($percent * 100) . '% camp discount to item ' . $item['product_id'] . ' for child ' . $child);
                }
            }
        }
    }

    // Similar logic for Course Multi-Child (adapt percentages: 20% 2nd, 30% 3rd+)
    $course_children = $context['courses_by_child'];
    if (count($course_children) >= 2) {
        // Similar sorting and application as above, with 0.20 for 2nd, 0.30 for 3rd+
        // Use 'course_multi_child_' . ($index + 1) for message key
        $child_totals = [];
        foreach ($course_children as $child => $items) {
            $total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
            $child_totals[$child] = $total;
        }
        // Sort children by total DESC (highest first gets 0%)
        arsort($child_totals);
        $sorted_children = array_keys($child_totals);
        
        foreach ($sorted_children as $index => $child) {
            $percent = 0;
            if ($index == 1) $percent = 0.20;  // 20% for 2nd
            elseif ($index >= 2) $percent = 0.25;  // 25% for 3rd+
            
            if ($percent > 0) {
                $message = intersoccer_get_discount_message('course_multi_child_' . ($index + 1), 'cart_message', ($percent * 100) . '% Course Sibling Discount');
                foreach ($course_children[$child] as $item) {
                    $cart_key = $item['cart_key'];
                    $base_price = $cart->cart_contents[$cart_key]['base_price'];
                    $discounted_price = $base_price * (1 - $percent);
                    $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                    $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                    $cart->cart_contents[$cart_key]['discount_note'] = $message;
                    error_log('InterSoccer: Applied ' . ($percent * 100) . '% camp discount to item ' . $item['product_id'] . ' for child ' . $child);
                }
            }
        }
    }

    // Course Same-Season Same-Child
    foreach ($context['courses_by_season_child'] as $season => $children) {
        foreach ($children as $child => $items) {
            if (count($items) >= 2) {
                // Sort items by base price ASC (cheap first full, expensive discount)
                usort($items, function($a, $b) { return $a['price'] <=> $b['price']; });
                // Only discount the second (index 1, which is higher price)
                $percent = 0.50;
                $item = $items[1];  // Second item
                $cart_key = $item['cart_key'];
                $base_price = $cart->cart_contents[$cart_key]['base_price'];
                $discounted_price = $base_price * (1 - $percent);
                $cart->cart_contents[$cart_key]['data']->set_price($discounted_price);
                $cart->cart_contents[$cart_key]['discount_amount'] = $base_price - $discounted_price;
                $cart->cart_contents[$cart_key]['discount_note'] = intersoccer_get_discount_message('course_same_season', 'cart_message', '50% Same Season Course Discount');
                error_log('InterSoccer: Applied 50% same-season discount to item ' . $item['product_id']);
            }
        }
    }
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
    
    // Flatten all camps across children
    foreach ($camps_by_child as $child_id => $camps) {
        foreach ($camps as $camp) {
            $camp['child_id'] = $child_id;
            $all_camps[] = $camp;
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
        
        // Enhanced discount label with discount type info
        if ($i === 1) {
            $discount_label = sprintf(__('%d%% Sibling Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        } else {
            $discount_label = sprintf(__('%d%% Multi-Child Camp Discount', 'intersoccer-product-variations'), $discount_percentage);
        }
        
        WC()->cart->add_fee($discount_label, -$discount_amount);
        
        error_log(sprintf(
            'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
            $discount_label,
            $discount_amount,
            $camp['child_id'] + 1
        ));
    }
}

/**
 * Apply course combo discounts for multiple children
 * 20% discount for 2nd child, 30% for 3rd and additional children
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
            
            // Enhanced discount label
            if ($i === 1) {
                $discount_label = sprintf(__('%d%% Sibling Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            } else {
                $discount_label = sprintf(__('%d%% Multi-Child Course Discount', 'intersoccer-product-variations'), $discount_percentage);
            }
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            error_log(sprintf(
                'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
                $discount_label,
                $discount_amount,
                $child_id + 1
            ));
        }
    }
}

/**
 * Apply same-season course discounts (same child, multiple courses)
 * 50% discount for 2nd course in the same season
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
                
                // Enhanced discount label
                $discount_label = sprintf(
                    __('50%% Same Season Course Discount (%s)', 'intersoccer-product-variations'),
                    $season
                );
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                error_log(sprintf(
                    'InterSoccer: Applied %s: -CHF %.2f (Child %d)',
                    $discount_label,
                    $discount_amount,
                    $child_id + 1
                ));
            }
        }
    }
}

/**
 * Determine discount type from fee name
 */
function intersoccer_determine_discount_type($fee_name) {
    $fee_name_lower = strtolower($fee_name);
    
    if (strpos($fee_name_lower, 'camp') !== false && (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false)) {
        return 'camp_sibling';
    } elseif (strpos($fee_name_lower, 'course') !== false && (strpos($fee_name_lower, 'sibling') !== false || strpos($fee_name_lower, 'multi-child') !== false)) {
        return 'course_multi_child';
    } elseif (strpos($fee_name_lower, 'same season') !== false) {
        return 'course_same_season';
    } elseif (strpos($fee_name_lower, 'coupon') !== false) {
        return 'coupon';
    }
    
    return 'other';
}

/**
 * Allocate discounts to order items for precise reporting
 */
function intersoccer_allocate_discounts_to_order_items($order_id, $order, $all_discounts) {
    if (empty($all_discounts)) {
        return;
    }
    
    $order_items = $order->get_items();
    $total_subtotal = 0;
    
    // Calculate total subtotal for proportional allocation
    foreach ($order_items as $item) {
        $total_subtotal += floatval($item->get_subtotal());
    }
    
    if ($total_subtotal <= 0) {
        return;
    }
    
    // Allocate discounts to items
    foreach ($order_items as $item_id => $item) {
        $item_subtotal = floatval($item->get_subtotal());
        $item_discounts = [];
        $total_item_discount = 0;
        
        foreach ($all_discounts as $discount) {
            // For reporting purposes, allocate proportionally
            // This will be enhanced for precise allocation in future updates
            $allocated_amount = ($item_subtotal / $total_subtotal) * $discount['amount'];
            
            if ($allocated_amount > 0.01) { // Only allocate if > 1 cent
                $item_discounts[] = [
                    'name' => $discount['name'],
                    'type' => $discount['type'],
                    'amount' => round($allocated_amount, 2),
                    'allocation_method' => 'proportional_reporting'
                ];
                $total_item_discount += $allocated_amount;
            }
        }
        
        // Store item-level discount data for reporting
        if (!empty($item_discounts)) {
            wc_update_order_item_meta($item_id, '_intersoccer_item_discounts', $item_discounts);
            wc_update_order_item_meta($item_id, '_intersoccer_total_item_discount', round($total_item_discount, 2));
        }
    }
}

/**
 * Add discount information to cart item data for display
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
    // Get applicable discounts for this item
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
    
    if ($assigned_player === null || !$product_type) {
        return $item_data;
    }
    
    // Check what discounts might apply to this item
    $potential_discounts = [];
    $cart_items = WC()->cart->get_cart();
    
    if ($product_type === 'camp') {
        $camps_for_analysis = [];
        foreach ($cart_items as $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'camp' && isset($item['assigned_attendee'])) {
                $variation_id = $item['variation_id'] ?? 0;
                $booking_type = get_post_meta($variation_id ?: $item['product_id'], 'attribute_pa_booking-type', true);
                
                if ($booking_type === 'full-week' || empty($booking_type)) {
                    $camps_for_analysis[] = $item;
                }
            }
        }
        
        if (count($camps_for_analysis) >= 2) {
            $potential_discounts[] = 'Sibling Camp Discount';
        }
    } elseif ($product_type === 'course') {
        $courses_for_analysis = [];
        $same_child_courses = [];
        
        foreach ($cart_items as $item) {
            if (intersoccer_get_product_type($item['product_id']) === 'course' && isset($item['assigned_attendee'])) {
                $courses_for_analysis[] = $item;
                
                if ($item['assigned_attendee'] === $assigned_player) {
                    $same_child_courses[] = $item;
                }
            }
        }
        
        // Check for multi-child discount
        $unique_children = array_unique(array_column($courses_for_analysis, 'assigned_attendee'));
        if (count($unique_children) >= 2) {
            $potential_discounts[] = 'Multi-Child Course Discount';
        }
        
        // Check for same-season discount
        if (count($same_child_courses) >= 2) {
            $potential_discounts[] = 'Same Season Course Discount';
        }
    }
    
    // if (!empty($potential_discounts)) {
    //     $item_data[] = [
    //         'key' => __('Potential Discounts', 'intersoccer-product-variations'),
    //         'value' => implode(', ', $potential_discounts),
    //         'display' => '<small style="color: #0073aa; font-style: italic;">' . implode(', ', $potential_discounts) . '</small>'
    //     ];
    // }
    
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

// Add debug hook when WP_DEBUG is enabled
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('woocommerce_cart_calculate_fees', 'intersoccer_debug_discount_cart', 999);
}

error_log('InterSoccer: Loaded ENHANCED discounts.php with precise allocation and reporting integration');
?>