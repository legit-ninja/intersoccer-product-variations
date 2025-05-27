<?php
/**
 * File: woocommerce-modifications.php
 * Description: Customizes WooCommerce functionality for the InterSoccer Player Management plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Changes (Summarized):
 * - Initial implementation with price calculations, session management, and cart item handling (2025-05-26).
 * - Enhanced security, fixed price display, and improved product type detection (2025-05-27).
 * - Added dynamic price calculation via AJAX and removed manual subtotal adjustments (2025-05-27).
 * - Persisted adjusted price in cart session for correct totals (2025-05-27).
 * - Added defensive checks to prevent fatal error when adding Courses to cart (2025-05-27).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate the price for a product based on submitted data (server-side).
 *
 * @param int $product_id The product ID.
 * @param int $variation_id The variation ID (if applicable).
 * @param array $camp_days Selected days for Camps (if applicable).
 * @param int $remaining_weeks Remaining weeks for Courses (if applicable).
 * @return float The calculated price.
 */
function intersoccer_calculate_price($product_id, $variation_id, $camp_days = [], $remaining_weeks = null) {
    $product = wc_get_product($variation_id ? $variation_id : $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for price calculation: ' . ($variation_id ?: $product_id));
        return 0; // Invalid product
    }

    $price = floatval($product->get_price()); // Base price from product/variation
    $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);

    if ($product_type === 'camp') {
        // Camp pricing logic
        $booking_type = get_post_meta($variation_id ? $variation_id : $product_id, 'attribute_pa_booking-type', true);
        if ($booking_type === 'single-days' && !empty($camp_days)) {
            // Calculate price based on number of selected days
            $price_per_day = $price; // Price is per day for single-days variation
            $price = $price_per_day * count($camp_days);
            error_log('InterSoccer: Calculated Camp price for ' . ($variation_id ?: $product_id) . ': ' . $price . ' (per day: ' . $price_per_day . ', days: ' . count($camp_days) . ')');
        }
        // Full-week Camps use the base price as-is
    } elseif ($product_type === 'course' && $remaining_weeks !== null) {
        // Course pricing logic with pro-rated discount
        $total_weeks = (int) get_post_meta($variation_id ? $variation_id : $product_id, '_course_total_weeks', true);
        $weekly_discount = (float) get_post_meta($variation_id ? $variation_id : $product_id, '_course_weekly_discount', true);

        if ($total_weeks > 0 && $remaining_weeks > 0 && $remaining_weeks <= $total_weeks) {
            $weekly_rate = $weekly_discount > 0 ? $weekly_discount : ($price / $total_weeks);
            $price = $weekly_rate * $remaining_weeks;
            $price = max(0, $price); // Ensure price doesn't go below 0
            error_log('InterSoccer: Calculated Course price for ' . ($variation_id ?: $product_id) . ': ' . $price . ' (weekly rate: ' . $weekly_rate . ', remaining weeks: ' . $remaining_weeks . ')');
        } else {
            error_log('InterSoccer: Invalid total_weeks or remaining_weeks for Course price calculation: ' . ($variation_id ?: $product_id) . ', total_weeks: ' . $total_weeks . ', remaining_weeks: ' . $remaining_weeks);
        }
    } else {
        error_log('InterSoccer: No special pricing logic applied for product type: ' . ($product_type ?: 'unknown') . ', using base price: ' . $price);
    }

    // Ensure price is a float and not negative
    return max(0, floatval($price));
}

/**
 * Determine the product type server-side.
 *
 * @param int $product_id The product ID.
 * @return string The product type ('camp', 'course', or empty string).
 */
