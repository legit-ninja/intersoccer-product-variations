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
 * FIXED: Now uses safe language detection with proper error handling
 */
function intersoccer_get_discount_message($rule_id, $message_type = 'cart_message', $fallback = '') {
    // Use the safe version if available, otherwise implement basic version
    if (function_exists('intersoccer_get_discount_message_safe')) {
        return intersoccer_get_discount_message_safe($rule_id, $message_type, $fallback);
    }
    
    // Basic implementation as fallback
    return intersoccer_get_discount_message_basic($rule_id, $message_type, $fallback);
}

/**
 * Basic implementation without external dependencies
 * Used when language helper functions are not available
 */
function intersoccer_get_discount_message_basic($rule_id, $message_type = 'cart_message', $fallback = '') {
    try {
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
            error_log("InterSoccer: Rule not found for ID: {$rule_id}");
            return $fallback;
        }
        
        $message_key = $rule['message_key'] ?? $rule_id;
        
        // Get current language - safe method
        $current_lang = intersoccer_get_current_language_safe();
        
        // Get message for current language
        $message_data = $discount_messages[$message_key][$current_lang] ?? [];
        $message = $message_data[$message_type] ?? '';
        
        // Fallback to English if not found and current language is not English
        if (empty($message) && $current_lang !== 'en') {
            $message_data = $discount_messages[$message_key]['en'] ?? [];
            $message = $message_data[$message_type] ?? '';
        }
        
        // Use fallback if still empty
        if (empty($message)) {
            $message = $fallback;
        }
        
        // Apply WPML string translation if available
        if (function_exists('icl_t') && !empty($message)) {
            $string_name = "intersoccer_discount_{$rule_id}_{$message_type}";
            $translated = icl_t('intersoccer-product-variations', $string_name, $message);
            return $translated;
        }
        
        return $message;
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error in intersoccer_get_discount_message_basic: " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Safe language detection that doesn't depend on external functions
 * FIXED: This replaces the missing intersoccer_get_current_language() function
 */
function intersoccer_get_current_language_safe() {
    try {
        // Check for WPML
        if (function_exists('icl_get_current_language')) {
            $lang = icl_get_current_language();
            if ($lang) {
                return $lang;
            }
        }
        
        // Check for Polylang
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language();
            if ($lang) {
                return $lang;
            }
        }
        
        // Fallback to WordPress locale
        $locale = get_locale();
        $lang = substr($locale, 0, 2); // Extract language code (e.g., 'en' from 'en_US')
        
        // Validate language code
        if (strlen($lang) === 2 && ctype_alpha($lang)) {
            return strtolower($lang);
        }
        
        // Ultimate fallback
        return 'en';
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error detecting language: " . $e->getMessage());
        return 'en';
    }
}

/**
 * Safe function to get available languages
 * FIXED: Now has proper error handling and fallbacks
 */
function intersoccer_get_available_languages_safe() {
    try {
        // Use external function if available
        if (function_exists('intersoccer_get_available_languages')) {
            return intersoccer_get_available_languages();
        }
        
        // Basic implementation
        // Check for WPML
        if (function_exists('icl_get_languages')) {
            $languages = icl_get_languages('skip_missing=0');
            $available = [];
            
            foreach ($languages as $lang_code => $lang_info) {
                $available[$lang_code] = $lang_info['native_name'] ?? $lang_code;
            }
            
            if (!empty($available)) {
                return $available;
            }
        }
        
        // Check for Polylang
        if (function_exists('pll_languages_list')) {
            $lang_codes = pll_languages_list();
            $available = [];
            
            foreach ($lang_codes as $lang_code) {
                if (function_exists('pll_get_language')) {
                    $lang_obj = pll_get_language($lang_code);
                    $available[$lang_code] = $lang_obj ? $lang_obj->name : $lang_code;
                } else {
                    $available[$lang_code] = $lang_code;
                }
            }
            
            if (!empty($available)) {
                return $available;
            }
        }
        
        // Fallback to common languages for InterSoccer
        return [
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français'
        ];
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error getting available languages: " . $e->getMessage());
        return ['en' => 'English'];
    }
}

/**
 * Register discount messages with WPML String Translation
 * FIXED: Now uses safe language functions and registers template strings
 */
