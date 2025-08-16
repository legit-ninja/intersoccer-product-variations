<?php
/**
 * File: checkout-calculations.php
 * Description: Handles checkout and post-purchase logic, including download permissions and future player management for InterSoccer WooCommerce.
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

// Placeholder for future player management integrations (e.g., manage-players endpoint)
function intersoccer_handle_player_assignment($order_id) {
    // Future logic: Assign players post-checkout, update rosters, etc.
    error_log('InterSoccer: Placeholder for player assignment in order ' . $order_id);
}
// add_action('woocommerce_checkout_order_processed', 'intersoccer_handle_player_assignment', 10, 1);

error_log('InterSoccer: Loaded checkout-calculations.php');

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
 * Helper function to get all parent product attributes (non-variation)
 */
function intersoccer_get_parent_product_attributes($product_id, $variation_id = null) {
    $parent_product = wc_get_product($product_id);
    $variation_product = $variation_id ? wc_get_product($variation_id) : null;
    
    if (!$parent_product) {
        error_log('InterSoccer: Invalid parent product ID: ' . $product_id);
        return [];
    }
    
    $attributes = [];
    $parent_attributes = $parent_product->get_attributes();
    $variation_attributes = $variation_product ? $variation_product->get_variation_attributes() : [];
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Parent attributes for product ' . $product_id . ': ' . print_r($parent_attributes, true));
        error_log('InterSoccer: Variation attributes for variation ' . $variation_id . ': ' . print_r($variation_attributes, true));
    }
    
    foreach ($parent_attributes as $attribute_name => $attribute) {
        // Skip if this attribute is used for variations
        if (isset($variation_attributes['attribute_' . $attribute_name]) || isset($variation_attributes[$attribute_name])) {
            continue;
        }
        
        $label = wc_attribute_label($attribute_name);
        $value = '';
        
        if (is_object($attribute) && $attribute instanceof WC_Product_Attribute) {
            // Taxonomy attribute
            if ($attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product_id, $attribute_name, ['fields' => 'names']);
                $value = !empty($terms) ? implode(', ', $terms) : '';
            } else {
                // Custom attribute
                $value = $attribute->get_options() ? implode(', ', $attribute->get_options()) : '';
            }
        } elseif (is_array($attribute)) {
            // Legacy format
            $value = isset($attribute['value']) ? $attribute['value'] : '';
        }
        
        if (!empty($value)) {
            $attributes[$label] = $value;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added parent attribute: ' . $label . ' = ' . $value);
            }
        }
    }
    
    return $attributes;
}