function intersoccer_get_product_type($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for type detection: ' . $product_id);
        return '';
    }

    // Check existing meta
    $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
    if ($product_type) {
        error_log('InterSoccer: Product type from meta for product ' . $product_id . ': ' . $product_type);
        return $product_type;
    }

    // Check categories
    $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
    if (is_wp_error($categories)) {
        error_log('InterSoccer: Error fetching categories for product ' . $product_id . ': ' . $categories->get_error_message());
        $categories = [];
    }

    if (in_array('camps', $categories, true)) {
        $product_type = 'camp';
    } elseif (in_array('courses', $categories, true)) {
        $product_type = 'course';
    }

    // Fallback: Check attributes
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

    // Fallback: Check product title as a last resort
    if (!$product_type) {
        $title = $product->get_title();
        if (stripos($title, 'course') !== false) {
            $product_type = 'course';
        } elseif (stripos($title, 'camp') !== false) {
            $product_type = 'camp';
        }
        error_log('InterSoccer: Fallback to title for product type detection for product ' . $product_id . ', title: ' . $title . ', type: ' . ($product_type ?: 'none'));
    }

    // Save the detected type to meta for future consistency
    if ($product_type) {
        update_post_meta($product_id, '_intersoccer_product_type', $product_type);
        error_log('InterSoccer: Determined and saved product type for product ' . $product_id . ': ' . $product_type);
    } else {
        error_log('InterSoccer: Could not determine product type for product ' . $product_id . ', categories: ' . print_r($categories, true));
    }

    return $product_type;
}

/**
 * Modify variation prices for display in the frontend.
 * Avoid session access during product rendering to prevent fatal errors.
 */
add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices', 10, 3);
function intersoccer_modify_variation_prices($prices, $product, $for_display) {
    $product_id = $product->get_id();

    // During product rendering, session data isn't reliable; use defaults
    // Session data will be applied during cart operations
    $selected_days = []; // Default to empty array
    $remaining_weeks = null; // Default to null

    error_log('InterSoccer: Modifying variation prices for product ' . $product_id . ' during rendering, using defaults: selected_days: [], remaining_weeks: null');

    foreach ($prices['price'] as $variation_id => $price) {
        $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $selected_days, $remaining_weeks);
        $prices['price'][$variation_id] = $calculated_price;
        $prices['regular_price'][$variation_id] = $calculated_price; // Update regular price to match
        $prices['sale_price'][$variation_id] = $calculated_price; // Update sale price to match
    }

    return $prices;
}

/**
 * AJAX handler to calculate the price dynamically.
 */
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
function intersoccer_calculate_dynamic_price_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Invalid product or variation ID']);
        wp_die();
    }

    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);
    wp_send_json_success(['price' => wc_price($calculated_price), 'raw_price' => $calculated_price]);
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

                // Send AJAX request to update session
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
                // Clear session data on reset
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

            // Trigger an initial check to ensure price reflects session data on page load
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
        // Ensure session is initialized
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
            if ($remaining_weeks !== null) {
                WC()->session->set('intersoccer_remaining_weeks_' . $product_id, $remaining_weeks);
            }
            error_log('InterSoccer: Session data updated for product ' . $product_id . ', selected_days: ' . print_r($camp_days, true) . ', remaining_weeks: ' . ($remaining_weeks ?? 'null'));
            wp_send_json_success(array('message' => 'Session data updated'));
        } else {
            error_log('InterSoccer: WooCommerce session not available during intersoccer_update_session_data_callback');
            wp_send_json_error(array('message' => 'Session not available'));
        }
    } else {
        wp_send_json_error(array('message' => 'Invalid product ID'));
    }
    wp_die();
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
        // Ensure session is initialized
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, []);
            WC()->session->set('intersoccer_remaining_weeks_' . $product_id, null);
            error_log('InterSoccer: Session data cleared for product ' . $product_id);
            wp_send_json_success(array('message' => 'Session data cleared'));
        } else {
            error_log('InterSoccer: WooCommerce session not available during intersoccer_clear_session_data_callback');
            wp_send_json_error(array('message' => 'Session not available'));
        }
    } else {
        wp_send_json_error(array('message' => 'Invalid product ID'));
    }
    wp_die();
}

