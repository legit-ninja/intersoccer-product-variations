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
        error_log('InterSoccer: Invalid product or variation ID in intersoccer_get_days_of_week');
        wp_send_json_error(['message' => __('Invalid product or variation ID.', 'intersoccer-product-variations')], 400);
        return;
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = absint($_POST['variation_id']);
    $product = wc_get_product($variation_id);
    if (!$product) {
        error_log('InterSoccer: Product not found for ID: ' . $variation_id);
        wp_send_json_error(['message' => __('Product not found.', 'intersoccer-product-variations')], 404);
        return;
    }

    $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
    error_log('InterSoccer: Using parent product ID: ' . $parent_id);

    $attribute_name = 'pa_days-of-week';
    $days = wc_get_product_terms($parent_id, $attribute_name, ['fields' => 'names']);

    if (empty($days)) {
        error_log('InterSoccer: No days of the week found for parent product ID: ' . $parent_id . '. Ensure pa_days-of-week attribute is set.');
        $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"]; // Fallback
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
    foreach ($cart as $key => $item) {
        $item_product_type = intersoccer_get_product_type($item['product_id']);
        $item_player_id = isset($item['player_assignment']) ? $item['player_assignment'] : null;
        $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_season', true);

        if ($item_product_type === $product_type) {
            $player_items[$item_player_id][] = $key;
        }
    }

    // Apply discounts
    if ($product_type === 'camp') {
        foreach ($player_items as $player => $item_keys) {
            $item_count = count($item_keys);
            if ($item_count > 1) {
                foreach ($item_keys as $index => $key) {
                    if ($index === 0) continue; // Skip the first item (no discount)
                    $item = $cart[$key];
                    $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], $item['camp_days']);
                    $age_group = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_age-group', true);
                    $discount_rate = ($index === 1) ? 0.20 : 0.25; // 20% for 2nd, 25% for 3rd+

                    // Check for half-day vs full-day
                    $is_half_day = strpos($age_group, 'Half-Day') !== false;
                    $reference_item = $cart[$item_keys[0]]; // First item as reference
                    $reference_age_group = get_post_meta($reference_item['variation_id'] ?: $reference_item['product_id'], 'attribute_pa_age-group', true);
                    $is_reference_half_day = strpos($reference_age_group, 'Half-Day') !== false;

                    if ($is_half_day && !$is_reference_half_day) {
                        $discount_amount = $item_base_price * $discount_rate;
                    } elseif (!$is_half_day && $is_reference_half_day) {
                        $reference_base_price = isset($reference_item['intersoccer_base_price']) ? $reference_item['intersoccer_base_price'] : intersoccer_calculate_price($reference_item['product_id'], $reference_item['variation_id'], $reference_item['camp_days']);
                        $discount_amount = min($item_base_price, $reference_base_price) * $discount_rate;
                    } else {
                        $discount_amount = $item_base_price * $discount_rate;
                    }

                    $discount += $discount_amount;
                    error_log('InterSoccer: Applied camp combo discount for item ' . $key . ': Player ' . $player . ', Index ' . $index . ', Discount: ' . $discount_amount . ' (Rate: ' . ($discount_rate * 100) . '%)');
                }
            }
        }
    } elseif ($product_type === 'course') {
        foreach ($player_items as $player => $item_keys) {
            $item_count = count($item_keys);
            if ($item_count > 1) {
                foreach ($item_keys as $index => $key) {
                    if ($index === 0) continue; // Skip the first item (no discount)
                    $item = $cart[$key];
                    $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], [], $item['remaining_weeks']);
                    $discount_rate = ($index === 1) ? 0.20 : 0.30; // 20% for 2nd, 30% for 3rd+
                    $discount_amount = $item_base_price * $discount_rate;
                    $discount += $discount_amount;
                    error_log('InterSoccer: Applied course combo discount for item ' . $key . ': Player ' . $player . ', Index ' . $index . ', Discount: ' . $discount_amount . ' (Rate: ' . ($discount_rate * 100) . '%)');
                }
            }

            // Check for same player, same season discount
            $first_season = null;
            foreach ($item_keys as $index => $key) {
                $item = $cart[$key];
                $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_season', true);
                if ($index === 0) {
                    $first_season = $item_season;
                    continue;
                }
                if ($item_season === $first_season) {
                    $item_base_price = isset($item['intersoccer_base_price']) ? $item['intersoccer_base_price'] : intersoccer_calculate_price($item['product_id'], $item['variation_id'], [], $item['remaining_weeks']);
                    $discount_rate = 0.50; // 50% discount
                    $discount_amount = $item_base_price * $discount_rate;
                    $discount += $discount_amount;
                    error_log('InterSoccer: Applied same-season course discount for item ' . $key . ': Player ' . $player . ', Season ' . $item_season . ', Discount: ' . $discount_amount . ' (Rate: 50%)');
                }
            }
        }
    }

    return $discount;
}
?>