function intersoccer_register_wpml_strings() {
    if (!function_exists('icl_register_string')) {
        return;
    }

    try {
        $context = 'intersoccer-product-variations';

        // Register fallback template strings for dynamic messages
        $template_strings = [
            'camp_combo_discount_template' => '%d%% Camp Combo Discount (Child %d)',
            'course_multi_child_discount_template' => '%d%% Course Multi-Child Discount (Child %d)',
            'tournament_multi_child_discount_template' => '%d%% Tournament Multi-Child Discount (Child %d)',
            'same_season_course_discount_template' => '50%% Same Season Course Discount (Child %d, %s)',
            'discount_information_label' => 'Discount Information',
            'discounts_applied_label' => 'Discounts Applied',
            'saved_label' => 'saved'
        ];

        foreach ($template_strings as $string_name => $string_value) {
            icl_register_string($context, $string_name, $string_value);
            error_log("InterSoccer: Registered WPML template string: {$string_name}");
        }

        // Register discount rule messages from database
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
                            icl_register_string($context, $string_name, $message);
                            error_log("InterSoccer: Registered WPML discount string: {$string_name}");
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("InterSoccer: Error registering WPML strings: " . $e->getMessage());
    }
}
add_action('init', 'intersoccer_register_wpml_strings', 20);

/**
 * Update discount functions to use custom messages
 * FIXED: Now properly handles missing language functions
 */

// Override camp combo discount function to use custom messages
function intersoccer_apply_camp_combo_discounts_with_messages($camps_by_child) {
    try {
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
            
            // Safe fallback message creation using WPML-compatible translation
            $default_message_template = intersoccer_translate_string(
                '%d%% Camp Combo Discount (Child %d)',
                'intersoccer-product-variations',
                'camp_combo_discount_template'
            );
            $default_message = sprintf(
                $default_message_template,
                ($discount_rate * 100),
                $camp['child_id'] + 1
            );

            $discount_label = intersoccer_get_discount_message($rule_id, 'cart_message', $default_message);
            
            WC()->cart->add_fee($discount_label, -$discount_amount);
            
            error_log(sprintf(
                'InterSoccer: Applied camp discount with custom message: %s, Amount: -CHF %.2f',
                $discount_label,
                $discount_amount
            ));
        }
    } catch (Exception $e) {
        error_log("InterSoccer: Error in camp combo discounts with messages: " . $e->getMessage());
        // Fallback to basic discount application
        if (function_exists('intersoccer_apply_camp_combo_discounts')) {
            intersoccer_apply_camp_combo_discounts($camps_by_child);
        }
    }
}

// Override course combo discount function to use custom messages
function intersoccer_apply_course_combo_discounts_with_messages($courses_by_child) {
    try {
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

                // Safe fallback message using WPML-compatible translation
                $default_message_template = intersoccer_translate_string(
                    '%d%% Course Multi-Child Discount (Child %d)',
                    'intersoccer-product-variations',
                    'course_multi_child_discount_template'
                );
                $default_message = sprintf(
                    $default_message_template,
                    ($discount_rate * 100),
                    $child_id + 1
                );

                $discount_label = intersoccer_get_discount_message($rule_id, 'cart_message', $default_message);
                
                WC()->cart->add_fee($discount_label, -$discount_amount);
                
                error_log(sprintf(
                    'InterSoccer: Applied course multi-child discount with custom message: %s, Amount: -CHF %.2f',
                    $discount_label,
                    $discount_amount
                ));
            }
        }
    } catch (Exception $e) {
        error_log("InterSoccer: Error in course combo discounts with messages: " . $e->getMessage());
        // Fallback to basic discount application
        if (function_exists('intersoccer_apply_course_combo_discounts')) {
            intersoccer_apply_course_combo_discounts($courses_by_child);
        }
    }
}

