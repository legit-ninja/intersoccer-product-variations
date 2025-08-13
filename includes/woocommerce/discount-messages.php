<?php
/**
 * Discount Messages Integration
 * Integrates custom discount messages with the discount system and WPML
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get localized discount message for a specific discount rule
 */
function intersoccer_get_discount_message($rule_id, $message_type = 'cart_message', $fallback = '') {
    $discount_rules = get_option('intersoccer_discount_rules', []);
    $discount_messages = get_option('intersoccer_discount_messages', []);
    
    // Find the rule
    $rule = null;
    foreach ($discount_rules as $stored_rule) {
        if ($stored_rule['id'] === $rule_id) {
            $rule = $stored_rule;
            break;
        }
    }
    
    if (!$rule) {
        return $fallback;
    }
    
    $message_key = $rule['message_key'] ?? $rule_id;
    $current_lang = intersoccer_get_current_language();
    
    // Get message for current language
    $message_data = $discount_messages[$message_key][$current_lang] ?? [];
    $message = $message_data[$message_type] ?? '';
    
    // Fallback to default language if not found
    if (empty($message) && $current_lang !== 'en') {
        $message_data = $discount_messages[$message_key]['en'] ?? [];
        $message = $message_data[$message_type] ?? '';
    }
    
    // Use fallback if still empty
    if (empty($message)) {
        $message = $fallback;
    }
    
    // Apply WPML string translation if available
    if (function_exists('icl_t')) {
        $string_name = "intersoccer_discount_{$rule_id}_{$message_type}";
        $message = icl_t('intersoccer-product-variations', $string_name, $message);
    }
    
    return $message;
}

/**
 * Register discount messages with WPML String Translation
 */
function intersoccer_register_wpml_strings() {
    if (!function_exists('icl_register_string')) {
        return;
    }
    
    $discount_rules = get_option('intersoccer_discount_rules', []);
    $discount_messages = get_option('intersoccer_discount_messages', []);
    
    foreach ($discount_rules as $rule) {
        $message_key = $rule['message_key'] ?? $rule['id'];
        $rule_messages = $discount_messages[$message_key] ?? [];
        
        foreach ($rule_messages as $lang_code => $messages) {
            if ($lang_code === 'en') { // Register English as source
                foreach ($messages as $type => $message) {
                    if (!empty($message)) {
                        $string_name = "intersoccer_discount_{$rule['id']}_{$type}";
                        $context = 'intersoccer-product-variations';
                        icl_register_string($context, $string_name, $message);
                    }
                }
            }
        }
    }
}
add_action('init', 'intersoccer_register_wpml_strings', 20);

/**
 * Update discount functions to use custom messages
 */

// Override camp combo discount function to use custom messages
function intersoccer_apply_camp_combo_discounts_with_messages($camps_by_child) {
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
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        $discount_amount = $camp['price'] * $discount_rate;
        
        // Get custom message for the appropriate rule
        $rule_id = ($i === 1) ? 'camp_2nd_child' : 'camp_3rd_plus_child';
        $discount_label = intersoccer_get_discount_message(
            $rule_id, 
            'cart_message', 
            sprintf(__('%d%% Camp Combo Discount (Child %d)', 'intersoccer-product-variations'), 
                    ($discount_rate * 100), 
                    $camp['child_id'] + 1)
        );
        
        WC()->cart->add_fee($discount_label, -$discount_amount);
        
        error_log(sprintf(
            'InterSoccer: Applied camp discount with custom message: %s, Amount: -CHF %.2f',
            $discount_label,
            $discount_amount
        ));
    }
}

