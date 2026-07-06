<?php
/**
 * Helper Functions for InterSoccer Product Variations
 * 
 * @package InterSoccer_Product_Variations
 * @version 1.0.0
 */

defined('ABSPATH') or die('Restricted access');

// Player read API is owned by player-management (includes/player-data.php).
// PV callers use function_exists() guards before invoking shared helpers.

/**
 * Whether a WooCommerce booking-type attribute value means single-day (per-day) camp booking.
 * Supports EN/FR slugs and common DE/WPML slug patterns; excludes obvious full-week values.
 *
 * @param string|null $booking_type Variation attribute_pa_booking-type slug or equivalent.
 * @return bool
 */
function intersoccer_is_single_day_booking_type($booking_type) {
    if (!is_string($booking_type) || $booking_type === '') {
        return false;
    }
    $bt = function_exists('mb_strtolower') ? mb_strtolower($booking_type, 'UTF-8') : strtolower($booking_type);
    if ($bt === 'full-week' || strpos($bt, 'full-week') !== false) {
        return false;
    }
    if (strpos($bt, 'ganze') !== false && strpos($bt, 'woche') !== false) {
        return false;
    }
    if ($bt === 'single-days' || $bt === 'à la journée' || $bt === 'a-la-journee') {
        return true;
    }
    if ($bt === 'tag' || preg_match('/^(?:1[-_])?ein[-_]?tag$/', $bt) || preg_match('/^nur[-_]?tag$/', $bt)) {
        return true;
    }
    $markers = ['single', 'journée', 'journee', 'einzel', 'ein-tag', 'eintag', '1-tag', 'taeglich', 'täglich', 'nur-tag', 'pro-tag', 'pro_tag', 'pro tag', 'tagesbuchung', 'tages-buchung'];
    foreach ($markers as $needle) {
        if (strpos($bt, $needle) !== false) {
            return true;
        }
    }
    return false;
}
