<?php
/**
 * File: cart-calculations.php
 * Description: Handles cart-level calculations, session updates, and price modifications for InterSoccer WooCommerce.
 * Dependencies: WooCommerce, product-types.php, product-camps.php, product-course.php, discounts.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
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

    return $cart_item_data;
}

/**
 * Add derived data (e.g., discount_note) to cart item after initial data.
 */
add_filter('woocommerce_add_cart_item', 'intersoccer_add_derived_cart_item_data', 10, 1);
function intersoccer_add_derived_cart_item_data($cart_item) {
    $product_id = $cart_item['product_id'];
    $variation_id = $cart_item['variation_id'];
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type === 'camp') {
        $camp_days = $cart_item['camp_days'] ?? [];
        $cart_item['discount_note'] = InterSoccer_Camp::calculate_discount_note($variation_id, $camp_days);
    } elseif ($product_type === 'course') {
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
        $cart_item['discount_note'] = InterSoccer_Course::calculate_discount_note($variation_id, $remaining_sessions);
        $cart_item['remaining_sessions'] = $remaining_sessions; // For potential use in display/meta
    }

    if (defined('WP_DEBUG') && WP_DEBUG && isset($cart_item['discount_note'])) {
        error_log('InterSoccer: Added discount_note to cart item for product ' . $product_id . ': ' . $cart_item['discount_note']);
    }

    return $cart_item;
}

/**
 * Calculate price based on product type (delegates to type-specific classes).
 *
 * @param int $product_id Product ID.
 * @param int $variation_id Variation ID.
 * @param array $camp_days Selected camp days (for camps).
 * @param int|null $remaining_weeks Remaining weeks (unused, calculated internally for courses).
 * @return float Calculated price.
 */
function intersoccer_calculate_price($product_id, $variation_id, $camp_days = [], $remaining_weeks = null) {
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);

    if ($product_type === 'camp') {
        return InterSoccer_Camp::calculate_price($product_id, $variation_id, $camp_days);
    } elseif ($product_type === 'course') {
        return InterSoccer_Course::calculate_price($product_id, $variation_id, $remaining_weeks);
    } else {
        $product = wc_get_product($variation_id ?: $product_id);
        $price = $product ? floatval($product->get_price()) : 0;
        error_log('InterSoccer: Calculated price for non-camp/course product ' . $product_id . ': ' . $price);
        return $price;
    }
}

/**
 * Modify variation prices for frontend display (avoids session during rendering).
 */
add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices', 10, 3);
function intersoccer_modify_variation_prices($prices, $product, $for_display) {
    $product_id = $product->get_id();
    error_log('InterSoccer: Modifying variation prices for product ' . $product_id . ' during rendering, using defaults');

    foreach ($prices['price'] as $variation_id => $price) {
        $calculated_price = intersoccer_calculate_price($product_id, $variation_id, [], null);
        $prices['price'][$variation_id] = $calculated_price;
        $prices['regular_price'][$variation_id] = $calculated_price;
        $prices['sale_price'][$variation_id] = $calculated_price;
    }

    return $prices;
}

/**
 * AJAX handler for dynamic price calculation.
 */
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
function intersoccer_calculate_dynamic_price_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');
    error_log('InterSoccer: AJAX dynamic price calculation called');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;

    if (!$product_id || !$variation_id) {
        wp_send_json_error(['message' => 'Invalid product or variation ID']);
        wp_die();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Dynamic price params - product_id: ' . $product_id . ', variation_id: ' . $variation_id . ', camp_days: ' . json_encode($camp_days));
    }

    $calculated_price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);
    wp_send_json_success(['price' => wc_price($calculated_price), 'raw_price' => $calculated_price]);
    wp_die();
}

/**
 * Update session data on variation change.
 */
add_action('wp_footer', 'intersoccer_update_session_on_variation_change');
function intersoccer_update_session_on_variation_change() {
    if (!is_product()) {
        return;
    }

    global $post;
    $product_id = $post->ID;
    
    // Pass debug state to JavaScript
    $debug_enabled = defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false';
    ?>
    <script>
        jQuery(document).ready(function($) {
            var currentVariationId = null;
            var debugEnabled = <?php echo $debug_enabled; ?>;

            // Function to calculate and update price
            function updateCampPrice() {
                var campDays = $('form.cart').find('input[name="camp_days[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (debugEnabled) {
                    console.log('InterSoccer: Updating price, camp_days:', campDays, 'variation_id:', currentVariationId);
                }

                if (!currentVariationId) return;

                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_calculate_dynamic_price',
                        nonce: intersoccerCheckout.nonce,
                        product_id: <?php echo json_encode($product_id); ?>,
                        variation_id: currentVariationId,
                        camp_days: campDays
                    },
                    success: function(response) {
                        if (response.success) {
                            if (debugEnabled) {
                                console.log('InterSoccer: Price updated to', response.data.price);
                            }
                            // Update price display
                            $('.woocommerce-variation-price .price').html(response.data.price);
                        } else {
                            if (debugEnabled) {
                                console.log('InterSoccer: Price update failed', response);
                            }
                        }
                    },
                    error: function(err) {
                        if (debugEnabled) {
                            console.log('InterSoccer: Price AJAX error', err);
                        }
                    }
                });
            }

            // On variation found
            $('form.cart').on('found_variation', function(event, variation) {
                currentVariationId = variation.variation_id;
                if (debugEnabled) {
                    console.log('InterSoccer: Variation found, id:', currentVariationId);
                }

                // Update session (existing)
                var campDays = $(this).find('input[name="camp_days[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                $.ajax({
                    url: intersoccerCheckout.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_update_session_data',
                        nonce: intersoccerCheckout.nonce,
                        product_id: <?php echo json_encode($product_id); ?>,
                        variation_id: variation.variation_id,
                        camp_days: campDays,
                        remaining_weeks: variation.remaining_sessions || null
                    },
                    success: function(response) {
                        if (response.success && debugEnabled) {
                            console.log('InterSoccer: Session data updated');
                        }
                    }
                });

                // Trigger price update
                updateCampPrice();
            });

            // On day checkbox change
            $('form.cart').on('change', 'input[name="camp_days[]"]', function() {
                if (debugEnabled) {
                    console.log('InterSoccer: Camp day checkbox changed');
                }
                updateCampPrice();
            });
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

error_log('InterSoccer: Loaded cart-calculations.php');
?>