// Override same-season course discount function to use custom messages
function intersoccer_apply_same_season_course_discounts_with_messages($courses_by_season_child) {
    try {
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

                    // Safe fallback message using WPML-compatible translation
                    $default_message_template = intersoccer_translate_string(
                        '50%% Same Season Course Discount (Child %d, %s)',
                        'intersoccer-product-variations',
                        'same_season_course_discount_template'
                    );
                    $default_message = sprintf(
                        $default_message_template,
                        $child_id + 1,
                        $season
                    );

                    $discount_label = intersoccer_get_discount_message('course_same_season', 'cart_message', $default_message);
                    
                    WC()->cart->add_fee($discount_label, -$discount_amount);
                    
                    error_log(sprintf(
                        'InterSoccer: Applied same-season discount with custom message: %s, Amount: -CHF %.2f',
                        $discount_label,
                        $discount_amount
                    ));
                }
            }
        }
    } catch (Exception $e) {
        error_log("InterSoccer: Error in same-season course discounts with messages: " . $e->getMessage());
        // Fallback to basic discount application
        if (function_exists('intersoccer_apply_same_season_course_discounts')) {
            intersoccer_apply_same_season_course_discounts($courses_by_season_child);
        }
    }
}

/**
 * Add custom message display to cart item data
 * FIXED: Now handles missing functions gracefully
 */
add_filter('woocommerce_get_item_data', 'intersoccer_add_discount_messages_to_cart', 15, 2);
function intersoccer_add_discount_messages_to_cart($item_data, $cart_item) {
    try {
        $product_id = $cart_item['product_id'];
        
        // Check if helper functions exist
        if (!function_exists('intersoccer_get_product_type')) {
            return $item_data;
        }
        
        $product_type = intersoccer_get_product_type($product_id);
        $assigned_player = isset($cart_item['assigned_attendee']) ? $cart_item['assigned_attendee'] : null;
        
        if ($assigned_player === null) {
            return $item_data;
        }
        
        // Get applicable discounts for this specific item
        if (function_exists('intersoccer_get_applicable_discounts')) {
            $discounts = intersoccer_get_applicable_discounts($cart_item);
            
            if (!empty($discounts)) {
                foreach ($discounts as $discount) {
                    // Determine rule ID based on discount type
                    $rule_id = intersoccer_get_rule_id_from_discount($discount);
                    
                    // Get custom customer note if available
                    $customer_note = intersoccer_get_discount_message($rule_id, 'customer_note');
                    
                    if (!empty($customer_note)) {
                        $discount_info_label = intersoccer_translate_string(
                            'Discount Information',
                            'intersoccer-product-variations',
                            'discount_information_label'
                        );
                        $item_data[] = [
                            'key' => $discount_info_label,
                            'value' => esc_html($customer_note),
                            'display' => '<small style="color: #666; font-style: italic;">' . esc_html($customer_note) . '</small>'
                        ];
                    }
                }
            }
        }
        
        return $item_data;
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error adding discount messages to cart: " . $e->getMessage());
        return $item_data;
    }
}

/**
 * Determine rule ID from discount information
 * FIXED: Added proper error handling
 */
function intersoccer_get_rule_id_from_discount($discount) {
    try {
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
    } catch (Exception $e) {
        error_log("InterSoccer: Error determining rule ID from discount: " . $e->getMessage());
        return '';
    }
}

/**
 * Add email templates for discount notifications
 * FIXED: Added error handling for message retrieval
 */
function intersoccer_add_discount_email_content($order, $sent_to_admin = false) {
    if ($sent_to_admin) {
        return;
    }
    
    try {
        $discount_fees = [];
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_amount() < 0) { // Negative amount = discount
                $discount_fees[] = $fee;
            }
        }
        
        if (empty($discount_fees)) {
            return;
        }

        $discounts_applied_text = intersoccer_translate_string(
            'Discounts Applied',
            'intersoccer-product-variations',
            'discounts_applied_label'
        );
        $saved_text = intersoccer_translate_string(
            'saved',
            'intersoccer-product-variations',
            'saved_label'
        );

        echo '<h3>' . esc_html($discounts_applied_text) . '</h3>';
        echo '<ul>';

        foreach ($discount_fees as $fee) {
            $fee_name = $fee->get_name();
            $fee_amount = wc_price(abs($fee->get_amount()));

            echo '<li><strong>' . esc_html($fee_name) . ':</strong> ' . $fee_amount . ' ' . esc_html($saved_text) . '</li>';
            
            // Try to find and display customer note for this discount
            $discount_rules = get_option('intersoccer_discount_rules', []);
            foreach ($discount_rules as $rule) {
                $rule_message = intersoccer_get_discount_message($rule['id'], 'cart_message', '');
                if (!empty($rule_message) && strpos($fee_name, $rule_message) !== false) {
                    $customer_note = intersoccer_get_discount_message($rule['id'], 'customer_note', '');
                    if (!empty($customer_note)) {
                        echo '<li style="margin-left: 20px; color: #666; font-style: italic;">' . esc_html($customer_note) . '</li>';
                    }
                    break;
                }
            }
        }
        
        echo '</ul>';
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error adding discount email content: " . $e->getMessage());
    }
}
add_action('woocommerce_email_order_details', 'intersoccer_add_discount_email_content', 25, 2);

