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
 * English weekday labels derived from intersoccer_weekday_slug_map().
 *
 * @return array<int,string>
 */
function intersoccer_canonical_weekday_labels() {
    $labels = [];
    if (!function_exists('intersoccer_weekday_slug_map')) {
        return $labels;
    }
    foreach (intersoccer_weekday_slug_map() as $slug => $number) {
        $number = (int) $number;
        if ($number > 0 && !isset($labels[$number])) {
            $labels[$number] = ucfirst((string) $slug);
        }
    }
    return $labels;
}

/**
 * Map one posted day token to a weekday number. Does not split comma-separated values.
 *
 * @param string $value
 * @return int 1-7, or 0 when the token is not one known day.
 */
function intersoccer_camp_day_number($value) {
    if (!function_exists('intersoccer_weekday_slug_map')) {
        return 0;
    }
    $key = strtolower(trim((string) $value));
    if ($key === '') {
        return 0;
    }
    $map = intersoccer_weekday_slug_map();
    return isset($map[$key]) ? (int) $map[$key] : 0;
}

/**
 * Weekday numbers this variation actually offers.
 *
 * Uses _intersoccer_camp_days_available when it is set. Otherwise Monday-Friday,
 * matching the variation admin default.
 *
 * @param int $variation_id
 * @return array<int,bool>
 */
function intersoccer_variation_camp_day_numbers($variation_id) {
    $variation_id = (int) $variation_id;
    $available = $variation_id > 0 ? get_post_meta($variation_id, '_intersoccer_camp_days_available', true) : [];
    if (is_array($available) && $available !== []) {
        $numbers = [];
        foreach ($available as $day => $enabled) {
            if (!$enabled) {
                continue;
            }
            if (is_int($day) || (is_string($day) && ctype_digit($day))) {
                $candidate = is_string($enabled) ? $enabled : '';
            } else {
                $candidate = (string) $day;
            }
            $number = intersoccer_camp_day_number($candidate);
            if ($number > 0) {
                $numbers[$number] = true;
            }
        }
        return $numbers;
    }

    return [1 => true, 2 => true, 3 => true, 4 => true, 5 => true];
}

/**
 * Keep only real, unique camp days for this variation.
 *
 * Unknown entries, including one value that lists several days, are dropped.
 * A single posted string is never split into separate days.
 *
 * @param array $posted        Raw camp_days values.
 * @param int   $variation_id  Variation whose available days limit the list.
 * @return string[] Canonical English day names, in weekday order.
 */
function intersoccer_normalize_posted_camp_days($posted, $variation_id = 0) {
    if (!is_array($posted)) {
        return [];
    }
    $allowed = intersoccer_variation_camp_day_numbers($variation_id);
    $labels = intersoccer_canonical_weekday_labels();
    $seen = [];
    foreach ($posted as $entry) {
        $entry = sanitize_text_field(wp_unslash((string) $entry));
        if ($entry === '') {
            continue;
        }
        $number = intersoccer_camp_day_number($entry);
        if ($number <= 0 || empty($allowed[$number]) || isset($seen[$number]) || !isset($labels[$number])) {
            continue;
        }
        $seen[$number] = $labels[$number];
    }
    ksort($seen);
    return array_values($seen);
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

        $is_single_day = function_exists('intersoccer_is_single_day_booking_type')
            ? intersoccer_is_single_day_booking_type($booking_type)
            : false;

        if ($is_single_day) {
            $camp_days = intersoccer_normalize_posted_camp_days($camp_days, $variation_id ?: $product_id);
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
            if (function_exists('intersoccer_is_single_day_booking_type') && intersoccer_is_single_day_booking_type($booking_type)) {
                $raw_camp_days = isset($_POST['camp_days']) && is_array($_POST['camp_days']) ? $_POST['camp_days'] : [];
                $camp_days = intersoccer_normalize_posted_camp_days($raw_camp_days, $variation_id);
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