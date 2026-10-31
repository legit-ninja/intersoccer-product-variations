<?php
/**
 * Camp/course time range display formatting (shared with checkout order-line labels).
 *
 * Storefront variation selects are formatted on the time-formats branch (#141); this file
 * ships the pure helpers early so checkout can format order-line human labels without
 * depending on #141 merge. Each symbol is guarded for a later merge without redeclare fatals.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_time_range_taxonomies')) {
    /**
     * Attribute taxonomies whose term names are time ranges.
     *
     * @return string[]
     */
    function intersoccer_time_range_taxonomies() {
        return ['pa_camp-times', 'pa_course-times'];
    }
}

if (!function_exists('intersoccer_time_range_language')) {
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
}

if (!function_exists('intersoccer_order_line_time_range_language')) {
    /**
     * WPML language for order-line time labels: order meta first, then current request language.
     *
     * @param WC_Order|null $order
     * @return string Two-letter language code.
     */
    function intersoccer_order_line_time_range_language($order = null) {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            $wpml = (string) $order->get_meta('wpml_language', true);
            if ($wpml !== '') {
                $code = strtolower(substr(trim($wpml), 0, 2));

                return $code !== '' ? $code : intersoccer_time_range_language();
            }
        }

        return intersoccer_time_range_language();
    }
}

if (!function_exists('intersoccer_time_range_format')) {
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

        $default_format = ($language === 'fr') ? 'G\hi' : 'H:i';

        return (string) apply_filters('intersoccer_time_range_format', $default_format, $language);
    }
}

if (!function_exists('intersoccer_time_range_valid_fallback')) {
    /**
     * Validates normalised HHMM-HHMM when attribute-enforcement is not loaded (#134).
     *
     * @param string $range Normalised HHMM-HHMM.
     * @return bool
     */
    function intersoccer_time_range_valid_fallback($range) {
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
}

if (!function_exists('intersoccer_format_time_of_day')) {
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
}

if (!function_exists('intersoccer_format_time_range')) {
    /**
     * Reformat a time range like "1000-1230" or "10h00-11h30" for display.
     * Anything that is not exactly a valid time range comes back unchanged.
     *
     * @param string      $value    Term name.
     * @param string|null $language Optional two-letter language; defaults to current WPML language.
     * @return string
     */
    function intersoccer_format_time_range($value, $language = null) {
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

        if ($language === null) {
            $language = intersoccer_time_range_language();
        } else {
            $language = strtolower(substr(trim((string) $language), 0, 2));
            if ($language === '') {
                $language = intersoccer_time_range_language();
            }
        }

        $separator = (string) apply_filters('intersoccer_time_range_separator', "\u{2013}");

        return intersoccer_format_time_of_day($start_h, $start_m, $language)
            . $separator
            . intersoccer_format_time_of_day($end_h, $end_m, $language);
    }
}

if (!function_exists('intersoccer_format_order_line_time_attribute_label')) {
    /**
     * Format a taxonomy term name for order-line display when the attribute is a time range.
     *
     * @param string      $term_name       Translated term name.
     * @param string      $attribute_name  Taxonomy name (e.g. pa_camp-times).
     * @param string|null $language        Order or request language code.
     * @return string
     */
    function intersoccer_format_order_line_time_attribute_label($term_name, $attribute_name, $language = null) {
        if (!in_array($attribute_name, intersoccer_time_range_taxonomies(), true)) {
            return $term_name;
        }

        return intersoccer_format_time_range($term_name, $language);
    }
}
