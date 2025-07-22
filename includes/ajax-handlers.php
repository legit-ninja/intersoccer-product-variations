<?php
/**
 * File: ajax-handlers.php
 * Description: Handles AJAX requests for product variations operations in the InterSoccer Product Variations plugin.
 * Dependencies: None
 * Changes:
 * - Removed player add/edit/delete handlers, retaining only read operations (2025-06-09).
 * - Added logging and fallback for missing pa_days-of-week (2025-05-25).
 * - Improved course metadata validation (2025-05-25).
 * - Relaxed user_id check in intersoccer_get_user_players (2025-05-26).
 * - Added detailed logging for nonce and user issues (2025-05-26).
 * - Removed duplicate intersoccer_get_product_type handler, using version from woocommerce-modifications.php (2025-05-27).
 * - Updated intersoccer_get_course_metadata to use intersoccer_get_product_type (2025-05-27).
 * - Fixed start_date retrieval to use get_attribute for pa_start-date (2025-05-27).
 * - Fixed start_date retrieval to use get_post_meta for _course_start_date (2025-05-27).
 * - Removed fallback in intersoccer_get_days_of_week, relying on pa_days-of-week attribute (2025-05-27).
 * - Updated to ensure consistent 'days' response key (2025-06-22).
 * - Added support for combo discount logging (2025-06-22).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Debugging to confirm handler is loaded
error_log('InterSoccer: ajax-handlers.php loaded');

// Get user players (read-only for selection)
add_action('wp_ajax_intersoccer_get_user_players', 'intersoccer_get_user_players');
add_action('wp_ajax_nopriv_intersoccer_get_user_players', 'intersoccer_get_user_players');
function intersoccer_get_user_players()
{
    if (ob_get_length()) {
        ob_clean();
    }

    error_log('InterSoccer: intersoccer_get_user_players called');
    error_log('InterSoccer: POST data: ' . print_r($_POST, true));

    // Verify nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        error_log('InterSoccer: Nonce verification failed for intersoccer_get_user_players. Provided nonce: ' . $nonce);
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        return;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('InterSoccer: User not logged in for intersoccer_get_user_players');
        wp_send_json_error(['message' => __('You must be logged in.', 'intersoccer-product-variations')], 403);
        return;
    }

    // Relaxed user_id check: Log mismatch but don't fail
    if (isset($_POST['user_id']) && absint($_POST['user_id']) !== $user_id) {
        error_log('InterSoccer: User ID mismatch in intersoccer_get_user_players. POST user_id: ' . absint($_POST['user_id']) . ', Server user_id: ' . $user_id);
        // Proceed with server-side user_id
    }

    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    error_log('InterSoccer: Fetched players for user ID ' . $user_id . ': ' . print_r($players, true));
    wp_send_json_success(['players' => $players]);
}

// Get days of the week for a product
add_action('wp_ajax_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week');
add_action('wp_ajax_nopriv_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week');
function intersoccer_get_days_of_week()
{
    if (ob_get_length()) {
        ob_clean();
    }

    error_log('InterSoccer: intersoccer_get_days_of_week called');
    error_log('InterSoccer: POST data: ' . print_r($_POST, true));
    error_log('InterSoccer: Current user ID: ' . get_current_user_id());

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        error_log('InterSoccer: Nonce verification failed for intersoccer_get_days_of_week. Provided nonce: ' . $nonce);
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        return;
    }

    if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id']) || !isset($_POST['variation_id']) || !is_numeric($_POST['variation_id'])) {
        error_log('InterSoccer: Invalid or missing product_id or variation_id in intersoccer_get_days_of_week. product_id: ' . ($_POST['product_id'] ?? 'not set') . ', variation_id: ' . ($_POST['variation_id'] ?? 'not set'));
        wp_send_json_error(['message' => __('Invalid product or variation ID.', 'intersoccer-product-variations')], 400);
        return;
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = absint($_POST['variation_id']);
    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer: Product not found for variation_id: ' . $variation_id . ', product_id: ' . $product_id);
        wp_send_json_error(['message' => __('Product not found.', 'intersoccer-product-variations')], 404);
        return;
    }

    $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
    error_log('InterSoccer: Using parent product ID: ' . $parent_id);

    $attribute_name = 'pa_days-of-week';
    $days = wc_get_product_terms($parent_id, $attribute_name, ['fields' => 'names']);

    if (empty($days)) {
        error_log('InterSoccer: No days of the week found for parent product ID: ' . $parent_id . '. Returning fallback days.');
        $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
    } else {
        $day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        usort($days, function ($a, $b) use ($day_order) {
            $pos_a = array_search($a, $day_order);
            $pos_b = array_search($b, $day_order);
            if ($pos_a === false) $pos_a = count($day_order);
            if ($pos_b === false) $pos_b = count($day_order);
            return $pos_a - $pos_b;
        });
    }

    error_log('InterSoccer: Days fetched and sorted for product ' . $parent_id . ': ' . print_r($days, true));
    wp_send_json_success(['days' => $days]);
}

// Get course metadata
add_action('wp_ajax_intersoccer_get_course_metadata', 'intersoccer_get_course_metadata');
add_action('wp_ajax_nopriv_intersoccer_get_course_metadata', 'intersoccer_get_course_metadata');
function intersoccer_get_course_metadata()
{
    if (ob_get_length()) {
        ob_clean();
    }

    error_log('InterSoccer: intersoccer_get_course_metadata called');
    error_log('InterSoccer: POST data: ' . print_r($_POST, true));

    check_ajax_referer('intersoccer_nonce', 'nonce', false);
    if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id']) || !isset($_POST['variation_id']) || !is_numeric($_POST['variation_id'])) {
        wp_send_json_error(['message' => __('Invalid product or variation ID.', 'intersoccer-product-variations')], 400);
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = absint($_POST['variation_id']);
    $product = wc_get_product($variation_id);
    if (!$product) {
        wp_send_json_error(['message' => __('Product not found.', 'intersoccer-product-variations')], 404);
    }

    $product_type = intersoccer_get_product_type($product_id);
    if ($product_type !== 'course') {
        error_log('InterSoccer: Product ' . $product_id . ' is not a Course, returning default metadata');
        wp_send_json_success(array(
            'start_date' => '',
            'total_weeks' => 0,
            'weekly_discount' => 0,
            'remaining_weeks' => 0,
        ));
        wp_die();
    }

    // Retrieve start_date using get_post_meta for _course_start_date
    $start_date = get_post_meta($variation_id, '_course_start_date', true);
    if (!$start_date) {
        // Fallback to parent product if variation doesn't have the meta
        $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
        $start_date = get_post_meta($parent_id, '_course_start_date', true);
        error_log('InterSoccer: Retrieved _course_start_date from parent product ' . $parent_id . ': ' . ($start_date ?: 'not set'));
    }

    $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
    $weekly_discount = (float) get_post_meta($variation_id, '_course_weekly_discount', true);

    // Validate date format (should already be in YYYY-MM-DD from admin save)
    if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
        error_log('InterSoccer: Invalid or missing _course_start_date for variation ' . $variation_id . ', raw value: ' . ($start_date ?: 'not set'));
        $start_date = date('Y-m-d'); // Fallback to today
    } else {
        error_log('InterSoccer: Retrieved _course_start_date for variation ' . $variation_id . ': ' . $start_date);
    }

    $weekly_discount = floatval($weekly_discount ?: 0);
    $total_weeks = intval($total_weeks ?: 1);
    if ($total_weeks < 1) {
        error_log('InterSoccer: Invalid _course_total_weeks for variation ' . $variation_id);
        $total_weeks = 1;
    }

    // Calculate remaining weeks
    $server_time = current_time('timestamp');
    $start = strtotime($start_date);

    $remaining_weeks = $total_weeks;
    if ($start && $server_time > $start) {
        $weeks_passed = floor(($server_time - $start) / (7 * 24 * 60 * 60));
        $remaining_weeks = max(0, $total_weeks - $weeks_passed);
    }

    $metadata = [
        'start_date' => $start_date,
        'total_weeks' => $total_weeks,
        'weekly_discount' => $weekly_discount,
        'remaining_weeks' => $remaining_weeks,
    ];

    error_log('InterSoccer: Course metadata for variation ' . $variation_id . ': ' . print_r($metadata, true));
    wp_send_json_success($metadata);
}

/**
 * Apply combo discounts based on cart contents.
 *
 * @param string $cart_item_key The cart item key.
 * @param string $product_type The product type ('camp' or 'course').
 * @param string $player_id The player assignment index.
 * @param string $season The season attribute (for courses).
 * @param float $base_price The base price of the item.
 * @return float The discount amount.
 */
