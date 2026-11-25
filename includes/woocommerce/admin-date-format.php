<?php
/**
 * Swiss admin date input format (DD/MM/YYYY).
 *
 * Admin course/camp date fields display and accept day-month-year. Values are
 * stored as Y-m-d. Impossible dates are rejected; month and day are never swapped.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_admin_date_placeholder')) {
    /**
     * Placeholder shown on admin date inputs.
     *
     * @return string
     */
    function intersoccer_admin_date_placeholder() {
        return 'DD/MM/YYYY';
    }
}

if (!function_exists('intersoccer_admin_parse_date_result')) {
    /**
     * Parse an admin date field into Y-m-d.
     *
     * Accepts DD/MM/YYYY or DD.MM.YYYY (1- or 2-digit day/month, 4-digit year)
     * and unambiguous year-first dates (Y-m-d, also with / or .).
     * Does not treat slash dates as month-first.
     *
     * @param mixed $input Raw input.
     * @return array{ok:bool,ymd:string,empty:bool,invalid:bool,input:string}
     */
    function intersoccer_admin_parse_date_result($input) {
        $empty = [
            'ok'      => false,
            'ymd'     => '',
            'empty'   => true,
            'invalid' => false,
            'input'   => '',
        ];

        if (!is_string($input) && !is_numeric($input)) {
            return $empty;
        }

        $raw = trim((string) $input);
        if ($raw === '') {
            return $empty;
        }

        $fail = [
            'ok'      => false,
            'ymd'     => '',
            'empty'   => false,
            'invalid' => true,
            'input'   => $raw,
        ];

        $accept = static function ($year, $month, $day, $raw) use ($fail) {
            $year  = (int) $year;
            $month = (int) $month;
            $day   = (int) $day;
            if ($year < 1000 || $year > 9999 || !checkdate($month, $day, $year)) {
                return $fail;
            }

            return [
                'ok'      => true,
                'ymd'     => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'empty'   => false,
                'invalid' => false,
                'input'   => $raw,
            ];
        };

        // Year-first is unambiguous (2027-06-03 is 3 June, never 6 March).
        if (preg_match('/^(\d{4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})$/', $raw, $m)) {
            return $accept($m[1], $m[2], $m[3], $raw);
        }

        // Swiss day-month-year. Separators are . or / only, not hyphen.
        if (preg_match('/^(\d{1,2})\s*[.\/]\s*(\d{1,2})\s*[.\/]\s*(\d{4})$/', $raw, $m)) {
            return $accept($m[3], $m[2], $m[1], $raw);
        }

        return $fail;
    }
}

if (!function_exists('intersoccer_admin_parse_date')) {
    /**
     * Parse an admin date input to Y-m-d, or empty when blank or invalid.
     *
     * @param mixed $input Raw input.
     * @return string
     */
    function intersoccer_admin_parse_date($input) {
        $result = intersoccer_admin_parse_date_result($input);
        return $result['ok'] ? $result['ymd'] : '';
    }
}

if (!function_exists('intersoccer_admin_format_date')) {
    /**
     * Format a stored or typed date for admin inputs (DD/MM/YYYY).
     *
     * @param mixed $value Stored Y-m-d or an accepted admin input.
     * @return string
     */
    function intersoccer_admin_format_date($value) {
        $ymd = intersoccer_admin_parse_date($value);
        if ($ymd === '') {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!$dt instanceof DateTime) {
            return '';
        }

        return $dt->format('d/m/Y');
    }
}

if (!function_exists('intersoccer_admin_date_flag_error')) {
    /**
     * Record and surface an invalid admin date so it is not silently ignored.
     *
     * @param string $message Error shown to the admin.
     * @return void
     */
    function intersoccer_admin_date_flag_error($message) {
        $message = trim((string) $message);
        if ($message === '') {
            return;
        }

        if (!isset($GLOBALS['intersoccer_admin_date_errors']) || !is_array($GLOBALS['intersoccer_admin_date_errors'])) {
            $GLOBALS['intersoccer_admin_date_errors'] = [];
        }
        $GLOBALS['intersoccer_admin_date_errors'][] = $message;

        if (class_exists('WC_Admin_Meta_Boxes') && is_callable(['WC_Admin_Meta_Boxes', 'add_error'])) {
            WC_Admin_Meta_Boxes::add_error($message);
        }
    }
}

if (!function_exists('intersoccer_admin_date_errors')) {
    /**
     * Errors flagged in this request (for tests and notices).
     *
     * @return array<int,string>
     */
    function intersoccer_admin_date_errors() {
        if (!isset($GLOBALS['intersoccer_admin_date_errors']) || !is_array($GLOBALS['intersoccer_admin_date_errors'])) {
            return [];
        }

        return array_values($GLOBALS['intersoccer_admin_date_errors']);
    }
}

if (!function_exists('intersoccer_admin_date_format_enqueue')) {
    /**
     * Datepicker and input helpers on product edit and Program Manager screens.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    function intersoccer_admin_date_format_enqueue($hook) {
        $is_pm = is_string($hook) && strpos($hook, 'intersoccer-program-manager') !== false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_product = $screen && isset($screen->post_type) && $screen->post_type === 'product';
        if (!$is_pm && !$is_product) {
            return;
        }

        if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL')) {
            return;
        }

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script(
            'intersoccer-admin-date-format',
            INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/admin-date-format.js',
            ['jquery', 'jquery-ui-datepicker'],
            '1.0.0',
            true
        );
        wp_add_inline_style(
            'wp-admin',
            'input.intersoccer-admin-date-invalid{border-color:#d63638;}'
        );
    }
}

if (function_exists('add_action')) {
    add_action('admin_enqueue_scripts', 'intersoccer_admin_date_format_enqueue');
}