// Save player and days to cart item
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    static $is_processing = false;
    if ($is_processing) {
        error_log('InterSoccer: Skipped recursive call in intersoccer_add_cart_item_data');
        return $cart_item_data;
    }
    $is_processing = true;

    if (isset($_POST['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($_POST['player_assignment']);
        error_log('InterSoccer: Added player to cart via POST: ' . $cart_item_data['player_assignment']);
    } elseif (isset($cart_item_data['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($cart_item_data['player_assignment']);
        error_log('InterSoccer: Added player to cart via cart_item_data: ' . $cart_item_data['player_assignment']);
    }

    if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $_POST['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via POST: ' . print_r($cart_item_data['camp_days'], true));
        unset($_POST['camp_days']);
    } elseif (isset($cart_item_data['camp_days']) && is_array($cart_item_data['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $cart_item_data['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via cart_item_data: ' . print_r($cart_item_data['camp_days'], true));
    }

    if (isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($_POST['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via POST: ' . $cart_item_data['remaining_weeks']);
    } elseif (isset($cart_item_data['remaining_weeks']) && is_numeric($cart_item_data['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via cart_item_data: ' . $cart_item_data['remaining_weeks']);
    }

    // Fallback: Calculate remaining_weeks if not provided
    if (!isset($cart_item_data['remaining_weeks']) && !isset($_POST['remaining_weeks'])) {
        $product = wc_get_product($variation_id ?: $product_id);
        if ($product) {
            $product_type = intersoccer_get_product_type($product_id);
            if ($product_type === 'course') {
                $start_date = get_post_meta($variation_id, '_course_start_date', true);
                if (!$start_date) {
                    // Fallback to parent product if variation doesn't have the meta
                    $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
                    $start_date = get_post_meta($parent_id, '_course_start_date', true);
                    error_log('InterSoccer: Retrieved _course_start_date from parent product ' . $parent_id . ': ' . ($start_date ?: 'not set'));
                }

                $total_weeks = get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);
                
                // Validate date format (should already be in YYYY-MM-DD from admin save)
                if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !strtotime($start_date)) {
                    error_log('InterSoccer: Invalid or missing _course_start_date for course item ' . ($variation_id ?: $product_id) . ', raw value: ' . ($start_date ?: 'not set'));
                    $start_date = date('Y-m-d'); // Fallback to today
                } else {
                    error_log('InterSoccer: Retrieved _course_start_date for course item ' . ($variation_id ?: $product_id) . ': ' . $start_date);
                }

                $total_weeks = intval($total_weeks ?: 1);
                $server_time = current_time('Y-m-d');
                $start = new DateTime($start_date);
                $current = new DateTime($server_time);
                $weeks_passed = floor(($current->getTimestamp() - $start->getTimestamp()) / (7 * 24 * 60 * 60));
                $cart_item_data['remaining_weeks'] = max(0, $total_weeks - $weeks_passed);
                error_log('InterSoccer: Fallback calculated remaining_weeks for course item ' . ($variation_id ?: $product_id) . ': ' . $cart_item_data['remaining_weeks']);
            }
        }
    }

    $is_processing = false;
    return $cart_item_data;
}

// Reinforce cart item data
add_filter('woocommerce_add_cart_item', 'intersoccer_reinforce_cart_item_data', 10, 2);
function intersoccer_reinforce_cart_item_data($cart_item_data, $cart_item_key) {
    // Validate cart item data
    if (!is_array($cart_item_data) || !isset($cart_item_data['product_id']) || !isset($cart_item_key)) {
        error_log('InterSoccer: Invalid cart item data in intersoccer_reinforce_cart_item_data: ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    // Validate product and variation IDs
    $product_id = $cart_item_data['product_id'];
    $variation_id = isset($cart_item_data['variation_id']) ? $cart_item_data['variation_id'] : 0;
    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product in cart item ' . $cart_item_key . ': product_id=' . $product_id . ', variation_id=' . $variation_id);
        return $cart_item_data;
    }

    // Validate 'data' key and ensure it's a WC_Product object
    if (!isset($cart_item_data['data']) || !($cart_item_data['data'] instanceof WC_Product)) {
        error_log('InterSoccer: Missing or invalid "data" key in cart item ' . $cart_item_key . ': ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    if (isset($cart_item_data['remaining_weeks'])) {
        WC()->cart->cart_contents[$cart_item_key]['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Reinforced remaining weeks for cart item ' . $cart_item_key . ': ' . $cart_item_data['remaining_weeks']);
    }

    // Calculate the price server-side
    $camp_days = isset($cart_item_data['camp_days']) ? $cart_item_data['camp_days'] : [];
    $remaining_weeks = isset($cart_item_data['remaining_weeks']) ? (int) $cart_item_data['remaining_weeks'] : null;
    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);

    // Set the cart item price
    $cart_item_data['data']->set_price($calculated_price);
    $cart_item_data['intersoccer_calculated_price'] = $calculated_price; // Store for persistence
    error_log('InterSoccer: Set server-side calculated price for cart item ' . $cart_item_key . ': ' . $calculated_price);

    // Ensure the price is persisted in the session
    if (isset(WC()->cart->cart_contents[$cart_item_key]) && isset(WC()->cart->cart_contents[$cart_item_key]['data']) && WC()->cart->cart_contents[$cart_item_key]['data'] instanceof WC_Product) {
        WC()->cart->cart_contents[$cart_item_key]['data']->set_price($calculated_price);
        WC()->cart->set_session(); // Persist the cart session
    } else {
        error_log('InterSoccer: Unable to persist price in cart session for item ' . $cart_item_key . ': invalid cart contents or data');
    }

    return $cart_item_data;
}

// Ensure the adjusted price is retained when loading cart from session
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
    return $cart_item_data;
}

// Persist cart item meta
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
    return $cart_item_data;
}

// Redirect to checkout for Buy Now
add_action('woocommerce_add_to_cart', 'intersoccer_handle_buy_now', 20, 6);
function intersoccer_handle_buy_now($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}

// Display player, days, and discount in cart and checkout
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 300, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    error_log('InterSoccer: Entering intersoccer_display_cart_item_data for product ID: ' . ($cart_item['product_id'] ?? 'not set'));
    error_log('InterSoccer: Cart item data: ' . print_r($cart_item, true));

    // Handle Assigned Attendee
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
                error_log('InterSoccer: Added Assigned Attendee for item ' . $cart_item['product_id'] . ': ' . $player_name);
            } else {
                error_log('InterSoccer: Invalid player data for index ' . $cart_item['player_assignment'] . ': ' . print_r($player, true));
            }
        } catch (Exception $e) {
            error_log('InterSoccer: Error in get_player_details for item ' . $cart_item['product_id'] . ': ' . $e->getMessage());
        }
    } else {
        error_log('InterSoccer: No player_assignment in cart item ' . $cart_item['product_id']);
    }

    // Handle Course-specific fields (Discount message only)
    $product = wc_get_product($cart_item['product_id']);
    if (!$product) {
        error_log('InterSoccer: Invalid product for item ' . $cart_item['product_id']);
        return $item_data;
    }

    $product_type = intersoccer_get_product_type($cart_item['product_id']);
    if ($product_type === 'course') {
        error_log('InterSoccer: Identified course item ' . $cart_item['product_id']);
        if (isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
            $weeks_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
            $item_data[] = [
                'key' => __('Discount', 'intersoccer-player-management'),
                'value' => $weeks_display,
                'display' => $weeks_display
            ];
            error_log('InterSoccer: Added discount attribute for course item ' . $cart_item['product_id'] . ': ' . $weeks_display);
        } else {
            error_log('InterSoccer: No valid remaining_weeks for course item ' . $cart_item['product_id'] . ': ' . print_r($cart_item['remaining_weeks'] ?? 'not set', true));
        }
    } else {
        error_log('InterSoccer: Item ' . $cart_item['product_id'] . ' is not a course. Product type: ' . $product_type);
    }

    // Handle Camp-specific fields (Days Selected message only)
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

    return $item_data;
}