function apply_combo_discounts($cart_item_key, $product_type, $player_id, $season, $base_price) {
    $discount = 0;
    $cart = WC()->cart->get_cart();

    // Group cart items by player and product type
    $player_items = [];
    $player_seasons = []; // For same-season course discounts
    foreach ($cart as $key => $item) {
        $item_product_type = intersoccer_get_product_type($item['product_id']);
        $item_player_id = isset($item['player_assignment']) ? $item['player_assignment'] : null;
        $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_program-season', true) ?: 'unknown';

        if ($item_product_type === $product_type && $item_player_id) {
            $player_items[$item_player_id][] = [
                'key' => $item_product_type === 'camp' ? $key : ($item_product_type === 'course' && $item_season === $season ? $key : null),
                'item' => $item
            ];
            if ($item_product_type === 'course' && $item_season === $season) {
                $player_seasons[$item_player_id][] = $key;
            }
        }
    }

    // Apply discounts
    if ($product_type === 'camp') {
        // Family Discounts: Count unique players (children)
        $unique_players = array_keys($player_items);
        $player_count = count($unique_players);
        if ($player_count > 1) {
            // Sort items by player to determine discount eligibility
            $all_items = [];
            foreach ($player_items as $player => $items) {
                foreach ($items as $item) {
                    if ($item['key']) {
                        $all_items[] = [
                            'key' => $item['key'],
                            'player' => $player,
                            'item' => $item['item']
                        ];
                    }
                }
            }
            // Sort by item addition order (assuming cart order reflects sequence)
            $player_index = array_search($player_id, $unique_players);
            if ($player_index > 0) { // Skip first child
                $discount_rate = ($player_index == 1) ? 0.20 : 0.25; // 20% for 2nd child, 25% for 3rd+
                $item = $cart[$cart_item_key];
                $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], $item['camp_days']);
                $age_group = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_age-group', true);
                $is_half_day = strpos($age_group, 'Half-Day') !== false;

                // Find reference item (first item of first player)
                $reference_item = null;
                foreach ($all_items as $item_data) {
                    if ($item_data['player'] === $unique_players[0]) {
                        $reference_item = $item_data['item'];
                        break;
                    }
                }
                $reference_age_group = $reference_item ? get_post_meta($reference_item['variation_id'] ?: $reference_item['product_id'], 'attribute_pa_age-group', true) : 'unknown';
                $is_reference_half_day = strpos($reference_age_group, 'Half-Day') !== false;

                if ($is_half_day && !$is_reference_half_day) {
                    $discount_amount = $item_base_price * $discount_rate;
                } elseif (!$is_half_day && $is_reference_half_day) {
                    $reference_base_price = $reference_item ? intersoccer_calculate_price($reference_item['product_id'], $reference_item['variation_id'], $reference_item['camp_days']) : $item_base_price;
                    $discount_amount = min($item_base_price, $reference_base_price) * $discount_rate;
                } else {
                    $discount_amount = $item_base_price * $discount_rate;
                }

                $discount += $discount_amount;
                $discount_note = sprintf(__('%d%% Family Discount (%s Child)', 'intersoccer-player-management'), $discount_rate * 100, ($player_index == 1) ? '2nd' : ($player_index == 2 ? '3rd' : 'Additional'));
                $cart[$cart_item_key]['combo_discount_note'] = $discount_note;
                error_log('InterSoccer: Applied camp family discount for item ' . $cart_item_key . ': Player ' . $player_id . ', Index ' . $player_index . ', Discount: ' . $discount_amount . ' (Rate: ' . ($discount_rate * 100) . '%, Note: ' . $discount_note . ')');
            }
        }
    } elseif ($product_type === 'course') {
        // Combo Offer: Count unique players and same-season courses
        $unique_players = array_keys($player_items);
        $player_count = count($unique_players);
        if ($player_count > 1) {
            // Sort items by player for different children discount
            $all_items = [];
            foreach ($player_items as $player => $items) {
                foreach ($items as $item) {
                    if ($item['key']) {
                        $all_items[] = [
                            'key' => $item['key'],
                            'player' => $player,
                            'item' => $item['item']
                        ];
                    }
                }
            }
            $player_index = array_search($player_id, $unique_players);
            if ($player_index > 0) { // Skip first child
                $discount_rate = ($player_index == 1) ? 0.20 : 0.30; // 20% for 2nd child, 30% for 3rd+
                $item = $cart[$cart_item_key];
                $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], [], $item['remaining_weeks']);
                $discount_amount = $item_base_price * $discount_rate;
                $discount += $discount_amount;
                $discount_note = sprintf(__('%d%% Combo Discount (%s Child)', 'intersoccer-player-management'), $discount_rate * 100, ($player_index == 1) ? '2nd' : ($player_index == 2 ? '3rd' : 'Additional'));
                $cart[$cart_item_key]['combo_discount_note'] = $discount_note;
                error_log('InterSoccer: Applied course combo discount for item ' . $cart_item_key . ': Player ' . $player_id . ', Index ' . $player_index . ', Discount: ' . $discount_amount . ' (Rate: ' . ($discount_rate * 100) . '%, Note: ' . $discount_note . ')');
            }
        }

        // Same player, same season discount
        if (isset($player_seasons[$player_id]) && count($player_seasons[$player_id]) > 1) {
            $item_index = array_search($cart_item_key, $player_seasons[$player_id]);
            if ($item_index > 0) { // Skip first course for same player
                $item = $cart[$cart_item_key];
                $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], [], $item['remaining_weeks']);
                $discount_rate = 0.50; // 50% for 2nd+ course in same season
                $discount_amount = $item_base_price * $discount_rate;
                $discount += $discount_amount;
                $discount_note = __('50% Same-Season Course Discount', 'intersoccer-player-management');
                $cart[$cart_item_key]['combo_discount_note'] = $discount_note;
                error_log('InterSoccer: Applied same-season course discount for item ' . $cart_item_key . ': Player ' . $player_id . ', Season ' . $season . ', Discount: ' . $discount_amount . ' (Rate: 50%, Note: ' . $discount_note . ')');
            }
        }
    }

    return $discount;
}

