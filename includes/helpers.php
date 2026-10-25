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
    static $needles = null;
    if ($needles === null) {
        $needles = [
            'half-day',
            'half day',
            'halber-tag',
            'halber tag',
            'halbtag',
            'demi-journee',
            'demi-journée',
            'demi-jour',
            'demi journee',
            'demi journée',
        ];
    }

    foreach ([$slug, $label] as $hay) {
        $h = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $hay), 'UTF-8') : strtolower(trim((string) $hay));
        if ($h === '') {
            continue;
        }
        if (function_exists('remove_accents')) {
            $h = remove_accents($h);
        }
        $h = str_replace(['_', ' '], '-', $h);
        foreach ($needles as $needle) {
            $n = function_exists('remove_accents') ? remove_accents($needle) : $needle;
            if (strpos($h, $n) !== false) {
                return true;
            }
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

/**
 * Whether a variation post status means Enabled in WooCommerce.
 *
 * @param string $status Variation status (publish|private|…).
 * @return bool
 */
function intersoccer_pm_variation_is_enabled($status) {
    return (string) $status === 'publish';
}

/**
 * Map Enabled checkbox to WooCommerce variation post status.
 *
 * @param bool $enabled
 * @return string publish|private
 */
function intersoccer_pm_variation_enabled_status($enabled) {
    return $enabled ? 'publish' : 'private';
}

/**
 * True when a camp end date (Y-m-d) is strictly before today.
 *
 * @param string      $end_ymd   Camp end date.
 * @param string|null $today_ymd Override today (Y-m-d); null uses site timezone via current_time.
 * @return bool
 */
function intersoccer_pm_camp_end_is_past($end_ymd, $today_ymd = null) {
    $end_ymd = trim((string) $end_ymd);
    if ($end_ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_ymd)) {
        return false;
    }
    if ($today_ymd === null || $today_ymd === '') {
        $today_ymd = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
    }
    $today_ymd = trim((string) $today_ymd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today_ymd)) {
        return false;
    }
    return $end_ymd < $today_ymd;
}

/**
 * True when a camp variation is past end and still Enabled (publish) — needs disable.
 *
 * @param string      $end_ymd   Camp end date (Y-m-d).
 * @param string      $status    Variation post status.
 * @param string|null $today_ymd Override today (Y-m-d); null uses site timezone.
 * @return bool
 */
function intersoccer_pm_variation_ended_needs_action($end_ymd, $status, $today_ymd = null) {
    return intersoccer_pm_camp_end_is_past($end_ymd, $today_ymd)
        && intersoccer_pm_variation_is_enabled($status);
}

/**
 * Normalize a program-year value to a bare calendar year (e.g. 2027).
 *
 * @param string $year Raw year (name or slug).
 * @return string Empty string if invalid.
 */
