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