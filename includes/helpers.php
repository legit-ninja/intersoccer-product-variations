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

/**
 * Canonical full-day camp time slug (10:00–17:00) used by the live catalogue.
 */
if (!defined('INTERSOCCER_CAMP_TIME_FULL_DAY')) {
    define('INTERSOCCER_CAMP_TIME_FULL_DAY', '1000-1700');
}

/**
 * Canonical half-day / Mini camp time slug (10:00–12:30) used by the live catalogue.
 */
if (!defined('INTERSOCCER_CAMP_TIME_HALF_DAY')) {
    define('INTERSOCCER_CAMP_TIME_HALF_DAY', '1000-1230');
}

/**
 * Preferred camp-time slug for an age-group slug (Full Day vs Half Day).
 *
 * Prefers matches from $allowed_time_slugs when provided; otherwise falls back to
 * catalogue defaults. Returns empty string when no safe match exists in a non-empty pool.
 *
 * @param string   $age_slug            pa_age-group term slug.
 * @param string[] $allowed_time_slugs  Optional pool (parent product times).
 * @return string
 */
function intersoccer_pm_default_camp_time_slug_for_age($age_slug, $allowed_time_slugs = []) {
    $age_slug = is_string($age_slug) ? strtolower(trim($age_slug)) : '';
    if (function_exists('sanitize_title')) {
        $age_slug = sanitize_title((string) $age_slug);
    }
    $allowed = array_values(array_filter(array_map('strval', (array) $allowed_time_slugs)));

    $is_half = function_exists('intersoccer_is_half_day_age_group')
        ? intersoccer_is_half_day_age_group('', $age_slug)
        : (strpos($age_slug, 'half-day') !== false || strpos($age_slug, 'half_day') !== false);

    $preferred = $is_half
        ? [
            INTERSOCCER_CAMP_TIME_HALF_DAY,
            '1000-1200',
            '0900-1200',
            '1000-1230',
        ]
        : [
            INTERSOCCER_CAMP_TIME_FULL_DAY,
            '1000-1700',
            '1000-1500',
            '0900-1700',
        ];

    /**
     * Filter preferred camp-time slug candidates for an age group.
     *
     * @param string[] $preferred Preferred slugs (first match wins).
     * @param string   $age_slug
     * @param bool     $is_half
     * @param string[] $allowed
     */
    if (function_exists('apply_filters')) {
        $preferred = apply_filters('intersoccer_pm_camp_time_slug_candidates', $preferred, $age_slug, $is_half, $allowed);
    }

    $pool = !empty($allowed) ? $allowed : $preferred;

    foreach ($preferred as $candidate) {
        if (in_array($candidate, $pool, true)) {
            return $candidate;
        }
    }

    if (!empty($allowed)) {
        foreach ($allowed as $slug) {
            $s = strtolower((string) $slug);
            if ($is_half && (preg_match('/12[0-3]0$/', $s) || strpos($s, '1200') !== false || strpos($s, '1230') !== false)) {
                return $slug;
            }
            if (!$is_half && (preg_match('/17[0-3]0$/', $s) || strpos($s, '1700') !== false || strpos($s, '1500') !== false)) {
                return $slug;
            }
        }
        return '';
    }

    return $is_half ? INTERSOCCER_CAMP_TIME_HALF_DAY : INTERSOCCER_CAMP_TIME_FULL_DAY;
}

/**
 * Infer a camp-terms slug for a variation from schedule meta + parent term pool.
 *
 * @param int      $variation_id
 * @param string[] $allowed_term_slugs Parent pa_camp-terms slugs.
 * @return string
 */
function intersoccer_pm_infer_camp_term_slug_for_variation($variation_id, $allowed_term_slugs = []) {
    $allowed = array_values(array_filter(array_map('strval', (array) $allowed_term_slugs)));
    if ($allowed === []) {
        return '';
    }

    $week = (int) get_post_meta($variation_id, '_camp_week_index', true);
    $start = (string) get_post_meta($variation_id, '_camp_start_date', true);

    if ($week > 0) {
        foreach ($allowed as $slug) {
            if (preg_match('/(?:^|[-_])week[-_]?0*' . preg_quote((string) $week, '/') . '(?:[-_]|$)/i', $slug)) {
                return $slug;
            }
        }
    }

    if ($start !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $start, $m)) {
        $month = (int) $m[2];
        $day = (int) $m[3];
        $month_names = [
            1 => 'january', 2 => 'february', 3 => 'march', 4 => 'april',
            5 => 'may', 6 => 'june', 7 => 'july', 8 => 'august',
            9 => 'september', 10 => 'october', 11 => 'november', 12 => 'december',
        ];
        $month_name = $month_names[$month] ?? '';
        if ($month_name !== '') {
            foreach ($allowed as $slug) {
                $s = strtolower($slug);
                if (strpos($s, $month_name) !== false && preg_match('/(?:^|[-_])' . $day . '(?:[-_]|$)/', $s)) {
                    return $slug;
                }
            }
        }
    }

    if (count($allowed) === 1) {
        return $allowed[0];
    }

    return '';
}

/**
 * Infer a venue slug when the parent has a single venue (safe default).
 *
 * @param string[] $allowed_venue_slugs
 * @return string
 */
function intersoccer_pm_infer_venue_slug_for_variation($allowed_venue_slugs = []) {
    $allowed = array_values(array_filter(array_map('strval', (array) $allowed_venue_slugs)));
    return count($allowed) === 1 ? $allowed[0] : '';
}

/**
 * Build course variation matrix rows from selected day / age / time / venue slugs.
 *
 * Empty days → empty matrix. Empty optional dimensions are omitted from each row.
 * Prefer empty $time_slugs at create — course times are assigned per variation.
 *
 * @param string[] $day_slugs
 * @param string[] $age_slugs
 * @param string[] $time_slugs
 * @param string[] $venue_slugs
 * @return array<int,array<string,string>>
 */
function intersoccer_pm_build_course_matrix_rows($day_slugs = [], $age_slugs = [], $time_slugs = [], $venue_slugs = []) {
    $days = array_values(array_filter(array_map('strval', (array) $day_slugs)));
    if ($days === []) {
        return [];
    }

    $ages = array_values(array_filter(array_map('strval', (array) $age_slugs)));
    if ($ages === []) {
        $ages = [''];
    }

    $times = array_values(array_filter(array_map('strval', (array) $time_slugs)));
    if ($times === []) {
        $times = [''];
    }

    $venues = array_values(array_filter(array_map('strval', (array) $venue_slugs)));
    if ($venues === []) {
        $venues = [''];
    }

    $matrix = [];
    foreach ($days as $day) {
        foreach ($ages as $age) {
            foreach ($times as $time) {
                foreach ($venues as $venue) {
                    $row   = ['pa_course-day' => $day];
                    $parts = [$day];
                    if ($age !== '') {
                        $row['pa_age-group'] = $age;
                        $parts[]            = $age;
                    }
                    if ($time !== '') {
                        $row['pa_course-times'] = $time;
                        $parts[]                = $time;
                    }
                    if ($venue !== '') {
                        $row['pa_intersoccer-venues'] = $venue;
                        $parts[]                      = $venue;
                    }
                    $row['label'] = implode(' / ', $parts);
                    $matrix[]     = $row;
                }
            }
        }
    }

    return $matrix;
}

/**
 * Whether a WooCommerce product status may be set via Program Manager quick/detail edit.
 *
 * @param string $status
 * @return bool
 */
function intersoccer_pm_is_allowed_product_status($status) {
    return in_array((string) $status, ['draft', 'publish', 'private'], true);
}
