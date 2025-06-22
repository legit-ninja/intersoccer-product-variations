<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get sibling discount percentage based on number of siblings and product type.
 *
 * @param int $sibling_count Number of siblings in the cart for the same event.
 * @param string $product_type Type of product (e.g., 'camp' or 'course').
 * @param bool $same_child Whether the discount is for the same child (currently unused, but retained for future flexibility).
 * @return float Discount percentage (0 to 1).
 */
function intersoccer_get_sibling_discount($sibling_count, $product_type, $same_child = false) {
    // Unified discount based on pa_program-season, 20% for 2nd, 25% for 3rd+
    if ($sibling_count <= 1) return 0;
    elseif ($sibling_count == 2) return 0.20; // 20% discount for 2nd product
    else return 0.25; // 25% discount for 3rd and additional products
}

/**
 * Check if two products match for sibling discount (same attributes except age-group).
 *
 * @param array $item1 First cart item's attributes.
 * @param array $item2 Second cart item's attributes.
 * @param string $product_type Type of product ('camp' or 'course').
 * @return bool True if products match for discount eligibility.
 */
function intersoccer_products_match_for_discount($item1, $item2, $product_type) {
    $key_attributes = ($product_type === 'camp') ? ['pa_activity-type', 'pa_intersoccer-venues', 'pa_camp-terms', 'pa_course-season'] : ['pa_activity-type', 'pa_intersoccer-venues', 'pa_course-season'];
    foreach ($key_attributes as $attr) {
        if (!isset($item1['parent_attributes'][$attr]) || !isset($item2['parent_attributes'][$attr]) ||
            $item1['parent_attributes'][$attr] !== $item2['parent_attributes'][$attr]) {
            return false;
        }
    }
    return true;
}

/**
 * Calculate the price for a product based on submitted data (server-side), including sibling discount.
 *
 * @param int $product_id The product ID.
 * @param int $variation_id The variation ID (if applicable).
 * @param array $camp_days Selected days for Camps (if applicable).
 * @param int $remaining_weeks Remaining weeks for Courses (if applicable).
 * @param int|null $sibling_count Number of siblings (optional, for frontend calculation).
 * @return float The calculated price.
 */
function intersoccer_calculate_price($product_id, $variation_id, $camp_days = [], $remaining_weeks = null, $sibling_count = null, $combo_discount = 0) {
    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for price calculation: ' . ($variation_id ?: $product_id));
        return 0;
    }

    $base_price = floatval($product->get_regular_price());
    $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
    $price = $base_price;

    if ($product_type === 'camp') {
        $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);
        if ($booking_type === 'single-days' && !empty($camp_days)) {
            $day_count = count($camp_days);
            $price = $base_price * $day_count;
            error_log("InterSoccer: Calculated Single-Day Camp price for " . ($variation_id ?: $product_id) . ": $price (base: $base_price, days: $day_count)");
        } elseif ($booking_type === 'full-week') {
            $price = $base_price; // Base price, combo discount applied later
            error_log("InterSoccer: Calculated Full-Week Camp base price for " . ($variation_id ?: $product_id) . ": $price");
        }
    } elseif ($product_type === 'course') {
        $start_date = get_post_meta($variation_id ?: $product_id, '_course_start_date', true);
        if ($start_date && preg_match('/^\d{2}\/\d{2}\/\d{2}$/', $start_date)) {
            $start_date = DateTime::createFromFormat('m/d/y', $start_date)->format('Y-m-d');
        }
        $current_time = current_time('timestamp');
        $start_timestamp = $start_date ? strtotime($start_date) : false;
        if ($start_timestamp && $start_timestamp < $current_time) {
            $total_weeks = (int)get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true) ?: 16;
            $weekly_discount = (float)get_post_meta($variation_id ?: $product_id, '_course_weekly_discount', true) ?: ($base_price / $total_weeks);
            $remaining_weeks = $remaining_weeks ?: max(0, $total_weeks - floor(($current_time - $start_timestamp) / (7 * 24 * 60 * 60)));
            $price = $weekly_discount * $remaining_weeks;
            error_log("InterSoccer: Calculated Pro-Rated Course price for " . ($variation_id ?: $product_id) . ": $price (weekly: $weekly_discount, remaining: $remaining_weeks)");
        } else {
            $price = $base_price; // No pro-rating if start date is future or invalid
            error_log("InterSoccer: Using base price for Course " . ($variation_id ?: $product_id) . ": $price (start date: $start_date)");
        }
    }

    $sibling_discount = $sibling_count !== null && $sibling_count > 1 ? intersoccer_get_sibling_discount($sibling_count, $product_type) : 0;
    $price *= (1 - $sibling_discount); // Combo discount applied in cart

    return max(0, floatval($price));
}

/**
 * Get visible, non-variation parent attributes for a product, excluding camp-specific overlaps.
 *
 * @param WC_Product $product Product object (variation or parent).
 * @param string $product_type Product type ('camp', 'course', etc.).
 * @return array Array of attribute label => value pairs.
 */
function intersoccer_get_parent_attributes($product, $product_type = '') {
    $attributes = [];

    $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();
    $parent_product = wc_get_product($parent_id);

    if (!$parent_product) {
        error_log('InterSoccer: No parent product found for product ID ' . $product->get_id());
        return $attributes;
    }

    $product_attributes = $parent_product->get_attributes();
    error_log('InterSoccer: Retrieving attributes for parent product ID ' . $parent_id . ': ' . print_r($product_attributes, true));

    foreach ($product_attributes as $attribute_name => $attribute) {
        $label = wc_attribute_label($attribute_name);
        $value = '';

        if ($product_type === 'camp' && $attribute_name === 'pa_days-of-week') {
            error_log('InterSoccer: Skipped attribute ' . $attribute_name . ' for camp product ' . $parent_id . ': overlaps with Days Selected meta');
            continue;
        }

        if (!is_object($attribute)) {
            $is_visible = isset($attribute['is_visible']) && $attribute['is_visible'];
            if ($is_visible) {
                $value = $attribute['value'];
                error_log('InterSoccer: Processing custom attribute ' . $attribute_name . ' for product ' . $parent_id . ': visible=' . ($is_visible ? 'true' : 'false') . ', value=' . ($value ?: 'empty'));
            } else {
                error_log('InterSoccer: Skipped custom attribute ' . $attribute_name . ' for product ' . $parent_id . ': not visible');
            }
        } else {
            $is_visible = $attribute->get_visible();
            $is_variation = $attribute->get_variation();
            if ($is_visible && !$is_variation) {
                $terms = wc_get_product_terms($parent_id, $attribute_name, ['fields' => 'names']);
                $value = !empty($terms) ? implode(', ', $terms) : '';
                error_log('InterSoccer: Processing taxonomy attribute ' . $attribute_name . ' for product ' . $parent_id . ': visible=' . ($is_visible ? 'true' : 'false') . ', variation=' . ($is_variation ? 'true' : 'false') . ', value=' . ($value ?: 'empty'));
            } else {
                error_log('InterSoccer: Skipped taxonomy attribute ' . $attribute_name . ' for product ' . $parent_id . ': visible=' . ($is_visible ? 'true' : 'false') . ', variation=' . ($is_variation ? 'true' : 'false'));
            }
        }

        if (!empty($value)) {
            $attributes[$label] = $value;
        }
    }

    return $attributes;
}

