<?php
/**
 * Consistent display of camp and course time ranges.
 *
 * Time attribute terms are stored in mixed styles ("1000-1230", "10h00-11h30",
 * "10:00 - 12:30"). This only changes how a pure time range is shown in the
 * product page variation selects; term names, slugs and order data stay as
 * they are. Times use the site's time_format option, which WPML can translate
 * per language.
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
 * Format one time of day with the site time format.
 *
 * @param int $hour   0-23.
 * @param int $minute 0-59.
 * @return string
 */
function intersoccer_format_time_of_day($hour, $minute) {
    $format = (string) get_option('time_format', 'H:i');
    if ($format === '') {
        $format = 'H:i';
    }
    $format = (string) apply_filters('intersoccer_time_of_day_format', $format);
    // A seconds-since-midnight timestamp formatted as UTC gives the wall-clock time, whatever the site timezone.
    return date_i18n($format, ((int) $hour * 3600) + ((int) $minute * 60), true);
}

/**
 * Reformat a time range like "1000-1230" or "10h00-11h30" with the site time format.
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
    if ($start_h > 23 || $end_h > 23 || $start_m > 59 || $end_m > 59) {
        return $value;
    }
    $separator = (string) apply_filters('intersoccer_time_range_separator', "\u{2013}");
    return intersoccer_format_time_of_day($start_h, $start_m) . $separator . intersoccer_format_time_of_day($end_h, $end_m);
}

/**
 * Show camp/course time terms in one format in the variation selects.
 *
 * @param string     $name      Option label (term name).
 * @param mixed      $term      WP_Term or null.
 * @param string     $attribute Attribute taxonomy name.
 * @param mixed      $product   Product.
 * @return string
 */
add_filter('woocommerce_variation_option_name', 'intersoccer_variation_option_time_range', 20, 4);
function intersoccer_variation_option_time_range($name, $term = null, $attribute = '', $product = null) {
    $taxonomy = (is_object($term) && isset($term->taxonomy)) ? (string) $term->taxonomy : (string) $attribute;
    if (!in_array($taxonomy, intersoccer_time_range_taxonomies(), true)) {
        return $name;
    }
    return intersoccer_format_time_range($name);
}
