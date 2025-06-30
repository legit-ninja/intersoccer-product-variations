<?php
/**
 * File: woocommerce-modifications.php
 * Description: Customizes WooCommerce functionality for the InterSoccer Player Management plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
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
 * @return string The product type ('camp', 'course', 'birthday', or empty string).
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
    } elseif (in_array('birthdays', $categories, true)) {
        $product_type = 'birthday';
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

    // Fallback: Check product title as a last resort
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
 * Adjust cart item prices to reflect combo discounts based on season and product type.
 */
add_action('woocommerce_before_calculate_totals', 'intersoccer_apply_discounts', 10, 1);
function intersoccer_apply_discounts($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    $cart_items = $cart->get_cart();

    // Group items by season
    $grouped_items = [];
    foreach ($cart_items as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type === 'camp') { // Focus only on Camps
            $season = get_post_meta($cart_item['variation_id'] ?: $product_id, 'attribute_pa_program-season', true) ?: 'unknown';
            $grouped_items[$season][$cart_item_key] = $cart_item;
        }
    }

    // Apply discounts per season group
    foreach ($grouped_items as $season => $items) {
        $camp_index = 0; // Reset camp index per season

        foreach ($items as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'] ?: $product_id;
            $base_price = floatval($cart_item['data']->get_regular_price());
            $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $cart_item['camp_days'] ?? [], $cart_item['remaining_weeks'] ?? null);
            $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true) ?: 'full-week';
            $age_group = get_post_meta($variation_id, 'attribute_pa_age-group', true) ?: 'unknown';

            $discount = 0;
            $original_price = $calculated_price;

            // Apply discount only for full-week camps
            if ($product_type === 'camp' && $booking_type === 'full-week') {
                $camp_index++; // Increment only for full-week camps in this season
                if ($camp_index == 2) {
                    $discount = 0.20; // 20% for the second item
                } elseif ($camp_index >= 3) {
                    $discount = 0.25; // 25% for the third and beyond
                }
                if ($discount > 0) {
                    $is_half_day = strpos($age_group, 'Half-Day') !== false;
                    $reference_item = reset(array_filter($items, function($item) {
                        return get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_booking-type', true) === 'full-week';
                    }));
                    $reference_age_group = $reference_item ? get_post_meta($reference_item['variation_id'] ?: $reference_item['product_id'], 'attribute_pa_age-group', true) : 'unknown';
                    $is_reference_half_day = strpos($reference_age_group, 'Half-Day') !== false;

                    if ($is_half_day && !$is_reference_half_day) {
                        $discount_amount = $base_price * $discount;
                    } elseif (!$is_half_day && $is_reference_half_day) {
                        $reference_base_price = $reference_item ? intersoccer_calculate_price($reference_item['product_id'], $reference_item['variation_id'], $reference_item['camp_days'] ?? []) : $base_price;
                        $discount_amount = min($base_price, $reference_base_price) * $discount;
                    } else {
                        $discount_amount = $base_price * $discount;
                    }
                    $final_price = $calculated_price - $discount_amount;
                }
            }

            $final_price = $calculated_price * (1 - $discount);
            $cart_item['data']->set_price($final_price);

            // Set discount message and store in cart item for later order meta
            if ($discount > 0) {
                $discount_percent = round($discount * 100);
                $discount_message = sprintf(__('%d%% Combo Discount', 'intersoccer-player-management'), $discount_percent);
                $cart->cart_contents[$cart_item_key]['combo_discount_note'] = $discount_message;
                $cart->cart_contents[$cart_item_key]['discount_applied'] = $discount_message; // Store for order meta
                error_log("InterSoccer: Applied $discount_message for camp $cart_item_key (Season: $season, Booking Type: $booking_type, Camp Index: $camp_index) at " . date('Y-m-d H:i:s', time()));
            } else {
                unset($cart->cart_contents[$cart_item_key]['combo_discount_note']);
                unset($cart->cart_contents[$cart_item_key]['discount_applied']);
                error_log("InterSoccer: No discount applied for $cart_item_key (Season: $season, Booking Type: $booking_type, Camp Index: $camp_index) at " . date('Y-m-d H:i:s', time()));
            }
        }
    }
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
        // Ensure session is initialized
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
 * Get visible, non-variation parent attributes for a product, excluding camp-specific overlaps.
 *
 * @param WC_Product $product Product object (variation or parent).
 * @param string $product_type Product type ('camp', 'course', etc.).
 * @return array Array of attribute label => value pairs.
 */