// Override course combo discount function to use custom messages
function intersoccer_apply_course_combo_discounts_with_messages($courses_by_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $second_child_rate = $rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $rates['3rd_plus_child'] ?? 0.30;
    
    $children_with_courses = array_keys($courses_by_child);
    
    if (count($children_with_courses) < 2) {
        return;
    }
    
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
                    }
                }
            }
        }
        unset($course); // Break reference
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
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        
        foreach ($courses_by_child[$child_id] as $course) {
            $discount_amount = $course['price'] * $discount_rate;
            
            // Get custom message for the appropriate rule
            $rule_id = ($i === 1) ? 'course_2nd_child' : 'course_3rd_plus_child';
            $discount_label = intersoccer_get_discount_message(
                $rule_id,
                'cart_message',
                sprintf(__('%d%% Course Multi-Child Discount (Child %d)', 'intersoccer-product-variations'),
                        ($discount_rate * 100),
                        $child_id + 1)
            );
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            error_log(sprintf(
                'InterSoccer: Applied course multi-child discount with custom message: %s, Amount: -CHF %.2f',
                $discount_label,
                $discount_amount
            ));
        }
    }
}

// Override same-season course discount function to use custom messages
function intersoccer_apply_same_season_course_discounts_with_messages($courses_by_season_child) {
    $rates = intersoccer_get_discount_rates()['course'];
    $combo_rate = $rates['same_season_course'] ?? 0.50;
    
    foreach ($courses_by_season_child as $season => $children_courses) {
        foreach ($children_courses as $child_id => $courses) {
            if (count($courses) < 2) {
                continue;
            }
            
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
                        }
                    }
                }
            }
            unset($course); // Break reference
            
            // Sort by price (descending) to apply discount to cheaper course
            usort($courses, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });
            
            // Apply discount to 2nd course (and any additional courses) - after proration
            for ($i = 1; $i < count($courses); $i++) {
                $course = $courses[$i];
                $discount_amount = $course['price'] * $combo_rate;
                
                // Get custom message
                $discount_label = intersoccer_get_discount_message(
                    'course_same_season',
                    'cart_message',
                    sprintf(__('50%% Same Season Course Discount (Child %d, %s)', 'intersoccer-product-variations'),
                            $child_id + 1,
                            $season)
                );
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                error_log(sprintf(
                    'InterSoccer: Applied same-season discount with custom message: %s, Amount: -CHF %.2f',
                    $discount_label,
                    $discount_amount
                ));
            }
        }
    }
}

/**
 * Add custom message display to cart item data
 */
add_filter('woocommerce_get_item_data', 'intersoccer_add_discount_messages_to_cart', 15, 2);
function intersoccer_add_discount_messages_to_cart($item_data, $cart_item) {
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
    
    if ($assigned_player === null) {
        return $item_data;
    }
    
    // Get applicable discounts for this specific item
    $discounts = intersoccer_get_applicable_discounts($cart_item);
    
    if (!empty($discounts)) {
        foreach ($discounts as $discount) {
            // Determine rule ID based on discount type
            $rule_id = intersoccer_get_rule_id_from_discount($discount);
            
            // Get custom customer note if available
            $customer_note = intersoccer_get_discount_message($rule_id, 'customer_note');
            
            if (!empty($customer_note)) {
                $item_data[] = [
                    'key' => __('Discount Information', 'intersoccer-product-variations'),
                    'value' => esc_html($customer_note),
                    'display' => '<small style="color: #666; font-style: italic;">' . esc_html($customer_note) . '</small>'
                ];
            }
        }
    }
    
    return $item_data;
}

/**
 * Determine rule ID from discount information
 */
function intersoccer_get_rule_id_from_discount($discount) {
    if (!is_array($discount)) {
        return '';
    }
    
    $type = $discount['type'] ?? '';
    
    switch ($type) {
        case 'camp_sibling':
            $percentage = $discount['percentage'] ?? 0;
            return ($percentage == 20) ? 'camp_2nd_child' : 'camp_3rd_plus_child';
            
        case 'course_multi_child':
            $percentage = $discount['percentage'] ?? 0;
            return ($percentage == 20) ? 'course_2nd_child' : 'course_3rd_plus_child';
            
        case 'course_same_season':
            return 'course_same_season';
            
        default:
            return '';
    }
}

/**
 * Add email templates for discount notifications
 */
