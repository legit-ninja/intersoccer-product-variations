<?php
/**
 * Language Helper Functions
 * Provides language support for InterSoccer plugin with WPML/Polylang compatibility
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get current language code
 * Works with WPML, Polylang, or falls back to WordPress locale
 * 
 * @return string Language code (e.g., 'en', 'de', 'fr')
 */
function intersoccer_get_current_language() {
    // Log function call for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('InterSoccer: intersoccer_get_current_language() called');
    }

    // Check for WPML
    if (function_exists('icl_get_current_language')) {
        $lang = icl_get_current_language();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer: WPML detected, current language: ' . $lang);
        }
        return $lang;
    }

    // Check for Polylang
    if (function_exists('pll_current_language')) {
        $lang = pll_current_language();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer: Polylang detected, current language: ' . $lang);
        }
        return $lang ? $lang : 'en';
    }

    // Fallback to WordPress locale
    $locale = get_locale();
    $lang = substr($locale, 0, 2); // Extract language code from locale (e.g., 'en' from 'en_US')

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('InterSoccer: No multilingual plugin detected, using WordPress locale: ' . $locale . ' -> ' . $lang);
    }

    return $lang;
}

/**
 * Get all available languages
 * Returns array of language codes and names
 * 
 * @return array Array of language_code => language_name
 */
function intersoccer_get_available_languages() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('InterSoccer: intersoccer_get_available_languages() called');
    }

    // Check for WPML
    if (function_exists('icl_get_languages')) {
        $languages = icl_get_languages('skip_missing=0');
        $available = [];

        foreach ($languages as $lang_code => $lang_info) {
            $available[$lang_code] = $lang_info['native_name'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug('InterSoccer: WPML languages: ' . print_r($available, true));
        }
        return $available;
    }

    // Check for Polylang
    if (function_exists('pll_languages_list')) {
        $lang_codes = pll_languages_list();
        $available = [];

        foreach ($lang_codes as $lang_code) {
            $lang_obj = pll_get_language($lang_code);
            $available[$lang_code] = $lang_obj ? $lang_obj->name : $lang_code;
        }

        if (!empty($available)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug('InterSoccer: Polylang languages: ' . print_r($available, true));
            }
            return $available;
        }
    }

    // Fallback to common languages
    $fallback = [
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français'
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug('InterSoccer: Using fallback languages: ' . print_r($fallback, true));
    }
    return $fallback;
}

/**
 * Get language name from language code
 * 
 * @param string $lang_code Language code (e.g., 'en', 'de', 'fr')
 * @return string Language name
 */
function intersoccer_get_language_name($lang_code) {
    $languages = intersoccer_get_available_languages();
    return $languages[$lang_code] ?? $lang_code;
}

/**
 * Check if multilingual plugin is active
 * 
 * @return string|false Plugin name if active, false otherwise
 */
function intersoccer_get_multilingual_plugin() {
    if (function_exists('icl_get_current_language')) {
        return 'WPML';
    }
    
    if (function_exists('pll_current_language')) {
        return 'Polylang';
    }
    
    return false;
}

/**
 * Get localized strings for the player assignment workflow.
 *
 * @param array $args Optional overrides for URLs used in the generated strings.
 * @return array
 */
function intersoccer_get_player_assignment_strings($args = []) {
    $defaults = [
        'dashboard_url' => function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('dashboard') : '',
        'manage_players_url' => function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('manage-players') : '',
    ];

    $args = wp_parse_args($args, $defaults);

    $allowed_link_tags = [
        'a' => [
            'href' => [],
        ],
    ];

    return [
        'loadingPlayers' => __('Loading players...', 'intersoccer-product-variations'),
        'errorLoadingPlayers' => __('Error: Unable to load players. Please try refreshing the page.', 'intersoccer-product-variations'),
        'errorLoadingPlayersWithMessage' => __('Error loading players: %s. Please try again.', 'intersoccer-product-variations'),
        'loginPromptHtml' => wp_kses(
            sprintf(
                __('Please <a href="%1$s">log in</a> or <a href="%2$s">register</a> to select an attendee.', 'intersoccer-product-variations'),
                esc_url($args['dashboard_url']),
                esc_url($args['dashboard_url'])
            ),
            $allowed_link_tags
        ),
        'loginPromptText' => __('Please log in or register to select an attendee.', 'intersoccer-product-variations'),
        'noPlayersRegisteredHtml' => wp_kses(
            sprintf(
                __('No players registered. <a href="%s">Add a player</a>.', 'intersoccer-product-variations'),
                esc_url($args['manage_players_url'])
            ),
            $allowed_link_tags
        ),
        'pleaseAddPlayer' => __('Please add a player to continue.', 'intersoccer-product-variations'),
        'selectAttendee' => __('Select an Attendee', 'intersoccer-product-variations'),
        'selectAttendeeToAdd' => __('Please select an attendee to add to cart.', 'intersoccer-product-variations'),
        'selectAttendeeShort' => __('Please select an attendee.', 'intersoccer-product-variations'),
        'selectAtLeastOneDay' => __('Please select at least one day.', 'intersoccer-product-variations'),
        'resolveError' => __('Please resolve the error to continue.', 'intersoccer-product-variations'),
        'genericRequestFailed' => __('Request failed', 'intersoccer-product-variations'),
    ];
}