/**
 * Modify variation prices for display in the frontend.
 * Avoid session access during product rendering to prevent fatal errors.
 */
add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices', 10, 3);
function intersoccer_modify_variation_prices($prices, $product, $for_display) {
    $product_id = $product->get_id();
    $selected_days = [];
    $remaining_weeks = null;
    $sibling_count = 1;

    error_log('InterSoccer: Modifying variation prices for product ' . $product_id . ' during rendering, using defaults: selected_days: [], remaining_weeks: null, sibling_count: 1');

    foreach ($prices['price'] as $variation_id => $price) {
        $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $selected_days, $remaining_weeks, $sibling_count);
        $formatted_price = wc_price($calculated_price);
        $prices['price'][$variation_id] = $for_display ? $formatted_price : $calculated_price;
        $prices['regular_price'][$variation_id] = $for_display ? $formatted_price : $calculated_price;
        $prices['sale_price'][$variation_id] = $for_display ? $formatted_price : $calculated_price;
    }

    return $prices;
}

// AJAX handler for dynamic price calculation
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
function intersoccer_calculate_dynamic_price_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;
    $sibling_count = isset($_POST['sibling_count']) && is_numeric($_POST['sibling_count']) ? (int) $_POST['sibling_count'] : 1;

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Invalid product or variation ID']);
        wp_die();
    }

    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks, $sibling_count);
    wp_send_json_success([
        'price' => floatval($calculated_price), // Raw numeric price
        'formatted_price' => wc_price($calculated_price), // Formatted for display if needed
    ]);
    wp_die();
}

/**
 * Update session data when variation data changes.
 */
add_action('wp_footer', 'intersoccer_update_session_on_variation_change');
function intersoccer_update_session_on_variation_change() {
    if (!is_product()) {
        return;
    }

    global $post;
    $product_id = $post->ID;
    ?>
    <script>
        jQuery(document).ready(function($) {
            $('form.cart').on('found_variation', function(event, variation) {
                var productId = <?php echo json_encode($product_id); ?>;
                var campDays = $(this).find('input[name="camp_days[]"]').map(function() {
                    return $(this).val();
                }).get();
                var remainingWeeks = $(this).find('input[name="remaining_weeks"]').val() || null;

                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId,
                        camp_days: campDays,
                        remaining_weeks: remainingWeeks
                    },
                    success: function(response) {
                        console.log('InterSoccer: Session data updated:', response);
                    },
                    error: function(xhr) {
                        console.error('InterSoccer: Failed to update session data:', xhr.status, xhr.responseText);
                    }
                });
            });

            $('form.cart').on('reset_data', function() {
                var productId = <?php echo json_encode($product_id); ?>;
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_clear_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: productId
                    },
                    success: function(response) {
                        console.log('InterSoccer: Session data cleared:', response);
                    },
                    error: function(xhr) {
                        console.error('InterSoccer: Failed to clear session data:', xhr.status, xhr.responseText);
                    }
                });
            });

            $('form.cart').trigger('check_variations');
        });
    </script>
    <?php
}

/**
 * AJAX handler to update session data.
 */
add_action('wp_ajax_intersoccer_update_session_data', 'intersoccer_update_session_data_callback');
add_action('wp_ajax_nopriv_intersoccer_update_session_data', 'intersoccer_update_session_data_callback');
function intersoccer_update_session_data_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;

    if ($product_id) {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
            if ($remaining_weeks !== null) {
                WC()->session->set('intersoccer_remaining_weeks_' . $product_id, $remaining_weeks);
            }
            error_log('InterSoccer: Session data updated for product ' . $product_id . ', selected_days: ' . print_r($camp_days, true) . ', remaining_weeks: ' . ($remaining_weeks ?? 'null'));
            wp_send_json_success(['message' => 'Session data updated']);
        } else {
            error_log('InterSoccer: WooCommerce session not available during intersoccer_update_session_data_callback');
            wp_send_json_error(['message' => 'Session not available']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid product ID']);
        wp_die();
    }
}

/**
 * AJAX handler to clear session data.
 */
add_action('wp_ajax_intersoccer_clear_session_data', 'intersoccer_clear_session_data_callback');
add_action('wp_ajax_nopriv_intersoccer_clear_session_data', 'intersoccer_clear_session_data_callback');
function intersoccer_clear_session_data_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

    if ($product_id) {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, []);
            WC()->session->set('intersoccer_remaining_weeks_' . $product_id, null);
            error_log('InterSoccer: Session data cleared for product ' . $product_id);
            wp_send_json_success(['message' => 'Session data cleared']);
        } else {
            error_log('InterSoccer: WooCommerce session not available during intersoccer_clear_session_data_callback');
            wp_send_json_error(['message' => 'Session not available']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid product ID']);
        wp_die();
    }
}



/**
 * Reinforce cart item data, including sibling discount.
 */