function intersoccer_pm_normalize_program_year($year) {
    $year = sanitize_text_field((string) $year);
    if (preg_match('/^(20\d{2})$/', $year, $m)) {
        return $m[1];
    }
    if (preg_match('/\b(20\d{2})\b/', $year, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Rewrite a program title for a target year (strip "(Copy)", replace or append year).
 *
 * @param string $name Source or draft title.
 * @param string $year Target year (normalized internally).
 * @return string
 */
function intersoccer_pm_rewrite_program_title_for_year($name, $year) {
    $year = intersoccer_pm_normalize_program_year($year);
    $name = trim((string) $name);
    if ($year === '' || $name === '') {
        return $name;
    }
    $name = preg_replace('/\s*\(Copy\)\s*$/i', '', $name);
    $name = trim($name);
    if (preg_match('/\b20\d{2}\b/', $name)) {
        return preg_replace('/\b20\d{2}\b/', $year, $name, 1);
    }
    return $name . ' ' . $year;
}

/**
 * True when a season label looks year-qualified (e.g. "Autumn 2027") — forbidden for new terms.
 *
 * @param string $label Season term name or slug.
 * @return bool
 */
function intersoccer_pm_is_year_qualified_season_label($label) {
    $label = trim((string) $label);
    if ($label === '') {
        return false;
    }
    // Bare year alone is a program-year, not a season label.
    if (preg_match('/^(20\d{2})$/', $label)) {
        return false;
    }
    // Match "Autumn 2027", "autumn-2027", etc.
    return (bool) preg_match('/20\d{2}/', $label);
}

/**
 * Compose a same-season discount key from season label + optional program year.
 *
 * When year is known (pa_program-year), returns "season|YYYY" so evergreen seasons
 * (Autumn) do not cross-discount across years. When year is empty, returns the season
 * string unchanged so legacy year-qualified labels (Autumn 2026) stay unique.
 *
 * @param string $season       Season term name or empty.
 * @param string $program_year Raw year (normalized internally).
 * @return string
 */
function intersoccer_discount_compose_season_key($season, $program_year = '', $season_term_id = 0) {
    $season = trim((string) $season);
    $year   = intersoccer_pm_normalize_program_year((string) $program_year);
    $season_term_id = (int) $season_term_id;
    if (
        $season_term_id > 0
        && $year !== ''
        && empty($GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'])
    ) {
        $taxonomy = function_exists('intersoccer_attr_taxonomy')
            ? intersoccer_attr_taxonomy('program-season')
            : 'pa_program-season';
        $canonical_id = intersoccer_discount_wpml_canonical_term_id($season_term_id, $taxonomy);
        if ($canonical_id > 0) {
            return 'ps:' . $canonical_id . '|' . $year;
        }
    }
    if ($year !== '') {
        return strtolower($season) . '|' . $year;
    }
    return $season;
}

/**
 * Whether WPML default language is configured (discount paths only).
 *
 * @return bool
 */
function intersoccer_discount_wpml_is_configured() {
    return function_exists('apply_filters') && (bool) apply_filters('wpml_default_language', null);
}

/**
 * Run a callback while WPML is switched to a language, restoring the previous language.
 *
 * @param string|null $language_code Target language or null to skip switching.
 * @param callable    $callback
 * @return mixed
 */
function intersoccer_discount_wpml_execute_in_language($language_code, callable $callback) {
    if ($language_code === null || $language_code === '' || !intersoccer_discount_wpml_is_configured()) {
        return $callback();
    }
    if (!empty($GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'])) {
        return $callback();
    }
    $previous = apply_filters('wpml_current_language', null);
    $switched = false;
    if (function_exists('do_action') && $previous !== $language_code) {
        do_action('wpml_switch_language', $language_code);
        $switched = true;
    }
    $restore_term_suppress = false;
    if (array_key_exists('intersoccer_test_wpml_suppress_term_filters', $GLOBALS)) {
        $restore_term_suppress = true;
        $prior_term_suppress = $GLOBALS['intersoccer_test_wpml_suppress_term_filters'];
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = true;
    }
    try {
        return $callback();
    } finally {
        if ($restore_term_suppress) {
            $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = $prior_term_suppress;
        }
        if ($switched && $previous !== null && $previous !== '' && function_exists('do_action')) {
            do_action('wpml_switch_language', $previous);
        }
    }
}

/**
 * WPML default-language term ID for discount grouping (falls back to the given ID).
 *
 * @param int    $term_id
 * @param string $taxonomy
 * @return int
 */
function intersoccer_discount_wpml_canonical_term_id($term_id, $taxonomy) {
    $term_id = (int) $term_id;
    if ($term_id <= 0) {
        return 0;
    }
    if (function_exists('apply_filters')) {
        $default_lang = apply_filters('wpml_default_language', null);
        if ($default_lang) {
            $canonical = (int) apply_filters('wpml_object_id', $term_id, $taxonomy, true, $default_lang);
            if ($canonical > 0) {
                return $canonical;
            }
        }
    }
    return $term_id;
}

/**
 * Default-language term name for a taxonomy term (WPML-aware).
 *
 * @param int    $term_id
 * @param string $taxonomy
 * @return string
 */
function intersoccer_discount_canonical_term_name($term_id, $taxonomy) {
    $term_id = (int) $term_id;
    if ($term_id <= 0) {
        return '';
    }
    $use_default_lang_term = empty($GLOBALS['intersoccer_test_disable_discount_term_default_lang_read']);
    $read_id = $use_default_lang_term
        ? intersoccer_discount_wpml_canonical_term_id($term_id, $taxonomy)
        : $term_id;
    if ($read_id <= 0) {
        return '';
    }
    $restore_term_suppress = false;
    if (
        $use_default_lang_term
        && array_key_exists('intersoccer_test_wpml_suppress_term_filters', $GLOBALS)
    ) {
        $restore_term_suppress = true;
        $prior_term_suppress = $GLOBALS['intersoccer_test_wpml_suppress_term_filters'];
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = true;
    }
    try {
        $term = get_term($read_id, $taxonomy);
        if ($term && !is_wp_error($term) && !empty($term->name)) {
            return (string) $term->name;
        }
        return '';
    } finally {
        if ($restore_term_suppress) {
            $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = $prior_term_suppress;
        }
    }
}

/**
 * Default-language product or variation ID for discount identity (WPML-aware).
 *
 * @param int    $post_id Product or variation ID.
 * @param string $type    WPML element type: product or product_variation.
 * @return int
 */
function intersoccer_discount_wpml_canonical_post_id($post_id, $type) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return 0;
    }
    if (!function_exists('apply_filters')) {
        return $post_id;
    }
    $default_lang = apply_filters('wpml_default_language', null);
    if (!$default_lang) {
        return $post_id;
    }
    $canonical = (int) apply_filters('wpml_object_id', $post_id, $type, true, $default_lang);
    return $canonical > 0 ? $canonical : $post_id;
}

/**
 * Default-language variation ID for discount comparisons (falls back to raw ID).
 *
 * @param int $variation_id
 * @return int
 */
function intersoccer_discount_wpml_canonical_variation_id($variation_id) {
    return intersoccer_discount_wpml_canonical_post_id($variation_id, 'product_variation');
}

/**
 * Default-language product ID for discount comparisons (falls back to raw ID).
 *
 * @param int $product_id
 * @return int
 */
function intersoccer_discount_wpml_canonical_product_id($product_id) {
    return intersoccer_discount_wpml_canonical_post_id($product_id, 'product');
}

/**
 * Canonical camp line identity for discount comparisons (variation, else parent product).
 *
 * @param int $variation_id
 * @param int $product_id
 * @return int
 */
function intersoccer_discount_wpml_canonical_camp_booking_id($variation_id, $product_id = 0) {
    $variation_id = (int) $variation_id;
    $product_id = (int) $product_id;
    if ($variation_id > 0) {
        return intersoccer_discount_wpml_canonical_variation_id($variation_id);
    }
    if ($product_id > 0) {
        return intersoccer_discount_wpml_canonical_product_id($product_id);
    }
    return 0;
}

/**
 * Map a stored or translated season label to the default-language season name.
 *
 * @param string $label Season term name (any language).
 * @return string Canonical season name, or the original label when unresolved.
 */
function intersoccer_discount_normalize_season_label_for_lookup($label) {
    $label = html_entity_decode(trim((string) $label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $label = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $label);
    if (function_exists('remove_accents')) {
        $label = remove_accents($label);
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($label, 'UTF-8');
    }
    return strtolower($label);
}

/**
 * Resolve a pa_program-season term ID from a stored label (any WPML language).
 *
 * @param string $label Season term name or slug fragment from order meta.
 * @return int Default-language term ID, or 0 when unresolved.
 */
function intersoccer_discount_season_term_id_from_label($label) {
    if (!empty($GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'])) {
        return 0;
    }
    $label = trim((string) $label);
    if ($label === '') {
        return 0;
    }
    $taxonomy = function_exists('intersoccer_attr_taxonomy')
        ? intersoccer_attr_taxonomy('program-season')
        : 'pa_program-season';
    $needle = intersoccer_discount_normalize_season_label_for_lookup($label);
    if ($needle === '') {
        return 0;
    }

    $restore_term_suppress = false;
    if (array_key_exists('intersoccer_test_wpml_suppress_term_filters', $GLOBALS)) {
        $restore_term_suppress = true;
        $prior_term_suppress = $GLOBALS['intersoccer_test_wpml_suppress_term_filters'];
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = true;
    }

    try {
        if (!empty($GLOBALS['intersoccer_test_terms']) && is_array($GLOBALS['intersoccer_test_terms'])) {
            foreach ($GLOBALS['intersoccer_test_terms'] as $term_id => $row) {
                if (!is_array($row) || ($row['taxonomy'] ?? '') !== $taxonomy) {
                    continue;
                }
                $name_norm = intersoccer_discount_normalize_season_label_for_lookup($row['name'] ?? '');
                $slug_norm = intersoccer_discount_normalize_season_label_for_lookup(
                    $row['slug'] ?? (function_exists('sanitize_title') ? sanitize_title((string) ($row['name'] ?? '')) : '')
                );
                if ($name_norm === $needle || $slug_norm === $needle) {
                    return intersoccer_discount_wpml_canonical_term_id((int) $term_id, $taxonomy);
                }
            }
        }

        if (function_exists('get_terms')) {
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]);
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if (!is_object($term) || empty($term->term_id)) {
                        continue;
                    }
                    $name_norm = intersoccer_discount_normalize_season_label_for_lookup($term->name ?? '');
                    $slug_norm = intersoccer_discount_normalize_season_label_for_lookup($term->slug ?? '');
                    if ($name_norm === $needle || $slug_norm === $needle) {
                        return intersoccer_discount_wpml_canonical_term_id((int) $term->term_id, $taxonomy);
                    }
                }
            }
        }

        if (function_exists('get_term_by')) {
            $term = get_term_by('name', $label, $taxonomy);
            if (!$term && function_exists('sanitize_title')) {
                $term = get_term_by('slug', sanitize_title($label), $taxonomy);
            }
            if ($term && !is_wp_error($term) && !empty($term->term_id)) {
                return intersoccer_discount_wpml_canonical_term_id((int) $term->term_id, $taxonomy);
            }
        }
    } finally {
        if ($restore_term_suppress) {
            $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = $prior_term_suppress;
        }
    }

    return 0;
}

function intersoccer_discount_canonicalize_season_label($label) {
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }
    if (!empty($GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'])) {
        return $label;
    }
    if (!empty($GLOBALS['intersoccer_test_season_label_to_canonical'][$label])) {
        return (string) $GLOBALS['intersoccer_test_season_label_to_canonical'][$label];
    }
    $taxonomy = function_exists('intersoccer_attr_taxonomy')
        ? intersoccer_attr_taxonomy('program-season')
        : 'pa_program-season';
    $term_id = intersoccer_discount_season_term_id_from_label($label);
    if ($term_id > 0) {
        $canonical = intersoccer_discount_canonical_term_name($term_id, $taxonomy);
        if ($canonical !== '') {
            return $canonical;
        }
    }
    return $label;
}

/**
 * Normalize a persisted or computed discount season key for comparisons.
 *
 * Accepts legacy keys that used translated season term names in the label segment.
 *
 * @param string $key Raw _intersoccer_discount_season_key or composed key.
 * @return string
 */
function intersoccer_discount_normalize_season_key($key) {
    $key = trim((string) $key);
    if ($key === '') {
        return '';
    }
    $parts = explode('|', $key, 2);
    $season_label = $parts[0];
    $year_part    = isset($parts[1]) ? $parts[1] : '';
    if (preg_match('/^ps:(\d+)$/i', $season_label, $id_match)) {
        $term_id = (int) $id_match[1];
        if ($term_id > 0 && $year_part !== '') {
            return intersoccer_discount_compose_season_key('', $year_part, $term_id);
        }
    }
    $canonical_label = intersoccer_discount_canonicalize_season_label($season_label);
    $term_id = intersoccer_discount_season_term_id_from_label($season_label);
    if ($term_id <= 0 && $canonical_label !== $season_label) {
        $term_id = intersoccer_discount_season_term_id_from_label($canonical_label);
    }
    if ($term_id > 0 && $year_part !== '') {
        return intersoccer_discount_compose_season_key('', $year_part, $term_id);
    }
    return intersoccer_discount_compose_season_key($canonical_label, $year_part);
}

/**
 * Localized WooCommerce My Account endpoint slug for Manage Players (WPML + fallbacks).
 *
 * @return string Endpoint slug (no leading/trailing slashes).
 */
function intersoccer_pv_manage_players_endpoint_slug() {
    $default = 'manage-players';
    $slug = apply_filters('wpml_translate_single_string', $default, 'WordPress', 'URL manage-players slug');
    if (!is_string($slug) || $slug === '') {
        $slug = $default;
    }

    $lang = apply_filters('wpml_current_language', null);
    $lang_code = is_string($lang) && $lang !== '' ? substr(strtolower($lang), 0, 2) : 'en';
    if ($slug === $default && $lang_code !== 'en') {
        $map = [
            'fr' => 'gerer-participants',
            'de' => 'teilnehmer-verwalten',
        ];
        if (isset($map[$lang_code])) {
            $slug = $map[$lang_code];
        }
    }

    return $slug;
}

/**
 * Absolute URL to the localized Manage Players My Account endpoint.
 *
 * @return string Empty when WooCommerce account URL helpers are unavailable.
 */
function intersoccer_pv_manage_players_url() {
    $slug = intersoccer_pv_manage_players_endpoint_slug();
    if ($slug === '') {
        return '';
    }

    if (function_exists('wc_get_endpoint_url') && function_exists('wc_get_page_permalink')) {
        $account = wc_get_page_permalink('myaccount');
        if (is_string($account) && $account !== '') {
            return wc_get_endpoint_url($slug, '', $account);
        }
    }

    if (function_exists('wc_get_account_endpoint_url')) {
        $url = wc_get_account_endpoint_url($slug);
        return is_string($url) ? $url : '';
    }

    return '';
}

/**
 * Two-letter language code for the no-DOB notice WPML/locale fallback.
 *
 * @return string
 */
function intersoccer_pv_age_no_dob_notice_lang_code() {
    $lang = apply_filters('wpml_current_language', null);
    if (is_string($lang) && $lang !== '') {
        return substr(strtolower($lang), 0, 2);
    }
    if (function_exists('determine_locale')) {
        $locale = determine_locale();
        if (is_string($locale) && $locale !== '') {
            return substr(strtolower($locale), 0, 2);
        }
    }

    return 'en';
}

/**
 * Customer-facing add-to-cart / checkout notice when the assigned player has no valid DOB.
 *
 * @return string Plain text or HTML (anchor only) for wc_add_notice / WP_Error message.
 */
function intersoccer_pv_age_no_dob_error_message() {
    $url = intersoccer_pv_manage_players_url();
    if ($url === '') {
        return __(
            'The selected attendee needs a valid date of birth. Please update it in your account before booking.',
            'intersoccer-product-variations'
        );
    }

    $link = '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>';
    $english_template = 'The selected attendee needs a valid date of birth. Update it under Manage Players: %s';
    /* translators: %s: URL to Manage Players */
    $template_string = __('The selected attendee needs a valid date of birth. Update it under Manage Players: %s', 'intersoccer-product-variations');
    if ($template_string === $english_template) {
        $lang_code = intersoccer_pv_age_no_dob_notice_lang_code();
        if ($lang_code === 'fr') {
            $template_string = "Le participant sélectionné doit avoir une date de naissance valide. Mettez-la à jour dans Gérer les participants\u{00A0}: %s";
        } elseif ($lang_code === 'de') {
            $template_string = 'Für den ausgewählten Teilnehmer ist ein gültiges Geburtsdatum erforderlich. Bitte aktualisieren Sie es unter „Teilnehmer verwalten“: %s';
        }
    }
    $template = esc_html($template_string);

    return sprintf($template, $link);
}

/**
 * Customer-facing notice when a girls-only session attendee has no gender set.
 *
 * @return string HTML (anchor only) or plain text when Manage Players URL is unavailable.
 */
function intersoccer_pv_girls_only_gender_required_message() {
    $url = intersoccer_pv_manage_players_url();
    if ($url === '') {
        return __(
            'This session is for girls only. The selected attendee needs gender set to female in your account. Please update Manage Players before booking.',
            'intersoccer-product-variations'
        );
    }

    $allowed_link_tags = [
        'a' => [
            'href' => [],
        ],
    ];

    return wp_kses(
        sprintf(
            /* translators: %s: URL to Manage Players */
            __(
                'This session is for girls only. The selected attendee needs gender set to female in your account. Please update it under <a href="%s">Manage Players</a>.',
                'intersoccer-product-variations'
            ),
            esc_url($url)
        ),
        $allowed_link_tags
    );
}

