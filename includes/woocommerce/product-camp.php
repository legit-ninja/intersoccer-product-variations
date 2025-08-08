<?php
/**
 * File: product-camps.php
 * Description: Camp-specific logic for InterSoccer WooCommerce products, including price calculations and validation.
 * Dependencies: WooCommerce, product-types.php (for type detection)
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle camp-specific calculations and validation.
 */
class InterSoccer_Camp {

    /**
     * Calculate camp price based on booking type and selected days.
     *
     * @param int $product_id Product ID.
     * @param int $variation_id Variation ID.
     * @param array $camp_days Selected days for single-day camps.
     * @return float Calculated price.
     */
    public static function calculate_price($product_id, $variation_id, $camp_days = []) {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            error_log('InterSoccer: Invalid product for camp price calculation: ' . ($variation_id ?: $product_id));
            return 0;
        }

        $price = floatval($product->get_price());
        $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);

        if ($booking_type === 'single-days' && !empty($camp_days)) {
            $price_per_day = $price; // CHF 100/day as base price
            $price = $price_per_day * count($camp_days);
            error_log('InterSoccer: Camp price for variation ' . $variation_id . ': ' . $price . ' (days: ' . count($camp_days) . ')');
        } else {
            // Full-week price (e.g., CHF 500/week)
            error_log('InterSoccer: Camp price for variation ' . $variation_id . ': ' . $price . ' (full-week)');
        }

        return max(0, floatval($price));
    }

    /**
     * Validate single-day camp selection.
     *
     * @param bool $passed Current validation status.
     * @param int $product_id Product ID.
     * @param int $quantity Quantity.
     * @return bool Updated validation status.
     */
    public static function validate_single_day($passed, $product_id, $quantity) {
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

    /**
     * Calculate discount note for camp.
     *
     * @param int $variation_id Variation ID.
     * @param array $camp_days Selected days (if any).
     * @return string Discount note.
     */
    public static function calculate_discount_note($variation_id, $camp_days = []) {
        $discount_note = '';
        // Placeholder for discount logic (to be expanded in discounts.php)
        if (!empty($camp_days)) {
            $discount_note = count($camp_days) . ' Day(s) Selected';
        }
        error_log('InterSoccer: Calculated discount_note for camp variation ' . $variation_id . ': ' . $discount_note);
        return $discount_note;
    }
}

// Procedural wrappers for backward compatibility
function intersoccer_calculate_camp_price($product_id, $variation_id, $camp_days = []) {
    return InterSoccer_Camp::calculate_price($product_id, $variation_id, $camp_days);
}

function intersoccer_validate_single_day_camp($passed, $product_id, $quantity) {
    return InterSoccer_Camp::validate_single_day($passed, $product_id, $quantity);
}

error_log('InterSoccer: Defined camp functions in product-camps.php');
?>