add_filter('woocommerce_add_cart_item', 'intersoccer_reinforce_cart_item_data', 10, 2);
function intersoccer_reinforce_cart_item_data($cart_item_data, $cart_item_key) {
    if (!is_array($cart_item_data) || !isset($cart_item_data['product_id']) || !isset($cart_item_key)) {
        error_log('InterSoccer: Invalid cart item data in intersoccer_reinforce_cart_item_data: ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    $product_id = $cart_item_data['product_id'];
    $variation_id = isset($cart_item_data['variation_id']) ? $cart_item_data['variation_id'] : 0;
    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product in cart item ' . $cart_item_key . ': product_id=' . $product_id . ', variation_id=' . $variation_id);
        return $cart_item_data;
    }

    if (!isset($cart_item_data['data']) || !($cart_item_data['data'] instanceof WC_Product)) {
        error_log('InterSoccer: Missing or invalid "data" key in cart item ' . $cart_item_key . ': ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    if (isset($cart_item_data['remaining_weeks'])) {
        WC()->cart->cart_contents[$cart_item_key]['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Reinforced remaining weeks for cart item ' . $cart_item_key . ': ' . $cart_item_data['remaining_weeks']);
    }

    if (isset($cart_item_data['parent_attributes']) && is_array($cart_item_data['parent_attributes'])) {
        WC()->cart->cart_contents[$cart_item_key]['parent_attributes'] = $cart_item_data['parent_attributes'];
        error_log('InterSoccer: Reinforced parent attributes for cart item ' . $cart_item_key . ': ' . print_r($cart_item_data['parent_attributes'], true));
    }

    $camp_days = isset($cart_item_data['camp_days']) ? $cart_item_data['camp_days'] : [];
    $remaining_weeks = isset($cart_item_data['remaining_weeks']) ? (int) $cart_item_data['remaining_weeks'] : null;
    $sibling_count = isset($cart_item_data['sibling_count']) ? (int) $cart_item_data['sibling_count'] : 1;
    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks, $sibling_count);

    $cart_item_data['data']->set_price($calculated_price);
    $cart_item_data['intersoccer_calculated_price'] = $calculated_price;
    $cart_item_data['intersoccer_sibling_discount'] = intersoccer_get_sibling_discount($sibling_count, get_post_meta($product_id, '_intersoccer_product_type', true));
    error_log('InterSoccer: Set server-side calculated price for cart item ' . $cart_item_key . ': ' . $calculated_price . ', Sibling Count: ' . $sibling_count);

    if (isset(WC()->cart->cart_contents[$cart_item_key]) && isset(WC()->cart->cart_contents[$cart_item_key]['data']) && WC()->cart->cart_contents[$cart_item_key]['data'] instanceof WC_Product) {
        WC()->cart->cart_contents[$cart_item_key]['data']->set_price($calculated_price);
        WC()->cart->cart_contents[$cart_item_key]['parent_attributes'] = $cart_item_data['parent_attributes'];
        WC()->cart->set_session();
    } else {
        error_log('InterSoccer: Unable to persist price or data in cart session for item ' . $cart_item_key . ': invalid cart contents or data');
    }

    return $cart_item_data;
}

add_filter('woocommerce_get_cart_item_from_session', 'intersoccer_restore_cart_item_price', 10, 3);
add_filter('woocommerce_get_cart_item_from_session', 'intersoccer_restore_cart_item_price', 10, 3);
function intersoccer_restore_cart_item_price($cart_item_data, $cart_item_session_data, $cart_item_key) {
    if (!isset($cart_item_data['data']) || !($cart_item_data['data'] instanceof WC_Product)) {
        error_log('InterSoccer: Missing or invalid "data" key in cart item ' . $cart_item_key . ' during session restore: ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    if (isset($cart_item_session_data['intersoccer_calculated_price'])) {
        $calculated_price = floatval($cart_item_session_data['intersoccer_calculated_price']);
        $cart_item_data['data']->set_price($calculated_price);
        $cart_item_data['intersoccer_calculated_price'] = $calculated_price;
        error_log('InterSoccer: Restored calculated price for cart item ' . $cart_item_key . ' from session: ' . $calculated_price);
    }

    if (isset($cart_item_session_data['parent_attributes']) && is_array($cart_item_session_data['parent_attributes'])) {
        $cart_item_data['parent_attributes'] = $cart_item_session_data['parent_attributes'];
        error_log('InterSoccer: Restored parent attributes for cart item ' . $cart_item_key . ': ' . print_r($cart_item_data['parent_attributes'], true));
    }

    if (isset($cart_item_session_data['intersoccer_sibling_discount'])) {
        $cart_item_data['intersoccer_sibling_discount'] = floatval($cart_item_session_data['intersoccer_sibling_discount']);
        error_log('InterSoccer: Restored sibling discount for cart item ' . $cart_item_key . ': ' . ($cart_item_data['intersoccer_sibling_discount'] * 100) . '%');
    }

    if (isset($cart_item_session_data['player_assignment'])) {
        $cart_item_data['player_assignment'] = $cart_item_session_data['player_assignment'];
        error_log('InterSoccer: Restored player assignment for cart item ' . $cart_item_key . ': ' . $cart_item_data['player_assignment']);
    }

    if (isset($cart_item_session_data['camp_days']) && is_array($cart_item_session_data['camp_days'])) {
        $cart_item_data['camp_days'] = $cart_item_session_data['camp_days'];
        error_log('InterSoccer: Restored camp days for cart item ' . $cart_item_key . ': ' . print_r($cart_item_data['camp_days'], true));
    }

    if (isset($cart_item_session_data['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item_session_data['remaining_weeks']);
        error_log('InterSoccer: Restored remaining weeks for cart item ' . $cart_item_key . ': ' . $cart_item_data['remaining_weeks']);
    }

    return $cart_item_data;
}

/**
 * Persist cart item meta, including sibling discount.
 */
add_filter('woocommerce_cart_item_set_data', 'intersoccer_persist_cart_item_data', 10, 2);
function intersoccer_persist_cart_item_data($cart_item_data, $cart_item) {
    if (isset($cart_item['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item['remaining_weeks']);
        error_log('InterSoccer: Persisted remaining weeks in cart item: ' . $cart_item['remaining_weeks']);
    }
    if (isset($cart_item['intersoccer_calculated_price'])) {
        $cart_item_data['intersoccer_calculated_price'] = floatval($cart_item['intersoccer_calculated_price']);
        error_log('InterSoccer: Persisted calculated price in cart item: ' . $cart_item['intersoccer_calculated_price']);
    }
    if (isset($cart_item['parent_attributes']) && is_array($cart_item['parent_attributes'])) {
        $cart_item_data['parent_attributes'] = $cart_item['parent_attributes'];
        error_log('InterSoccer: Persisted parent attributes in cart item: ' . print_r($cart_item['parent_attributes'], true));
    }
    if (isset($cart_item['intersoccer_sibling_discount'])) {
        $cart_item_data['intersoccer_sibling_discount'] = floatval($cart_item['intersoccer_sibling_discount']);
        error_log('InterSoccer: Persisted sibling discount in cart item: ' . ($cart_item['intersoccer_sibling_discount'] * 100) . '%');
    }
    return $cart_item_data;
}

/**
 * Display player, days, discount, and parent attributes in cart and checkout, including sibling discount.
 */
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 300, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    error_log('InterSoccer: Entering intersoccer_display_cart_item_data for product ID: ' . ($cart_item['product_id'] ?? 'not set'));
    error_log('InterSoccer: Cart item data: ' . print_r($cart_item, true));

    if (isset($cart_item['player_assignment'])) {
        try {
            $player = get_player_details($cart_item['player_assignment']);
            if (!empty($player['first_name']) && !empty($player['last_name'])) {
                $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
                $item_data[] = [
                    'key' => __('Assigned Attendee', 'intersoccer-player-management'),
                    'value' => $player_name,
                    'display' => $player_name
                ];
                error_log('InterSoccer: Added Assigned Attendee for item ' . ($cart_item['product_id'] ?? 'not set') . ': ' . $player_name);
            } else {
                error_log('InterSoccer: Invalid player data for index ' . $cart_item['player_assignment'] . ': ' . print_r($player, true));
            }
        } catch (Exception $e) {
            error_log('InterSoccer: Error in get_player_details for item ' . ($cart_item['product_id'] ?? 'not set') . ': ' . $e->getMessage());
        }
    }

    $product = wc_get_product($cart_item['product_id']);
    if (!$product) {
        error_log('InterSoccer: Invalid product for item ' . ($cart_item['product_id'] ?? 'not set'));
        return $item_data;
    }

    $product_type = intersoccer_get_product_type($cart_item['product_id']);
    if ($product_type === 'camp' && isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
        $days = array_map('esc_html', $cart_item['camp_days']);
        $days_display = implode(', ', $days);
        $item_data[] = [
            'key' => __('Days Selected', 'intersoccer-player-management'),
            'value' => $days_display,
            'display' => $days_display
        ];
        error_log('InterSoccer: Added days selected for camp item ' . $cart_item['product_id'] . ': ' . $days_display);
    }

    if ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $weeks_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
        $item_data[] = [
            'key' => __('Discount', 'intersoccer-player-management'),
            'value' => $weeks_display,
            'display' => $weeks_display
        ];
        error_log('InterSoccer: Added discount attribute for course item ' . $cart_item['product_id'] . ': ' . $weeks_display);
    }

    if (isset($cart_item['combo_discount_note'])) {
        $item_data[] = [
            'key' => __('Discount', 'intersoccer-player-management'),
            'value' => $cart_item['combo_discount_note'],
            'display' => $cart_item['combo_discount_note']
        ];
        error_log('InterSoccer: Added Combo Discount note for item ' . $cart_item['product_id'] . ': ' . $cart_item['combo_discount_note']);
    }

    if (isset($cart_item['parent_attributes']) && is_array($cart_item['parent_attributes'])) {
        foreach ($cart_item['parent_attributes'] as $label => $value) {
            $item_data[] = [
                'key' => esc_html($label),
                'value' => esc_html($value),
                'display' => esc_html($value)
            ];
            error_log('InterSoccer: Added parent attribute for item ' . $cart_item['product_id'] . ': ' . $label . ' = ' . $value);
        }
    }

    return $item_data;
}

/**
 * Save player, days, discount, parent attributes, and downloads to order.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_data', 300, 4);

function intersoccer_save_order_item_data($item, $cart_item_key, $values, $order) {
    error_log('InterSoccer: Saving order item data for cart item ' . $cart_item_key . ': ' . print_r($values, true));

    if (isset($values['player_assignment'])) {
        $user_id = get_current_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        $player_index = sanitize_text_field($values['player_assignment']);
        if (isset($players[$player_index])) {
            $player = $players[$player_index];
            $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
            $item->add_meta_data(__('Assigned Attendee', 'intersoccer-player-management'), $player_name);
        }
    }

    if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
        $days = array_map('sanitize_text_field', $values['camp_days']);
        $item->add_meta_data(__('Days Selected', 'intersoccer-player-management'), implode(', ', $days));
    }

    if (isset($values['remaining_weeks']) && $values['remaining_weeks'] > 0) {
        $item->add_meta_data(__('Discount', 'intersoccer-player-management'), $values['pro_rated_note']);
    }

    if (isset($values['combo_discount_note'])) {
        $item->add_meta_data(__('Discount Applied', 'intersoccer-player-management'), $values['combo_discount_note']);
    }

    if (isset($values['parent_attributes']) && is_array($values['parent_attributes'])) {
        foreach ($values['parent_attributes'] as $label => $value) {
            $item->add_meta_data(esc_html($label), esc_html($value));
        }
    }

    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $product = wc_get_product($variation_id ?: $product_id);
    if ($product) {
        $product_type = intersoccer_get_product_type($product_id);
        if (in_array($product_type, ['camp', 'course', 'birthday'])) {
            $downloads = get_post_meta($product_id, '_intersoccer_downloads', true);
            if (is_array($downloads) && !empty($downloads)) {
                foreach ($downloads as $download) {
                    $file_id = md5($download['file']);
                    $item->add_meta_data('_downloadable_file_' . $file_id, [
                        'name' => $download['name'],
                        'file' => $download['file'],
                        'limit' => $download['limit'],
                        'expiry' => $download['expiry']
                    ]);
                }
            }
        }
    }
}

add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_data', 10, 4);




/**
 * Helper function to retrieve player details.
 */
function get_player_details($player_index) {
    $user_id = get_current_user_id();
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    return $players[$player_index] ?? ['first_name' => 'Unknown', 'last_name' => 'Player'];
}

add_action('woocommerce_before_cart', function () {
    error_log('InterSoccer: Cart contents before rendering: ' . print_r(WC()->cart->get_cart(), true));
});

add_action('woocommerce_process_product_meta', 'intersoccer_save_product_type_meta', 100);
function intersoccer_save_product_type_meta($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }

    $product_type = intersoccer_get_product_type($product_id);
}

add_action('woocommerce_save_product_variation', 'intersoccer_save_course_metadata', 10, 2);
function intersoccer_save_course_metadata($variation_id, $i) {
    $product_id = wp_get_post_parent_id($variation_id);
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type === 'course') {
        $total_weeks = isset($_POST['_course_total_weeks'][$i]) ? (int) $_POST['_course_total_weeks'][$i] : 0;
        $weekly_discount = isset($_POST['_course_weekly_discount'][$i]) ? (float) $_POST['_course_weekly_discount'][$i] : 0;
        update_post_meta($variation_id, '_course_total_weeks', $total_weeks);
        update_post_meta($variation_id, '_course_weekly_discount', $weekly_discount);
        error_log('InterSoccer: Saved course metadata for variation ' . $variation_id . ': total_weeks=' . $total_weeks . ', weekly_discount=' . $weekly_discount);
    }
}

add_action('init', 'intersoccer_update_existing_product_types', 1);
function intersoccer_update_existing_product_types() {
    $has_run = get_option('intersoccer_product_type_update_20250527', false);
    if ($has_run) {
        return;
    }

    $products = wc_get_products(['limit' => -1]);
    foreach ($products as $product) {
        $product_id = $product->get_id();
        intersoccer_get_product_type($product_id);
    }

    update_option('intersoccer_product_type_update_20250527', true);
    error_log('InterSoccer: Completed one-time product type meta update for all products');
}

add_filter('woocommerce_get_item_data', 'intersoccer_display_event_cpt_data', 320, 2);
function intersoccer_display_event_cpt_data($item_data, $cart_item) {
    $product_id = $cart_item['product_id'];
    $post_type = get_post_meta($product_id, '_intersoccer_product_type', true);
    if ($post_type && in_array($post_type, ['camp', 'course', 'birthday'])) {
        $event = get_posts([
            'post_type' => $post_type,
            'meta_key' => '_product_id',
            'meta_value' => $product_id,
            'posts_per_page' => 1,
        ]);
        if ($event) {
            $item_data[] = [
                'key' => __($post_type === 'camp' ? 'Camp' : ($post_type === 'course' ? 'Course' : 'Birthday'), 'intersoccer-events-cpt'),
                'value' => esc_html($event[0]->post_title),
            ];
        }
    }
    return $item_data;
}

add_action('admin_menu', 'intersoccer_add_update_orders_submenu');
function intersoccer_add_update_orders_submenu() {
    add_submenu_page(
        'woocommerce',
        __('Update Order Details', 'intersoccer-player-management'),
        __('Update Order Details', 'intersoccer-player-management'),
        'manage_woocommerce',
        'intersoccer-update-orders',
        'intersoccer_render_update_orders_page'
    );
}

function intersoccer_render_update_orders_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    $orders = wc_get_orders([
        'status' => 'processing',
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    ?>
    <div class="wrap">
        <h1><?php _e('Update Processing Orders with Parent Attributes', 'intersoccer-player-management'); ?></h1>
        <p><?php _e('Select orders to update with new visible, non-variation parent product attributes for reporting and analytics. Use "Remove Assigned Player" to delete the unwanted assigned_player field from orders. Use "Fix Incorrect Attributes" to correct orders with unwanted attributes (e.g., all days of the week).', 'intersoccer-player-management'); ?></p>
        <?php if (!empty($orders)) : ?>
            <form id="intersoccer-update-orders-form">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-orders"></th>
                            <th><?php _e('Order ID', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Customer', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Date', 'intersoccer-player-management'); ?></th>
                            <th><?php _e('Total', 'intersoccer-player-management'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order) : ?>
                            <tr>
                                <td><input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($order->get_id()); ?>"></td>
                                <td><?php echo esc_html($order->get_order_number()); ?></td>
                                <td><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                                <td><?php echo esc_html($order->get_date_created()->date_i18n('Y-m-d')); ?></td>
                                <td><?php echo wc_price($order->get_total()); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <label>
                        <input type="checkbox" id="remove-assigned-player" name="remove_assigned_player">
                        <?php _e('Remove Assigned Player Field', 'intersoccer-player-management'); ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="fix-incorrect-attributes" name="fix_incorrect_attributes">
                        <?php _e('Fix Incorrect Attributes (e.g., remove Days-of-week)', 'intersoccer-player-management'); ?>
                    </label>
                </p>
                <p>
                    <button type="button" id="intersoccer-update-orders-button" class="button button-primary"><?php _e('Update Selected Orders', 'intersoccer-player-management'); ?></button>
                    <span id="intersoccer-update-status"></span>
                </p>
                <?php wp_nonce_field('intersoccer_update_orders_nonce', 'intersoccer_update_orders_nonce'); ?>
            </form>
        <?php else : ?>
            <p><?php _e('No Processing orders found.', 'intersoccer-player-management'); ?></p>
        <?php endif; ?>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#select-all-orders').on('change', function() {
                $('input[name="order_ids[]"]').prop('checked', $(this).prop('checked'));
            });

            $('#intersoccer-update-orders-button').on('click', function() {
                var orderIds = $('input[name="order_ids[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                var nonce = $('#intersoccer_update_orders_nonce').val();
                var removeAssignedPlayer = $('#remove-assigned-player').is(':checked');
                var fixIncorrect = $('#fix-incorrect-attributes').is(':checked');

                if (orderIds.length === 0) {
                    alert('<?php _e('Please select at least one order.', 'intersoccer-player-management'); ?>');
                    return;
                }

                $('#intersoccer-update-status').text('<?php _e('Updating orders...', 'intersoccer-player-management'); ?>').removeClass('error');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_processing_orders',
                        nonce: nonce,
                        order_ids: orderIds,
                        remove_assigned_player: removeAssignedPlayer,
                        fix_incorrect_attributes: fixIncorrect
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#intersoccer-update-status').text('<?php _e('Orders updated successfully!', 'intersoccer-player-management'); ?>');
                        } else {
                            $('#intersoccer-update-status').text('<?php _e('Error: ', 'intersoccer-player-management'); ?>' + response.data.message).addClass('error');
                        }
                    },
                    error: function() {
                        $('#intersoccer-update-status').text('<?php _e('An error occurred while updating orders.', 'intersoccer-player-management'); ?>').addClass('error');
                    }
                });
            });
        });
    </script>
    <style>
        #intersoccer-update-status { margin-left: 10px; color: green; }
        #intersoccer-update-status.error { color: red; }
    </style>
    <?php
}