function intersoccer_get_parent_attributes($product, $product_type = '') {
    $attributes = [];

    // Get parent product for variations
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

        // Skip Days-of-week for camps to avoid overlap with Days Selected
        if ($product_type === 'camp' && $attribute_name === 'pa_days-of-week') {
            error_log('InterSoccer: Skipped attribute ' . $attribute_name . ' for camp product ' . $parent_id . ': overlaps with Days Selected meta');
            continue;
        }

        if (!is_object($attribute)) {
            // Custom attribute
            $is_visible = isset($attribute['is_visible']) && $attribute['is_visible'];
            if ($is_visible) {
                $value = $attribute['value'];
                error_log('InterSoccer: Processing custom attribute ' . $attribute_name . ' for product ' . $parent_id . ': visible=' . ($is_visible ? 'true' : 'false') . ', value=' . ($value ?: 'empty'));
            } else {
                error_log('InterSoccer: Skipped custom attribute ' . $attribute_name . ' for product ' . $parent_id . ': not visible');
            }
        } else {
            // Taxonomy-based attribute
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

// Save player, days, discount, and parent attributes to cart item
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_cart_item_data', 10, 3);
function intersoccer_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    static $is_processing = false;
    if ($is_processing) {
        error_log('InterSoccer: Skipped recursive call in intersoccer_add_cart_item_data');
        return $cart_item_data;
    }
    $is_processing = true;

    // Get the product (variation or simple)
    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid product for cart item: product_id=' . $product_id . ', variation_id=' . $variation_id);
        $is_processing = false;
        return $cart_item_data;
    }

    // Add player assignment (use player_assignment, not assigned_player)
    if (isset($_POST['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($_POST['player_assignment']);
        error_log('InterSoccer: Added player to cart via POST: ' . $cart_item_data['player_assignment']);
    } elseif (isset($cart_item_data['player_assignment'])) {
        $cart_item_data['player_assignment'] = sanitize_text_field($cart_item_data['player_assignment']);
        error_log('InterSoccer: Added player to cart via cart_item_data: ' . $cart_item_data['player_assignment']);
    }

    // Add camp days for camps
    if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $_POST['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via POST: ' . print_r($cart_item_data['camp_days'], true));
        unset($_POST['camp_days']);
    } elseif (isset($cart_item_data['camp_days']) && is_array($cart_item_data['camp_days'])) {
        $cart_item_data['camp_days'] = array_unique(array_map('sanitize_text_field', $cart_item_data['camp_days']));
        error_log('InterSoccer: Added unique camp days to cart via cart_item_data: ' . print_r($cart_item_data['camp_days'], true));
    }

    // Add remaining weeks for courses
    if (isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($_POST['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via POST: ' . $cart_item_data['remaining_weeks']);
    } elseif (isset($cart_item_data['remaining_weeks']) && is_numeric($cart_item_data['remaining_weeks'])) {
        $cart_item_data['remaining_weeks'] = intval($cart_item_data['remaining_weeks']);
        error_log('InterSoccer: Added remaining weeks to cart via cart_item_data: ' . $cart_item_data['remaining_weeks']);
    }

    // Fallback: Calculate remaining_weeks for courses if not provided
    if (!isset($cart_item_data['remaining_weeks']) && !isset($_POST['remaining_weeks'])) {
        $product_type = intersoccer_get_product_type($product_id);
        if ($product_type === 'course') {
            $start_date = get_post_meta($variation_id, '_course_start_date', true);
            if (!$start_date) {
                $parent_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product_id;
                $start_date = get_post_meta($parent_id, '_course_start_date', true);
                error_log('InterSoccer: Retrieved _course_start_date from parent product ' . $parent_id . ': ' . ($start_date ?: 'not set'));
            }

            $total_weeks = get_post_meta($variation_id ?: $product_id, '_course_total_weeks', true);

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

    // Add visible, non-variation parent attributes
    $product_type = intersoccer_get_product_type($product_id);
    $cart_item_data['parent_attributes'] = intersoccer_get_parent_attributes($product, $product_type);

    $is_processing = false;
    return $cart_item_data;
}

/**
 * Reinforce cart item data.
 */
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

    if (isset($cart_item_data['parent_attributes']) && is_array($cart_item_data['parent_attributes'])) {
        WC()->cart->cart_contents[$cart_item_key]['parent_attributes'] = $cart_item_data['parent_attributes'];
        error_log('InterSoccer: Reinforced parent attributes for cart item ' . $cart_item_key . ': ' . print_r($cart_item_data['parent_attributes'], true));
    }

    // Calculate the base price server-side (discounts applied in woocommerce_before_calculate_totals)
    $camp_days = isset($cart_item_data['camp_days']) ? $cart_item_data['camp_days'] : [];
    $remaining_weeks = isset($cart_item_data['remaining_weeks']) ? (int) $cart_item_data['remaining_weeks'] : null;
    $base_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);

    // Set the cart item price to the base price (discounts will be applied later)
    $cart_item_data['data']->set_price($base_price);
    $cart_item_data['intersoccer_calculated_price'] = $base_price; // Store base price for reference
    $cart_item_data['intersoccer_base_price'] = $base_price; // Store base price for reference
    error_log('InterSoccer: Set server-side base price for cart item ' . $cart_item_key . ': ' . $base_price);

    // Ensure the price is persisted in the session
    if (isset(WC()->cart->cart_contents[$cart_item_key]) && isset(WC()->cart->cart_contents[$cart_item_key]['data']) && WC()->cart->cart_contents[$cart_item_key]['data'] instanceof WC_Product) {
        WC()->cart->cart_contents[$cart_item_key]['data']->set_price($base_price);
        WC()->cart->set_session(); // Persist the cart session
    } else {
        error_log('InterSoccer: Unable to persist price in cart session for item ' . $cart_item_key . ': invalid cart contents or data');
    }

    return $cart_item_data;
}

// Ensure the adjusted price and attributes are retained when loading cart from session
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
    if (isset($cart_item['parent_attributes']) && is_array($cart_item['parent_attributes'])) {
        $cart_item_data['parent_attributes'] = $cart_item['parent_attributes'];
        error_log('InterSoccer: Persisted parent attributes in cart item: ' . print_r($cart_item['parent_attributes'], true));
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

/**
 * Display player, days, discount, and parent attributes in cart and checkout, including combo discount.
 */
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_data', 300, 2);
function intersoccer_display_cart_item_data($item_data, $cart_item) {
    $cart_item_key = isset($cart_item['key']) ? $cart_item['key'] : 'unknown'; // Fallback to 'unknown' if key is missing
    error_log('InterSoccer: Entering intersoccer_display_cart_item_data for product ID: ' . ($cart_item['product_id'] ?? 'not set') . ', cart_item_key: ' . $cart_item_key);
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
                error_log('InterSoccer: Added Assigned Attendee for item ' . ($cart_item['product_id'] ?? 'not set') . ' (key: ' . $cart_item_key . '): ' . $player_name);
            } else {
                error_log('InterSoccer: Invalid player data for index ' . $cart_item['player_assignment'] . ' in item ' . ($cart_item['product_id'] ?? 'not set') . ' (key: ' . $cart_item_key . '): ' . print_r($player, true));
            }
        } catch (Exception $e) {
            error_log('InterSoccer: Error in get_player_details for item ' . ($cart_item['product_id'] ?? 'not set') . ' (key: ' . $cart_item_key . '): ' . $e->getMessage());
        }
    }

    // Handle Camp-specific fields (Days Selected message only)
    $product = wc_get_product($cart_item['product_id']);
    if (!$product) {
        error_log('InterSoccer: Invalid product for item ' . ($cart_item['product_id'] ?? 'not set') . ' (key: ' . $cart_item_key . ')');
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
        error_log('InterSoccer: Added days selected for camp item ' . $cart_item['product_id'] . ' (key: ' . $cart_item_key . '): ' . $days_display);
    }

    // Handle Course-specific fields (Discount message only)
    if ($product_type === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $weeks_display = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
        $item_data[] = [
            'key' => __('Discount', 'intersoccer-player-management'),
            'value' => $weeks_display,
            'display' => $weeks_display
        ];
        error_log('InterSoccer: Added discount attribute for course item ' . $cart_item['product_id'] . ' (key: ' . $cart_item_key . '): ' . $weeks_display);
    }

    // Handle combo discount display with fallback
    $discount_note = isset($cart_item['combo_discount_note']) ? $cart_item['combo_discount_note'] : '';
    if (empty($discount_note)) {
        $product_type = intersoccer_get_product_type($cart_item['product_id']);
        $variation_id = $cart_item['variation_id'] ?: $cart_item['product_id'];
        $season = get_post_meta($variation_id, 'attribute_pa_program-season', true) ?: 'unknown';
        $grouped_items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_program-season', true) ?: 'unknown';
            if ($item_season === $season && in_array(intersoccer_get_product_type($item['product_id']), ['camp', 'course'])) {
                $grouped_items[$key] = $item;
            }
        }
        $camp_index = array_search($cart_item_key, array_keys($grouped_items));
        $course_index = array_search($cart_item_key, array_keys(array_filter($grouped_items, function($item) { return intersoccer_get_product_type($item['product_id']) === 'course'; })));
        if ($product_type === 'camp' && $camp_index !== false && $camp_index >= 1) {
            $discount_note = ($camp_index == 1) ? '20% Combo Discount' : '25% Combo Discount';
        } elseif ($product_type === 'course' && $course_index !== false && $course_index == 1) {
            $discount_note = '50% Course Combo Discount';
        }
    }
    if ($discount_note) {
        $item_data[] = [
            'key' => __('Discount', 'intersoccer-player-management'),
            'value' => $discount_note,
            'display' => $discount_note
        ];
        error_log('InterSoccer: Added combo discount note for item ' . $cart_item['product_id'] . ' (key: ' . $cart_item_key . '): ' . $discount_note);
    }

    // Handle parent attributes
    if (isset($cart_item['parent_attributes']) && is_array($cart_item['parent_attributes'])) {
        foreach ($cart_item['parent_attributes'] as $label => $value) {
            $item_data[] = [
                'key' => esc_html($label),
                'value' => esc_html($value),
                'display' => esc_html($value)
            ];
            error_log('InterSoccer: Added parent attribute for item ' . $cart_item['product_id'] . ' (key: ' . $cart_item_key . '): ' . $label . ' = ' . $value);
        }
    }

    return $item_data;
}

