<?php
/**
 * File: checkout-calculations.php
 * Description: Handles checkout and post-purchase logic, including download permissions and player management for InterSoccer WooCommerce.
 * Dependencies: WooCommerce, product-types.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grant download permissions after order completion.
 */
add_action('woocommerce_order_status_completed', 'intersoccer_grant_download_permissions', 10, 1);
function intersoccer_grant_download_permissions($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' for granting download permissions');
        return;
    }

    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);

        if (!$product) {
            error_log('InterSoccer: Invalid product for order item ' . $item_id . ' in order ' . $order_id);
            continue;
        }

        $product_type = InterSoccer_Product_Types::get_product_type($product_id);
        if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
            continue;
        }

        $downloads = get_post_meta($product_id, '_intersoccer_downloads', true);
        if (!is_array($downloads) || empty($downloads)) {
            continue;
        }

        foreach ($downloads as $download) {
            $file_id = md5($download['file']);
            $download_data = [
                'product_id' => $product_id,
                'order_id' => $order_id,
                'user_id' => $order->get_customer_id(),
                'download_id' => $file_id,
                'downloads_remaining' => !empty($download['limit']) ? $download['limit'] : '',
                'access_expires' => !empty($download['expiry']) ? date('Y-m-d H:i:s', strtotime('+' . $download['expiry'] . ' days')) : null,
                'file' => [
                    'name' => $download['name'],
                    'file' => $download['file']
                ]
            ];

            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s",
                $order_id,
                $product_id,
                $file_id
            ));

            if (!$exists) {
                $download_obj = new WC_Customer_Download();
                $download_obj->set_data($download_data);
                $download_obj->save();
                error_log('InterSoccer: Granted download permission for file ' . $download['name'] . ' to order ' . $order_id);
            }
        }
    }
}

/**
 * Helper function to get player details from user metadata
 */
function intersoccer_get_player_details($user_id, $player_index) {
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    
    if (isset($players[$player_index])) {
        $player = $players[$player_index];
        return [
            'first_name' => $player['first_name'] ?? '',
            'last_name' => $player['last_name'] ?? '',
            'name' => trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
            'dob' => $player['dob'] ?? '',
            'gender' => $player['gender'] ?? '',
            'medical_conditions' => $player['medical_conditions'] ?? ''
        ];
    }
    
    return [
        'first_name' => 'Unknown',
        'last_name' => 'Player',
        'name' => 'Unknown Player',
        'dob' => '',
        'gender' => '',
        'medical_conditions' => ''
    ];
}

/**
 * Helper function to get parent product attributes (excluding variation attributes)
 */
function intersoccer_get_parent_product_attributes($product_id, $variation_id = null) {
    $parent_product = wc_get_product($product_id);
    $variation_product = $variation_id ? wc_get_product($variation_id) : null;
    
    if (!$parent_product) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Invalid parent product ID: ' . $product_id);
        }
        return [];
    }
    
    $attributes = [];
    $parent_attributes = $parent_product->get_attributes();
    $variation_attributes = $variation_product ? $variation_product->get_variation_attributes() : [];
    
    // Normalize variation attribute keys (remove 'attribute_' prefix)
    $variation_attribute_keys = array_map(function($key) {
        return str_replace('attribute_', '', $key);
    }, array_keys($variation_attributes));
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Parent attributes for product ' . $product_id . ': ' . print_r($parent_attributes, true));
        error_log('InterSoccer: Variation attributes for variation ' . ($variation_id ?: 'none') . ': ' . print_r($variation_attributes, true));
        error_log('InterSoccer: Normalized variation attribute keys: ' . print_r($variation_attribute_keys, true));
    }
    
    // Define chronological order for days of the week
    $days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    
    foreach ($parent_attributes as $attribute_name => $attribute) {
        // Skip if attribute is used in variation
        if (in_array($attribute_name, $variation_attribute_keys)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Skipping variation attribute: ' . $attribute_name);
            }
            continue;
        }
        
        if ($attribute->is_taxonomy()) {
            $terms = wc_get_product_terms($product_id, $attribute_name, ['fields' => 'names']);
            if ($attribute_name === 'pa_days-of-week' && !empty($terms)) {
                // Sort days of the week chronologically
                $terms = array_intersect($days_order, $terms);
                $value = !empty($terms) ? implode(', ', array_values($terms)) : '';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Sorted Days of Week for product ' . $product_id . ': ' . $value);
                }
            } else {
                $value = !empty($terms) ? implode(', ', $terms) : '';
            }
        } else {
            $value = $attribute->get_options() ? implode(', ', $attribute->get_options()) : '';
        }
        
        if ($value) {
            $label = wc_attribute_label($attribute_name);
            $attributes[$label] = $value;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Including parent attribute: ' . $label . ' = ' . $value);
            }
        }
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Final attributes returned for product ' . $product_id . ': ' . print_r($attributes, true));
    }
    
    return $attributes;
}