add_action('wp_ajax_intersoccer_update_processing_orders', 'intersoccer_update_processing_orders_callback');
function intersoccer_update_processing_orders_callback() {
    check_ajax_referer('intersoccer_update_orders_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'intersoccer-player-management')]);
        wp_die();
    }

    $order_ids = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $remove_assigned_player = isset($_POST['remove_assigned_player']) && $_POST['remove_assigned_player'] === 'true';
    $fix_incorrect = isset($_POST['fix_incorrect_attributes']) && $_POST['fix_incorrect_attributes'] === 'true';

    if (empty($order_ids)) {
        wp_send_json_error(['message' => __('No orders selected.', 'intersoccer-player-management')]);
        wp_die();
    }

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'processing') {
            error_log('InterSoccer: Invalid or non-Processing order ID ' . $order_id);
            continue;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $product = wc_get_product($variation_id ?: $product_id);

            if (!$product) {
                error_log('InterSoccer: Invalid product for order item ' . $item_id . ' in order ' . $order_id);
                continue;
            }

            $product_type = intersoccer_get_product_type($product_id);

            if ($remove_assigned_player) {
                $item->delete_meta_data('assigned_player');
                error_log('InterSoccer: Removed assigned_player from order item ' . $item_id . ' in order ' . $order_id);
            }

            if ($fix_incorrect && $product_type === 'camp') {
                $item->delete_meta_data('Days-of-week');
                error_log('InterSoccer: Removed Days-of-week attribute from order item ' . $item_id . ' in order ' . $order_id);
            }

            $parent_attributes = intersoccer_get_parent_attributes($product, $product_type);

            foreach ($parent_attributes as $label => $value) {
                $existing_meta = $item->get_meta($label);
                if (!$existing_meta) {
                    $item->add_meta_data(esc_html($label), esc_html($value));
                    error_log('InterSoccer: Added new parent attribute to order item ' . $item_id . ' in order ' . $order_id . ': ' . $label . ' = ' . $value);
                } else {
                    error_log('InterSoccer: Skipped existing parent attribute for order item ' . $item_id . ' in order ' . $order_id . ': ' . $label);
                }
            }

            $item->save();
        }

        $order->save();
        error_log('InterSoccer: Updated order ' . $order_id . ' with new parent attributes' . ($remove_assigned_player ? ' and removed assigned_player' : '') . ($fix_incorrect ? ' and fixed incorrect attributes' : ''));
    }

    wp_send_json_success(['message' => __('Orders updated successfully.', 'intersoccer-player-management')]);
    wp_die();
}

