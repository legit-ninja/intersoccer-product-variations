<?php
/**
 * File: late-pickup.php
 * Description: Handles late pickup functionality for camp products in InterSoccer plugin.
 * Dependencies: WooCommerce, product-types.php
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add late pickup fields to camp product pages.
 */
add_action('woocommerce_after_variations_form', 'intersoccer_add_late_pickup_fields');
function intersoccer_add_late_pickup_fields() {
    global $product;
    if (!intersoccer_is_camp($product->get_id())) {
        return;
    }

    // Check if any variation has late pickup enabled
    $variations = $product->get_available_variations();
    $has_late_pickup_enabled = false;
    $variation_settings = [];

    foreach ($variations as $variation) {
        $variation_id = $variation['variation_id'];
        $enabled = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
        $variation_settings[$variation_id] = $enabled === 'yes';
        if ($enabled === 'yes') {
            $has_late_pickup_enabled = true;
        }
    }

    if (!$has_late_pickup_enabled) {
        return;
    }

    // Register strings for WPML
    if (function_exists('icl_register_string')) {
        icl_register_string('intersoccer-product-variations', 'Late Pick Up Options', 'Late Pick Up Options');
        icl_register_string('intersoccer-product-variations', 'None', 'None');
        icl_register_string('intersoccer-product-variations', 'Full Week', 'Full Week');
        icl_register_string('intersoccer-product-variations', 'Single Day(s)', 'Single Day(s)');
        icl_register_string('intersoccer-product-variations', 'Select Days for Late Pick Up', 'Select Days for Late Pick Up');
        icl_register_string('intersoccer-product-variations', 'Total Cost', 'Total Cost');
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($days as $day) {
            icl_register_string('intersoccer-product-variations', 'Day_' . $day, $day);
        }
    }

    // Get admin-configured prices
    $per_day_cost = floatval(get_option('intersoccer_late_pickup_per_day', 25));
    $full_week_cost = floatval(get_option('intersoccer_late_pickup_full_week', 90));
    $currency_symbol = get_woocommerce_currency_symbol();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Rendering late pickup fields for product ' . $product->get_id() . ', Language: ' . (function_exists('wpml_get_current_language') ? wpml_get_current_language() : 'default'));
        error_log('InterSoccer: Late pickup prices - Per Day: ' . $per_day_cost . ', Full Week: ' . $full_week_cost . ', Currency Symbol: ' . $currency_symbol);
    }
    ?>
    <div class="intersoccer-late-pickup">
        <h4><?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Late Pick Up Options', 'Late Pick Up Options') : 'Late Pick Up Options'); ?></h4>
        <div class="intersoccer-radio-group">
            <label>
                <input type="radio" name="late_pickup_type" value="none" checked>
                <?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'None', 'None') : 'None'); ?>
            </label>
            <label>
                <input type="radio" name="late_pickup_type" value="full-week">
                <?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Full Week', 'Full Week') : 'Full Week'); ?>
                <span>(<?php echo esc_html($currency_symbol . number_format($full_week_cost, 2)); ?>)</span>
            </label>
            <label>
                <input type="radio" name="late_pickup_type" value="single-days">
                <?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Single Day(s)', 'Single Day(s)') : 'Single Day(s'); ?>
                <span>(<?php echo esc_html($currency_symbol . number_format($per_day_cost, 2)); ?>/day)</span>
            </label>
        </div>
        <div class="intersoccer-late-pickup-days" style="display: none;">
            <h5><?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Select Days for Late Pick Up', 'Select Days for Late Pick Up') : 'Select Days for Late Pick Up'); ?></h5>
            <?php
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $index => $day) {
                $translated_day = function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Day_' . $day, $day) : $day;
                ?>
                <label class="intersoccer-checkbox-item">
                    <input type="checkbox" name="late_pickup_days[]" value="<?php echo esc_attr($day); ?>" class="late-pickup-day-checkbox">
                    <?php echo esc_html($translated_day); ?> <span>(<?php echo esc_html($currency_symbol . number_format($per_day_cost, 2)); ?>)</span>
                </label>
                <?php
            }
            ?>
        </div>
        <div class="intersoccer-late-pickup-total">
            <?php esc_html_e('Late Pick Up Cost: ', 'intersoccer-product-variations'); ?><span class="late-pickup-cost"><span class="intersoccer-late-pickup-price"><?php echo wp_kses_post(wc_price(0)); ?></span></span>
        </div>
        <div class="intersoccer-total-cost">
            <?php echo esc_html(function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Total Cost', 'Total Cost') : 'Total Cost'); ?>: <span class="total-cost"><span class="intersoccer-total-price"><?php echo wp_kses_post(wc_price(0)); ?></span></span>
        </div>
    </div>
    <input type="hidden" id="intersoccer_late_pickup_cost" name="late_pickup_cost" value="0.00">
    <input type="hidden" id="intersoccer_base_price" name="base_price" value="0.00">
    <script>
        jQuery(document).ready(function($) {
            var debugEnabled = <?php echo defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'; ?>;
            var perDayCost = <?php echo json_encode($per_day_cost); ?>;
            var fullWeekCost = <?php echo json_encode($full_week_cost); ?>;
            var variationSettings = <?php echo json_encode($variation_settings); ?>;
            var previousBookingType = '';

            // Function to check if current variation has late pickup enabled
            function isLatePickupEnabledForVariation(variationId) {
                return variationSettings[variationId] === true;
            }

            // Function to show/hide late pickup fields based on selected variation
            function updateLatePickupVisibility() {
                var selectedVariation = $('input[name="variation_id"]').val();
                if (selectedVariation && isLatePickupEnabledForVariation(selectedVariation)) {
                    $('.intersoccer-late-pickup').show();
                    if (debugEnabled) {
                        console.log('InterSoccer: Showing late pickup for variation ' + selectedVariation);
                    }
                } else {
                    $('.intersoccer-late-pickup').hide();
                    // Reset late pickup selections when hidden
                    $('input[name="late_pickup_type"]').prop('checked', false);
                    $('input.late-pickup-day-checkbox').prop('checked', false);
                    $('#intersoccer_late_pickup_cost').val('0.00');
                    updateTotalCost();
                    if (debugEnabled) {
                        console.log('InterSoccer: Hiding late pickup for variation ' + selectedVariation);
                    }
                }
            }

            // Move Add to Cart button to bottom of form
            if ($('.woocommerce-variation-add-to-cart').length) {
                $('.variations_form').append($('.woocommerce-variation-add-to-cart'));
                if (debugEnabled) {
                    console.log('InterSoccer: Moved Add to Cart button to bottom of form');
                }
            } else {
                if (debugEnabled) {
                    console.log('InterSoccer: Add to Cart button container not found');
                }
            }

            function getBasePrice() {
                // Correctly extract the base price from the displayed variation price
                var priceText = $('.woocommerce-variation-price .price .woocommerce-Price-amount').text() || $('.woocommerce-Price-amount').first().text() || '0';
                return parseFloat(priceText.replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
            }

            function updateLatePickupCost() {
                var latePickupType = $('input[name="late_pickup_type"]:checked').val();
                var totalCost = 0;

                if (latePickupType === 'full-week') {
                    totalCost = fullWeekCost;
                } else if (latePickupType === 'single-days') {
                    var selectedDays = $('input.late-pickup-day-checkbox:checked').length;
                    totalCost = selectedDays * perDayCost;
                }

                $('.late-pickup-cost .intersoccer-late-pickup-price').html('<?php echo wp_kses_post(wc_price(0)); ?>'.replace('0.00', totalCost.toFixed(2)));
                $('#intersoccer_late_pickup_cost').val(totalCost.toFixed(2));

                if (debugEnabled) {
                    console.log('InterSoccer: Updated late pickup cost to CHF ' + totalCost.toFixed(2) + ' (Type: ' + latePickupType + ', Selected Late Days: ' + $('input.late-pickup-day-checkbox:checked').length + ', Per Day: ' + perDayCost + ', Full Week: ' + fullWeekCost + ', HTML: ' + $('.late-pickup-cost').html());
                }

                updateTotalCost();
            }

            function updateTotalCost() {
                var basePrice = getBasePrice();
                var latePickupCost = parseFloat($('#intersoccer_late_pickup_cost').val()) || 0;
                var totalCost = basePrice + latePickupCost;

                $('.intersoccer-total-cost .total-cost').html('<?php echo wp_kses_post(wc_price(0)); ?>'.replace('0.00', totalCost.toFixed(2)));
                $('#intersoccer_base_price').val(basePrice.toFixed(2)); // Update hidden base price for cart submission

                if (debugEnabled) {
                    console.log('InterSoccer: Updated total cost to CHF ' + totalCost.toFixed(2) + ' (Base: ' + basePrice + ', Late Pickup: ' + latePickupCost + ', HTML: ' + $('.total-cost').html());
                }
            }

            // Toggle late pickup days visibility and update cost
            $('input[name="late_pickup_type"]').on('change', function() {
                var value = $(this).val();
                if (debugEnabled) {
                    console.log('InterSoccer: Late pickup type changed to: ' + value);
                }
                
                if (value === 'single-days') {
                    $('.intersoccer-late-pickup-days').show();
                    if (debugEnabled) {
                        console.log('InterSoccer: Showing late pickup days');
                    }
                } else {
                    $('.intersoccer-late-pickup-days').hide();
                    $('input.late-pickup-day-checkbox').prop('checked', false);
                    if (debugEnabled) {
                        console.log('InterSoccer: Hiding late pickup days and clearing checkboxes');
                    }
                }
                updateLatePickupCost();
            });

            // Debounce update on late pickup day checkbox change
            var debounceLatePickupUpdate = debounce(updateLatePickupCost, 200);
            $('input.late-pickup-day-checkbox').on('change', function() {
                if (debugEnabled) {
                    console.log('InterSoccer: Late pickup day checkbox changed');
                }
                debounceLatePickupUpdate();
            });

            // Update total cost when variation changes (e.g., price updates for single-days)
            $(document).on('found_variation', function(event, variation) {
                updateTotalCost();
                updateLatePickupVisibility();
            });

            // Hide late pickup when no variation is selected
            $(document).on('reset_data', function() {
                updateLatePickupVisibility();
            });

            // Update total cost when price is dynamically updated via AJAX (for single-days selection)
            $(document).on('intersoccer_price_updated', function() {
                updateTotalCost();
            });

            function debounce(func, wait) {
                var timeout;
                return function() {
                    clearTimeout(timeout);
                    timeout = setTimeout(func, wait);
                };
            }

            // Initial cost update
            updateLatePickupCost();
            updateLatePickupVisibility(); // Initial visibility check
        });
    </script>
    <?php
}

/**
 * Add late pickup data to cart item.
 */
add_filter('woocommerce_add_cart_item_data', 'intersoccer_add_late_pickup_data', 10, 3);
function intersoccer_add_late_pickup_data($cart_item_data, $product_id, $variation_id) {
    // Check if late pickup is enabled for this variation
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    if ($enable_late_pickup !== 'yes') {
        return $cart_item_data;
    }

    if (isset($_POST['late_pickup_cost'])) {
        $cart_item_data['late_pickup_cost'] = floatval($_POST['late_pickup_cost']);
    }
    if (isset($_POST['late_pickup_type'])) {
        $cart_item_data['late_pickup_type'] = sanitize_text_field($_POST['late_pickup_type']);
    }
    if (isset($_POST['late_pickup_days']) && is_array($_POST['late_pickup_days'])) {
        $cart_item_data['late_pickup_days'] = array_map('sanitize_text_field', $_POST['late_pickup_days']);
    }
    if (isset($_POST['base_price'])) {
        $cart_item_data['base_price'] = floatval($_POST['base_price']);
    }
    return $cart_item_data;
}

/**
 * Adjust cart item price to include late pickup cost.
 */
add_filter('woocommerce_add_cart_item', 'intersoccer_add_late_pickup_to_price', 10, 2);
function intersoccer_add_late_pickup_to_price($cart_item, $cart_item_key) {
    // Check if late pickup is enabled for this variation
    $variation_id = $cart_item['variation_id'];
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    if ($enable_late_pickup !== 'yes') {
        return $cart_item;
    }

    if (isset($cart_item['late_pickup_cost']) && $cart_item['late_pickup_cost'] > 0) {
        $original_price = $cart_item['data']->get_price();
        $cart_item['data']->set_price($original_price + $cart_item['late_pickup_cost']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Adjusted cart item price for ' . $cart_item_key . ' from ' . $original_price . ' to ' . ($original_price + $cart_item['late_pickup_cost']) . ' including late pickup cost ' . $cart_item['late_pickup_cost']);
        }
    }
    return $cart_item;
}

/**
 * Validate late pickup for single-days.
 */
add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_late_pickup', 10, 5);
function intersoccer_validate_late_pickup($passed, $product_id, $quantity, $variation_id = '', $variations = []) {
    // Check if late pickup is enabled for this variation
    $enable_late_pickup = get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
    if ($enable_late_pickup !== 'yes') {
        return $passed;
    }

    if (intersoccer_is_camp($product_id) && isset($_POST['late_pickup_type']) && $_POST['late_pickup_type'] === 'single-days') {
        $late_pickup_days = isset($_POST['late_pickup_days']) && is_array($_POST['late_pickup_days']) ? array_map('sanitize_text_field', $_POST['late_pickup_days']) : [];
        if (empty($late_pickup_days)) {
            wc_add_notice(__('Please select at least one day for late pick up when choosing Single Day(s).', 'intersoccer-product-variations'), 'error');
            error_log('InterSoccer: Validation failed - No late pickup days selected for variation ' . $variation_id);
            return false;
        }
    }
    return $passed;
}
?>