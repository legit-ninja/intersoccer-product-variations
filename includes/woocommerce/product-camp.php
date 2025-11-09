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
     * Calculate camp price based on booking type and quantity.
     *
     * @param int $product_id Product ID.
     * @param int $variation_id Variation ID.
     * @param array $camp_days Selected days for single-day camps.
     * @param int $quantity Cart item quantity.
     * @return float Calculated price.
     */
    public static function calculate_price($product_id, $variation_id, $camp_days = [], $quantity = 1) {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            intersoccer_debug('InterSoccer: Invalid product for camp price calculation: ' . ($variation_id ?: $product_id));
            return 0;
        }

        $price = floatval($product->get_price());
        $booking_type = get_post_meta($variation_id ?: $product_id, 'attribute_pa_booking-type', true);

        // Check if this is a single-day booking
        $is_single_day = stripos($booking_type, 'single') !== false ||
                        stripos($booking_type, 'jour') !== false ||
                        stripos($booking_type, 'day') !== false ||
                        stripos($booking_type, 'einzel') !== false ||
                        stripos($booking_type, 'tag') !== false;

        if ($is_single_day) {
            $price_per_day = $price; // CHF 55/day as base price
            $num_days = count($camp_days);
            if ($num_days > 0) {
                $price = $price_per_day * $num_days;
            } else {
                // Fallback to quantity if no days selected (shouldn't happen in normal flow)
                $price = $price_per_day * $quantity;
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Camp price for variation ' . $variation_id . ': ' . $price . ' (' . $num_days . ' days selected, per_day: ' . $price_per_day . ', booking_type: ' . $booking_type . ')');
            }
        } else {
            // Full-week price (e.g., CHF 500/week)
            intersoccer_debug('InterSoccer: Camp price for variation ' . $variation_id . ': ' . $price . ' (full-week, booking_type: ' . $booking_type . ')');
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
                    intersoccer_debug('InterSoccer: Validation failed - no valid camp_days data for product ' . $product_id . ': ' . print_r($_POST, true));
                } elseif (count($camp_days) !== $quantity) {
                    $passed = false;
                    wc_add_notice(__('The number of selected days must match the quantity.', 'intersoccer-product-variations'), 'error');
                    intersoccer_debug('InterSoccer: Validation failed - camp_days count (' . count($camp_days) . ') does not match quantity (' . $quantity . ') for product ' . $product_id);
                } else {
                    intersoccer_debug('InterSoccer: Validated single-day camp with ' . count($camp_days) . ' days and quantity ' . $quantity . ' for product ' . $product_id . ': ' . print_r($camp_days, true));
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
        if (!empty($camp_days)) {
            $discount_note = sprintf(__('%d Day(s) Selected', 'intersoccer-product-variations'), count($camp_days));
        }
        intersoccer_debug('InterSoccer: Calculated discount_note for camp variation ' . $variation_id . ': ' . $discount_note);
        return $discount_note;
    }
}

// Procedural wrappers for backward compatibility
function intersoccer_calculate_camp_price($product_id, $variation_id, $camp_days = [], $quantity = 1) {
    return InterSoccer_Camp::calculate_price($product_id, $variation_id, $camp_days, $quantity);
}

function intersoccer_validate_single_day_camp($passed, $product_id, $quantity) {
    return InterSoccer_Camp::validate_single_day($passed, $product_id, $quantity);
}

intersoccer_debug('InterSoccer: Defined camp functions in product-camps.php');
?>