// Save player, days, and discount to order
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
            error_log('InterSoccer: Saved player to order item ' . $cart_item_key . ': ' . $player_name);
        } else {
            error_log('InterSoccer: Invalid player index ' . $player_index . ' for order item ' . $cart_item_key);
        }
    }

    if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
        $days = array_map('sanitize_text_field', $values['camp_days']);
        $days_display = implode(', ', $days);
        $item->add_meta_data(__('Days Selected', 'intersoccer-player-management'), $days_display);
        error_log('InterSoccer: Saved selected days to order item ' . $cart_item_key . ': ' . $days_display);
    }

    // Check both $values and cart item for remaining_weeks
    $remaining_weeks = isset($values['remaining_weeks']) ? intval($values['remaining_weeks']) : (isset($cart_item['remaining_weeks']) ? intval($cart_item['remaining_weeks']) : 0);
    if ($remaining_weeks > 0) {
        $weeks_display = esc_html($remaining_weeks . ' Weeks Remaining');
        $item->add_meta_data(__('Discount', 'intersoccer-player-management'), $weeks_display);
        error_log('InterSoccer: Saved discount weeks to order item ' . $cart_item_key . ': ' . $weeks_display);
    } else {
        error_log('InterSoccer: No remaining_weeks for order item ' . $cart_item_key);
    }
}

