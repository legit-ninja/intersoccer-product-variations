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
    intersoccer_debug('Add cart item: product=' . $product_id . ', variation=' . $variation_id);

    // Assigned player
    if (isset($_POST['player_assignment'])) {
        $cart_item_data['assigned_player'] = absint($_POST['player_assignment']);
        $user_id = get_current_user_id();
        $player_details = intersoccer_get_player_details($user_id, $cart_item_data['assigned_player']);
        $cart_item_data['assigned_attendee'] = $player_details['name'];
    }

    // Camp days
    if (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        $cart_item_data['camp_days'] = array_map('sanitize_text_field', $_POST['camp_days']);
    }

    // Late pickup data
    if (intersoccer_is_camp($product_id) && isset($_POST['late_pickup_type'])) {
        $cart_item_data['late_pickup_type'] = sanitize_text_field($_POST['late_pickup_type']);
        if ($cart_item_data['late_pickup_type'] === 'single-days' && isset($_POST['late_pickup_days']) && is_array($_POST['late_pickup_days'])) {
            $cart_item_data['late_pickup_days'] = array_map('sanitize_text_field', $_POST['late_pickup_days']);
        } else {
            $cart_item_data['late_pickup_days'] = [];
        }
        // Validate late pickup cost server-side
        $per_day_cost = floatval(get_option('intersoccer_late_pickup_per_day', 25));
        $full_week_cost = floatval(get_option('intersoccer_late_pickup_full_week', 90));
        $late_pickup_cost = ($cart_item_data['late_pickup_type'] === 'full-week') ? $full_week_cost : ($cart_item_data['late_pickup_type'] === 'single-days' ? count($cart_item_data['late_pickup_days']) * $per_day_cost : 0);
        $cart_item_data['late_pickup_cost'] = $late_pickup_cost;
    }

    // Validate base price server-side
    if (isset($cart_item_data['camp_days']) && intersoccer_is_camp($product_id)) {
        $cart_item_data['base_price'] = InterSoccer_Camp::calculate_price($product_id, $variation_id, $cart_item_data['camp_days']);
    } elseif (intersoccer_get_product_type($product_id) === 'course') {
        $cart_item_data['base_price'] = InterSoccer_Course::calculate_price($product_id, $variation_id);
    } else {
        $cart_item_data['base_price'] = floatval(wc_get_product($variation_id ?: $product_id)->get_price());
    }

    return $cart_item_data;
}

/**
 * Track when items are actually added to cart
 */
add_action('woocommerce_add_to_cart', 'intersoccer_track_add_to_cart', 10, 6);
function intersoccer_track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    intersoccer_debug('Add to cart: product=' . $product_id . ', variation=' . $variation_id . ', qty=' . $quantity);
}

/**
 * Validate cart item before adding to cart
 */
add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_cart_item', 10, 6);
function intersoccer_validate_cart_item($passed, $product_id, $quantity, $variation_id = null, $variations = null, $cart_item_data = null) {
    $product_type = intersoccer_get_product_type($product_id);
    
    // Note: Course player assignment validation removed - using default player assignment logic
    // The cart data handler (intersoccer_add_custom_cart_item_data) processes player_assignment for all products
    // This matches how camps work - no explicit validation, just let the default handler process it
    
    // Check if this is a camp product
    if (!intersoccer_is_camp($product_id)) {
        return $passed;
    }

    // Get the booking type from the variation
    $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);

    $is_single_day = function_exists('intersoccer_is_single_day_booking_type')
        ? intersoccer_is_single_day_booking_type($booking_type)
        : false;

    // For single-day camps, require at least one day to be selected
    if ($is_single_day) {
        $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];

        if (empty($camp_days)) {
            wc_add_notice(__('Please select at least one day for this single-day camp.', 'intersoccer-product-variations'), 'error');
            $passed = false;

            intersoccer_warning('Cart validation failed: no camp days selected for product ' . $product_id);
        }
    }

    return $passed;
}

/**
 * Ensure correct redirect URL when validation fails
 * Only intervenes when there are validation errors - otherwise respects WooCommerce's redirect settings
 */
add_filter('woocommerce_add_to_cart_redirect', 'intersoccer_fix_add_to_cart_redirect', 10, 1);
function intersoccer_fix_add_to_cart_redirect($url) {
    // Only use stored URL if there are validation errors (validation failed)
    // This ensures we redirect back to the product page when validation fails
    if (function_exists('WC') && WC()->session && wc_notice_count('error') > 0) {
        $stored_url = WC()->session->get('intersoccer_redirect_url');
        if ($stored_url) {
            WC()->session->__unset('intersoccer_redirect_url');
            return $stored_url;
        }
    }

    return $url;
}