add_action('add_meta_boxes', 'intersoccer_add_downloadable_documents_metabox');
function intersoccer_add_downloadable_documents_metabox() {
    $screen = get_current_screen();
    if ($screen->id !== 'product') {
        return;
    }

    global $post;
    $product_type = intersoccer_get_product_type($post->ID);

    if (in_array($product_type, ['camp', 'course', 'birthday'])) {
        add_meta_box(
            'intersoccer_downloadable_documents',
            __('Event Documents', 'intersoccer-player-management'),
            'intersoccer_render_downloadable_documents_metabox',
            'product',
            'normal',
            'default'
        );
    }
}

function intersoccer_render_downloadable_documents_metabox($post) {
    $downloads = get_post_meta($post->ID, '_intersoccer_downloads', true);
    $downloads = is_array($downloads) ? $downloads : [];
    wp_nonce_field('intersoccer_save_downloads', 'intersoccer_downloads_nonce');
    ?>
    <div id="intersoccer-downloads-container">
        <?php foreach ($downloads as $index => $download) : ?>
            <div class="intersoccer-download-row" style="margin-bottom: 10px;">
                <input type="text" name="intersoccer_downloads[<?php echo $index; ?>][name]" value="<?php echo esc_attr($download['name']); ?>" placeholder="<?php _e('Document Name', 'intersoccer-player-management'); ?>" style="width: 200px; margin-right: 10px;">
                <input type="hidden" name="intersoccer_downloads[<?php echo $index; ?>][file]" value="<?php echo esc_attr($download['file']); ?>" class="intersoccer-download-file">
                <button type="button" class="button intersoccer-upload-button"><?php _e('Choose File', 'intersoccer-player-management'); ?></button>
                <span class="intersoccer-file-name"><?php echo basename($download['file']); ?></span>
                <input type="number" name="intersoccer_downloads[<?php echo $index; ?>][limit]" value="<?php echo esc_attr($download['limit']); ?>" placeholder="<?php _e('Download Limit', 'intersoccer-player-management'); ?>" style="width: 100px; margin-left: 10px;">
                <input type="number" name="intersoccer_downloads[<?php echo $index; ?>][expiry]" value="<?php echo esc_attr($download['expiry']); ?>" placeholder="<?php _e('Days Until Expiry', 'intersoccer-player-management'); ?>" style="width: 100px; margin-left: 10px;">
                <button type="button" class="button intersoccer-remove-download" style="margin-left: 10px;"><?php _e('Remove', 'intersoccer-player-management'); ?></button>
            </div>
        <?php endforeach; ?>
        <div id="intersoccer-downloads-template" style="display: none;">
            <div class="intersoccer-download-row" style="margin-bottom: 10px;">
                <input type="text" name="intersoccer_downloads[{index}][name]" placeholder="<?php _e('Document Name', 'intersoccer-player-management'); ?>" style="width: 200px; margin-right: 10px;">
                <input type="hidden" name="intersoccer_downloads[{index}][file]" class="intersoccer-download-file">
                <button type="button" class="button intersoccer-upload-button"><?php _e('Choose File', 'intersoccer-player-management'); ?></button>
                <span class="intersoccer-file-name"></span>
                <input type="number" name="intersoccer_downloads[{index}][limit]" placeholder="<?php _e('Download Limit', 'intersoccer-player-management'); ?>" style="width: 100px; margin-left: 10px;">
                <input type="number" name="intersoccer_downloads[{index}][expiry]" placeholder="<?php _e('Days Until Expiry', 'intersoccer-player-management'); ?>" style="width: 100px; margin-left: 10px;">
                <button type="button" class="button intersoccer-remove-download" style="margin-left: 10px;"><?php _e('Remove', 'intersoccer-player-management'); ?></button>
            </div>
        </div>
        <p><button type="button" id="intersoccer-add-download" class="button"><?php _e('Add Document', 'intersoccer-player-management'); ?></button></p>
    </div>
    <script>
        jQuery(document).ready(function($) {
            var index = <?php echo count($downloads); ?>;
            
            $('#intersoccer-add-download').on('click', function() {
                var template = $('#intersoccer-downloads-template').html().replace(/{index}/g, index++);
                $('#intersoccer-downloads-container').append(template);
            });

            $(document).on('click', '.intersoccer-remove-download', function() {
                $(this).closest('.intersoccer-download-row').remove();
            });

            $(document).on('click', '.intersoccer-upload-button', function(e) {
                e.preventDefault();
                var button = $(this);
                var fileFrame = wp.media({
                    title: '<?php _e('Select PDF Document', 'intersoccer-player-management'); ?>',
                    button: { text: '<?php _e('Use this file', 'intersoccer-player-management'); ?>' },
                    multiple: false,
                    library: { type: 'application/pdf' }
                });

                fileFrame.on('select', function() {
                    var attachment = fileFrame.state().get('selection').first().toJSON();
                    button.siblings('.intersoccer-download-file').val(attachment.url);
                    button.siblings('.intersoccer-file-name').text(attachment.filename);
                });

                fileFrame.open();
            });
        });
    </script>
    <?php
}