/**
 * Initialize default discount messages when rules are created
 * FIXED: Uses safe language functions and proper error handling
 */
function intersoccer_initialize_default_messages() {
    try {
        $discount_rules = get_option('intersoccer_discount_rules', []);
        $discount_messages = get_option('intersoccer_discount_messages', []);
        $available_languages = intersoccer_get_available_languages_safe();
        
        // Default messages in English - these will be stored in the database
        // and translated via WPML String Translation
        $default_messages = [
            'camp_2nd_child' => [
                'cart_message' => '20% Sibling Discount Applied',
                'admin_description' => 'Second child camp discount',
                'customer_note' => 'You saved 20% on this camp because you have multiple children enrolled in camps.'
            ],
            'camp_3rd_plus_child' => [
                'cart_message' => '25% Multi-Child Discount Applied',
                'admin_description' => 'Third or additional child camp discount',
                'customer_note' => 'You saved 25% on this camp for your third (or additional) child enrolled in camps.'
            ],
            'course_2nd_child' => [
                'cart_message' => '20% Course Sibling Discount',
                'admin_description' => 'Second child course discount',
                'customer_note' => 'You saved 20% on this course because you have multiple children enrolled in courses.'
            ],
            'course_3rd_plus_child' => [
                'cart_message' => '30% Multi-Child Course Discount',
                'admin_description' => 'Third or additional child course discount',
                'customer_note' => 'You saved 30% on this course for your third (or additional) child enrolled in courses.'
            ],
            'course_same_season' => [
                'cart_message' => '50% Same Season Course Discount',
                'admin_description' => 'Same child, multiple courses in same season',
                'customer_note' => 'You saved 50% on this course because your child is enrolled in multiple courses this season.'
            ],
            'tournament_2nd_child' => [
                'cart_message' => '20% Tournament Sibling Discount',
                'admin_description' => 'Second child tournament discount',
                'customer_note' => 'You saved 20% on this tournament because you have multiple children enrolled in tournaments.'
            ],
            'tournament_3rd_plus_child' => [
                'cart_message' => '30% Multi-Child Tournament Discount',
                'admin_description' => 'Third or additional child tournament discount',
                'customer_note' => 'You saved 30% on this tournament for your third (or additional) child enrolled in tournaments.'
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
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error initializing default messages: " . $e->getMessage());
    }
}
add_action('admin_init', 'intersoccer_initialize_default_messages');

/**
 * Validation function to check if all required functions are available
 * Call this to diagnose missing dependencies
 */
function intersoccer_validate_discount_message_functions() {
    $missing_functions = [];
    $required_functions = [
        'intersoccer_get_discount_rates',
        'intersoccer_get_product_type',
        'intersoccer_get_product_season',
        'intersoccer_get_applicable_discounts'
    ];
    
    foreach ($required_functions as $function) {
        if (!function_exists($function)) {
            $missing_functions[] = $function;
        }
    }
    
    if (!empty($missing_functions)) {
        error_log("InterSoccer: Missing required functions for discount messages: " . implode(', ', $missing_functions));
        return false;
    }
    
    error_log("InterSoccer: All required functions for discount messages are available");
    return true;
}

// Run validation on admin init
add_action('admin_init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_validate_discount_message_functions();
    }
});

/**
 * Emergency fallback: Replace problematic functions with safe versions
 * This ensures the discount system continues to work even if some functions are missing
 */