/**
 * Fix redirect when validation fails (WooCommerce uses wp_get_referer)
 * Also fixes malformed referer URLs that might cause redirect issues
 */
add_filter('wp_get_referer', 'intersoccer_fix_validation_fail_redirect', 10, 1);
function intersoccer_fix_validation_fail_redirect($referer) {
    // Only fix referer if we're in an add-to-cart context
    if (!isset($_POST['add-to-cart'])) {
        return $referer;
    }

    $product_id = absint($_POST['add-to-cart']);
    if (!$product_id) {
        return $referer;
    }
    
    $product_url = get_permalink($product_id);
    if (!$product_url) {
        return $referer;
    }

    // Check if referer is malformed (contains search params, empty params, etc.)
    $is_malformed = false;
    if (empty($referer)) {
        $is_malformed = true;
    } elseif (strpos($referer, '?post_types=') !== false ||
              strpos($referer, '?s=') !== false || 
              strpos($referer, '?zc_gad=') !== false ||
              preg_match('/\?[^=]*=$/', $referer)) { // Empty query params
        $is_malformed = true;
    }

    // Fix referer if it's malformed or if we have validation errors
    if ($is_malformed || wc_notice_count('error') > 0) {
        return $product_url;
    }

    return $referer;
}

/**
 * Add derived data to cart item
 */
add_filter('woocommerce_add_cart_item', 'intersoccer_add_derived_cart_item_data', 10, 1);
function intersoccer_add_derived_cart_item_data($cart_item) {
    $product_id = $cart_item['product_id'];
    $variation_id = $cart_item['variation_id'];
    $product_type = intersoccer_get_product_type($product_id);

    $calculated_price = isset($cart_item['base_price']) ? floatval($cart_item['base_price']) : 0;
    if ($product_type === 'camp' && isset($cart_item['late_pickup_cost'])) {
        $calculated_price += floatval($cart_item['late_pickup_cost']);
    }
    $cart_item['data']->set_price($calculated_price);
    $cart_item['base_price'] = $calculated_price;

    if ($product_type === 'camp') {
        $cart_item['discount_note'] = InterSoccer_Camp::calculate_discount_note($variation_id, $cart_item['camp_days'] ?? []);
    } elseif ($product_type === 'course') {
        $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
        $cart_item['discount_note'] = InterSoccer_Course::calculate_discount_note($variation_id, $remaining_sessions);
        $cart_item['remaining_sessions'] = $remaining_sessions;
    }

    return $cart_item;
}

/**
 * Display custom metadata in cart.
 */
add_filter('woocommerce_get_item_data', 'intersoccer_display_cart_item_metadata', 20, 2);
function intersoccer_display_cart_item_metadata($item_data, $cart_item) {
    $product_id = $cart_item['product_id'];
    $product_type = intersoccer_get_product_type($product_id);

    if ($product_type && in_array($product_type, ['camp', 'course', 'birthday', 'tournament'])) {
        // Assigned Attendee
        if (isset($cart_item['assigned_attendee']) && !empty($cart_item['assigned_attendee'])) {
            $item_data[] = [
                'key' => __('Assigned Attendee', 'intersoccer-product-variations'),
                'value' => esc_html($cart_item['assigned_attendee']),
                'display' => '<span class="intersoccer-cart-meta">' . esc_html($cart_item['assigned_attendee']) . '</span>'
            ];
        }

        // Days Selected (for single-day camps)
        if ($product_type === 'camp' && isset($cart_item['camp_days']) && is_array($cart_item['camp_days']) && !empty($cart_item['camp_days'])) {
            $item_data[] = [
                'key' => __('Days Selected', 'intersoccer-product-variations'),
                'value' => implode(', ', $cart_item['camp_days']),
                'display' => '<span class="intersoccer-cart-meta">' . esc_html(implode(', ', $cart_item['camp_days'])) . '</span>'
            ];
        }

        // Discount Note
        if (isset($cart_item['discount_note']) && !empty($cart_item['discount_note'])) {
            $item_data[] = [
                'key' => __('Discount', 'intersoccer-product-variations'),
                'value' => esc_html($cart_item['discount_note']),
                'display' => '<span class="intersoccer-cart-meta intersoccer-discount">' . esc_html($cart_item['discount_note']) . '</span>'
            ];
        }

        // Late Pickup Details
        if ($product_type === 'camp' && isset($cart_item['late_pickup_type']) && $cart_item['late_pickup_type'] !== 'none') {
            $item_data[] = [
                'key' => __('Late Pickup', 'intersoccer-product-variations'),
                'value' => $cart_item['late_pickup_type'] === 'full-week' ? __('Full Week', 'intersoccer-product-variations') : implode(', ', $cart_item['late_pickup_days']),
                'display' => '<span class="intersoccer-cart-meta">' . esc_html($cart_item['late_pickup_type'] === 'full-week' ? __('Full Week', 'intersoccer-product-variations') : implode(', ', $cart_item['late_pickup_days'])) . '</span>'
            ];
            $item_data[] = [
                'key' => __('Late Pickup Cost', 'intersoccer-product-variations'),
                'value' => wc_price($cart_item['late_pickup_cost']),
                'display' => '<span class="intersoccer-cart-meta">' . wp_kses_post(wc_price($cart_item['late_pickup_cost'])) . '</span>'
            ];
        }
    }

    return $item_data;
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
    }
    $product = wc_get_product($variation_id ?: $product_id);
    return $product ? floatval($product->get_price()) : 0;
}

