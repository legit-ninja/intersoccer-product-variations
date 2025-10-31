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

    // Map of attribute names to their registered English labels for translation
    $label_map = [
        'pa_intersoccer-venues' => 'Sites InterSoccer',
        'pa_course-day' => 'Course Day',
        'pa_age-group' => 'Age Group',
        'pa_course-times' => 'Course Times',
        'pa_activity-type' => 'Activity Type',
        'pa_program-season' => 'Season',
        'pa_booking-type' => 'Booking Type',
        'pa_canton-region' => 'Canton / Region',
        'pa_city' => 'City',
        'pa_note' => 'Note',
    ];

    // Build a map of variation attributes keyed by the taxonomy/attribute name
    // e.g. 'pa_city' => 'geneva'
    $variation_map = [];
    foreach ($variation_attributes as $vkey => $vval) {
        $clean = str_replace('attribute_', '', $vkey);
        $variation_map[$clean] = $vval;
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Parent attributes for product ' . $product_id . ': ' . print_r($parent_attributes, true));
        error_log('InterSoccer: Variation attributes for variation ' . ($variation_id ?: 'none') . ': ' . print_r($variation_attributes, true));
        error_log('InterSoccer: Variation map: ' . print_r($variation_map, true));
    }
    
    // Define canonical lowercase order for weekdays (used to order day slugs)
    $canonical_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    foreach ($parent_attributes as $attribute_name => $attribute) {
        $value = '';

        // If variation explicitly sets this attribute, prefer the variation value(s)
        if (isset($variation_map[$attribute_name]) && !empty($variation_map[$attribute_name])) {
            $selected = $variation_map[$attribute_name];
            // Variation values are usually slugs for taxonomy attributes
            if ($attribute->is_taxonomy()) {
                $slugs = array_map('trim', explode(',', $selected));
                // For days-of-week, respect canonical ordering
                if (in_array($attribute_name, ['pa_days-of-week', 'pa_course-day'])) {
                    $ordered = array_values(array_intersect($canonical_days, array_map('strtolower', $slugs)));
                    $names = [];
                    foreach ($ordered as $slug) {
                        $term = get_term_by('slug', $slug, $attribute_name);
                        if ($term && !is_wp_error($term)) {
                            $translated_name = $term->name;
                            if (function_exists('icl_object_id')) {
                                $translated_term_id = icl_object_id($term->term_id, $attribute_name, false, ICL_LANGUAGE_CODE);
                                if ($translated_term_id && $translated_term_id != $term->term_id) {
                                    $translated_term = get_term($translated_term_id, $attribute_name);
                                    if ($translated_term && !is_wp_error($translated_term)) {
                                        $translated_name = $translated_term->name;
                                    }
                                }
                            }
                            $names[] = $translated_name;
                        }
                    }
                    $value = !empty($names) ? implode(', ', $names) : '';
                } else {
                    $names = [];
                    foreach ($slugs as $slug) {
                        $term = get_term_by('slug', $slug, $attribute_name);
                        if ($term && !is_wp_error($term)) {
                            $translated_name = $term->name;
                            if (function_exists('icl_object_id')) {
                                $translated_term_id = icl_object_id($term->term_id, $attribute_name, false, ICL_LANGUAGE_CODE);
                                if ($translated_term_id && $translated_term_id != $term->term_id) {
                                    $translated_term = get_term($translated_term_id, $attribute_name);
                                    if ($translated_term && !is_wp_error($translated_term)) {
                                        $translated_name = $translated_term->name;
                                    }
                                }
                            }
                            $names[] = $translated_name;
                        }
                    }
                    $value = !empty($names) ? implode(', ', $names) : '';
                }
            } else {
                // Non-taxonomy variation attribute: use the raw value(s)
                $value = $selected;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Using variation-selected attribute for ' . $attribute_name . ': ' . $value);
            }
        } else {
            // No explicit variation selection: fall back to parent product terms/options
            if ($attribute->is_taxonomy()) {
                // Days-of-week get canonical ordered list
                if (in_array($attribute_name, ['pa_days-of-week', 'pa_course-day'])) {
                    $term_slugs = wc_get_product_terms($product_id, $attribute_name, ['fields' => 'slugs']);
                    $ordered_slugs = array_values(array_intersect($canonical_days, $term_slugs));
                    $names = [];
                    foreach ($ordered_slugs as $slug) {
                        $term = get_term_by('slug', $slug, $attribute_name);
                        if ($term && !is_wp_error($term)) {
                            $names[] = $term->name;
                        }
                    }
                    $value = !empty($names) ? implode(', ', $names) : '';
                } else {
                    // For other taxonomy attributes, only include if unambiguous (single parent term)
                    $terms = wc_get_product_terms($product_id, $attribute_name, ['fields' => 'names']);
                    if (is_array($terms) && count($terms) === 1) {
                        $value = $terms[0];
                    } else {
                        // Skip ambiguous parent lists to avoid dumping all venues, etc.
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('InterSoccer: Skipping ambiguous parent taxonomy ' . $attribute_name . ' (parent terms: ' . print_r($terms, true) . ')');
                        }
                        $value = '';
                    }
                }
            } else {
                $value = $attribute->get_options() ? implode(', ', $attribute->get_options()) : '';
            }
        }

        if ($value) {
            $label = wc_attribute_label($attribute_name);
            // If wc_attribute_label doesn't give us a proper label, format the attribute name
            if (empty($label) || $label === $attribute_name) {
                // Remove 'pa_' prefix if present and format the name
                $clean_name = preg_replace('/^pa_/', '', $attribute_name);
                $label = ucwords(str_replace(['-', '_'], ' ', $clean_name));
            }
            // Use registered English label for translation if available
            $english_label = $label_map[$attribute_name] ?? $label;
            $label = function_exists('icl_t') ? icl_t('intersoccer-product-variations', $english_label, $english_label) : $english_label;
            $attributes[$label] = $value;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Including parent attribute: ' . $label . ' = ' . $value . ' (from: ' . $attribute_name . ')');
            }
        }
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Final attributes returned for product ' . $product_id . ': ' . print_r($attributes, true));
    }
    
    return $attributes;
}