function intersoccer_emergency_discount_fallback() {
    // If the main discount functions are missing, provide basic fallbacks
    if (!function_exists('intersoccer_apply_camp_combo_discounts_with_messages')) {
        function intersoccer_apply_camp_combo_discounts_with_messages($camps_by_child) {
            error_log("InterSoccer: Using emergency fallback for camp combo discounts");
            if (function_exists('intersoccer_apply_camp_combo_discounts')) {
                return intersoccer_apply_camp_combo_discounts($camps_by_child);
            }
        }
    }
    
    if (!function_exists('intersoccer_apply_course_combo_discounts_with_messages')) {
        function intersoccer_apply_course_combo_discounts_with_messages($courses_by_child) {
            error_log("InterSoccer: Using emergency fallback for course combo discounts");
            if (function_exists('intersoccer_apply_course_combo_discounts')) {
                return intersoccer_apply_course_combo_discounts($courses_by_child);
            }
        }
    }
    
    if (!function_exists('intersoccer_apply_same_season_course_discounts_with_messages')) {
        function intersoccer_apply_same_season_course_discounts_with_messages($courses_by_season_child) {
            error_log("InterSoccer: Using emergency fallback for same-season course discounts");
            if (function_exists('intersoccer_apply_same_season_course_discounts')) {
                return intersoccer_apply_same_season_course_discounts($courses_by_season_child);
            }
        }
    }
    
    if (!function_exists('intersoccer_apply_tournament_combo_discounts_with_messages')) {
        function intersoccer_apply_tournament_combo_discounts_with_messages($tournaments_by_child) {
            error_log("InterSoccer: Using emergency fallback for tournament combo discounts");
            if (function_exists('intersoccer_apply_tournament_combo_discounts')) {
                return intersoccer_apply_tournament_combo_discounts($tournaments_by_child);
            }
        }
    }
}

// Register emergency fallbacks early
add_action('plugins_loaded', 'intersoccer_emergency_discount_fallback', 5);


/**
 * Get current language code
 * Works with WPML, Polylang, or falls back to WordPress locale
 * 
 * @return string Language code (e.g., 'en', 'de', 'fr')
 */
function intersoccer_get_current_language() {
    // Log function call for debugging
    error_log('InterSoccer: intersoccer_get_current_language() called');
    
    // Check for WPML
    if (function_exists('icl_get_current_language')) {
        $lang = icl_get_current_language();
        error_log('InterSoccer: WPML detected, current language: ' . $lang);
        return $lang;
    }
    
    // Check for Polylang
    if (function_exists('pll_current_language')) {
        $lang = pll_current_language();
        error_log('InterSoccer: Polylang detected, current language: ' . $lang);
        return $lang ? $lang : 'en';
    }
    
    // Fallback to WordPress locale
    $locale = get_locale();
    $lang = substr($locale, 0, 2); // Extract language code from locale (e.g., 'en' from 'en_US')
    
    error_log('InterSoccer: No multilingual plugin detected, using WordPress locale: ' . $locale . ' -> ' . $lang);
    
    return $lang;
}



/**
 * Get language name from language code
 * 
 * @param string $lang_code Language code (e.g., 'en', 'de', 'fr')
 * @return string Language name
 */
function intersoccer_get_language_name($lang_code) {
    $languages = intersoccer_get_available_languages();
    return $languages[$lang_code] ?? $lang_code;
}

/**
 * Check if multilingual plugin is active
 * 
 * @return string|false Plugin name if active, false otherwise
 */
function intersoccer_get_multilingual_plugin() {
    if (function_exists('icl_get_current_language')) {
        return 'WPML';
    }
    
    if (function_exists('pll_current_language')) {
        return 'Polylang';
    }
    
    return false;
}

/**
 * Get string translation using available multilingual plugin
 * 
 * @param string $string Original string
 * @param string $context Translation context
 * @param string $name String name/identifier
 * @return string Translated string
 */
function intersoccer_translate_string($string, $context = 'intersoccer-product-variations', $name = '') {
    // WPML String Translation
    if (function_exists('icl_t')) {
        $name = $name ?: md5($string);
        return icl_t($context, $name, $string);
    }
    
    // Polylang string translation (if available)
    if (function_exists('pll__')) {
        return pll__($string);
    }
    
    // WordPress fallback
    return __($string, 'intersoccer-product-variations');
}

