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

/**
 * Whether pa_age-group label or slug indicates a half-day camp session.
 *
 * @param string $label Term name or display label.
 * @param string $slug  Term slug (optional).
 * @return bool
 */
function intersoccer_is_half_day_age_group($label, $slug = '') {
    foreach ([$label, $slug] as $hay) {
        $h = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $hay), 'UTF-8') : strtolower(trim((string) $hay));
        if ($h !== '' && (strpos($h, 'half-day') !== false || strpos($h, 'half day') !== false)) {
            return true;
        }
    }

    /**
     * Filter half-day detection for custom age-group catalogs.
     *
     * @param bool   $is_half_day
     * @param string $label
     * @param string $slug
     */
    return (bool) apply_filters('intersoccer_is_half_day_age_group', false, $label, $slug);
}

/**
 * Whether late pickup is allowed for a variation (enabled meta + full-day age group).
 *
 * @param int $variation_id Variation post ID.
 * @return bool
 */
function intersoccer_variation_allows_late_pickup($variation_id) {
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0) {
        return false;
    }
    if (get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true) !== 'yes') {
        return false;
    }

    $parent_id = 0;
    if (function_exists('wc_get_product')) {
        $product = wc_get_product($variation_id);
        if ($product && method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();
        }
    }

    $label = function_exists('intersoccer_get_variation_age_group_label')
        ? (string) intersoccer_get_variation_age_group_label($parent_id, $variation_id)
        : '';
    $slug = function_exists('intersoccer_get_variation_age_group_slug')
        ? (string) intersoccer_get_variation_age_group_slug($variation_id)
        : '';

    return !intersoccer_is_half_day_age_group($label, $slug);
}

/**
 * Whether late pickup admin fields were submitted for this variation save.
 *
 * @param int $variation_id
 * @param int $loop
 * @return bool
 */
function intersoccer_late_pickup_admin_fields_submitted($variation_id, $loop) {
    if (
        $loop >= 0
        && isset($_POST['_intersoccer_late_pickup_fields_present'])
        && is_array($_POST['_intersoccer_late_pickup_fields_present'])
        && isset($_POST['_intersoccer_late_pickup_fields_present'][$loop])
    ) {
        return true;
    }

    if (isset($_POST['_intersoccer_late_pickup_fields_present_' . $variation_id])) {
        return true;
    }

    if ($loop < 0) {
        foreach (array_keys($_POST) as $key) {
            if (
                strpos($key, '_intersoccer_late_pickup_fields_present') !== false
                || strpos($key, '_intersoccer_enable_late_pickup') !== false
            ) {
                return true;
            }
        }
    }

    return false;
}