/**
 * Clear variation price cache when course data is updated
 */
add_action('updated_post_meta', 'intersoccer_clear_variation_cache_on_course_update', 10, 4);
function intersoccer_clear_variation_cache_on_course_update($meta_id, $post_id, $meta_key, $meta_value) {
    if (in_array($meta_key, ['_course_start_date', '_course_total_weeks', '_course_holiday_dates', '_course_weekly_discount'])) {
        $product = wc_get_product($post_id);
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                wp_cache_delete('wc_var_prices_' . $parent_id, 'wc_var_prices');
            }
        }
    }
}

/**
 * Modify variation prices for frontend display (avoids session during rendering).
 */
add_filter('woocommerce_variation_prices', 'intersoccer_modify_variation_prices', 999, 3);
function intersoccer_modify_variation_prices($prices, $product, $for_display) {
    $product_id = $product->get_id();
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);

    // Only modify prices for courses
    if ($product_type !== 'course') {
        return $prices;
    }

    // Calculate prorated price for each variation directly (no session dependency)
    foreach ($prices['price'] as $variation_id => $price) {
        $prorated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
        
        $prices['price'][$variation_id] = $prorated_price;
        $prices['regular_price'][$variation_id] = $prorated_price;
        $prices['sale_price'][$variation_id] = $prorated_price;
    }

    return $prices;
}

/**
 * Modify price HTML to reflect selected days
 */
add_filter('woocommerce_get_price_html', 'intersoccer_modify_price_html', 10, 2);
function intersoccer_modify_price_html($price_html, $product) {
    // Only modify for variable products that are camps
    if (!$product->is_type('variable') || !intersoccer_is_camp($product->get_id())) {
        return $price_html;
    }

    // Check if WooCommerce session is available (not available in admin)
    if (!WC()->session) {
        return $price_html;
    }

    return $price_html;
}

/**
 * Modify variation price at source level for courses
 * Calculates prorated price directly without session dependency
 */
add_filter('woocommerce_get_variation_price', 'intersoccer_modify_variation_price', 10, 4);
function intersoccer_modify_variation_price($price, $variation, $product, $min_or_max) {
    $product_type = InterSoccer_Product_Types::get_product_type($product->get_id());

    // Only modify for course products
    if ($product_type !== 'course') {
        return $price;
    }

    // Calculate prorated price directly
    $prorated_price = InterSoccer_Course::calculate_price($product->get_id(), $variation->get_id());
    
    return $prorated_price;
}

/**
 * Modify variation price HTML specifically for courses
 * Calculates prorated price directly without session dependency
 */
add_filter('woocommerce_variation_price_html', 'intersoccer_modify_variation_price_html', 10, 4);
function intersoccer_modify_variation_price_html($price_html, $variation, $product) {
    $product_type = InterSoccer_Product_Types::get_product_type($product->get_id());

    // Only modify for course products
    if ($product_type !== 'course') {
        return $price_html;
    }

    // Calculate prorated price directly
    $prorated_price = InterSoccer_Course::calculate_price($product->get_id(), $variation->get_id());
    
    // Return formatted HTML with <span class="price"> wrapper to match WooCommerce structure
    return '<span class="price">' . wc_price($prorated_price) . '</span>';
}

/**
 * Modify variation data to include prorated course price
 * Similar to camp price flicker fix - inject correct price into variation data
 */