/**
 * Register string for translation
 * 
 * @param string $string String to register
 * @param string $context Translation context
 * @param string $name String name/identifier
 * @return bool Success status
 */
function intersoccer_register_string_for_translation($string, $context = 'intersoccer-product-variations', $name = '') {
    // WPML String Translation
    if (function_exists('icl_register_string')) {
        $name = $name ?: md5($string);
        icl_register_string($context, $name, $string);
        error_log("InterSoccer: Registered WPML string - Context: {$context}, Name: {$name}, String: {$string}");
        return true;
    }
    
    // Polylang string registration (if available)
    if (function_exists('pll_register_string')) {
        $name = $name ?: $string;
        pll_register_string($name, $string, $context);
        error_log("InterSoccer: Registered Polylang string - Context: {$context}, Name: {$name}, String: {$string}");
        return true;
    }
    
    error_log("InterSoccer: No multilingual plugin available for string registration: {$string}");
    return false;
}

/**
 * Safe wrapper for getting discount message with language support
 * This replaces the problematic function in discount-messages.php
 * 
 * @param string $rule_id Rule identifier
 * @param string $message_type Type of message ('cart_message', 'customer_note', etc.)
 * @param string $fallback Fallback message if translation not found
 * @return string Localized message
 */
function intersoccer_get_discount_message_safe($rule_id, $message_type = 'cart_message', $fallback = '') {
    // Validate inputs
    if (empty($rule_id) || empty($message_type)) {
        error_log("InterSoccer: Invalid parameters for discount message - Rule ID: {$rule_id}, Type: {$message_type}");
        return $fallback;
    }
    
    try {
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
            error_log("InterSoccer: Rule not found for ID: {$rule_id}");
            return $fallback;
        }
        
        $message_key = $rule['message_key'] ?? $rule_id;
        $current_lang = intersoccer_get_current_language();
        
        // Get message for current language
        $message_data = $discount_messages[$message_key][$current_lang] ?? [];
        $message = $message_data[$message_type] ?? '';
        
        // Fallback to English if not found and current language is not English
        if (empty($message) && $current_lang !== 'en') {
            $message_data = $discount_messages[$message_key]['en'] ?? [];
            $message = $message_data[$message_type] ?? '';
            error_log("InterSoccer: Falling back to English for rule {$rule_id}, type {$message_type}");
        }
        
        // Use fallback if still empty
        if (empty($message)) {
            error_log("InterSoccer: No message found for rule {$rule_id}, type {$message_type}, using fallback");
            $message = $fallback;
        }
        
        // Apply translation if available
        if (!empty($message)) {
            $string_name = "intersoccer_discount_{$rule_id}_{$message_type}";
            $translated = intersoccer_translate_string($message, 'intersoccer-product-variations', $string_name);
            
            if ($translated !== $message) {
                error_log("InterSoccer: Applied translation for {$string_name}");
            }
            
            return $translated;
        }
        
        return $fallback;
        
    } catch (Exception $e) {
        error_log("InterSoccer: Error getting discount message - Rule: {$rule_id}, Type: {$message_type}, Error: " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Initialize language functions and validate dependencies
 * Call this during plugin activation or admin_init
 */
function intersoccer_init_language_support() {
    $multilingual_plugin = intersoccer_get_multilingual_plugin();
    
    if ($multilingual_plugin) {
        error_log("InterSoccer: Multilingual support initialized with {$multilingual_plugin}");
    } else {
        error_log("InterSoccer: No multilingual plugin detected, using WordPress defaults");
    }
    
    // Test the functions
    $current_lang = intersoccer_get_current_language();
    $available_langs = intersoccer_get_available_languages();
    
    error_log("InterSoccer: Language support test - Current: {$current_lang}, Available: " . implode(', ', array_keys($available_langs)));
}

// Initialize on admin_init to ensure all plugins are loaded
add_action('admin_init', 'intersoccer_init_language_support', 15);

// Initialize on init for frontend
add_action('init', 'intersoccer_init_language_support', 15);

error_log('InterSoccer: Language helper functions loaded');

add_action('admin_init', 'intersoccer_initialize_default_messages');

error_log('InterSoccer: Loaded discount messages integration with WPML support');
?>