/**
 * Save player, days, discount, parent attributes, and downloads to order.
 */
add_action('woocommerce_checkout_create_order_line_item', 'intersoccer_save_order_item_data', 10, 4);
function intersoccer_save_order_item_data($item, $cart_item_key, $values, $order) {
    // Initial validation of $item
    if (!is_a($item, 'WC_Order_Item_Product')) {
        error_log('InterSoccer: Initial invalid item type in intersoccer_save_order_item_data for cart item ' . $cart_item_key . ': ' . print_r($item, true));
        return;
    }

    error_log('InterSoccer: Saving order item data for cart item ' . $cart_item_key . ': ' . print_r($values, true));

    // Save player assignment
    if (isset($values['player_assignment'])) {
        $user_id = get_current_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        $player_index = sanitize_text_field($values['player_assignment']);
        if (isset($players[$player_index])) {
            $player = $players[$player_index];
            $player_name = esc_html($player['first_name'] . ' ' . $player['last_name']);
            if (is_a($item, 'WC_Order_Item_Product')) {
                $item->add_meta_data(__('Assigned Attendee', 'intersoccer-player-management'), $player_name);
                error_log('InterSoccer: Saved player to order item ' . $cart_item_key . ': ' . $player_name);
            } else {
                error_log('InterSoccer: Invalid item type for player assignment in ' . $cart_item_key . ': ' . gettype($item));
            }
        } else {
            error_log('InterSoccer: Invalid player index ' . $player_index . ' for order item ' . $cart_item_key);
        }
    }

    // Save camp days
    if (isset($values['camp_days']) && is_array($values['camp_days']) && !empty($values['camp_days'])) {
        $days = array_map('sanitize_text_field', $values['camp_days']);
        $days_display = implode(', ', $days);
        if (is_a($item, 'WC_Order_Item_Product')) {
            $item->add_meta_data(__('Days Selected', 'intersoccer-player-management'), $days_display);
            error_log('InterSoccer: Saved selected days to order item ' . $cart_item_key . ': ' . $days_display);
        } else {
            error_log('InterSoccer: Invalid item type for days selection in ' . $cart_item_key . ': ' . gettype($item));
        }
    }

    // Save remaining weeks discount
    $remaining_weeks = isset($values['remaining_weeks']) ? intval($values['remaining_weeks']) : 0;
    if ($remaining_weeks > 0) {
        $weeks_display = esc_html($remaining_weeks . ' Weeks Remaining');
        if (is_a($item, 'WC_Order_Item_Product')) {
            $item->add_meta_data(__('Discount', 'intersoccer-player-management'), $weeks_display);
            error_log('InterSoccer: Saved discount weeks to order item ' . $cart_item_key . ': ' . $weeks_display);
        } else {
            error_log('InterSoccer: Invalid item type for weeks discount in ' . $cart_item_key . ': ' . gettype($item));
        }
    }

    // Save combo discount with fallback
    $discount_note = isset($values['combo_discount_note']) ? $values['combo_discount_note'] : '';
    if (empty($discount_note)) {
        $product_type = intersoccer_get_product_type($values['product_id']);
        $variation_id = $values['variation_id'] ?: $values['product_id'];
        $season = get_post_meta($variation_id, 'attribute_pa_program-season', true) ?: 'unknown';
        $grouped_items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_program-season', true) ?: 'unknown';
            if ($item_season === $season && in_array(intersoccer_get_product_type($item['product_id']), ['camp', 'course'])) {
                $grouped_items[$key] = $item;
            }
        }
        $camp_index = array_search($cart_item_key, array_keys($grouped_items));
        $course_index = array_search($cart_item_key, array_keys(array_filter($grouped_items, function($item) { return intersoccer_get_product_type($item['product_id']) === 'course'; })));
        if ($product_type === 'camp' && $camp_index !== false && $camp_index >= 1) {
            $discount_note = ($camp_index == 1) ? '20% Combo Discount' : '25% Combo Discount';
        } elseif ($product_type === 'course' && $course_index !== false && $course_index == 1) {
            $discount_note = '50% Course Combo Discount';
        }
    }
    if ($discount_note && is_a($item, 'WC_Order_Item_Product')) {
        $item->add_meta_data(__('Discount Applied', 'intersoccer-player-management'), $discount_note);
        error_log('InterSoccer: Saved combo discount note to order item ' . $cart_item_key . ': ' . $discount_note);
    } elseif (!is_a($item, 'WC_Order_Item_Product')) {
        error_log('InterSoccer: Invalid item type for combo discount in ' . $cart_item_key . ': ' . gettype($item));
    }

    // Save parent attributes with item validation
    if (isset($values['parent_attributes']) && is_array($values['parent_attributes'])) {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            error_log('InterSoccer: Invalid item type for parent attributes in ' . $cart_item_key . ': ' . gettype($item) . ', attempting to reinitialize');
            $item_id = $item['item_id'] ?? $cart_item_key; // Fallback to cart_item_key if item_id is not set
            $order_id = $order->get_id();
            $item = new WC_Order_Item_Product();
            $item->set_order_id($order_id);
            $item->set_product_id($values['product_id']);
            $item->set_quantity($values['quantity']);
            // Note: This is a simplified reinitialization; full restoration may require more data
        }
        foreach ($values['parent_attributes'] as $label => $value) {
            if (is_a($item, 'WC_Order_Item_Product')) {
                $item->add_meta_data(esc_html($label), esc_html($value));
                error_log('InterSoccer: Saved parent attribute to order item ' . $cart_item_key . ': ' . $label . ' = ' . $value);
            } else {
                error_log('InterSoccer: Failed to save parent attribute ' . $label . ' for order item ' . $cart_item_key . ': item not a WC_Order_Item_Product');
            }
        }
    }

    // Assign downloads with item validation
    $product_id = $item->get_product_id() ?: $values['product_id'];
    $variation_id = $item->get_variation_id() ?: ($values['variation_id'] ?? 0);
    $product = wc_get_product($variation_id ?: $product_id);

    if ($product && is_a($item, 'WC_Order_Item_Product')) {
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
                    error_log('InterSoccer: Assigned downloadable file to order item ' . $cart_item_key . ': ' . $download['name']);
                }
            }
        }
    } else {
        error_log('InterSoccer: Invalid product or item type for downloads in ' . $cart_item_key . ': ' . ($product ? 'Valid product' : 'Invalid product') . ', item type: ' . gettype($item));
    }
}