add_filter('woocommerce_available_variation', 'intersoccer_inject_course_prorated_price', 10, 3);
function intersoccer_inject_course_prorated_price($variation_data, $product, $variation) {
    $product_id = $product->get_id();
    $product_type = intersoccer_get_product_type($product_id);
    
    // Only modify for course products
    if ($product_type !== 'course') {
        return $variation_data;
    }
    
    $variation_id = $variation->get_id();
    
    // Calculate prorated price
    $prorated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
    
    // Inject prorated price into variation data
    // This ensures WooCommerce displays the correct price immediately
    $variation_data['display_price'] = $prorated_price;
    $variation_data['display_regular_price'] = $prorated_price;
    $variation_data['display_sale_price'] = $prorated_price;
    $variation_data['price'] = $prorated_price;
    
    // Update the price HTML to match
    $variation_data['price_html'] = '<span class="price">' . wc_price($prorated_price) . '</span>';
    
    // PERFORMANCE OPTIMIZATION: Inject course info directly into variation data
    // This eliminates the need for a separate AJAX call
    $start_date = get_post_meta($variation_id, '_course_start_date', true);
    $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
    $holidays = get_post_meta($variation_id, '_course_holiday_dates', true);
    
    if (!is_array($holidays)) {
        $holidays = [];
    }
    
    // Calculate end date and remaining sessions
    $end_date = '';
    $remaining_sessions = 0;
    if (class_exists('InterSoccer_Course') && $start_date && $total_weeks) {
        $end_date = InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    }
    
    // Inject course info into variation data
    $variation_data['course_info'] = [
        'is_course' => true,
        'start_date' => $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '',
        'end_date' => $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '',
        'total_weeks' => $total_weeks,
        'remaining_sessions' => $remaining_sessions,
        'holidays' => array_map(function($holiday) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
                return date_i18n('F j, Y', strtotime($holiday));
            }
            return $holiday;
        }, $holidays)
    ];
    
    return $variation_data;
}

/**
 * AJAX handler for dynamic price calculation.
 * NOTE: This handler is DEPRECATED - the active handler is in includes/ajax-handlers.php
 * Keeping this commented out to avoid duplicate handler conflicts.
 */
// add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
// add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_calculate_dynamic_price_callback');
function intersoccer_calculate_dynamic_price_callback_DEPRECATED() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
    $camp_days = isset($_POST['camp_days']) ? (array) $_POST['camp_days'] : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) && is_numeric($_POST['remaining_weeks']) ? (int) $_POST['remaining_weeks'] : null;

    $price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, $remaining_weeks);
    wp_send_json_success(['price' => wc_price($price)]);
}

/**
 * Add custom JavaScript for dynamic price updates.
 */
