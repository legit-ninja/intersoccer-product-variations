<?php
/**
 * Consistent display of camp and course time ranges on the product page.
 *
 * Time attribute terms stay stored in mixed styles ("1000-1230", "10h00-11h30",
 * "08:30-16:00"). This filter only reformats pure time ranges in variation
 * selects on the storefront; admin, REST, Store API, and JSON requests keep raw
 * term names. Slugs, order meta, and stored titles are never rewritten.
 *
 * Display uses fixed 24h formats per language: H:i (en, de, default), H\hi for fr
 * (e.g. 10h00). Override via {@see intersoccer_time_range_format} filter.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attribute taxonomies whose term names are time ranges.
 *
 * @return string[]
 */
function intersoccer_time_range_taxonomies() {
    return ['pa_camp-times', 'pa_course-times'];
}

/**
 * Current two-letter language code for time range display.
 *
 * @return string Lowercase ISO 639-1 code (e.g. en, fr, de).
 */
function intersoccer_time_range_language() {
    if (function_exists('intersoccer_pv_get_wpml_language_code')) {
        $lang = intersoccer_pv_get_wpml_language_code();
    } else {
        $lang = apply_filters('wpml_current_language', null);
    }

    if (!is_string($lang) || trim($lang) === '') {
        if (function_exists('intersoccer_get_current_language')) {
            $lang = intersoccer_get_current_language();
        } elseif (function_exists('get_locale')) {
            $lang = substr((string) get_locale(), 0, 2);
        } else {
            $lang = 'en';
        }
    }

    $lang = strtolower(substr(trim((string) $lang), 0, 2));

    return $lang !== '' ? $lang : 'en';
}

/**
 * PHP date format for one time-of-day in a range for the given language.
 *
 * @param string|null $language Two-letter code; detected when null.
 * @return string
 */
function intersoccer_time_range_format($language = null) {
    if ($language === null) {
        $language = intersoccer_time_range_language();
    }
    $language = strtolower(substr(trim((string) $language), 0, 2));
    if ($language === '') {
        $language = 'en';
    }

    $default_format = ($language === 'fr') ? 'H\hi' : 'H:i';

    return (string) apply_filters('intersoccer_time_range_format', $default_format, $language);
}

/**
 * Whether variation option labels may be reformatted (storefront product page only).
 *
 * @return bool
 */
function intersoccer_time_range_display_allowed() {
    if (function_exists('is_admin') && is_admin()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (function_exists('wp_is_json_request') && wp_is_json_request()) {
        return false;
    }
    if (!empty($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
        if (strpos($_SERVER['REQUEST_URI'], '/wc/store/') !== false) {
            return false;
        }
    }
    if (!empty($_GET['rest_route']) && is_string($_GET['rest_route'])) {
        if (strpos($_GET['rest_route'], '/wc/store/') !== false) {
            return false;
        }
    }

    return true;
}

/**
 * Validates normalised HHMM-HHMM when attribute-enforcement is not loaded (#134).
 *
 * @param string $range Normalised HHMM-HHMM.
 * @return bool
 */
function intersoccer_time_range_valid_fallback($range) {
    // Mirrors intersoccer_attr_is_valid_camp_time_range (#134); drop after this branch is on master.
    $range = (string) $range;
    if (!preg_match('/^(\d{2})(\d{2})-(\d{2})(\d{2})$/', $range, $m)) {
        return false;
    }
    $start_h = (int) $m[1];
    $start_m = (int) $m[2];
    $end_h   = (int) $m[3];
    $end_m   = (int) $m[4];
    if ($start_h > 23 || $end_h > 23 || $start_m > 59 || $end_m > 59) {
        return false;
    }
    $start_minutes = ($start_h * 60) + $start_m;
    $end_minutes   = ($end_h * 60) + $end_m;

    return $start_minutes < $end_minutes;
}

/**
 * Format one time of day using the per-language range format.
 *
 * @param int         $hour     0-23.
 * @param int         $minute   0-59.
 * @param string|null $language Optional language code.
 * @return string
 */
function intersoccer_format_time_of_day($hour, $minute, $language = null) {
    $format = intersoccer_time_range_format($language);
    $seconds = ((int) $hour * 3600) + ((int) $minute * 60);

    return gmdate($format, $seconds);
}

/**
 * Reformat a time range like "1000-1230" or "10h00-11h30" for display.
 * Anything that is not exactly a valid time range comes back unchanged.
 *
 * @param string $value Term name.
 * @return string
 */
function intersoccer_format_time_range($value) {
    $value = (string) $value;
    $pattern = '/^\s*(\d{1,2})\s*[h:.]?\s*(\d{2})\s*[-\x{2013}\x{2014}]\s*(\d{1,2})\s*[h:.]?\s*(\d{2})\s*$/u';
    if (!preg_match($pattern, $value, $m)) {
        return $value;
    }

    $start_h = (int) $m[1];
    $start_m = (int) $m[2];
    $end_h   = (int) $m[3];
    $end_m   = (int) $m[4];

    $normalised = sprintf('%02d%02d-%02d%02d', $start_h, $start_m, $end_h, $end_m);

    if (function_exists('intersoccer_attr_is_valid_camp_time_range')) {
        $valid = intersoccer_attr_is_valid_camp_time_range($normalised);
    } else {
        $valid = intersoccer_time_range_valid_fallback($normalised);
    }

    if (!$valid) {
        return $value;
    }

    $language  = intersoccer_time_range_language();
    $separator = (string) apply_filters('intersoccer_time_range_separator', "\u{2013}");

    return intersoccer_format_time_of_day($start_h, $start_m, $language)
        . $separator
        . intersoccer_format_time_of_day($end_h, $end_m, $language);
}

/**
 * Show camp/course time terms in one format in the variation selects (storefront only).
 *
 * @param string     $name      Option label (term name).
 * @param mixed      $term      WP_Term or null.
 * @param string     $attribute Attribute taxonomy name.
 * @param mixed      $product   Product.
 * @return string
 */
add_filter('woocommerce_variation_option_name', 'intersoccer_variation_option_time_range', 20, 4);
function intersoccer_variation_option_time_range($name, $term = null, $attribute = '', $product = null) {
    if (!intersoccer_time_range_display_allowed()) {
        return $name;
    }

    $taxonomy = (is_object($term) && isset($term->taxonomy)) ? (string) $term->taxonomy : (string) $attribute;
    if (!in_array($taxonomy, intersoccer_time_range_taxonomies(), true)) {
        return $name;
    }

    return intersoccer_format_time_range($name);
}