/**
 * Get string translation using available multilingual plugin
 * 
 * @param string $string Original string
 * @param string $context Translation context
 * @param string $name String name/identifier
 * @return string Translated string
 */
function intersoccer_translate_string($string, $context = 'intersoccer-product-variations', $name = '') {
    // WPML String Translation
    if (function_exists('icl_t')) {
        $name = $name ?: md5($string);
        return icl_t($context, $name, $string);
    }
    
    // Polylang string translation (if available)
    if (function_exists('pll__')) {
        return pll__($string);
    }
    
    // WordPress fallback
    return __($string, 'intersoccer-product-variations');
}

/**
 * Register string for translation
 * 
 * @param string $string String to register
 * @param string $context Translation context
 * @param string $name String name/identifier
 * @return bool Success status
 */
function intersoccer_register_string_for_translation($string, $context = 'intersoccer-product-variations', $name = '') {
    // WPML String Translation
    if (function_exists('icl_register_string')) {
        $name = $name ?: md5($string);
        icl_register_string($context, $name, $string);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: Registered WPML string - Context: {$context}, Name: {$name}, String: {$string}");
        }
        return true;
    }

    // Polylang string registration (if available)
    if (function_exists('pll_register_string')) {
        $name = $name ?: $string;
        pll_register_string($name, $string, $context);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: Registered Polylang string - Context: {$context}, Name: {$name}, String: {$string}");
        }
        return true;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug("InterSoccer: No multilingual plugin available for string registration: {$string}");
    }
    return false;
}

/**
 * Safe wrapper for getting discount message with language support
 * This replaces the problematic function in discount-messages.php
 * 
 * @param string $rule_id Rule identifier
 * @param string $message_type Type of message ('cart_message', 'customer_note', etc.)
 * @param string $fallback Fallback message if translation not found
 * @return string Localized message
 */
function intersoccer_get_discount_message_safe($rule_id, $message_type = 'cart_message', $fallback = '') {
    // Validate inputs
    if (empty($rule_id) || empty($message_type)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: Invalid parameters for discount message - Rule ID: {$rule_id}, Type: {$message_type}");
        }
        return $fallback;
    }

    try {
        $discount_rules = get_option('intersoccer_discount_rules', []);
        $discount_messages = get_option('intersoccer_discount_messages', []);

        // Find the rule
        $rule = null;
        foreach ($discount_rules as $stored_rule) {
            if ($stored_rule['id'] === $rule_id) {
                $rule = $stored_rule;
                break;
            }
        }

        if (!$rule) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug("InterSoccer: Rule not found for ID: {$rule_id}");
            }
            return $fallback;
        }

        $message_key = $rule['message_key'] ?? $rule_id;
        $current_lang = intersoccer_get_current_language();

        // Get message for current language
        $message_data = $discount_messages[$message_key][$current_lang] ?? [];
        $message = $message_data[$message_type] ?? '';

        // Fallback to English if not found and current language is not English
        if (empty($message) && $current_lang !== 'en') {
            $message_data = $discount_messages[$message_key]['en'] ?? [];
            $message = $message_data[$message_type] ?? '';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug("InterSoccer: Falling back to English for rule {$rule_id}, type {$message_type}");
            }
        }

        // Use fallback if still empty
        if (empty($message)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                intersoccer_debug("InterSoccer: No message found for rule {$rule_id}, type {$message_type}, using fallback");
            }
            $message = $fallback;
        }

        // Apply translation if available
        if (!empty($message)) {
            $string_name = "intersoccer_discount_{$rule_id}_{$message_type}";
            $translated = intersoccer_translate_string($message, 'intersoccer-product-variations', $string_name);

            if ($translated !== $message) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    intersoccer_debug("InterSoccer: Applied translation for {$string_name}");
                }
            }

            return $translated;
        }

        return $fallback;

    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: Error getting discount message - Rule: {$rule_id}, Type: {$message_type}, Error: " . $e->getMessage());
        }
        return $fallback;
    }
}