/**
 * Add custom metadata to order items during checkout
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_add_order_item_meta', 10, 4);
function intersoccer_add_order_item_meta($item, $cart_item_key, $values, $order) {
    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'] ?? 0;
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Adding custom meta to order item for product ' . $product_id . ' / variation ' . $variation_id . '. Cart values: ' . json_encode($values));
    }

    // 1. Add player details
    if (isset($values['assigned_player']) && $values['assigned_player'] !== '') {
        $user_id = $order->get_customer_id();
        $player_details = intersoccer_get_player_details($user_id, $values['assigned_player']);
        
        $item->add_meta_data('Assigned Attendee', $player_details['name']);
        if (!empty($player_details['dob'])) {
            $item->add_meta_data('Attendee DOB', $player_details['dob']);
        }
        if (!empty($player_details['gender'])) {
            $item->add_meta_data('Attendee Gender', $player_details['gender']);
        }
        if (!empty($player_details['medical_conditions'])) {
            $item->add_meta_data('Medical Conditions', $player_details['medical_conditions']);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added player meta for cart item ' . $cart_item_key . ': ' . $player_details['name']);
        }
    }

    // 2. Add parent product attributes (excluding variation attributes)
    $product = wc_get_product($variation_id ?: $product_id);
    $parent_id = $variation_id ? $product->get_parent_id() : $product_id;
    $parent = wc_get_product($parent_id);
    $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
    
    // Get existing meta keys to avoid duplicates
    $existing_meta = $item->get_meta_data();
    $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);

    foreach ($attributes as $attr_slug => $value) {
        $label = wc_attribute_label($attr_slug);
        // Skip if attribute is already in order meta
        if (in_array($label, $existing_keys)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Skipping attribute ' . $label . ' (already exists)');
            }
            continue;
        }
        $item->add_meta_data($label, $value);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added parent attribute: ' . $label . ' = ' . $value);
        }
    }

    // 3. Add product type specific metadata
    if ($product_type === 'camp') {
        // Camp-specific metadata
        if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
            $days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            $sorted_days = array_intersect($days_order, array_map('sanitize_text_field', $values['camp_days']));
            $days_selected = implode(', ', array_values($sorted_days));
            $item->add_meta_data('Days Selected', $days_selected);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Days Selected: ' . $days_selected);
            }
        }
        
        if (isset($values['discount_note']) && !empty($values['discount_note'])) {
            $item->add_meta_data('Discount', sanitize_text_field($values['discount_note']));
        }
        
        // Camp times from meta
        $camp_times = get_post_meta($variation_id ?: $product_id, '_camp_times', true);
        if ($camp_times) {
            $item->add_meta_data('Camp Times', sanitize_text_field($camp_times));
        }
        
    } elseif ($product_type === 'course') {
        // Course-specific metadata
        $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
        if ($start_date) {
            $formatted_start = date_i18n('F j, Y', strtotime($start_date));
            $item->add_meta_data('Start Date', $formatted_start);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Start Date: ' . $formatted_start);
            }
        }
        
        $end_date = get_post_meta($variation_id ?: $product_id, '_end_date', true);
        if ($end_date) {
            $formatted_end = date_i18n('F j, Y', strtotime($end_date));
            $item->add_meta_data('End Date', $formatted_end);
        }
        
        $holidays = get_post_meta($variation_id ?: $product_id, '_course_holiday_dates', true) ?: [];
        if (!empty($holidays) && is_array($holidays)) {
            $formatted_holidays = array_map(function($date) {
                return date_i18n('F j, Y', strtotime($date));
            }, $holidays);
            $item->add_meta_data('Holidays', implode(', ', $formatted_holidays));
        }
        
        if (isset($values['remaining_sessions']) && is_numeric($values['remaining_sessions'])) {
            $item->add_meta_data('Remaining Sessions', absint($values['remaining_sessions']));
        }
        
        if (isset($values['discount_note']) && !empty($values['discount_note'])) {
            $item->add_meta_data('Discount', sanitize_text_field($values['discount_note']));
        }
    }

    // 4. Add Base Price and Discount Amount
    if (isset($values['base_price'])) {
        $item->add_meta_data('Base Price', wc_price($values['base_price']));
    }
    if (isset($values['discount_amount']) && $values['discount_amount'] > 0) {
        $item->add_meta_data('Discount Amount', wc_price($values['discount_amount']));
    }

    // 5. Add Variation ID for reference (if not already added)
    if ($variation_id) {
        $existing_variation_id = $item->get_meta('Variation ID');
        if (empty($existing_variation_id)) {
            $item->add_meta_data('Variation ID', $variation_id);
        }
    }

    // 6. Add Season information (important for reporting)
    $season = intersoccer_get_product_season($product_id);
    if ($season) {
        $item->add_meta_data('Season', $season);
    }

    // 7. Add Activity Type for clarity
    $activity_type = ucfirst($product_type ?: 'Unknown');
    $item->add_meta_data('Activity Type', $activity_type);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Completed adding order item meta for cart item ' . $cart_item_key);
        error_log('InterSoccer: Checkout metadata - Assigned Attendee: ' . ($values['assigned_attendee'] ?? 'none') . ', Discount: ' . ($values['discount_note'] ?? 'none'));
    }
}
/**
 * Display custom metadata in checkout review order table
 */