/**
 * Display base price in the cart's "Price" column.
 */
add_filter('woocommerce_cart_item_price', 'intersoccer_display_base_price_in_cart', 10, 3);
function intersoccer_display_base_price_in_cart($price_html, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $base_price = floatval($product->get_regular_price());
    return wc_price($base_price);
}

/**
 * Display discounted subtotal in the cart with green text for discount message.
 */
add_filter('woocommerce_cart_item_subtotal', 'intersoccer_cart_item_subtotal', 10, 3);
function intersoccer_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key) {
    $product = $cart_item['data'];
    $quantity = $cart_item['quantity'];
    $discounted_price = floatval($product->get_price());
    $subtotal = $discounted_price * $quantity;
    $subtotal_html = wc_price($subtotal);

    if (isset($cart_item['combo_discount_note'])) {
        $subtotal_html .= '<div class="intersoccer-discount" style="color: green; font-size: 0.9em; margin-top: 5px;">' . esc_html($cart_item['combo_discount_note']) . '</div>';
        error_log('InterSoccer: Added green discount message to subtotal for cart item ' . $cart_item_key . ': ' . $cart_item['combo_discount_note']);
    } elseif (intersoccer_get_product_type($cart_item['product_id']) === 'course' && isset($cart_item['remaining_weeks']) && intval($cart_item['remaining_weeks']) > 0) {
        $discount_message = esc_html($cart_item['remaining_weeks'] . ' Weeks Remaining');
        $subtotal_html .= '<div class="intersoccer-discount" style="color: green; font-size: 0.9em; margin-top: 5px;">' . $discount_message . '</div>';
        error_log('InterSoccer: Added green pro-rated discount message to subtotal for cart item ' . $cart_item_key . ': ' . $discount_message);
    }

    return $subtotal_html;
}