function intersoccer_add_discount_email_content($order, $sent_to_admin = false) {
    if ($sent_to_admin) {
        return;
    }
    
    $discount_fees = [];
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_amount() < 0) { // Negative amount = discount
            $discount_fees[] = $fee;
        }
    }
    
    if (empty($discount_fees)) {
        return;
    }
    
    echo '<h3>' . __('Discounts Applied', 'intersoccer-product-variations') . '</h3>';
    echo '<ul>';
    
    foreach ($discount_fees as $fee) {
        $fee_name = $fee->get_name();
        $fee_amount = wc_price(abs($fee->get_amount()));
        
        echo '<li><strong>' . esc_html($fee_name) . ':</strong> ' . $fee_amount . ' ' . __('saved', 'intersoccer-product-variations') . '</li>';
        
        // Try to find and display customer note for this discount
        foreach (get_option('intersoccer_discount_rules', []) as $rule) {
            $rule_message = intersoccer_get_discount_message($rule['id'], 'cart_message');
            if (!empty($rule_message) && strpos($fee_name, $rule_message) !== false) {
                $customer_note = intersoccer_get_discount_message($rule['id'], 'customer_note');
                if (!empty($customer_note)) {
                    echo '<li style="margin-left: 20px; color: #666; font-style: italic;">' . esc_html($customer_note) . '</li>';
                }
                break;
            }
        }
    }
    
    echo '</ul>';
}
add_action('woocommerce_email_order_details', 'intersoccer_add_discount_email_content', 25, 2);

/**
 * Initialize default discount messages when rules are created
 */
function intersoccer_initialize_default_messages() {
    $discount_rules = get_option('intersoccer_discount_rules', []);
    $discount_messages = get_option('intersoccer_discount_messages', []);
    $available_languages = intersoccer_get_available_languages();
    
    $default_messages = [
        'camp_2nd_child' => [
            'cart_message' => __('20% Sibling Discount Applied', 'intersoccer-product-variations'),
            'admin_description' => __('Second child camp discount', 'intersoccer-product-variations'),
            'customer_note' => __('You saved 20% on this camp because you have multiple children enrolled in camps.', 'intersoccer-product-variations')
        ],
        'camp_3rd_plus_child' => [
            'cart_message' => __('25% Multi-Child Discount Applied', 'intersoccer-product-variations'),
            'admin_description' => __('Third or additional child camp discount', 'intersoccer-product-variations'),
            'customer_note' => __('You saved 25% on this camp for your third (or additional) child enrolled in camps.', 'intersoccer-product-variations')
        ],
        'course_2nd_child' => [
            'cart_message' => __('20% Course Sibling Discount', 'intersoccer-product-variations'),
            'admin_description' => __('Second child course discount', 'intersoccer-product-variations'),
            'customer_note' => __('You saved 20% on this course because you have multiple children enrolled in courses.', 'intersoccer-product-variations')
        ],
        'course_3rd_plus_child' => [
            'cart_message' => __('30% Multi-Child Course Discount', 'intersoccer-product-variations'),
            'admin_description' => __('Third or additional child course discount', 'intersoccer-product-variations'),
            'customer_note' => __('You saved 30% on this course for your third (or additional) child enrolled in courses.', 'intersoccer-product-variations')
        ],
        'course_same_season' => [
            'cart_message' => __('50% Same Season Course Discount', 'intersoccer-product-variations'),
            'admin_description' => __('Same child, multiple courses in same season', 'intersoccer-product-variations'),
            'customer_note' => __('You saved 50% on this course because your child is enrolled in multiple courses this season.', 'intersoccer-product-variations')
        ]
    ];
    
    $updated = false;
    foreach ($discount_rules as $rule) {
        $rule_id = $rule['id'];
        $message_key = $rule['message_key'] ?? $rule_id;
        
        if (!isset($discount_messages[$message_key]) && isset($default_messages[$rule_id])) {
            foreach ($available_languages as $lang_code => $lang_name) {
                $discount_messages[$message_key][$lang_code] = $default_messages[$rule_id];
            }
            $updated = true;
        }
    }
    
    if ($updated) {
        update_option('intersoccer_discount_messages', $discount_messages);
        error_log('InterSoccer: Initialized default discount messages');
    }
}
add_action('admin_init', 'intersoccer_initialize_default_messages');

error_log('InterSoccer: Loaded discount messages integration with WPML support');
?>