// Prevent quantity changes in cart for all products
add_filter('woocommerce_cart_item_quantity', 'intersoccer_cart_item_quantity', 10, 3);
function intersoccer_cart_item_quantity($quantity_html, $cart_item_key, $cart_item) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Prevent quantity changes in checkout
add_filter('woocommerce_checkout_cart_item_quantity', 'intersoccer_checkout_cart_item_quantity', 10, 3);
function intersoccer_checkout_cart_item_quantity($quantity_html, $cart_item, $cart_item_key) {
    return '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
}

// Helper function to retrieve player details
function get_player_details($player_index) {
    $user_id = get_current_user_id();
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    return $players[$player_index] ?? ['first_name' => 'Unknown', 'last_name' => 'Player'];
}

// Debug: Log cart contents before rendering
add_action('woocommerce_before_cart', function () {
    error_log('InterSoccer: Cart contents before rendering: ' . print_r(WC()->cart->get_cart(), true));
});

// Add product type meta to identify Camps and Courses
add_action('woocommerce_process_product_meta', 'intersoccer_save_product_type_meta', 100);
function intersoccer_save_product_type_meta($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }

    $product_type = intersoccer_get_product_type($product_id);
    // Already saved in intersoccer_get_product_type
}

// Add Course metadata (total weeks, weekly discount) to variations
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

// AJAX handler for intersoccer_get_product_type
add_action('wp_ajax_intersoccer_get_product_type', 'intersoccer_get_product_type_callback');
add_action('wp_ajax_nopriv_intersoccer_get_product_type', 'intersoccer_get_product_type_callback');
function intersoccer_get_product_type_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    if (!$product_id) {
        wp_send_json_error(array('message' => 'Invalid product ID'));
        wp_die();
    }

    $product_type = intersoccer_get_product_type($product_id);
    wp_send_json_success(array('product_type' => $product_type));
    wp_die();
}

// One-time script to update product type meta for all existing products
add_action('init', 'intersoccer_update_existing_product_types', 1);
function intersoccer_update_existing_product_types() {
    // Run only once by checking a flag
    $has_run = get_option('intersoccer_product_type_update_20250527', false);
    if ($has_run) {
        return;
    }

    $products = wc_get_products(array('limit' => -1));
    foreach ($products as $product) {
        $product_id = $product->get_id();
        intersoccer_get_product_type($product_id); // This will also save the meta
    }

    update_option('intersoccer_product_type_update_20250527', true);
    error_log('InterSoccer: Completed one-time product type meta update for all products');
}
?>