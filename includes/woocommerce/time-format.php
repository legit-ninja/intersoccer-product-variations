<?php
/**
 * Time range display formatting (storefront #141 + checkout order-line labels).
 *
 * Storefront variation selects use {@see intersoccer_time_range_taxonomies()} on #141; checkout
 * reformats {@see intersoccer_checkout_order_line_time_taxonomies()} for FR course times only.
 * Each symbol is guarded for a later merge without redeclare fatals.
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

if (!function_exists('intersoccer_checkout_order_line_time_taxonomies')) {
    /**
     * Taxonomies whose order-line human labels may be reformatted at checkout (FR course only).
     *
     * @return string[]
     */
    function intersoccer_checkout_order_line_time_taxonomies() {
        return ['pa_course-times'];
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
     * @param string      $value     Term name.
     * @param string|null $language  Optional two-letter language; defaults to current WPML language.
     * @param string|null $separator Range separator; null uses {@see intersoccer_time_range_separator} (en dash).
     * @return string
     */
    function intersoccer_format_time_range($value, $language = null, $separator = null) {
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

        if ($separator === null) {
            $separator = (string) apply_filters('intersoccer_time_range_separator', "\u{2013}");
        } else {
            $separator = (string) $separator;
        }

        return intersoccer_format_time_of_day($start_h, $start_m, $language)
            . $separator
            . intersoccer_format_time_of_day($end_h, $end_m, $language);
    }
}

if (!function_exists('intersoccer_format_order_line_time_attribute_label')) {
    /**
     * Format a taxonomy term name for order-line display when the attribute is a time range.
     *
     * Checkout: FR {@see pa_course-times} only — compact HHMM-HHMM (optional WPML suffix) → FR
     * labels with an ASCII hyphen; already-localised FR names and camp times stay unchanged.
     *
     * @param string      $term_name       Translated term name.
     * @param string      $attribute_name  Taxonomy name (e.g. pa_course-times).
     * @param string|null $language        Order or request language code.
     * @return string
     */
    function intersoccer_format_order_line_time_attribute_label($term_name, $attribute_name, $language = null) {
        if (!in_array($attribute_name, intersoccer_checkout_order_line_time_taxonomies(), true)) {
            return $term_name;
        }

        if ($language === null) {
            $language = intersoccer_order_line_time_range_language();
        } else {
            $language = strtolower(substr(trim((string) $language), 0, 2));
            if ($language === '') {
                $language = intersoccer_order_line_time_range_language();
            }
        }

        if ($language !== 'fr') {
            return $term_name;
        }

        $term_name = (string) $term_name;
        $compact = function_exists('intersoccer_attr_camp_time_range_from_slug')
            ? intersoccer_attr_camp_time_range_from_slug($term_name)
            : '';
        if ($compact === '' && preg_match('/^(\d{4}-\d{4})(?:-(?:fr|de|en|\d+))?$/', strtolower(trim($term_name)), $m)) {
            $compact = $m[1];
        }
        if ($compact === '') {
            return $term_name;
        }

        if (function_exists('intersoccer_attr_is_valid_camp_time_range')) {
            $valid = intersoccer_attr_is_valid_camp_time_range($compact);
        } else {
            $valid = intersoccer_time_range_valid_fallback($compact);
        }
        if (!$valid) {
            return $term_name;
        }

        return intersoccer_format_time_range($compact, 'fr', '-');
    }
}

if (!function_exists('intersoccer_time_range_term_label')) {
    /**
     * Name used to order a time-range term (term name, then slug, then the value itself).
     *
     * @param mixed $term
     * @return string
     */
    function intersoccer_time_range_term_label($term) {
        if (is_object($term)) {
            if (isset($term->name) && (string) $term->name !== '') {
                return (string) $term->name;
            }
            if (isset($term->slug) && (string) $term->slug !== '') {
                return (string) $term->slug;
            }
        }

        return (string) $term;
    }
}

if (!function_exists('intersoccer_time_range_start_minutes')) {
    /**
     * Minutes from midnight of the first time in a range such as 9h30-11h00 or 0930-1100.
     *
     * @param string $label
     * @return int|null
     */
    function intersoccer_time_range_start_minutes($label) {
        $label = trim((string) $label);
        if (!preg_match('/(\d{1,2})\s*[h:.]?\s*(\d{2})/u', $label, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}

if (!function_exists('intersoccer_compare_time_range_terms')) {
    /**
     * @param mixed $left
     * @param mixed $right
     * @return int
     */
    function intersoccer_compare_time_range_terms($left, $right) {
        $left_minutes = intersoccer_time_range_start_minutes(intersoccer_time_range_term_label($left));
        $right_minutes = intersoccer_time_range_start_minutes(intersoccer_time_range_term_label($right));

        if ($left_minutes === null && $right_minutes === null) {
            return 0;
        }
        if ($left_minutes === null) {
            return 1;
        }
        if ($right_minutes === null) {
            return -1;
        }
        if ($left_minutes !== $right_minutes) {
            return $left_minutes <=> $right_minutes;
        }

        return strcmp(intersoccer_time_range_term_label($left), intersoccer_time_range_term_label($right));
    }
}

if (!function_exists('intersoccer_sort_time_range_product_terms')) {
    /**
     * Keep camp and course time options in start-time order (FR names are not zero-padded).
     *
     * @param mixed        $terms
     * @param int          $product_id
     * @param string       $taxonomy
     * @param array|string $args
     * @return mixed
     */
    function intersoccer_sort_time_range_product_terms($terms, $product_id, $taxonomy, $args) {
        unset($product_id, $args);
        if (!is_array($terms) || !in_array((string) $taxonomy, intersoccer_time_range_taxonomies(), true)) {
            return $terms;
        }

        usort($terms, 'intersoccer_compare_time_range_terms');

        return $terms;
    }
}

if (function_exists('add_filter')) {
    add_filter('woocommerce_get_product_terms', 'intersoccer_sort_time_range_product_terms', 20, 4);
}
