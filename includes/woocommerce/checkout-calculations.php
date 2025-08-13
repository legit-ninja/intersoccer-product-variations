```php
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
 * Add custom metadata to order line item during checkout.
 *
 * @param WC_Order_Item_Product $item Order item.
 * @param string $cart_item_key Cart item key.
 * @param array $values Cart item values.
 * @param WC_Order $order Order object.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_add_order_item_meta', 10, 4);
function intersoccer_add_order_item_meta($item, $cart_item_key, $values, $order) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Adding custom meta to order item for product ' . $values['product_id'] . ' / variation ' . $values['variation_id'] . '. Cart values: ' . json_encode($values));
    }

    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'];
    $product_type = intersoccer_get_product_type($product_id);

    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log('InterSoccer: No user ID for order ' . $order->get_id() . ' - Cannot resolve player name');
    }

    // Assigned Attendee (name + index)
    if (isset($values['assigned_player'])) {
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        $index = absint($values['assigned_player']);
        if (isset($players[$index]['name'])) {
            $item->add_meta_data('Assigned Attendee', sanitize_text_field($players[$index]['name']));
            $item->add_meta_data('assigned_player', $index); // Store index for reference
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Assigned Attendee: ' . $players[$index]['name'] . ', index: ' . $index);
            }
        }
    }

    // Load product and parent
    $product = wc_get_product($variation_id ?: $product_id);
    $parent_id = $variation_id ? $product->get_parent_id() : $product_id;
    $parent = wc_get_product($parent_id);
    if (!$product || !$parent) {
        error_log('InterSoccer: Invalid product/parent for item ' . $cart_item_key . ': product_id=' . $product_id . ', variation_id=' . $variation_id);
        return;
    }

    // Get existing meta keys to avoid duplicates
    $existing_meta = $item->get_meta_data();
    $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Existing meta keys: ' . json_encode($existing_keys));
    }

    // Add all attributes (parent + variation)
    $attributes = array_merge($parent->get_attributes(), $product->get_attributes());
    foreach ($attributes as $attr_slug => $attribute) {
        $label = wc_attribute_label($attr_slug);
        if (in_array($label, $existing_keys)) {
            continue;
        }

        $value = $product->get_attribute($attr_slug);
        if (empty($value) && $variation_id) {
            $value = $parent->get_attribute($attr_slug); // Fallback to parent
        }
        if (!empty($value)) {
            $item->add_meta_data($label, sanitize_text_field($value));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added attribute meta: ' . $label . ' = ' . $value);
            }
        }
    }

    // Add custom meta based on product type
    if ($product_type === 'camp') {
        if (isset($values['camp_days']) && is_array($values['camp_days'])) {
            $days_selected = implode(', ', array_map('sanitize_text_field', $values['camp_days']));
            $item->add_meta_data('Days Selected', $days_selected);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Days Selected: ' . $days_selected);
            }
        }
        if (isset($values['discount_note'])) {
            $item->add_meta_data('Discount', sanitize_text_field($values['discount_note']));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Discount: ' . $values['discount_note']);
            }
        }
        $camp_times = get_post_meta($variation_id ?: $product_id, '_camp_times', true);
        if ($camp_times) {
            $item->add_meta_data('Camp Times', sanitize_text_field($camp_times));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Camp Times: ' . $camp_times);
            }
        }
    } elseif ($product_type === 'course') {
        $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
        if ($start_date) {
            $item->add_meta_data('Start Date', sanitize_text_field($start_date));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Start Date: ' . $start_date);
            }
        }
        $end_date = get_post_meta($variation_id ?: $product_id, '_end_date', true);
        if ($end_date) {
            $item->add_meta_data('End Date', sanitize_text_field($end_date));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added End Date: ' . $end_date);
            }
        }
        $holidays = get_post_meta($variation_id ?: $product_id, '_course_holiday_dates', true) ?: [];
        if (!empty($holidays)) {
            $holidays_str = implode(', ', array_map('sanitize_text_field', $holidays));
            $item->add_meta_data('Holidays', $holidays_str);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Holidays: ' . $holidays_str);
            }
        }
        if (isset($values['remaining_sessions'])) {
            $item->add_meta_data('Remaining Weeks', absint($values['remaining_sessions']));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Remaining Weeks: ' . $values['remaining_sessions']);
            }
        }
        if (isset($values['discount_note'])) {
            $item->add_meta_data('Discount', sanitize_text_field($values['discount_note']));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Added Discount: ' . $values['discount_note']);
            }
        }
    }

    // Variation ID (for reference, if not already added)
    if ($variation_id && !in_array('Variation ID', $existing_keys)) {
        $item->add_meta_data('Variation ID', $variation_id);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added Variation ID: ' . $variation_id);
        }
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
    if ($assigned_attendee = $item->get_meta('Assigned Attendee')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Assigned Attendee', 'intersoccer-product-variations') . ':</strong> ' . esc_html($assigned_attendee) . '</div>';
    }
    if ($days_selected = $item->get_meta('Days Selected')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Days Selected', 'intersoccer-product-variations') . ':</strong> ' . esc_html($days_selected) . '</div>';
    }
    if ($remaining_weeks = $item->get_meta('Remaining Weeks')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Remaining Weeks', 'intersoccer-product-variations') . ':</strong> ' . esc_html($remaining_weeks) . '</div>';
    }
    if ($discount_note = $item->get_meta('Discount')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Discount Note', 'intersoccer-product-variations') . ':</strong> ' . esc_html($discount_note) . '</div>';
    }
    if ($start_date = $item->get_meta('Start Date')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Start Date', 'intersoccer-product-variations') . ':</strong> ' . esc_html($start_date) . '</div>';
    }
    if ($end_date = $item->get_meta('End Date')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('End Date', 'intersoccer-product-variations') . ':</strong> ' . esc_html($end_date) . '</div>';
    }
    if ($holidays = $item->get_meta('Holidays')) {
        echo '<div class="intersoccer-order-meta"><strong>' . __('Holidays', 'intersoccer-product-variations') . ':</strong> ' . esc_html($holidays) . '</div>';
    }
}
?>
```