/**
 * Calculate dynamic price for a variation
 */
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price');
function intersoccer_calculate_dynamic_price() {
    if (ob_get_length()) {
        ob_clean();
    }

    error_log('InterSoccer: intersoccer_calculate_dynamic_price called');
    error_log('InterSoccer: POST data: ' . print_r($_POST, true));

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        error_log('InterSoccer: Nonce verification failed for intersoccer_calculate_dynamic_price. Provided nonce: ' . $nonce);
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        return;
    }

    if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id']) || !isset($_POST['variation_id']) || !is_numeric($_POST['variation_id'])) {
        error_log('InterSoccer: Invalid or missing product_id or variation_id in intersoccer_calculate_dynamic_price. product_id: ' . ($_POST['product_id'] ?? 'not set') . ', variation_id: ' . ($_POST['variation_id'] ?? 'not set'));
        wp_send_json_error(['message' => __('Invalid product or variation ID.', 'intersoccer-product-variations')], 400);
        return;
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = absint($_POST['variation_id']);
    $selected_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? array_map('sanitize_text_field', $_POST['camp_days']) : [];
    $remaining_sessions = isset($_POST['remaining_weeks']) ? absint($_POST['remaining_weeks']) : null;

    error_log('InterSoccer: Calculating price for product_id: ' . $product_id . ', variation_id: ' . $variation_id . ', selected_days: ' . json_encode($selected_days) . ', remaining_sessions: ' . $remaining_sessions);

    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer: Product not found for variation_id: ' . $variation_id);
        wp_send_json_error(['message' => __('Product not found.', 'intersoccer-product-variations')], 404);
        return;
    }

    $product_type = intersoccer_get_product_type($product_id);
    $base_price = floatval($product->get_price());
    $price = $base_price;

    if ($product_type === 'camp' && !empty($selected_days)) {
        $price_per_day = $base_price; // Base price is per day for single-days
        $price = $price_per_day * count($selected_days);
        error_log('InterSoccer: Calculated Camp price for ' . $variation_id . ': ' . $price . ' (per day: ' . $price_per_day . ', days: ' . count($selected_days) . ', selected_days: ' . json_encode($selected_days) . ')');
    } elseif ($product_type === 'course' && $remaining_sessions !== null) {
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $session_rate = floatval(get_post_meta($variation_id, '_course_weekly_discount', true));
        $total_sessions = calculate_total_sessions($variation_id, $total_weeks); // Add this function similar to woocommerce-modifications.php
        if ($remaining_sessions < $total_sessions && $session_rate > 0) {
            $price = $session_rate * $remaining_sessions;
        } // Else, use the base $price if remaining == total or no session_rate
        error_log('InterSoccer: Calculated Course price for ' . $variation_id . ': ' . $price . ' (base price: ' . $base_price . ', session_rate: ' . $session_rate . ', remaining sessions: ' . $remaining_sessions . ', total sessions: ' . $total_sessions . ')');
    }

    $formatted_price = wc_price($price);
    $response = [
        'price' => $formatted_price,
        'raw_price' => $price
    ];

    // Store in session for cart consistency
    WC()->session->set('intersoccer_selected_days_' . $variation_id, $selected_days);
    WC()->session->set('intersoccer_calculated_price_' . $variation_id, $price);
    error_log('InterSoccer: Session data updated for product ' . $product_id . ', selected_days: ' . json_encode($selected_days) . ', price: ' . $price);

    wp_send_json_success($response);
}
?>
