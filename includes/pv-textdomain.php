<?php
/**
 * Load intersoccer-product-variations textdomain after WPML/request locale is known.
 *
 * @package InterSoccer\ProductVariations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return string
 */
function intersoccer_pv_textdomain_name() {
    return 'intersoccer-product-variations';
}

/**
 * Relative path under wp-content/plugins for load_plugin_textdomain().
 *
 * @return string
 */
function intersoccer_pv_textdomain_relative_path() {
    return dirname(plugin_basename(INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . 'intersoccer-product-variations.php')) . '/lang';
}

/**
 * Absolute path to lang/ inside the plugin.
 *
 * @return string
 */
function intersoccer_pv_textdomain_lang_dir() {
    $base = rtrim(INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR, '/\\');
    return $base . '/lang/';
}

/**
 * Active locale string for textdomain loading.
 *
 * @return string
 */
function intersoccer_pv_get_request_locale() {
    if (function_exists('determine_locale')) {
        return (string) determine_locale();
    }

    return function_exists('get_locale') ? (string) get_locale() : 'en_US';
}

/**
 * Resolve a readable .mo file for the given locale (full locale, then short language code).
 *
 * @param string $locale Locale (e.g. fr_FR).
 * @return string Absolute path, or empty when English / missing.
 */
function intersoccer_pv_resolve_textdomain_mo_path($locale) {
    $locale = (string) $locale;
    if ($locale === '' || stripos($locale, 'en') === 0) {
        return '';
    }

    $domain = intersoccer_pv_textdomain_name();
    $locale = apply_filters('plugin_locale', $locale, $domain);
    $lang_dir = intersoccer_pv_textdomain_lang_dir();
    $candidates = [
        $lang_dir . $domain . '-' . $locale . '.mo',
    ];
    $short = strtok($locale, '_');
    if (is_string($short) && $short !== '' && $short !== $locale) {
        $candidates[] = $lang_dir . $domain . '-' . $short . '.mo';
    }

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    return '';
}

/**
 * Load or reload the PV textdomain for the current (or given) locale.
 *
 * @param string|null $locale Optional locale override (e.g. after switch_to_locale).
 * @return bool
 */
function intersoccer_pv_load_textdomain_for_current_locale($locale = null) {
    if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
        return false;
    }

    $domain = intersoccer_pv_textdomain_name();
    $locale = $locale !== null && $locale !== '' ? (string) $locale : intersoccer_pv_get_request_locale();
    $previous = isset($GLOBALS['intersoccer_pv_textdomain_locale'])
        ? (string) $GLOBALS['intersoccer_pv_textdomain_locale']
        : '';

    $loaded = function_exists('is_textdomain_loaded') && is_textdomain_loaded($domain);
    if ($loaded && $previous === $locale) {
        return true;
    }

    if ($loaded && function_exists('unload_textdomain')) {
        unload_textdomain($domain, true);
    }

    if (function_exists('load_plugin_textdomain')) {
        load_plugin_textdomain($domain, false, intersoccer_pv_textdomain_relative_path());
    }

    $ok = true;
    $mofile = intersoccer_pv_resolve_textdomain_mo_path($locale);
    if ($mofile !== '' && function_exists('load_textdomain')) {
        $ok = (bool) load_textdomain($domain, $mofile, $locale);
    }

    $GLOBALS['intersoccer_pv_textdomain_locale'] = $locale;

    return (bool) $ok;
}

/**
 * WPML language code for the current request (e.g. fr, de), or empty when unavailable.
 *
 * @return string
 */
function intersoccer_pv_get_wpml_language_code() {
    $lang = apply_filters('wpml_current_language', null);
    if (!is_string($lang)) {
        return '';
    }
    $lang = trim($lang);
    return $lang === '' ? '' : $lang;
}

/**
 * Map a WPML language code to a WordPress locale for .mo loading.
 *
 * @param string $code
 * @return string
 */
function intersoccer_pv_locale_from_wpml_code($code) {
    $code = trim((string) $code);
    if ($code === '') {
        return intersoccer_pv_get_request_locale();
    }
    if (strpos($code, '_') !== false) {
        return $code;
    }
    $map = [
        'en' => 'en_US',
        'fr' => 'fr_FR',
        'de' => 'de_DE',
    ];
    if (isset($map[$code])) {
        return $map[$code];
    }

    return $code;
}

/**
 * admin-ajax.php URL with optional WPML lang query arg for storefront requests.
 *
 * @param array<string, string> $extra_args
 * @param bool                  $include_wpml_lang
 * @return string
 */
function intersoccer_pv_admin_ajax_url(array $extra_args = [], $include_wpml_lang = true) {
    $args = $extra_args;
    if ($include_wpml_lang) {
        $lang = intersoccer_pv_get_wpml_language_code();
        if ($lang !== '') {
            $args['lang'] = $lang;
        }
    }

    $url = admin_url('admin-ajax.php');
    if ($args !== []) {
        $url = add_query_arg($args, $url);
    }

    return $url;
}

/**
 * Shared ajax_url / nonce_refresh_url (and lang when WPML is active) for customer scripts.
 *
 * @param bool $include_wpml_lang
 * @return array<string, string>
 */
function intersoccer_pv_customer_script_localize_base($include_wpml_lang = true) {
    $data = [
        'ajax_url' => intersoccer_pv_admin_ajax_url([], $include_wpml_lang),
        'nonce_refresh_url' => intersoccer_pv_admin_ajax_url(
            ['action' => 'intersoccer_refresh_nonce'],
            $include_wpml_lang
        ),
    ];

    if ($include_wpml_lang) {
        $lang = intersoccer_pv_get_wpml_language_code();
        if ($lang !== '') {
            $data['lang'] = $lang;
        }
    }

    return $data;
}

/**
 * Switch WPML + reload PV textdomain on customer admin-ajax when lang is present.
 */
function intersoccer_pv_bootstrap_customer_ajax_language() {
    if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
        return;
    }

    $lang = '';
    if (isset($_REQUEST['lang'])) {
        $lang = sanitize_text_field(wp_unslash((string) $_REQUEST['lang']));
    }
    if ($lang === '' || !preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z]{2,4})?$/', $lang)) {
        return;
    }

    if (has_action('wpml_switch_language')) {
        do_action('wpml_switch_language', $lang);
    }

    intersoccer_pv_load_textdomain_for_current_locale(intersoccer_pv_locale_from_wpml_code($lang));
}

/**
 * Register hooks for storefront/admin locale and WPML switches.
 */
function intersoccer_pv_register_textdomain_hooks() {
    add_action('init', 'intersoccer_pv_load_textdomain_for_current_locale', 20);
    add_action('wpml_language_has_switched', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
    add_action('change_locale', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
    add_action('admin_init', 'intersoccer_pv_bootstrap_customer_ajax_language', 0);
}

intersoccer_pv_register_textdomain_hooks();
