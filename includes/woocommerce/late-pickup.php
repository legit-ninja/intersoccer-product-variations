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
    
    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Checking product ' . $product->get_id() . ' for late pickup fields');
        $is_camp_result = intersoccer_is_camp($product->get_id());
        error_log('InterSoccer Late Pickup: Product type check: ' . ($is_camp_result ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Current language: ' . (function_exists('wpml_get_current_language') ? wpml_get_current_language() : 'default'));
    }
    
    if (!intersoccer_is_camp($product->get_id())) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: Product is not a camp, skipping');
        }
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: Variation ' . $variation_id . ' metadata: "' . $enabled . '" -> enabled: ' . ($enabled === 'yes' ? 'true' : 'false'));
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Variations checked: ' . count($variations));
        error_log('InterSoccer Late Pickup: Has late pickup enabled: ' . ($has_late_pickup_enabled ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Variation settings: ' . json_encode($variation_settings));
        error_log('InterSoccer Late Pickup: Product ID: ' . $product->get_id());
        error_log('InterSoccer Late Pickup: Is camp check: ' . (intersoccer_is_camp($product->get_id()) ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Rendering late pickup HTML now');
    }

    if (!$has_late_pickup_enabled) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: No variations have late pickup enabled, skipping render');
        }
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Rendering late pickup fields for product ' . $product->get_id());
    }

    // Register strings for WPML
    if (function_exists('icl_register_string')) {
        icl_register_string('intersoccer-product-variations', 'Late Pick Up Options', 'Late Pick Up Options');
        icl_register_string('intersoccer-product-variations', 'Late Pickup Type', 'Late Pickup Type');
        icl_register_string('intersoccer-product-variations', 'Single Day', 'Single Day');
        icl_register_string('intersoccer-product-variations', 'Full Week', 'Full Week');
        icl_register_string('intersoccer-product-variations', 'Late Pickup Days', 'Late Pickup Days');
        icl_register_string('intersoccer-product-variations', 'CHF', 'CHF');
    }

    // Enqueue scripts and styles
    wp_enqueue_style('intersoccer-late-pickup', plugin_dir_url(__FILE__) . 'css/late-pickup.css', [], '1.0', 'all');
    wp_enqueue_script('intersoccer-late-pickup', plugin_dir_url(__FILE__) . 'js/late-pickup.js', ['jquery'], '1.0', true);

    // Localize script with PHP data
    $per_day_cost = get_option('intersoccer_late_pickup_per_day_cost', 10);
    $full_week_cost = get_option('intersoccer_late_pickup_full_week_cost', 50);
    wp_localize_script('intersoccer-late-pickup', 'intersoccerLatePickup', [
        'perDayCost' => $per_day_cost,
        'fullWeekCost' => $full_week_cost,
        'variationSettings' => $variation_settings,
        'debug' => defined('WP_DEBUG') && WP_DEBUG,
    ]);

    // Output HTML for late pickup options
    ?>
    <div class="intersoccer-late-pickup" style="display:none;">
        <h3><?php _e('Late Pick Up Options', 'intersoccer-product-variations'); ?></h3>
        <div class="form-row">
            <label>
                <input type="radio" name="late_pickup_type" value="single-days" checked>
                <?php _e('Single Day', 'intersoccer-product-variations'); ?>
            </label>
            <label>
                <input type="radio" name="late_pickup_type" value="full-week">
                <?php _e('Full Week', 'intersoccer-product-variations'); ?>
            </label>
        </div>
        <div class="intersoccer-late-pickup-days" style="display:none;">
            <h4><?php _e('Select Late Pickup Days', 'intersoccer-product-variations'); ?></h4>
            <?php
            // Output checkboxes for each day
            $days = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday'];
            foreach ($days as $day_key => $day_name) {
                echo '<label><input type="checkbox" class="late-pickup-day-checkbox" name="late_pickup_days[]" value="' . esc_attr($day_key) . '"> ' . esc_html($day_name) . '</label>';
            }
            ?>
        </div>
        <div class="late-pickup-cost">
            <strong><?php _e('Late Pickup Cost:', 'intersoccer-product-variations'); ?> <span class="intersoccer-late-pickup-price"><?php echo wp_kses_post(wc_price(0)); ?>(0)); ?></span></strong>(0)); ?>
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
    
    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Checking product ' . $product->get_id() . ' for late pickup fields');
        $is_camp_result = intersoccer_is_camp($product->get_id());
        error_log('InterSoccer Late Pickup: Product type check: ' . ($is_camp_result ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Current language: ' . (function_exists('wpml_get_current_language') ? wpml_get_current_language() : 'default'));
    }
    
    if (!intersoccer_is_camp($product->get_id())) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: Product is not a camp, skipping');
        }
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: Variation ' . $variation_id . ' metadata: "' . $enabled . '" -> enabled: ' . ($enabled === 'yes' ? 'true' : 'false'));
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Variations checked: ' . count($variations));
        error_log('InterSoccer Late Pickup: Has late pickup enabled: ' . ($has_late_pickup_enabled ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Variation settings: ' . json_encode($variation_settings));
        error_log('InterSoccer Late Pickup: Product ID: ' . $product->get_id());
        error_log('InterSoccer Late Pickup: Is camp check: ' . (intersoccer_is_camp($product->get_id()) ? 'true' : 'false'));
        error_log('InterSoccer Late Pickup: Rendering late pickup HTML now');
    }

    if (!$has_late_pickup_enabled) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Late Pickup: No variations have late pickup enabled, skipping render');
        }
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Late Pickup: Rendering late pickup fields for product ' . $product->get_id());
    }

    // Register strings for WPML
    if (function_exists('icl_register_string')) {
        icl_register_string('intersoccer-product-variations', 'Late Pick Up Options', 'Late Pick Up Options');
        icl_register_string('intersoccer-product-variations', 'Late Pickup Type', 'Late Pickup Type');
        icl_register_string('intersoccer-product-variations', 'Single Day', 'Single Day');
        icl_register_string('intersoccer-product-variations', 'Full Week', 'Full Week');
        icl_register_string('intersoccer-product-variations', 'Late Pickup Days', 'Late Pickup Days');
        icl_register_string('intersoccer-product-variations', 'CHF', 'CHF');
    }

    // Enqueue scripts and styles
    wp_enqueue_style('intersoccer-late-pickup', plugin_dir_url(__FILE__) . 'css/late-pickup.css', [], '1.0', 'all');
    wp_enqueue_script('intersoccer-late-pickup', plugin_dir_url(__FILE__) . 'js/late-pickup.js', ['jquery'], '1.0', true);

    // Localize script with PHP data
    $per_day_cost = get_option('intersoccer_late_pickup_per_day_cost', 10);
    $full_week_cost = get_option('intersoccer_late_pickup_full_week_cost', 50);
    wp_localize_script('intersoccer-late-pickup', 'intersoccerLatePickup', [
        'perDayCost' => $per_day_cost,
        'fullWeekCost' => $full_week_cost,
        'variationSettings' => $variation_settings,
        'debug' => defined('WP_DEBUG') && WP_DEBUG,
    ]);

    // Output HTML for late pickup options
    ?>
    <div class="intersoccer-late-pickup" style="display:none;">
        <h3><?php _e('Late Pick Up Options', 'intersoccer-product-variations'); ?></h3>
        <div class="form-row">
            <label>
                <input type="radio" name="late_pickup_type" value="single-days" checked>
                <?php _e('Single Day', 'intersoccer-product-variations'); ?>
            </label>
            <label>
                <input type="radio" name="late_pickup_type" value="full-week">
                <?php _e('Full Week', 'intersoccer-product-variations'); ?>
            </label>
        </div>
        <div class="intersoccer-late-pickup-days" style="display:none;">
            <h4><?php _e('Select Late Pickup Days', 'intersoccer-product-variations'); ?></h4>
            <?php
            // Output checkboxes for each day
            $days = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday'];
            foreach ($days as $day_key => $day_name) {
                echo '<label><input type="checkbox" class="late-pickup-day-checkbox" name="late_pickup_days[]" value="' . esc_attr($day_key) . '"> ' . esc_html($day_name) . '</label>';
            }
            ?>
        </div>
        <div class="late-pickup-cost">
            <strong><?php _e('Late Pickup Cost:', 'intersoccer-product-variations'); ?> <span class="intersoccer-late-pickup-price"><?php echo wp_kses_post(wc_price(0)); ?>(0)); ?></span></strong>
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
            
            // Also check after a short delay in case variation is pre-selected
            setTimeout(function() {
                updateLatePickupVisibility();
                if (debugEnabled) {
                    console.log('InterSoccer Late Pickup: Delayed visibility check completed');
                }
            }, 500);
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