add_action('save_post_product', 'intersoccer_save_downloadable_documents', 10, 2);
function intersoccer_save_downloadable_documents($post_id, $post) {
    if (!isset($_POST['intersoccer_downloads_nonce']) || !wp_verify_nonce($_POST['intersoccer_downloads_nonce'], 'intersoccer_save_downloads')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $downloads = isset($_POST['intersoccer_downloads']) && is_array($_POST['intersoccer_downloads']) ? $_POST['intersoccer_downloads'] : [];
    $sanitized_downloads = [];

    foreach ($downloads as $download) {
        if (!empty($download['file']) && !empty($download['name'])) {
            $sanitized_downloads[] = [
                'name' => sanitize_text_field($download['name']),
                'file' => esc_url_raw($download['file']),
                'limit' => !empty($download['limit']) ? absint($download['limit']) : '',
                'expiry' => !empty($download['expiry']) ? absint($download['expiry']) : ''
            ];
        }
    }

    update_post_meta($post_id, '_intersoccer_downloads', $sanitized_downloads);

    $wc_downloads = [];
    foreach ($sanitized_downloads as $download) {
        $file_id = md5($download['file']);
        $wc_downloads[$file_id] = [
            'name' => $download['name'],
            'file' => $download['file'],
            'download_limit' => $download['limit'],
            'download_expiry' => $download['expiry']
        ];
    }

    update_post_meta($post_id, '_downloadable_files', $wc_downloads);
    update_post_meta($post_id, '_downloadable', 'yes');
    error_log('InterSoccer: Saved downloadable documents for product ' . $post_id . ': ' . print_r($sanitized_downloads, true));
}

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

        $product_type = intersoccer_get_product_type($product_id);
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
 * Adjust cart item prices to reflect combo discounts based on season and product type.
 */
add_action('woocommerce_before_calculate_totals', 'intersoccer_apply_discounts', 10, 1);
function intersoccer_apply_discounts($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    $cart_items = $cart->get_cart();

    // Clear all existing discount messages to prevent carryover
    foreach ($cart_items as $cart_item_key => $cart_item) {
        unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
    }

    // Group items by pa_program-season
    $grouped_items = [];
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if (in_array($product_type, ['camp', 'course'])) {
            $season = isset($cart_item['parent_attributes']['Program Season']) ? $cart_item['parent_attributes']['Program Season'] : 'unknown';
            $grouped_items[$season][$cart_item_key] = $cart_item;
        }
    }

    // Apply discounts and set messages per season group
    foreach ($grouped_items as $season => $items) {
        $camp_index = 0;
        $course_index = 0;

        foreach ($items as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
            $variation_id = $cart_item['variation_id'] ?: $product_id;
            $base_price = floatval($cart_item['data']->get_regular_price());
            $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $cart_item['camp_days'] ?? [], $cart_item['remaining_weeks'] ?? null);
            $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true) ?: 'full-week';

            $discount = 0;
            $original_price = $calculated_price; // Base price before combo discount

            // Apply discount based on product type and position within season
            if ($product_type === 'camp' && in_array($booking_type, ['full-week', 'single-days'])) {
                $camp_index++;
                if ($camp_index == 2) {
                    $discount = 0.20; // 20% off second camp
                } elseif ($camp_index >= 3) {
                    $discount = 0.25; // 25% off third and beyond camps
                }
            } elseif ($product_type === 'course') {
                $course_index++;
                if ($course_index == 2) {
                    $discount = 0.50; // 50% off second course
                }
            }

            $final_price = $calculated_price * (1 - $discount);
            $cart_item['data']->set_price($final_price);

            // Set discount message only if a discount is applied
            if ($discount > 0) {
                if ($product_type === 'camp') {
                    $discount_percent = round($discount * 100);
                    $cart->cart_contents[$cart_item_key]['combo_discount_note'] = "$discount_percent% Combo Discount";
                    error_log("InterSoccer: Applied $discount_percent% combo discount for camp $cart_item_key (Season: $season, Booking Type: $booking_type, Camp Index: $camp_index)");
                } elseif ($product_type === 'course' && $course_index == 2) {
                    $cart->cart_contents[$cart_item_key]['combo_discount_note'] = "50% Course Combo Discount";
                    error_log("InterSoccer: Applied 50% course combo discount for $cart_item_key (Season: $season, Course Index: $course_index)");
                }
            } else {
                unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
                error_log("InterSoccer: No discount applied for $cart_item_key (Season: $season, Type: $product_type, Booking Type: $booking_type, Camp Index: $camp_index, Course Index: $course_index), cleared combo_discount_note");
            }
        }
    }
}
/**
 * Determine the product type server-side.
 *
 * @param int $product_id The product ID.
 * @return string The product type ('camp', 'course', 'birthday', or empty string).
 */
