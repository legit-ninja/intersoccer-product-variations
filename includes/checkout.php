<?php
/**
 * File: includes/checkout.php
 * Description: Handles custom data preservation for cart and order items in InterSoccer Product Variations plugin.
 * Dependencies: woocommerce
 * Changes:
 * - Added preservation of Assigned Attendee, days selected, and all product attributes (variation + parent).
 * - Included course-specific meta (dates, notes) to match order examples.
 * - Added debug logs for validation.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Guard against multiple loads
if (defined('INTERSOCCER_CHECKOUT_LOADED')) {
    return;
}
define('INTERSOCCER_CHECKOUT_LOADED', true);

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('InterSoccer: checkout.php loaded at ' . current_time('c'));
}

/**
 * Add custom data from product form to cart item.
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_custom_cart_item_data', 10, 3);
function intersoccer_add_custom_cart_item_data($cart_item_data, $product_id, $variation_id) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Adding custom data to cart for product ' . $product_id . ' / variation ' . $variation_id . '. POST data: ' . json_encode($_POST));
    }

    // Assigned player (index from select)
    if (isset($_POST['player_assignment'])) {
        $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added assigned_player to cart: ' . $cart_item_data['assigned_player']);
        }
    }

    // Camp days selected
    if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added camp_days to cart: ' . json_encode($cart_item_data['camp_days']));
        }
    }

    // TODO: If remaining_weeks for courses needs preservation, add a hidden input in variation-details.js and capture here.

    return $cart_item_data;
}

/**
 * Transfer custom cart data and all attributes to order item meta.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_add_custom_order_item_meta', 10, 4);
function intersoccer_add_custom_order_item_meta($item, $cart_item_key, $values, $order) {
    $product_id = $values['product_id'];
    $variation_id = $values['variation_id'];
    $product_type = intersoccer_get_product_type($product_id);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Adding custom meta to order item for product ' . $product_id . ' / variation ' . $variation_id . '. Cart values: ' . json_encode($values));
    }

    // Assigned Attendee (name + index)
    if (isset($values['assigned_player'])) {
        $user_id = $order->get_user_id();
        if ($user_id) {
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $index = $values['assigned_player'];
            if (isset($players[$index])) {
                $player = $players[$index];
                $attendee_name = trim(sanitize_text_field($player['first_name'] . ' ' . $player['last_name']));
                $item->add_meta_data('Assigned Attendee', $attendee_name);
                $item->add_meta_data('assigned_player', $index);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Added Assigned Attendee to order meta: ' . $attendee_name . ' (index: ' . $index . ')');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Invalid player index ' . $index . ' for user ' . $user_id);
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: No user ID available for order ' . $order->get_id());
            }
        }
    }

    // Days Selected
    if (isset($values['camp_days']) && !empty($values['camp_days'])) {
        $item->add_meta_data('Days Selected', implode(', ', $values['camp_days']));
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Added Days Selected to order meta: ' . implode(', ', $values['camp_days']));
        }
    }

    // Activity Type
    $item->add_meta_data('Activity Type', ucfirst($product_type));

    // Course-specific meta
    if ($product_type === 'course' && $variation_id) {
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        if ($start_date) {
            $item->add_meta_data('Start Date', date_i18n('d/m/y', strtotime($start_date)));
        }

        $end_date = get_post_meta($variation_id, '_end_date', true);
        if ($end_date) {
            $item->add_meta_data('End Date', date_i18n('d/m/Y', strtotime($end_date)));
        }

        // Note (static per examples)
        $item->add_meta_data('Note', 'Join anytime – prices are pro-rated if you start late.');

        // Booking Type (static for courses per examples)
        $item->add_meta_data('Booking Type', 'Full Term');

        // TODO: If "Discount: X Weeks Remaining" needs dynamic calculation here, add logic using InterSoccer_Course::calculate_remaining_sessions($variation_id).
    }

    // Add parent attributes (if not already added by WooCommerce for variations)
    $product = wc_get_product($variation_id ?: $product_id);
    $parent_id = $variation_id ? $product->get_parent_id() : $product_id;
    $parent = wc_get_product($parent_id);

    // Get existing meta keys to avoid duplicates
    $existing_meta = $item->get_meta_data();
    $existing_keys = array_map(function($meta) { return $meta->key; }, $existing_meta);

    $attributes = $parent->get_attributes();
    foreach ($attributes as $attr_slug => $attribute) {
        $label = wc_attribute_label($attr_slug);
        if (in_array($label, $existing_keys)) {
            continue; // Skip if already added (e.g., from variation)
        }

        if ($attribute->is_taxonomy()) {
            $terms = wc_get_product_terms($parent_id, $attr_slug, ['fields' => 'names']);
            if (!empty($terms)) {
                $item->add_meta_data($label, implode(', ', $terms));
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Added parent attribute to order meta: ' . $label . ' = ' . implode(', ', $terms));
                }
            }
        } else {
            $options = $attribute->get_options();
            if (!empty($options)) {
                $item->add_meta_data($label, implode(', ', $options));
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Added custom parent attribute to order meta: ' . $label . ' = ' . implode(', ', $options));
                }
            }
        }
    }

    // Variation ID (for reference, if not already added)
    if ($variation_id) {
        $item->add_meta_data('Variation ID', $variation_id);
    }
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('InterSoccer: checkout.php handlers registered');
}
?>