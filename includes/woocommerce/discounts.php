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
    error_log('InterSoccer: Fetched discount rules from database: ' . print_r($rules, true));

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
        $rate = floatval($rule['rate'] ?? 0) / 100; // Convert % to decimal

        if (in_array($type, ['camp', 'course']) && $condition !== 'none') {
            $rates[$type][$condition] = $rate;
            error_log('InterSoccer: Mapped discount rule - Type: ' . $type . ', Condition: ' . $condition . ', Rate: ' . $rate);
        }
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
add_action('woocommerce_before_calculate_totals', function() {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    
    // Store a flag to prevent duplicate fee additions
    $session_key = 'intersoccer_fees_cleared_' . md5(serialize(WC()->cart->get_cart()));
    
    if (!WC()->session->get($session_key)) {
        // Mark that we've processed this cart state
        WC()->session->set($session_key, true);
        error_log('InterSoccer: Marked cart for fee processing: ' . $session_key);
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
    
    // Prevent duplicate fee calculation using cart hash
    $cart_hash = md5(serialize(WC()->cart->get_cart_contents()));
    $session_key = 'intersoccer_fees_applied_' . $cart_hash;
    
    if (WC()->session->get($session_key)) {
        error_log('InterSoccer: Fees already applied for this cart state');
        return;
    }
    
    error_log('InterSoccer: Starting combo discount calculation for cart hash: ' . $cart_hash);
    
    $cart_items = WC()->cart->get_cart();
    $camps_by_child = [];
    $courses_by_child = [];
    $courses_by_season_child = [];
    
    // Group cart items by product type and child
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_type = intersoccer_get_product_type($product_id);
        $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
        
        if ($assigned_player === null) {
            error_log('InterSoccer: Skipping item without assigned player: ' . $product_id);
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
    
    // Apply camp combo discounts (multi-child)
    intersoccer_apply_camp_combo_discounts($camps_by_child);
    
    // Apply course combo discounts (multi-child)
    intersoccer_apply_course_combo_discounts($courses_by_child);
    
    // Apply same-season course discounts (same child, multiple courses)
    intersoccer_apply_same_season_course_discounts($courses_by_season_child);
    
    // Mark fees as applied for this cart state
    WC()->session->set($session_key, true);
    
    error_log('InterSoccer: Finished combo discount calculation and marked as applied');
}

/**
 * Apply camp combo discounts for multiple children
 * 20% discount on 2nd camp, 25% on 3rd and additional camps
 * ONLY applies to full-week bookings, not single-day camps
 */
function intersoccer_apply_camp_combo_discounts($camps_by_child) {
    $rates = intersoccer_get_discount_rates()['camp'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20; // Default 20%
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.25; // Default 25%
    
    $all_camps = [];
    
    // Flatten all camps across children, but only include full-week bookings
    foreach ($camps_by_child as $child_id => $camps) {
        foreach ($camps as $camp) {
            $product_id = $camp['item']['product_id'];
            $variation_id = $camp['item']['variation_id'] ?? 0;
            
            // Check booking type - only apply discounts to full-week camps
            $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);
            
            if ($booking_type === 'full-week' || empty($booking_type)) { // Default to full-week if not set
                $camp['child_id'] = $child_id;
                $all_camps[] = $camp;
                error_log('InterSoccer: Including full-week camp for discount - Product: ' . $product_id . ', Booking Type: ' . ($booking_type ?: 'default'));
            } else {
                error_log('InterSoccer: Excluding single-day camp from discount - Product: ' . $product_id . ', Booking Type: ' . $booking_type);
            }
        }
    }
    
    if (count($all_camps) < 2) {
        error_log('InterSoccer: Less than 2 eligible full-week camps - no combo discount applicable');
        return; // No combo discount applicable
    }
    
    // Sort by price (descending) to apply discounts to cheaper items
    usort($all_camps, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    error_log('InterSoccer: Applying camp combo discounts to ' . count($all_camps) . ' full-week camps');
    
    for ($i = 1; $i < count($all_camps); $i++) {
        $camp = $all_camps[$i];
        $discount_percentage = ($i === 1) ? ($second_child_rate * 100) : ($third_plus_rate * 100);
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        $discount_amount = $camp['price'] * $discount_rate;
        
        $discount_label = sprintf(
            __('%d%% Camp Combo Discount (Child %d)', 'intersoccer-product-variations'),
            $discount_percentage,
            $camp['child_id'] + 1
        );
        
        WC()->cart->add_fee($discount_label, -$discount_amount);
        
        error_log(sprintf(
            'InterSoccer: Applied %d%% camp discount: -CHF %.2f for child %d (full-week only)',
            $discount_percentage,
            $discount_amount,
            $camp['child_id']
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
    $second_child_rate = $rates['2nd_child'] ?? 0.20; // Default 20%
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.30; // Default 30%
    
    $children_with_courses = array_keys($courses_by_child);
    
    if (count($children_with_courses) < 2) {
        return; // No multi-child discount applicable
    }
    
    error_log('InterSoccer: Applying course combo discounts for ' . count($children_with_courses) . ' children');
    
    // Calculate prorated prices first, then apply discounts
    foreach ($courses_by_child as $child_id => $courses) {
        foreach ($courses as &$course) {
            $product_id = $course['item']['product_id'];
            $variation_id = $course['item']['variation_id'] ?? 0;
            
            // Get course details for proration calculation
            $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
            $total_weeks = (int) get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
            $original_price = (float) $course['item']['data']->get_regular_price();
            
            if ($start_date && $total_weeks > 0) {
                $current_date = current_time('Y-m-d');
                
                if (strtotime($current_date) > strtotime($start_date)) {
                    // Course has started - calculate proration
                    if (class_exists('InterSoccer_Course')) {
                        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id ?: $product_id, $total_weeks);
                        $prorated_price = InterSoccer_Course::calculate_price($product_id, $variation_id ?: $product_id, $remaining_sessions);
                        
                        // Update the price to prorated amount
                        $course['price'] = $prorated_price;
                        error_log('InterSoccer: Prorated course price - Product: ' . $product_id . ', Original: ' . $original_price . ', Prorated: ' . $prorated_price . ', Remaining Sessions: ' . $remaining_sessions);
                    } else {
                        error_log('InterSoccer: InterSoccer_Course class not available for proration calculation');
                    }
                } else {
                    error_log('InterSoccer: Course has not started yet - using full price - Product: ' . $product_id);
                }
            } else {
                error_log('InterSoccer: Missing course start date or total weeks for proration - Product: ' . $product_id);
            }
        }
        unset($course); // Break reference
    }
    
    // Sort children by total course value (descending) to apply discounts to cheaper ones
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
            // Apply discount to the (potentially prorated) price
            $discount_amount = $course['price'] * $discount_rate;
            
            $discount_label = sprintf(
                __('%d%% Course Multi-Child Discount (Child %d)', 'intersoccer-product-variations'),
                $discount_percentage,
                $child_id + 1
            );
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            error_log(sprintf(
                'InterSoccer: Applied %d%% course multi-child discount: -CHF %.2f for child %d (after proration)',
                $discount_percentage,
                $discount_amount,
                $child_id
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
    $combo_rate = $rates['same_season_course'] ?? 0.50; // Default 50%
    
    foreach ($courses_by_season_child as $season => $children_courses) {
        foreach ($children_courses as $child_id => $courses) {
            if (count($courses) < 2) {
                continue; // Need at least 2 courses for same-season discount
            }
            
            error_log("InterSoccer: Applying same-season discount for child {$child_id} in {$season}");
            
            // Calculate prorated prices first
            foreach ($courses as &$course) {
                $product_id = $course['item']['product_id'];
                $variation_id = $course['item']['variation_id'] ?? 0;
                
                // Get course details for proration calculation
                $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
                $total_weeks = (int) get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
                $original_price = (float) $course['item']['data']->get_regular_price();
                
                if ($start_date && $total_weeks > 0) {
                    $current_date = current_time('Y-m-d');
                    
                    if (strtotime($current_date) > strtotime($start_date)) {
                        // Course has started - calculate proration
                        if (class_exists('InterSoccer_Course')) {
                            $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id ?: $product_id, $total_weeks);
                            $prorated_price = InterSoccer_Course::calculate_price($product_id, $variation_id ?: $product_id, $remaining_sessions);
                            
                            // Update the price to prorated amount
                            $course['price'] = $prorated_price;
                            error_log('InterSoccer: Prorated same-season course price - Product: ' . $product_id . ', Original: ' . $original_price . ', Prorated: ' . $prorated_price);
                        } else {
                            error_log('InterSoccer: InterSoccer_Course class not available for same-season proration calculation');
                        }
                    } else {
                        error_log('InterSoccer: Same-season course has not started yet - using full price - Product: ' . $product_id);
                    }
                } else {
                    error_log('InterSoccer: Missing course start date or total weeks for same-season proration - Product: ' . $product_id);
                }
            }
            unset($course); // Break reference
            
            // Sort by price (descending) to apply discount to cheaper course
            usort($courses, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });
            
            // Apply 50% discount to 2nd course (and any additional courses) - after proration
            for ($i = 1; $i < count($courses); $i++) {
                $course = $courses[$i];
                $discount_amount = $course['price'] * $combo_rate;
                
                $discount_label = sprintf(
                    __('50%% Same Season Course Discount (Child %d, %s)', 'intersoccer-product-variations'),
                    $child_id + 1,
                    $season
                );
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                error_log(sprintf(
                    'InterSoccer: Applied 50%% same-season discount: -CHF %.2f for child %d in %s (after proration)',
                    $discount_amount,
                    $child_id,
                    $season
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

error_log('InterSoccer: Loaded discounts.php with database-driven rules and session-based fee management');
?>