add_filter('woocommerce_checkout_cart_item', 'intersoccer_display_checkout_cart_item_metadata', 10, 3);
function intersoccer_display_checkout_cart_item_metadata($cart_item_name, $cart_item, $cart_item_key) {
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type && in_array($product_type, ['camp', 'course', 'birthday'])) {
        $meta_output = '';
        
        // Assigned Attendee
        if (isset($cart_item['assigned_attendee']) && !empty($cart_item['assigned_attendee'])) {
            $meta_output .= '<div class="intersoccer-checkout-meta"><strong>' . esc_html__('Assigned Attendee', 'intersoccer-product-variations') . ':</strong> ' . esc_html($cart_item['assigned_attendee']) . '</div>';
        }

        // Discount Note
        if (isset($cart_item['discount_note']) && !empty($cart_item['discount_note'])) {
            $meta_output .= '<div class="intersoccer-checkout-meta intersoccer-discount"><strong>' . esc_html__('Discount', 'intersoccer-product-variations') . ':</strong> ' . esc_html($cart_item['discount_note']) . '</div>';
        }

        if ($meta_output) {
            $cart_item_name .= '<div class="intersoccer-checkout-meta-wrapper">' . $meta_output . '</div>';
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Checkout display metadata - Item Key: ' . $cart_item_key . ', Attendee: ' . ($cart_item['assigned_attendee'] ?? 'none') . ', Discount: ' . ($cart_item['discount_note'] ?? 'none'));
        }
    }

    return $cart_item_name;
}

/**
 * Display custom order item metadata in admin order details.
 */
// add_action('woocommerce_admin_order_item_values', 'intersoccer_display_order_item_meta_admin', 10, 3);
// function intersoccer_display_order_item_meta_admin($product, $item, $item_id) {
//     $important_meta = [
//         'Assigned Attendee',
//         'Days Selected',
//         'Remaining Sessions',
//         'Start Date',
//         'End Date',
//         'Season',
//         'Activity Type',
//         'Discount'
//     ];
    
//     foreach ($important_meta as $meta_key) {
//         $meta_value = $item->get_meta($meta_key);
//         if (!empty($meta_value)) {
//             echo '<div class="intersoccer-order-meta"><strong>' . esc_html($meta_key) . ':</strong> ' . esc_html($meta_value) . '</div>';
//         }
//     }
// }

/**
 * Ensure cart item data includes assigned_player in the correct format
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_ensure_player_assignment_format', 5, 3);
function intersoccer_ensure_player_assignment_format($cart_item_data, $product_id, $variation_id) {
    if (isset($_POST['player_assignment']) && !isset($cart_item_data['assigned_player'])) {
        $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Normalized player_assignment to assigned_player: ' . $cart_item_data['assigned_player']);
        }
    }
    
    return $cart_item_data;
}

/**
 * Placeholder for future player management integrations
 */
function intersoccer_handle_player_assignment($order_id) {
    error_log('InterSoccer: Placeholder for player assignment in order ' . $order_id);
}
// add_action('woocommerce_checkout_order_processed', 'intersoccer_handle_player_assignment', 10, 1);

error_log('InterSoccer: Loaded checkout-calculations.php');
?>