<?php
/**
 * Helper Functions for InterSoccer Product Variations
 * 
 * @package InterSoccer_Product_Variations
 * @version 1.0.0
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Shared cache for player data (request-level)
 * 
 * @var array
 */
function &_intersoccer_get_players_cache() {
    static $cache = [];
    return $cache;
}

/**
 * Get players for a user with request-level caching
 * 
 * This function provides a fast, cached way to retrieve player metadata.
 * Uses static caching to avoid multiple database queries in the same request.
 * 
 * @param int $user_id WordPress user ID
 * @param bool $force_refresh If true, bypass cache and fetch fresh data
 * @return array Array of player data, or empty array if none found
 */
function intersoccer_get_user_players($user_id, $force_refresh = false) {
    $cache = &_intersoccer_get_players_cache();
    
    // Validate user ID
    if (empty($user_id) || !is_numeric($user_id)) {
        return [];
    }
    
    $user_id = (int) $user_id;
    
    // Check cache first (unless forcing refresh)
    if (!$force_refresh && isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    
    // Get from database
    $players = get_user_meta($user_id, 'intersoccer_players', true);
    
    // Handle serialized data
    if (is_string($players)) {
        $players = maybe_unserialize($players);
    }
    
    // Ensure it's an array
    if (!is_array($players)) {
        $players = [];
    }
    
    // Cache the result for this request
    $cache[$user_id] = $players;
    
    return $players;
}

/**
 * Clear the player cache for a specific user (useful after updates)
 * 
 * @param int $user_id WordPress user ID
 */
function intersoccer_clear_user_players_cache($user_id = null) {
    $cache = &_intersoccer_get_players_cache();
    
    if ($user_id === null) {
        // Clear all cached players
        $cache = [];
    } else {
        // Clear specific user's cache
        unset($cache[(int) $user_id]);
    }
}

/**
 * Get a specific player by index with caching
 * 
 * @param int $user_id WordPress user ID
 * @param int $player_index Player index in the array
 * @return array|null Player data or null if not found
 */
function intersoccer_get_player_by_index($user_id, $player_index) {
    $players = intersoccer_get_user_players($user_id);
    
    if (isset($players[$player_index]) && is_array($players[$player_index])) {
        return $players[$player_index];
    }
    
    return null;
}

/**
 * Whether a WooCommerce booking-type attribute value means single-day (per-day) camp booking.
 * Supports EN/FR slugs and common DE/WPML slug patterns; excludes obvious full-week values.
 *
 * @param string|null $booking_type Variation attribute_pa_booking-type slug or equivalent.
 * @return bool
 */
function intersoccer_is_single_day_booking_type($booking_type) {
    if (!is_string($booking_type) || $booking_type === '') {
        return false;
    }
    $bt = function_exists('mb_strtolower') ? mb_strtolower($booking_type, 'UTF-8') : strtolower($booking_type);
    if ($bt === 'full-week' || strpos($bt, 'full-week') !== false) {
        return false;
    }
    if (strpos($bt, 'ganze') !== false && strpos($bt, 'woche') !== false) {
        return false;
    }
    if ($bt === 'single-days' || $bt === 'à la journée' || $bt === 'a-la-journee') {
        return true;
    }
    if ($bt === 'tag' || preg_match('/^(?:1[-_])?ein[-_]?tag$/', $bt) || preg_match('/^nur[-_]?tag$/', $bt)) {
        return true;
    }
    $markers = ['single', 'journée', 'journee', 'einzel', 'ein-tag', 'eintag', '1-tag', 'taeglich', 'täglich', 'nur-tag', 'pro-tag', 'pro_tag', 'pro tag', 'tagesbuchung', 'tages-buchung'];
    foreach ($markers as $needle) {
        if (strpos($bt, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Clear player cache when user meta is updated
 * 
 * This ensures the cache is invalidated when players are added/updated/deleted
 */
function intersoccer_clear_players_cache_on_update($meta_id, $user_id, $meta_key, $meta_value) {
    // Only clear cache if intersoccer_players meta was updated
    if ($meta_key === 'intersoccer_players') {
        intersoccer_clear_user_players_cache($user_id);
    }
}

// Register hooks - safe to call at file load time as WordPress hooks are available
// These hooks only fire when user meta is actually updated, not during plugin activation
if (function_exists('add_action')) {
    add_action('updated_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
    add_action('added_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
    add_action('deleted_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
}

