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
 * Clear player cache when user meta is updated
 * 
 * This ensures the cache is invalidated when players are added/updated/deleted
 */
function intersoccer_clear_players_cache_on_update($meta_id, $user_id, $meta_key, $meta_value) {
    // Only clear cache if intersoccer_players meta was updated
    if ($meta_key === 'intersoccer_players') {
        intersoccer_clear_user_players_cache($user_id);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Cleared player cache for user ' . $user_id . ' after meta update');
        }
    }
}

// Register hooks - safe to call at file load time as WordPress hooks are available
// These hooks only fire when user meta is actually updated, not during plugin activation
if (function_exists('add_action')) {
    add_action('updated_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
    add_action('added_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
    add_action('deleted_user_meta', 'intersoccer_clear_players_cache_on_update', 10, 4);
}

