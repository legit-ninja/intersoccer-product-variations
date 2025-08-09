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
    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log('InterSoccer: No user ID for order ' . $order->get_id() . ' - Cannot resolve player name');
    }

    // Resolve and add assigned attendee
    if (isset($values['assigned_attendee'])) {
        $assigned_index = $values['assigned_attendee'];
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        if (isset($players[$assigned_index])) {
            $player = $players[$assigned_index];
            $assigned_name = $player['first_name'] . ' ' . $player['last_name'];
            $item->add_meta_data(__('Assigned Attendee', 'intersoccer-product-variations'), $assigned_name, true);
            $item->add_meta_data('_assigned_player_index', $assigned_index, true); // Hidden meta for reference
            error_log('InterSoccer: Resolved and added Assigned Attendee to order item metadata for order ' . $order->get_id() . ': Index ' . $assigned_index . ' -> ' . $assigned_name);
        } else {
            $item->add_meta_data(__('Assigned Attendee', 'intersoccer-product-variations'), __('Unknown Player (Index: ' . $assigned_index . ')'), true);
            $item->add_meta_data('_assigned_player_index', $assigned_index, true);
            error_log('InterSoccer: Failed to resolve player index ' . $assigned_index . ' for user ' . $user_id . ' in order ' . $order->get_id() . ' - Players count: ' . count($players) . ' - Cart values: ' . print_r($values, true));
        }
    } else {
        error_log('InterSoccer: No assigned_attendee found in cart values for item ' . $cart_item_key . ' in order ' . $order->get_id() . ' - Values: ' . print_r($values, true));
    }

    // Add camp days and discount note for camps
    if (isset($values['camp_days'])) {
        $days_selected = implode(', ', $values['camp_days']);
        $item->add_meta_data(__('Days Selected', 'intersoccer-product-variations'), $days_selected, true);
        if (isset($values['discount_note'])) {
            $item->add_meta_data(__('Discount', 'intersoccer-product-variations'), $values['discount_note'], true);
        }
        error_log('InterSoccer: Added camp days to order item metadata for order ' . $order->get_id() . ': ' . $days_selected);
    }

    // Add remaining weeks and discount note for courses
    if (isset($values['remaining_weeks'])) {
        $item->add_meta_data(__('Remaining Weeks', 'intersoccer-product-variations'), $values['remaining_weeks'], true);
        if (isset($values['discount_note'])) {
            $item->add_meta_data(__('Discount', 'intersoccer-product-variations'), $values['discount_note'], true);
        }
        error_log('InterSoccer: Added remaining weeks to order item metadata for order ' . $order->get_id() . ': ' . $values['remaining_weeks']);
    }

    // Add course-specific details
    if (isset($values['course_start_date']) && $values['course_start_date']) {
        $item->add_meta_data(__('Start Date', 'intersoccer-product-variations'), $values['course_start_date'], true);
    }
    if (isset($values['course_end_date']) && $values['course_end_date']) {
        $item->add_meta_data(__('End Date', 'intersoccer-product-variations'), $values['course_end_date'], true);
    }
    if (isset($values['course_holidays']) && $values['course_holidays']) {
        $item->add_meta_data(__('Holidays', 'intersoccer-product-variations'), $values['course_holidays'], true);
    }
    error_log('InterSoccer: Added course details to order item metadata for order ' . $order->get_id() . ': Start=' . ($values['course_start_date'] ?? 'N/A') . ', End=' . ($values['course_end_date'] ?? 'N/A') . ', Holidays=' . ($values['course_holidays'] ?? 'None'));

    // Add other common metadata (e.g., base price if needed)
    if (isset($values['intersoccer_base_price'])) {
        $item->add_meta_data('_intersoccer_base_price', $values['intersoccer_base_price'], true); // Hidden meta
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