function intersoccer_get_product_type($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for type detection: ' . $product_id);
        return '';
    }

    $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
    if ($product_type) {
        error_log('InterSoccer: Product type from meta for product ' . $product_id . ': ' . $product_type);
        return $product_type;
    }

    $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
    if (is_wp_error($categories)) {
        error_log('InterSoccer: Error fetching categories for product ' . $product_id . ': ' . $categories->get_error_message());
        $categories = [];
    }

    if (in_array('camps', $categories, true)) {
        $product_type = 'camp';
    } elseif (in_array('courses', $categories, true)) {
        $product_type = 'course';
    } elseif (in_array('birthdays', $categories, true)) {
        $product_type = 'birthday';
    }

    if (!$product_type) {
        $attributes = $product->get_attributes();
        if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
            $attribute = $attributes['pa_activity-type'];
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if ($term->slug === 'course') {
                            $product_type = 'course';
                            break;
                        } elseif ($term->slug === 'camp') {
                            $product_type = 'camp';
                            break;
                        } elseif ($term->slug === 'birthday') {
                            $product_type = 'birthday';
                            break;
                        }
                    }
                } else {
                    error_log('InterSoccer: No terms found for pa_activity-type attribute for product ' . $product_id);
                }
            } else {
                error_log('InterSoccer: pa_activity-type attribute is not a taxonomy for product ' . $product_id);
            }
        } else {
            error_log('InterSoccer: pa_activity-type attribute not found for product ' . $product_id);
        }
    }

    if (!$product_type) {
        $title = $product->get_title();
        if (stripos($title, 'course') !== false) {
            $product_type = 'course';
        } elseif (stripos($title, 'camp') !== false) {
            $product_type = 'camp';
        } elseif (stripos($title, 'birthday') !== false) {
            $product_type = 'birthday';
        }
        error_log('InterSoccer: Fallback to title for product type detection for product ' . $product_id . ', title: ' . $title . ', type: ' . ($product_type ?: 'none'));
    }

    if ($product_type) {
        update_post_meta($product_id, '_intersoccer_product_type', $product_type);
        error_log('InterSoccer: Determined and saved product type for product ' . $product_id . ': ' . $product_type);
    } else {
        error_log('InterSoccer: Could not determine product type for product ' . $product_id . ', categories: ' . print_r($categories, true));
    }

    return $product_type;
}

add_action('woocommerce_before_cart', function () {
    error_log('InterSoccer: Cart contents before rendering: ' . print_r(WC()->cart->get_cart(), true));
});

