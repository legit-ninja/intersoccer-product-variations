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
 * Register hooks for storefront/admin locale and WPML switches.
 */
function intersoccer_pv_register_textdomain_hooks() {
    add_action('init', 'intersoccer_pv_load_textdomain_for_current_locale', 20);
    add_action('wpml_language_has_switched', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
    add_action('change_locale', 'intersoccer_pv_load_textdomain_for_current_locale', 10, 0);
}

intersoccer_pv_register_textdomain_hooks();