add_action('woocommerce_single_product_summary', 'intersoccer_add_price_update_script', 35);
function intersoccer_add_price_update_script() {
    if (!is_product()) return;
    $product_id = get_the_ID();
    $nonce = wp_create_nonce('intersoccer_nonce');
    ?>
    <style>
        .intersoccer-loading {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .intersoccer-price-loading {
            animation: pulse 1.5s ease-in-out infinite;
        }
        .intersoccer-button-loading {
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <script>
        jQuery(document).ready(function($) {
            var debugEnabled = <?php echo defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'; ?>;
            var currentVariationId = null;
            var currentVariation = null;
            var isCalculatingPrice = false;
            var priceUpdateTimeout = null;

            function showPriceLoading() {
                // Add loading indicator to price elements
                $('.woocommerce-Price-amount.amount, .price .woocommerce-Price-amount').each(function() {
                    if (!$(this).find('.intersoccer-price-loading').length) {
                        $(this).append('<span class="intersoccer-price-loading" style="margin-left: 8px; font-size: 0.8em; color: #666;">(updating...)</span>');
                    }
                });

                // Disable add to cart buttons
                $('.single_add_to_cart_button, .add_to_cart_button, button[type="submit"]').prop('disabled', true).addClass('intersoccer-loading');

                // Add loading class to buttons for styling
                $('.single_add_to_cart_button, .add_to_cart_button, button[type="submit"]').each(function() {
                    if (!$(this).find('.intersoccer-button-loading').length) {
                        $(this).append('<span class="intersoccer-button-loading" style="margin-left: 8px;">⟳</span>');
                    }
                });
            }

            function hidePriceLoading() {
                // Remove loading indicators
                $('.intersoccer-price-loading').remove();
                $('.intersoccer-button-loading').remove();

                // Re-enable add to cart buttons
                $('.single_add_to_cart_button, .add_to_cart_button, button[type="submit"]').prop('disabled', false).removeClass('intersoccer-loading');
            }

            function updateCampPrice() {
                console.log('InterSoccer: updateCampPrice called');
                if (!currentVariation) {
                    console.log('InterSoccer: No variation selected for price update');
                    // Reset price display
                    $('.woocommerce-Price-amount.amount, .price .woocommerce-Price-amount').html('<?php echo wp_kses_post(wc_price(0)); ?>');
                    $('.camp-cost .intersoccer-camp-price').html('<?php echo wp_kses_post(wc_price(0)); ?>');
                    $('#intersoccer_base_price').val('0.00');
                    hidePriceLoading();
                    if (typeof updateTotalCost === 'function') {
                        updateTotalCost();
                    }
                    return;
                }

                // Prevent multiple simultaneous requests
                if (isCalculatingPrice) {
                    console.log('InterSoccer: Price calculation already in progress, skipping');
                    return;
                }

                isCalculatingPrice = true;
                showPriceLoading();

                var bookingType = currentVariation.attributes.attribute_pa_booking_type || '';
                var campDays = $('input[name="camp_days_temp[]"]:checked').map(function() {
                    return $(this).val();
                }).get();

                console.log('InterSoccer: updateCampPrice - bookingType:', bookingType, 'campDays:', campDays);

                // Fetch price from server
                console.log('InterSoccer: Making AJAX call for camp price - Variation ID:', currentVariationId, 'Camp Days:', campDays);
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'intersoccer_calculate_camp_price',
                        nonce: '<?php echo esc_js($nonce); ?>',
                        variation_id: currentVariationId,
                        camp_days: campDays
                    },
                    success: function(response) {
                        console.log('InterSoccer: AJAX response received:', response);
                        isCalculatingPrice = false;
                        if (response.success) {
                            var campCost = parseFloat(response.data.raw_price).toFixed(2);
                            console.log('InterSoccer: Updating price display to:', campCost, 'formatted:', response.data.price);
                            
                            // Try multiple selectors to find the actual price element
                            var priceSelectors = [
                                '.woocommerce-Price-amount.amount',
                                '.price .woocommerce-Price-amount',
                                '.woocommerce-variation-price .woocommerce-Price-amount',
                                '.single-product .price .woocommerce-Price-amount',
                                '.woocommerce-variation-price .price .woocommerce-Price-amount',
                                '.price .amount',
                                '.woocommerce-Price-amount'
                            ];
                            
                            var updated = false;
                            priceSelectors.forEach(function(selector) {
                                if ($(selector).length > 0) {
                                    $(selector).html(response.data.price);
                                    console.log('InterSoccer: Updated price using selector:', selector);
                                    updated = true;
                                }
                            });
                            
                            if (!updated) {
                                console.log('InterSoccer: No price elements found to update. Available price elements:');
                                $('.woocommerce-Price-amount, .price, .amount').each(function() {
                                    console.log('  Found element:', $(this).prop('tagName'), $(this).attr('class'), 'text:', $(this).text(), 'html:', $(this).html());
                                });
                                // Also check for any element containing price-like text
                                $('*').each(function() {
                                    var text = $(this).text();
                                    if (text && text.match && text.match(/CHF|\$|€|£/)) {
                                        console.log('  Potential price element:', $(this).prop('tagName'), $(this).attr('class'), $(this).attr('id'), 'text:', text);
                                    }
                                });
                            }
                            
                            // Also update any camp-cost element if it exists
                            $('.camp-cost .intersoccer-camp-price').html(response.data.price);
                            $('#intersoccer_base_price').val(campCost);
                            
                            // Trigger custom event for late pickup to update total
                            $(document).trigger('intersoccer_price_updated');
                            
                            // Try to refresh WooCommerce variation price display
                            if (typeof wc_ajax_object !== 'undefined' && wc_ajax_object.ajax_url) {
                                // Trigger WooCommerce's variation price update
                                $(document).trigger('woocommerce_variation_has_changed');
                                
                                // Also try to update the variation form
                                $('form.variations_form').trigger('check_variations');
                            }
                            
                            // Force refresh of price display
                            setTimeout(function() {
                                // Try to find and update the price container
                                var $priceContainer = $('.woocommerce-variation-price, .price');
                                if ($priceContainer.length > 0) {
                                    // Trigger WooCommerce's price update
                                    $('form.variations_form').trigger('found_variation', [currentVariation]);
                                }
                            }, 200);

                            // Try to update WooCommerce's variation data
                            if (typeof wc_variation_form !== 'undefined' && window.wc_variation_form) {
                                // Find the current variation in WooCommerce's data
                                var variationForm = $('form.variations_form').data('wc_variation_form');
                                if (variationForm && variationForm.variationData && variationForm.variationData[currentVariationId]) {
                                    variationForm.variationData[currentVariationId].display_price = campCost;
                                    variationForm.variationData[currentVariationId].display_regular_price = campCost;
                                    console.log('InterSoccer: Updated WooCommerce variation data display_price to:', campCost);
                                    
                                    // Trigger WooCommerce to update the price display
                                    $('form.variations_form').trigger('found_variation', [currentVariation]);
                                }
                            }
                            hidePriceLoading();
                        } else {
                            console.error('InterSoccer: AJAX price fetch failed:', response.data);
                            hidePriceLoading();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: AJAX error:', status, error, xhr.responseText);
                        isCalculatingPrice = false;
                        hidePriceLoading();
                    }
                });

                // Store selected days in session for price HTML filter (non-blocking)
                setTimeout(function() {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'intersoccer_store_selected_days',
                            nonce: '<?php echo esc_js($nonce); ?>',
                            product_id: <?php echo intval($product_id); ?>,
                            variation_id: currentVariationId,
                            camp_days: campDays
                        },
                        success: function(storeResponse) {
                            console.log('InterSoccer: Days stored in session:', storeResponse);
                        },
                        error: function(xhr, status, error) {
                            console.error('InterSoccer: Failed to store days in session:', status, error);
                        }
                    });
                }, 100);

                // Update hidden inputs for camp days
                $('input.intersoccer-camp-day-input').remove();
                campDays.forEach(function(day) {
                    $('form.cart').append('<input type="hidden" name="camp_days[]" value="' + day + '" class="intersoccer-camp-day-input">');
                });
            }

            // On variation found - with debouncing
            var debouncedVariationUpdate = debounce(function(variation) {
                currentVariationId = variation.variation_id;
                currentVariation = variation;
                if (debugEnabled) {
                    console.log('InterSoccer: Variation found (debounced), id: ' + currentVariationId + ', booking_type: ' + (variation.attributes.attribute_pa_booking_type || 'none'));
                }

                // Show/hide day selection based on booking type (hyphen + underscore keys; DE/WPML slugs)
                var attrs = variation.attributes || {};
                var bookingType = attrs.attribute_pa_booking_type || attrs['attribute_pa_booking-type'] || attrs.attribute_booking_type || attrs['attribute_booking-type'] || '';
                function intersoccerIsSingleDayBookingTypeCart(bt) {
                    if (bt == null || bt === '') return false;
                    var b = String(bt).toLowerCase();
                    if (b === 'full-week' || b.indexOf('full-week') !== -1) return false;
                    if (b.indexOf('ganze') !== -1 && b.indexOf('woche') !== -1) return false;
                    if (b === 'single-days' || b === 'à la journée' || b === 'a-la-journee') return true;
                    if (b === 'tag' || /^(?:1[-_])?ein[-_]?tag$/.test(b) || /^nur[-_]?tag$/.test(b)) return true;
                    return b.indexOf('single') !== -1 || b.indexOf('journée') !== -1 || b.indexOf('journee') !== -1
                        || b.indexOf('einzel') !== -1 || b.indexOf('ein-tag') !== -1 || b.indexOf('eintag') !== -1 || b.indexOf('1-tag') !== -1
                        || b.indexOf('taeglich') !== -1 || b.indexOf('täglich') !== -1 || b.indexOf('nur-tag') !== -1
                        || b.indexOf('pro-tag') !== -1 || b.indexOf('pro_tag') !== -1
                        || b.indexOf('pro tag') !== -1 || b.indexOf('tagesbuchung') !== -1 || b.indexOf('tages-buchung') !== -1;
                }
                var isSingleDayBooking = intersoccerIsSingleDayBookingTypeCart(bookingType);

                console.log('InterSoccer: isSingleDayBooking check:', isSingleDayBooking, 'for booking type:', bookingType);

                if (isSingleDayBooking) {
                    $('.intersoccer-day-selection').show();
                    console.log('InterSoccer: Calling updateCampPrice for single-day booking');
                    updateCampPrice();
                } else {
                    $('.intersoccer-day-selection').hide();
                    $('input[name="camp_days_temp[]"]').prop('checked', false);
                    // Remove hidden inputs when not single-days
                    $('input.intersoccer-camp-day-input').remove();
                    // Clear session data for non-single-day bookings
                    clearSessionData();
                    hidePriceLoading();

                    // Check if this is a course product and update price if needed
                    checkAndUpdateCoursePrice(variation);
                }
            }, 300);

            $('form.cart').on('found_variation', function(event, variation) {
                debouncedVariationUpdate(variation);
            });

            // Update on camp day checkbox change - with debouncing
            // NOTE: Disabled this handler to prevent duplicate price updates
            // elementor-widgets.php handles day checkbox changes for single-day camps
            // This handler was causing race conditions and price doubling (CHF 960 instead of CHF 480)
            /*
            var debounceCampUpdate = debounce(updateCampPrice, 500);
            $('form.cart').on('change', 'input[name="camp_days_temp[]"]', function() {
                console.log('InterSoccer: Day checkbox changed event fired');
                // Immediately update hidden inputs to ensure they're posted with cart addition
                $('input.intersoccer-camp-day-input').remove();
                var campDays = $('input[name="camp_days_temp[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                console.log('InterSoccer: Selected camp days:', campDays);
                if (campDays.length === 0) {
                    console.log('InterSoccer: No days selected, clearing session data');
                    clearSessionData();
                }
                campDays.forEach(function(day) {
                    $('form.cart').append('<input type="hidden" name="camp_days[]" value="' + day + '" class="intersoccer-camp-day-input">');
                });
                if (debugEnabled) {
                    console.log('InterSoccer: Camp day checkbox changed, updated hidden inputs immediately');
                }
                debounceCampUpdate();
            });
            */

            function debounce(func, wait) {
                var timeout;
                return function() {
                    var context = this, args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(function() {
                        func.apply(context, args);
                    }, wait);
                };
            }

            function checkAndUpdateCoursePrice(variation) {
                console.log('InterSoccer: Checking if course price needs updating for variation:', variation);

                // Check if course info is displayed (indicates this is a course with session data)
                if ($('.intersoccer-course-info').length > 0) {
                    console.log('InterSoccer: Course info found, checking price display');

                    // Get course data from the displayed info
                    var courseInfoText = $('.intersoccer-course-info').text();
                    var remainingMatch = courseInfoText.match(/Remaining Sessions: (\d+) of (\d+)/);

                    if (remainingMatch) {
                        var remainingSessions = parseInt(remainingMatch[1]);
                        var totalSessions = parseInt(remainingMatch[2]);

                        console.log('InterSoccer: Course data from display - remaining:', remainingSessions, 'total:', totalSessions);

                        // Calculate prorated price
                        var basePrice = parseFloat(variation.display_price || variation.price || 0);
                        if (basePrice > 0 && totalSessions > 0 && remainingSessions > 0) {
                            var proratedPrice = (basePrice / totalSessions) * remainingSessions;
                            var formattedPrice = 'CHF ' + proratedPrice.toFixed(2);

                            console.log('InterSoccer: Calculated prorated price - base:', basePrice, 'prorated:', proratedPrice, 'formatted:', formattedPrice);

                            // Update price display
                            var priceSelectors = [
                                '.woocommerce-Price-amount.amount',
                                '.price .woocommerce-Price-amount',
                                '.woocommerce-variation-price .woocommerce-Price-amount',
                                '.single-product .price .woocommerce-Price-amount',
                                '.woocommerce-variation-price .price .woocommerce-Price-amount',
                                '.price .amount',
                                '.woocommerce-Price-amount'
                            ];

                            var updated = false;
                            priceSelectors.forEach(function(selector) {
                                if ($(selector).length > 0) {
                                    var currentText = $(selector).text();
                                    if (currentText !== formattedPrice) {
                                        $(selector).html('<span class="woocommerce-Price-currencySymbol">CHF</span>' + proratedPrice.toFixed(2));
                                        console.log('InterSoccer: Updated course price using selector:', selector, 'from:', currentText, 'to:', formattedPrice);
                                        updated = true;
                                    }
                                }
                            });

                            if (!updated) {
                                console.log('InterSoccer: No price elements found to update for course');
                            }
                        } else {
                            console.log('InterSoccer: Invalid data for course price calculation - base:', basePrice, 'total:', totalSessions, 'remaining:', remainingSessions);
                        }
                    } else {
                        console.log('InterSoccer: Could not parse course info from display');
                    }
                } else {
                    console.log('InterSoccer: No course info displayed, not a course product');
                }
            }

            function clearSessionData() {
                console.log('InterSoccer: Clearing session data for product ' + <?php echo $product_id; ?>);
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'intersoccer_clear_session_data',
                        nonce: '<?php echo esc_js($nonce); ?>',
                        product_id: <?php echo $product_id; ?>
                    },
                    success: function(response) {
                        console.log('InterSoccer: Session data cleared');
                    },
                    error: function(xhr, status, error) {
                        console.error('InterSoccer: Failed to clear session data:', status, error);
                    }
                });
            }
        });
    </script>
    <?php
}