// Add meta box for player assignment on order item details
add_action('woocommerce_before_order_itemmeta', 'intersoccer_add_player_assignment_meta_box', 10, 3);
function intersoccer_add_player_assignment_meta_box($item_id, $item, $product) {
    if (!current_user_can('manage_woocommerce') || $item->get_order()->get_status() !== 'processing') {
        return;
    }

    $order_id = $item->get_order_id();
    $product_id = $item->get_product_id();
    $product_type = intersoccer_get_product_type($product_id);
    if (!in_array($product_type, ['camp', 'course'])) {
        return;
    }

    $assigned_player = $item->get_meta('Assigned Attendee');
    $user_id = $item->get_order()->get_user_id();
    $players = $user_id ? get_user_meta($user_id, 'intersoccer_players', true) : [];

    echo '<div class="intersoccer-player-assignment-meta">';
    echo '<h4>' . __('Assign Player', 'intersoccer-player-management') . '</h4>';
    echo '<select name="intersoccer_assign_player[' . $item_id . ']" class="intersoccer-player-select">';
    echo '<option value="">' . __('Select an attendee', 'intersoccer-player-management') . '</option>';
    foreach ($players as $index => $player) {
        $player_name = trim($player['first_name'] . ' ' . $player['last_name']);
        $selected = $assigned_player === $player_name ? 'selected' : '';
        if (!empty($player_name)) {
            echo '<option value="' . esc_attr($index) . '" ' . $selected . '>' . esc_html($player_name) . '</option>';
        }
    }
    echo '</select>';
    echo '<input type="hidden" name="intersoccer_order_item_id" value="' . esc_attr($item_id) . '">';
    echo '<button type="button" class="button intersoccer-save-player-assignment">' . __('Save Assignment', 'intersoccer-player-management') . '</button>';
    echo '<span class="intersoccer-save-status" style="margin-left: 10px; color: green; display: none;">' . __('Saved!', 'intersoccer-player-management') . '</span>';
    echo '</div>';

    // JavaScript for saving player assignment
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.intersoccer-save-player-assignment').on('click', function() {
            var $button = $(this);
            var $select = $button.siblings('select.intersoccer-player-select');
            var $status = $button.siblings('.intersoccer-save-status');
            var itemId = $button.siblings('input[name="intersoccer_order_item_id"]').val();
            var playerIndex = $select.val();

            $status.hide();
            $button.prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_save_player_assignment',
                    nonce: '<?php echo wp_create_nonce('intersoccer_save_player_nonce'); ?>',
                    order_item_id: itemId,
                    player_index: playerIndex
                },
                success: function(response) {
                    if (response.success) {
                        $status.show();
                        setTimeout(function() {
                            $status.fadeOut();
                        }, 2000);
                        var playerName = $select.find('option:selected').text();
                        if (playerName) {
                            $('#woocommerce-order-items').block({message: null});
                            $('#woocommerce-order-items').unblock();
                            $item.find('.wc-order-item-meta').append('<p><strong>Assigned Attendee:</strong> ' + playerName + '</p>');
                        }
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('<?php _e('Error saving player assignment.', 'intersoccer-player-management'); ?>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php
}

// AJAX handler to save player assignment
add_action('wp_ajax_intersoccer_save_player_assignment', 'intersoccer_save_player_assignment_callback');
function intersoccer_save_player_assignment_callback() {
    check_ajax_referer('intersoccer_save_player_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('You do not have permission.', 'intersoccer-player-management')]);
        wp_die();
    }

    $item_id = isset($_POST['order_item_id']) ? intval($_POST['order_item_id']) : 0;
    $player_index = isset($_POST['player_index']) ? sanitize_text_field($_POST['player_index']) : '';

    if (!$item_id) {
        wp_send_json_error(['message' => __('Invalid order item ID.', 'intersoccer-player-management')]);
        wp_die();
    }

    $item = WC_Order_Factory::get_order_item($item_id);
    if (!$item) {
        wp_send_json_error(['message' => __('Order item not found.', 'intersoccer-player-management')]);
        wp_die();
    }

    $order = $item->get_order();
    if ($order->get_status() !== 'processing') {
        wp_send_json_error(['message' => __('Player assignment can only be edited for processing orders.', 'intersoccer-player-management')]);
        wp_die();
    }

    $user_id = $order->get_user_id();
    $players = $user_id ? get_user_meta($user_id, 'intersoccer_players', true) : [];
    if ($player_index && isset($players[$player_index])) {
        $player = $players[$player_index];
        $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
        $item->update_meta_data('Assigned Attendee', $player_name);
        $item->save();
        error_log('InterSoccer: Saved player assignment for order item ' . $item_id . ': ' . $player_name);
        wp_send_json_success();
    } elseif (!$player_index) {
        $item->delete_meta_data('Assigned Attendee');
        $item->save();
        error_log('InterSoccer: Removed player assignment for order item ' . $item_id);
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => __('Invalid player index.', 'intersoccer-player-management')]);
        wp_die();
    }
}

// Add discount display under product price in cart
add_action('woocommerce_after_cart_item_name', 'intersoccer_display_discount_cart', 10, 2);
function intersoccer_display_discount_cart($cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    $discount_display = '';
    if (isset($cart_item['combo_discount_note'])) {
        $discount_display = $cart_item['combo_discount_note'];
    } elseif ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $discount_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
    }

    if ($discount_display) {
        echo '<div class="intersoccer-discount" style="color: green; font-size: 0.9em; margin-top: 5px;">' . esc_html($discount_display) . '</div>';
        error_log('InterSoccer: Added discount display for cart item ' . $cart_item_key . ': ' . $discount_display);
    }
}

// Add discount display under product price in checkout
add_action('woocommerce_review_order_after_cart_item', 'intersoccer_display_discount_checkout', 10, 3);
function intersoccer_display_discount_checkout($cart_item, $cart_item_key, $value) {
    $product = $cart_item['data'];
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);

    $discount_display = '';
    if (isset($cart_item['combo_discount_note'])) {
        $discount_display = $cart_item['combo_discount_note'];
    } elseif ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $discount_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
    }

    if ($discount_display) {
        echo '<tr class="intersoccer-discount-row"><td colspan="2"></td><td class="product-price" style="padding-top: 0;"><div class="intersoccer-discount" style="color: green; font-size: 0.9em;">' . esc_html($discount_display) . '</div></td></tr>';
        error_log('InterSoccer: Added discount display for checkout item ' . $cart_item_key . ': ' . $discount_display);
    }
}

// Move discount to subtotal column
add_filter('woocommerce_cart_item_subtotal', 'intersoccer_move_discount_to_subtotal', 10, 3);
function intersoccer_move_discount_to_subtotal($subtotal_html, $cart_item, $cart_item_key) {
    $discount_display = '';
    if (isset($cart_item['combo_discount_note'])) {
        $discount_display = '<div class="intersoccer-discount" style="color: green; font-size: 0.9em; margin-top: 5px;">' . esc_html($cart_item['combo_discount_note']) . '</div>';
    } elseif (intersoccer_get_product_type($cart_item['product_id']) === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $discount_display = '<div class="intersoccer-discount" style="color: green; font-size: 0.9em; margin-top: 5px;">' . esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining') . '</div>';
    }
    return $subtotal_html . ($discount_display ? $discount_display : '');
}

// Replace the existing intersoccer_get_user_players function
add_action('wp_ajax_intersoccer_get_user_players', 'intersoccer_get_user_players');
add_action('wp_ajax_nopriv_intersoccer_get_user_players', 'intersoccer_get_user_players');

add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_single_day_camp', 10, 3);
function intersoccer_validate_single_day_camp($passed, $product_id, $quantity) {
    if (isset($_POST['variation_id'])) {
        $variation_id = intval($_POST['variation_id']);
        $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
        if ($booking_type === 'single-days') {
            $camp_days = isset($_POST['camp_days']) ? json_decode(stripslashes($_POST['camp_days']), true) : [];
            if (!is_array($camp_days) || empty($camp_days)) {
                // Fallback to check individual camp_days[] inputs
                $camp_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? $_POST['camp_days'] : [];
                if (empty($camp_days)) {
                    $passed = false;
                    wc_add_notice(__('Please select at least one day for this single-day camp.', 'intersoccer-product-variations'), 'error');
                    error_log('InterSoccer: Validation failed - no valid camp_days data for product ' . $product_id . ': ' . print_r($_POST, true));
                } else {
                    error_log('InterSoccer: Validated single-day camp with ' . count($camp_days) . ' days for product ' . $product_id . ': ' . print_r($camp_days, true));
                }
            } else {
                error_log('InterSoccer: Validated single-day camp with ' . count($camp_days) . ' days for product ' . $product_id . ': ' . print_r($camp_days, true));
            }
        }
    }
    return $passed;
}
?>
