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
        unload_textdomain($domain);
    }

    $ok = load_plugin_textdomain($domain, false, intersoccer_pv_textdomain_relative_path());
    $GLOBALS['intersoccer_pv_textdomain_locale'] = $locale;

    return (bool) $ok;
}

/**
 * Register hooks for storefront/admin locale and WPML switches.
 */
function intersoccer_pv_register_textdomain_hooks() {
    add_action('init', 'intersoccer_pv_load_textdomain_for_current_locale', 20);
    add_action('wpml_language_has_switched', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
    add_action('change_locale', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
}

intersoccer_pv_register_textdomain_hooks();