// Prevent quantity changes in cart for all products
add_filter('woocommerce_cart_item_quantity', 'intersoccer_cart_item_quantity', 10, 3);
function intersoccer_cart_item_quantity($quantity_html, $cart_item_key, $cart_item) {
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $variation_id = $cart_item['variation_id'] ?: $product_id;
    $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true) ?: 'full-week';

    if ($product_type === 'camp' && $booking_type === 'single-days' && isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
        $days_count = count($cart_item['camp_days']);
        $quantity_html = '<span class="cart-item-quantity">' . esc_html($days_count) . ' day(s)</span>';
        error_log('InterSoccer: Updated quantity for single-day Camp ' . $cart_item_key . ' to ' . $days_count . ' day(s)');
    } else {
        $quantity_html = '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
    }

    return $quantity_html;
}

// Prevent quantity changes in checkout
add_filter('woocommerce_checkout_cart_item_quantity', 'intersoccer_checkout_cart_item_quantity', 10, 3);
function intersoccer_checkout_cart_item_quantity($quantity_html, $cart_item, $cart_item_key) {
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);
    $variation_id = $cart_item['variation_id'] ?: $product_id;
    $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true) ?: 'full-week';

    if ($product_type === 'camp' && $booking_type === 'single-days' && isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
        $days_count = count($cart_item['camp_days']);
        $quantity_html = '<span class="cart-item-quantity">' . esc_html($days_count) . ' day(s)</span>';
        error_log('InterSoccer: Updated quantity for single-day Camp ' . $cart_item_key . ' in checkout to ' . $days_count . ' day(s)');
    } else {
        $quantity_html = '<span class="cart-item-quantity">' . esc_html($cart_item['quantity']) . '</span>';
    }

    return $quantity_html;
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