/**
 * AJAX handler to update session data.
 */
add_action('wp_ajax_intersoccer_calculate_camp_price', 'intersoccer_calculate_camp_price_callback');
add_action('wp_ajax_nopriv_intersoccer_calculate_camp_price', 'intersoccer_calculate_camp_price_callback');
function intersoccer_calculate_camp_price_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $camp_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? array_map('sanitize_text_field', $_POST['camp_days']) : [];

    intersoccer_debug('InterSoccer: Processing AJAX request - Variation ID: ' . $variation_id . ', Camp days: ' . json_encode($camp_days));

    if (!$variation_id) {
        intersoccer_warning('InterSoccer: Invalid variation ID in AJAX request');
        wp_send_json_error(['message' => 'Invalid variation ID']);
        wp_die();
    }

    // Check if this is a single-day camp booking
    $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
    $is_single_day = function_exists('intersoccer_is_single_day_booking_type')
        ? intersoccer_is_single_day_booking_type($booking_type)
        : false;
    
    if ($is_single_day && empty($camp_days)) {
        wp_send_json_error(['message' => 'No camp days selected']);
        wp_die();
    }

    $price = InterSoccer_Camp::calculate_price(0, $variation_id, $camp_days);

    intersoccer_debug('InterSoccer: Calculated price: ' . $price . ' for ' . count($camp_days) . ' days');

    // Store selected days in session for price HTML filters
    if ($is_single_day && !empty($camp_days)) {
        $product_id = wp_get_post_parent_id($variation_id);
        if ($product_id) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
            WC()->session->set('intersoccer_current_variation_' . $product_id, $variation_id);
            intersoccer_debug('InterSoccer: Stored ' . count($camp_days) . ' selected days in session for product ' . $product_id);
        }
    } elseif ($is_single_day && empty($camp_days)) {
        // Clear session data when no days are selected
        $product_id = wp_get_post_parent_id($variation_id);
        if ($product_id) {
            WC()->session->set('intersoccer_selected_days_' . $product_id, null);
            WC()->session->set('intersoccer_current_variation_' . $product_id, null);
            intersoccer_debug('InterSoccer: Cleared session data for product ' . $product_id . ' (no days selected)');
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('InterSoccer: AJAX camp price calculated - Variation ID: ' . $variation_id . ', Days: ' . count($camp_days) . ', Price: ' . $price);
    }

    // Return properly formatted price HTML with WooCommerce structure to maintain CSS styling
    wp_send_json_success([
        'price' => '<span class="price">' . wc_price($price) . '</span>',
        'raw_price' => $price
    ]);
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
            intersoccer_debug('InterSoccer: Session data cleared for product ' . $product_id);
            wp_send_json_success(['message' => 'Session data cleared']);
        } else {
            intersoccer_warning('InterSoccer: WooCommerce session not available during intersoccer_clear_session_data_callback');
            wp_send_json_error(['message' => 'Session not available']);
        }
    } else {
        wp_send_json_error(['message' => 'Invalid product ID']);
        wp_die();
    }
}

