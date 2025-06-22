<?php
if (!defined('ABSPATH')) {
    exit;
}

// Apply combo discount in cart based on pa_program-season
add_action('woocommerce_before_calculate_totals', 'intersoccer_apply_combo_discount_in_cart', 10, 1);
function intersoccer_apply_combo_discount_in_cart($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    $cart_items = $cart->get_cart();
    $grouped_items = [];

    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_type = get_post_meta($cart_item['product_id'], '_intersoccer_product_type', true);
        if (in_array($product_type, ['camp', 'course'])) {
            $season = isset($cart_item['parent_attributes']['Program Season']) ? $cart_item['parent_attributes']['Program Season'] : 'unknown';
            if (!isset($grouped_items[$season])) {
                $grouped_items[$season] = [];
            }
            $price = isset($cart_item['intersoccer_calculated_price']) ? floatval($cart_item['intersoccer_calculated_price']) : floatval($cart_item['data']->get_price());
            $grouped_items[$season][$cart_item_key] = $price;
        }
    }

    foreach ($grouped_items as $season => $items) {
        $sorted_items = $items;
        asort($sorted_items);
        $item_index = 0;

        foreach ($sorted_items as $cart_item_key => $price) {
            $discount = 0;
            if ($item_index == 1) {
                $discount = 0.20; // 20% off second item
            } elseif ($item_index >= 2) {
                $discount = 0.25; // 25% off third and beyond
            }
            $final_price = $price * (1 - $discount);
            $cart_items[$cart_item_key]['data']->set_price($final_price);

            if ($discount > 0) {
                $discount_percent = $discount * 100;
                $cart_items[$cart_item_key]['combo_discount_note'] = "$discount_percent% Combo Discount";
            } else {
                unset($cart_items[$cart_item_key]['combo_discount_note']);
            }
            $item_index++;
        }
    }
}

/**
 * Save player, days, discount, and parent attributes to cart item.
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    static $is_processing = false;
    if ($is_processing) {
        error_log('InterSoccer: Skipped recursive call in intersoccer_add_cart_item_data');
        return $cart_item_data;
    }
    $is_processing = true;

    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for cart item: product_id=' . $product_id . ', variation_id=' . $variation_id);
        $is_processing = false;
        return $cart_item_data;
    }

    if (isset($_POST['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($_POST['player_assignment']);
        error_log('InterSoccer: Added player to cart via POST: ' . $cart_item_data['player_assignment']);
    }

    if (isset($_POST['camp_days'])) {
        $camp_days = json_decode(stripslashes($_POST['camp_days']), true);
        if (is_array($camp_days) && !empty($camp_days)) {
            $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $camp_days));
            error_log('InterSoccer: Added unique camp days to cart via POST: ' . print_r($cart_item_data['camp_days'], true));
        } else {
            error_log('InterSoccer: Invalid or empty camp_days data from POST: ' . print_r($_POST['camp_days'], true));
        }
        unset($_POST['camp_days']);
    }

    if (isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($_POST['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via POST: ' . $cart_item_data['remaining_weeks']);
    } elseif (!isset($cart_item_data['remaining_weeks']) && !isset($_POST['remaining_weeks'])) {
        $product_type = intersoccer_get_product_type($product_id);
        if ($product_type === 'course') {
            $start_date = get_post_meta($variation_id, '_course_start_date', true);
            if (!$start_date) {
                $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
                $start_date = get_post_meta($parent_id, '_course_start_date', true);
                error_log('InterSoccer: Retrieved _course_start_date from parent product ' . $parent_id . ': ' . ($start_date ?: 'not set'));
            }

            $total_weeks = get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
            if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && strtotime($start_date)) {
                $server_time = current_time('Y-m-d');
                $start = new DateTime($start_date);
                $current = new DateTime($server_time);
                $weeks_passed = floor(($current->getTimestamp() - $start->getTimestamp()) / (7 * 24 * 60 * 60));
                $cart_item_data['remaining_weeks'] = max(0, intval($total_weeks) - $weeks_passed);
                error_log('InterSoccer: Fallback calculated remaining_weeks for course item ' . ($variation_id ?: $product_id) . ': ' . $cart_item_data['remaining_weeks']);
            } else {
                $cart_item_data['remaining_weeks'] = 0;
                error_log('InterSoccer: No valid start date, setting remaining_weeks to 0 for course item ' . ($variation_id ?: $product_id));
            }
        }
    }

    if (isset($_POST['sibling_count']) && is_numeric($_POST['sibling_count'])) {
        $cart_item_data['sibling_count'] = intval($_POST['sibling_count']);
        error_log('InterSoccer: Added sibling count to cart via POST: ' . $cart_item_data['sibling_count']);
    }

    $product_type = intersoccer_get_product_type($product_id);
    $cart_item_data['parent_attributes'] = intersoccer_get_parent_attributes($product, $product_type);

    $is_processing = false;
    return $cart_item_data;
}
/**
 * Prevent quantity changes in cart for all products.
 */