/**
 * Variation IDs that are WPML translations of the given variation (excludes source).
 *
 * @param int $variation_id Source product_variation ID.
 * @return int[]
 */
function intersoccer_foreach_translated_product_variations($variation_id) {
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0) {
        return [];
    }
    if (!defined('ICL_SITEPRESS_VERSION') && !function_exists('icl_get_current_language')) {
        return [];
    }

    $languages = apply_filters('wpml_active_languages', null);
    if (!$languages || !is_array($languages)) {
        return [];
    }

    $original_lang = apply_filters('wpml_element_language_code', null, [
        'element_id'   => $variation_id,
        'element_type' => 'post_product_variation',
    ]);
    if (!$original_lang) {
        $original_lang = apply_filters('wpml_current_language', null);
    }

    $ids = [];
    foreach (array_keys($languages) as $lang_code) {
        if ($lang_code === $original_lang) {
            continue;
        }
        $tid = (int) apply_filters('wpml_object_id', $variation_id, 'product_variation', false, $lang_code);
        if ($tid > 0 && $tid !== $variation_id) {
            $ids[] = $tid;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Parent product IDs that are WPML translations of the given product (excludes source).
 *
 * @param int $product_id Source product ID.
 * @return int[]
 */
function intersoccer_foreach_translated_products($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
        return [];
    }
    if (!defined('ICL_SITEPRESS_VERSION') && !function_exists('icl_get_current_language')) {
        return [];
    }

    $languages = apply_filters('wpml_active_languages', null);
    if (!$languages || !is_array($languages)) {
        return [];
    }

    $original_lang = apply_filters('wpml_element_language_code', null, [
        'element_id'   => $product_id,
        'element_type' => 'post_product',
    ]);
    if (!$original_lang) {
        $original_lang = apply_filters('wpml_current_language', null);
    }

    $ids = [];
    foreach (array_keys($languages) as $lang_code) {
        if ($lang_code === $original_lang) {
            continue;
        }
        $tid = (int) apply_filters('wpml_object_id', $product_id, 'product', false, $lang_code);
        if ($tid <= 0) {
            $tid = (int) apply_filters('wpml_object_id', $product_id, 'post_product', false, $lang_code);
        }
        if ($tid > 0 && $tid !== $product_id) {
            $ids[] = $tid;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Fan out parent product post_status to WPML translation siblings.
 *
 * @param int    $product_id Source (usually EN) product ID.
 * @param string $status     Allowlisted status: draft|publish|private.
 * @return int[] Translated product IDs that were updated.
 */
function intersoccer_sync_product_status_to_translations($product_id, $status) {
    $product_id = (int) $product_id;
    $status     = sanitize_key((string) $status);
    $allowed    = function_exists('intersoccer_pm_is_allowed_product_status')
        ? intersoccer_pm_is_allowed_product_status($status)
        : in_array($status, ['draft', 'publish', 'private'], true);
    if ($product_id <= 0 || !$allowed) {
        return [];
    }

    static $syncing = [];
    if (isset($syncing[$product_id])) {
        return [];
    }
    $syncing[$product_id] = true;

    $updated = [];
    try {
        foreach (intersoccer_foreach_translated_products($product_id) as $tid) {
            if (function_exists('wc_get_product')) {
                $sibling = wc_get_product($tid);
                if ($sibling && is_a($sibling, 'WC_Product') && method_exists($sibling, 'set_status') && method_exists($sibling, 'save')) {
                    $sibling->set_status($status);
                    $sibling->save();
                    if (function_exists('wc_delete_product_transients')) {
                        wc_delete_product_transients($tid);
                    }
                    $updated[] = $tid;
                    continue;
                }
            }
            if (function_exists('wp_update_post')) {
                $result = wp_update_post([
                    'ID'          => $tid,
                    'post_status' => $status,
                ], true);
                if (!is_wp_error($result) && $result) {
                    $updated[] = $tid;
                }
            }
        }
    } finally {
        unset($syncing[$product_id]);
    }

    return $updated;
}

/**
 * Copy a taxonomy attribute slug to WPML-translated variations (meta + WC attrs when possible).
 *
 * @param int    $variation_id Source variation ID.
 * @param string $taxonomy     e.g. pa_intersoccer-venues.
 * @param string $slug         Attribute term slug (may be empty to clear).
 * @return void
 */
function intersoccer_sync_variation_taxonomy_attribute_to_translations($variation_id, $taxonomy, $slug) {
    $variation_id = (int) $variation_id;
    $taxonomy     = sanitize_title((string) $taxonomy);
    $slug         = sanitize_text_field((string) $slug);
    if ($variation_id <= 0 || $taxonomy === '') {
        return;
    }

    $meta_key = 'attribute_' . $taxonomy;
    foreach (intersoccer_foreach_translated_product_variations($variation_id) as $tid) {
        if (function_exists('wc_get_product')) {
            $variation = wc_get_product($tid);
            if ($variation && is_a($variation, 'WC_Product_Variation')) {
                $attrs              = $variation->get_attributes();
                $attrs[$taxonomy]   = $slug;
                $variation->set_attributes($attrs);
                $variation->save();
            }
        }
        update_post_meta($tid, $meta_key, $slug);
        if (function_exists('wp_set_object_terms')) {
            if ($slug !== '') {
                wp_set_object_terms($tid, $slug, $taxonomy);
            } else {
                wp_set_object_terms($tid, [], $taxonomy);
            }
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($tid);
        }
    }
}

/**
 * Copy regular/price to WPML-translated variations.
 *
 * @param int         $variation_id Source variation.
 * @param string      $regular_price Regular price string.
 * @param string|null $price         Active price (defaults to regular).
 * @return void
 */
function intersoccer_sync_variation_prices_to_translations($variation_id, $regular_price, $price = null) {
    $variation_id  = (int) $variation_id;
    $regular_price = (string) $regular_price;
    $price         = $price !== null ? (string) $price : $regular_price;
    if ($variation_id <= 0 || !function_exists('wc_get_product')) {
        return;
    }

    foreach (intersoccer_foreach_translated_product_variations($variation_id) as $tid) {
        $variation = wc_get_product($tid);
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            continue;
        }
        $variation->set_regular_price($regular_price);
        $variation->set_price($price);
        $variation->save();
        if (function_exists('wc_delete_product_transients')) {
            $parent = (int) $variation->get_parent_id();
            if ($parent > 0) {
                wc_delete_product_transients($parent);
            }
            wc_delete_product_transients($tid);
        }
    }
}

/**
 * Sync variation Enabled/Disabled (publish|private) to WPML-linked variations.
 *
 * @param int    $variation_id Source variation ID.
 * @param string $status       publish|private.
 * @return void
 */
function intersoccer_sync_variation_status_to_translations($variation_id, $status) {
    $variation_id = (int) $variation_id;
    $status       = (string) $status;
    if ($variation_id <= 0 || !in_array($status, ['publish', 'private'], true) || !function_exists('wc_get_product')) {
        return;
    }
    if (!function_exists('intersoccer_foreach_translated_product_variations')) {
        return;
    }

    foreach (intersoccer_foreach_translated_product_variations($variation_id) as $tid) {
        $variation = wc_get_product($tid);
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            continue;
        }
        $variation->set_status($status);
        $variation->save();
        if (function_exists('wc_delete_product_transients')) {
            $parent = (int) $variation->get_parent_id();
            if ($parent > 0) {
                wc_delete_product_transients($parent);
            }
            wc_delete_product_transients($tid);
        }
    }
}

/**
 * Initialize language functions and validate dependencies
 * Call this during plugin activation or admin_init
 */
function intersoccer_init_language_support() {
    $multilingual_plugin = intersoccer_get_multilingual_plugin();

    if ($multilingual_plugin) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: Multilingual support initialized with {$multilingual_plugin}");
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            intersoccer_debug("InterSoccer: No multilingual plugin detected, using WordPress defaults");
        }
    }

    // Test the functions
    $current_lang = intersoccer_get_current_language();
    $available_langs = intersoccer_get_available_languages();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        intersoccer_debug("InterSoccer: Language support test - Current: {$current_lang}, Available: " . implode(', ', array_keys($available_langs)));
    }
}

// Initialize on admin_init to ensure all plugins are loaded
add_action('admin_init', 'intersoccer_init_language_support', 15);

// Initialize on init for frontend
add_action('init', 'intersoccer_init_language_support', 15);

if (defined('WP_DEBUG') && WP_DEBUG) {
    intersoccer_debug('InterSoccer: Language helper functions loaded');
}
?>