// Add product type meta to identify Camps, Courses, and Birthdays
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
        wp_send_json_error(['message' => 'Invalid product ID']);
        wp_die();
    }

    $product_type = intersoccer_get_product_type($product_id);
    wp_send_json_success(['product_type' => $product_type]);
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

    $products = wc_get_products(['limit' => -1]);
    foreach ($products as $product) {
        $product_id = $product->get_id();
        intersoccer_get_product_type($product_id); // This will also save the meta
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

// Add admin submenu for updating Processing orders
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

/**
 * Render the admin page for updating Processing orders.
 */
function intersoccer_render_update_orders_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    // Get Processing orders
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

/**
 * AJAX handler to update Processing orders with parent attributes and remove unwanted fields.
 */
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

            // Remove assigned_player if requested
            if ($remove_assigned_player) {
                $item->delete_meta_data('assigned_player');
                error_log('InterSoccer: Removed assigned_player from order item ' . $item_id . ' in order ' . $order_id);
            }

            // Fix incorrect attributes if requested
            if ($fix_incorrect && $product_type === 'camp') {
                $item->delete_meta_data('Days-of-week');
                error_log('InterSoccer: Removed Days-of-week attribute from order item ' . $item_id . ' in order ' . $order_id);
            }

            // Get parent attributes
            $parent_attributes = intersoccer_get_parent_attributes($product, $product_type);

            // Add only new parent attributes to order item meta
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

// Add metabox for downloadable documents
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

/**
 * Render the downloadable documents metabox.
 */
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
            
            // Add new download row
            $('#intersoccer-add-download').on('click', function() {
                var template = $('#intersoccer-downloads-template').html().replace(/{index}/g, index++);
                $('#intersoccer-downloads-container').append(template);
            });

            // Remove download row
            $(document).on('click', '.intersoccer-remove-download', function() {
                $(this).closest('.intersoccer-download-row').remove();
            });

            // Upload file
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

/**
 * Save downloadable documents meta.
 */
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

    // Update WooCommerce downloadable files
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

// Grant download permissions after order is processed
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

            // Check if permission already exists
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
 * Validate single-day camp selection.
 */
add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_single_day_camp', 10, 3);
function intersoccer_validate_single_day_camp($passed, $product_id, $quantity) {
    if (isset($_POST['variation_id'])) {
        $variation_id = intval($_POST['variation_id']);
        $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
        if ($booking_type === 'single-days') {
            $camp_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? array_map('sanitize_text_field', $_POST['camp_days']) : [];
            if (empty($camp_days)) {
                $passed = false;
                wc_add_notice(__('Please select at least one day for this single-day camp.', 'intersoccer-product-variations'), 'error');
                error_log('InterSoccer: Validation failed - no valid camp_days data for product ' . $product_id . ': ' . print_r($_POST, true));
            } else {
                error_log('InterSoccer: Validated single-day camp with ' . count($camp_days) . ' days for product ' . $product_id . ': ' . print_r($camp_days, true));
            }
        }
    }
    return $passed;
}
?>