/**
 * AJAX handler to store selected days in session.
 */
add_action('wp_ajax_intersoccer_store_selected_days', 'intersoccer_store_selected_days_callback');
add_action('wp_ajax_nopriv_intersoccer_store_selected_days', 'intersoccer_store_selected_days_callback');
function intersoccer_store_selected_days_callback() {
    check_ajax_referer('intersoccer_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $camp_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? array_map('sanitize_text_field', $_POST['camp_days']) : [];

    if ($product_id && function_exists('WC') && WC()->session) {
        WC()->session->set('intersoccer_selected_days_' . $product_id, $camp_days);
        WC()->session->set('intersoccer_current_variation_' . $product_id, $variation_id);
        intersoccer_debug('InterSoccer: Stored selected days in session for product ' . $product_id . ': ' . json_encode($camp_days));
        wp_send_json_success(['message' => 'Days stored', 'days' => $camp_days]);
    } else {
        wp_send_json_error(['message' => 'Session not available']);
    }
}

intersoccer_debug('InterSoccer: Loaded cart-calculations.php');
// function intersoccer_apply_combo_discounts_to_items($cart) {
//     intersoccer_debug('InterSoccer: Starting cart calculation for ' . count($cart->get_cart()) . ' items');

//     foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
//         $product_id = $cart_item['product_id'];
//         $variation_id = $cart_item['variation_id'] ?? 0;
//         $camp_days = $cart_item['camp_days'] ?? [];
//         $quantity = $cart_item['quantity'];

//         intersoccer_debug("InterSoccer: Processing cart item {$cart_item_key} - Product: {$product_id}, Variation: {$variation_id}, Camp days: " . json_encode($camp_days) . ", Quantity: {$quantity}");

//         if (intersoccer_is_camp($product_id)) {
//             $price = intersoccer_calculate_price($product_id, $variation_id, $camp_days, null);
//             intersoccer_debug("InterSoccer: Calculated price for camp: {$price}");

//             $cart_item['data']->set_price($price);
//             $cart_item['base_price'] = $price;
//         }
//     }
// }