add_filter('woocommerce_cart_item_quantity', 'intersoccer_cart_item_quantity', 10, 3);
function intersoccer_cart_item_quantity($quantity_html, $cart_item_key, $cart_item) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

/**
 * Prevent quantity changes in checkout.
 */
add_filter('woocommerce_checkout_cart_item_quantity', 'intersoccer_checkout_cart_item_quantity', 10, 3);
function intersoccer_checkout_cart_item_quantity($quantity_html, $cart_item, $cart_item_key) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// [Existing get_player_details remains unchanged]

add_action('woocommerce_before_cart', function () {
    error_log('InterSoccer: Cart contents before rendering: ' . print_r(WC()->cart->get_cart(), true));
});

// [Existing intersoccer_save_product_type_meta and intersoccer_save_course_metadata remain unchanged]

add_action('wp_ajax_intersoccer_get_product_type', 'intersoccer_get_product_type_callback');
add_action('wp_ajax_nopriv_intersoccer_get_product_type', 'intersoccer_get_product_type_callback');
function intersoccer_get_product_type_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product ID']);
        wp_die();
    }

    $product_type = intersoccer_get_product_type($product_id);
    wp_send_json_success(['product_type' => $product_type]);
    wp_die();
}

// Adjust cart item price display to show base price
add_filter('woocommerce_cart_item_price', 'intersoccer_display_base_price_in_cart', 10, 3);
function intersoccer_display_base_price_in_cart($price_html, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $base_price = wc_price($product->get_regular_price());
    return $base_price; // Show original base price
}

function intersoccer_get_user_players()
{
    if (ob_get_length()) {
        ob_clean();
    }

    error_log('InterSoccer: intersoccer_get_user_players called');
    error_log('InterSoccer: POST data: ' . print_r($_POST, true));

    // Verify nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce) {
        error_log('InterSoccer: No nonce provided for intersoccer_get_user_players');
        wp_send_json_error(['message' => __('No nonce provided.', 'intersoccer-product-variations'), 'status' => 400]);
        return;
    }
    if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        error_log('InterSoccer: Nonce verification failed for intersoccer_get_user_players. Provided nonce: ' . $nonce);
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations'), 'status' => 403]);
        return;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('InterSoccer: User not logged in for intersoccer_get_user_players');
        wp_send_json_error(['message' => __('You must be logged in.', 'intersoccer-product-variations'), 'status' => 401]);
        return;
    }

    if (isset($_POST['user_id']) && absint($_POST['user_id']) !== $user_id) {
        error_log('InterSoccer: User ID mismatch in intersoccer_get_user_players. POST user_id: ' . absint($_POST['user_id']) . ', Server user_id: ' . $user_id);
    }

    $players = get_user_meta($user_id, 'intersoccer_players', true);
    if ($players === false) {
        error_log('InterSoccer: Failed to retrieve user meta for user ID ' . $user_id . '. Check database permissions or meta key.');
        wp_send_json_error(['message' => __('Failed to retrieve player data.', 'intersoccer-product-variations'), 'status' => 500]);
        return;
    }
    if (!is_array($players)) {
        error_log('InterSoccer: Invalid player data format for user ID ' . $user_id . ': ' . print_r($players, true));
        $players = [];
    }

    error_log('InterSoccer: Fetched players for user ID ' . $user_id . ': ' . print_r($players, true));
    wp_send_json_success(['players' => $players]);
}

// Add AJAX handler for fetching days of week for single-day camps
add_action('wp_ajax_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week_callback');
add_action('wp_ajax_nopriv_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week_callback');
function intersoccer_get_days_of_week_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;

    if (!$product_id || !$variation_id) {
        error_log('InterSoccer: Invalid product or variation ID for days of week fetch');
        wp_send_json_error(['message' => __('Invalid product or variation ID.', 'intersoccer-product-variations'), 'status' => 400]);
        wp_die();
    }

    $product = wc_get_product($variation_id);
    if (!$product || $product->get_type() !== 'variation') {
        error_log('InterSoccer: Invalid or non-variation product for days of week fetch: ' . $variation_id);
        wp_send_json_error(['message' => __('Invalid product type.', 'intersoccer-product-variations'), 'status' => 400]);
        wp_die();
    }

    $parent_id = $product->get_parent_id();
    $terms = wc_get_product_terms($parent_id, 'pa_days-of-week', ['fields' => 'names']);

    if (is_wp_error($terms) || empty($terms)) {
        error_log('InterSoccer: No valid pa_days-of-week terms found for parent product ' . $parent_id . ': ' . (is_wp_error($terms) ? $terms->get_error_message() : 'empty'));
        wp_send_json_error(['message' => __('No days of week available for this product.', 'intersoccer-product-variations'), 'status' => 404]);
    } else {
        error_log('InterSoccer: Fetched pa_days-of-week terms for parent product ' . $parent_id . ': ' . print_r($terms, true));
        wp_send_json_success(['days_of_week' => $terms]);
    }
    wp_die();
}

?>