/**
 * Add custom order item metadata during checkout.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_add_order_item_metadata', 10, 4);
function intersoccer_add_order_item_metadata($item, $cart_item_key, $values, $order) {
    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'];
    $quantity = $values['quantity'];
    $product_type = intersoccer_get_product_type($product_id);

    if (!$product_type || !in_array($product_type, ['camp', 'course', 'birthday'])) {
        return;
    }

    // 1. Add Assigned Attendee
    if (isset($values['assigned_attendee']) && !empty($values['assigned_attendee'])) {
        $item->add_meta_data('Assigned Attendee', sanitize_text_field($values['assigned_attendee']));
        if ($values['assigned_player'] !== null) {
            $item->add_meta_data('Player Index', absint($values['assigned_player']));
        }
    }

    // 2. Add Camp-specific metadata
    if ($product_type === 'camp') {
        if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
            $item->add_meta_data('Days Selected', implode(', ', array_map('sanitize_text_field', $values['camp_days'])));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added camp_days metadata: ' . implode(', ', $values['camp_days']) . ' for quantity ' . $quantity);
            }
        }

        // Add late pickup metadata
        if (isset($values['late_pickup_type']) && $values['late_pickup_type'] !== 'none') {
            $item->add_meta_data('Late Pickup Type', $values['late_pickup_type'] === 'full-week' ? 'Full Week' : 'Single Day(s)');
            if ($values['late_pickup_type'] === 'single-days' && isset($values['late_pickup_days']) && is_array($values['late_pickup_days'])) {
                $item->add_meta_data('Late Pickup Days', implode(', ', array_map('sanitize_text_field', $values['late_pickup_days'])));
            }
            if (isset($values['late_pickup_cost']) && $values['late_pickup_cost'] > 0) {
                $item->add_meta_data('Late Pickup Cost', wc_price($values['late_pickup_cost']));
            }
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
        $season_key = function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Season', 'Season') : 'Season';
        $item->add_meta_data($season_key, $season);
    }

    // 7. Add Activity Type for clarity
    $activity_type = ucfirst($product_type ?: 'Unknown');
    $activity_type = function_exists('icl_t') ? icl_t('intersoccer-product-variations', $activity_type, $activity_type) : $activity_type;
    $activity_type_key = function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Activity Type', 'Activity Type') : 'Activity Type';
    $item->add_meta_data($activity_type_key, $activity_type);

    // 8. Add parent product attributes (excluding variation attributes)
    $parent_attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
    foreach ($parent_attributes as $attribute_label => $attribute_value) {
        // Skip attributes that are already added separately to avoid duplication
        if (in_array($attribute_label, ['Activity Type', 'Season'])) {
            continue;
        }
        // Convert attribute label to metadata key format (e.g., "City" -> "City")
        $meta_key = $attribute_label;
        $item->add_meta_data($meta_key, sanitize_text_field($attribute_value));
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added parent attribute metadata: ' . $meta_key . ' = ' . $attribute_value);
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Completed adding order item meta for cart item ' . $cart_item_key . ', Quantity: ' . $quantity);
        error_log('InterSoccer: Checkout metadata - Assigned Attendee: ' . ($values['assigned_attendee'] ?? 'none') . ', Discount: ' . ($values['discount_note'] ?? 'none') . ', Late Pickup Type: ' . ($values['late_pickup_type'] ?? 'none'));
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
 * Ensure cart item data includes assigned_player in the correct format
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_ensure_player_assignment_format', 5, 4);
function intersoccer_ensure_player_assignment_format($cart_item_data, $product_id, $variation_id, $quantity) {
    if (isset($_POST['player_assignment']) && !isset($cart_item_data['assigned_player'])) {
        $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Normalized player_assignment to assigned_player: ' . $cart_item_data['assigned_player'] . ', Quantity: ' . $quantity);
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