/**
 * Add custom metadata to order line item during checkout.
 * This is the main function that should capture all cart item data and save it to the order.
 *
 * @param WC_Order_Item_Product $item Order item.
 * @param string $cart_item_key Cart item key.
 * @param array $values Cart item values.
 * @param WC_Order $order Order object.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_add_order_item_meta', 20, 4);
function intersoccer_add_order_item_meta($item, $cart_item_key, $values, $order) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Adding order item meta for cart item ' . $cart_item_key);
        error_log('InterSoccer: Cart values: ' . print_r($values, true));
    }

    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'] ?? 0;
    $product_type = intersoccer_get_product_type($product_id);
    $user_id = $order->get_user_id();

    // 1. Handle Assigned Player/Attendee
    if (isset($values['assigned_player']) || isset($values['player_assignment'])) {
        $player_index = $values['assigned_player'] ?? $values['player_assignment'] ?? null;
        
        if ($player_index !== null) {
            $player_details = intersoccer_get_player_details($user_id, $player_index);
            
            if (!empty($player_details['name']) && $player_details['name'] !== 'Unknown Player') {
                $item->add_meta_data('Assigned Attendee', $player_details['name']);
                $item->add_meta_data('assigned_player', $player_index); // Keep index for reference
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Added Assigned Attendee: ' . $player_details['name'] . ' (index: ' . $player_index . ')');
                }
            } else {
                error_log('InterSoccer: Could not resolve player details for index ' . $player_index . ' and user ' . $user_id);
            }
        }
    }

    // 2. Load product objects
    $product = wc_get_product($variation_id ?: $product_id);
    $parent_product = $variation_id ? wc_get_product($product_id) : $product;
    
    if (!$product || !$parent_product) {
        error_log('InterSoccer: Invalid product objects for cart item ' . $cart_item_key);
        return;
    }

    // 3. Add all variation attributes (these are already handled by WooCommerce but let's ensure they're there)
    if ($variation_id && $product instanceof WC_Product_Variation) {
        $variation_attributes = $product->get_variation_attributes();
        foreach ($variation_attributes as $attribute_name => $attribute_value) {
            if (!empty($attribute_value)) {
                $label = wc_attribute_label(str_replace('attribute_', '', $attribute_name));
                
                // Check if this meta already exists to avoid duplicates
                $existing_value = $item->get_meta($label);
                if (empty($existing_value)) {
                    $item->add_meta_data($label, $attribute_value);
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('InterSoccer: Added variation attribute: ' . $label . ' = ' . $attribute_value);
                    }
                }
            }
        }
    }

    // 4. Add parent product attributes (non-variation attributes)
    $parent_attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
    foreach ($parent_attributes as $label => $value) {
        // Check if this meta already exists to avoid duplicates
        $existing_value = $item->get_meta($label);
        if (empty($existing_value)) {
            $item->add_meta_data($label, $value);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added parent attribute: ' . $label . ' = ' . $value);
            }
        }
    }

    // 5. Add product type specific metadata
    if ($product_type === 'camp') {
        // Camp-specific metadata
        if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
            $days_selected = implode(', ', array_map('sanitize_text_field', $values['camp_days']));
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

    // 6. Add Variation ID for reference (if not already added)
    if ($variation_id) {
        $existing_variation_id = $item->get_meta('Variation ID');
        if (empty($existing_variation_id)) {
            $item->add_meta_data('Variation ID', $variation_id);
        }
    }

    // 7. Add Season information (important for reporting)
    $season = intersoccer_get_product_season($product_id);
    if ($season) {
        $item->add_meta_data('Season', $season);
    }

    // 8. Add Activity Type for clarity
    $activity_type = ucfirst($product_type ?: 'Unknown');
    $item->add_meta_data('Activity Type', $activity_type);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Completed adding order item meta for cart item ' . $cart_item_key);
    }
}

/**
 * Display custom order item metadata in admin order details.
 *
 * @param int $item_id Order item ID.
 * @param WC_Order_Item $item Order item.
 * @param WC_Product $product Product object.
 */
add_action('woocommerce_admin_order_item_values', 'intersoccer_display_order_item_meta_admin', 10, 3);
function intersoccer_display_order_item_meta_admin($product, $item, $item_id) {
    // Display key metadata in admin
    $important_meta = [
        'Assigned Attendee',
        'Days Selected',
        'Remaining Sessions',
        'Start Date',
        'End Date',
        'Season',
        'Activity Type'
    ];
    
    foreach ($important_meta as $meta_key) {
        $meta_value = $item->get_meta($meta_key);
        if (!empty($meta_value)) {
            echo '<div class="intersoccer-order-meta"><strong>' . esc_html($meta_key) . ':</strong> ' . esc_html($meta_value) . '</div>';
        }
    }
}

/**
 * Ensure cart item data includes assigned_player in the correct format
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_ensure_player_assignment_format', 5, 3);
function intersoccer_ensure_player_assignment_format($cart_item_data, $product_id, $variation_id) {
    // Normalize player assignment field names
    if (isset($_POST['player_assignment']) && !isset($cart_item_data['assigned_player'])) {
        $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Normalized player_assignment to assigned_player: ' . $cart_item_data['assigned_player']);
        }
    }
    
    return $cart_item_data;
}
?>