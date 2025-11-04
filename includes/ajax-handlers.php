<?php
/**
 * File: ajax-handlers.php
 * Description: Handles essential AJAX requests for InterSoccer Product Variations plugin.
 * Dependencies: product-types.php
 * Changes:
 * - Added missing AJAX handlers that variation-details.js requires
 * - Fixed 400 error by adding intersoccer_get_product_type handler
 * - Added all handlers needed for complete functionality
 * - Kept existing handlers and added new ones
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Guard against multiple loads
if (defined('INTERSOCCER_AJAX_HANDLERS_LOADED')) {
    return;
}
define('INTERSOCCER_AJAX_HANDLERS_LOADED', true);

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('InterSoccer: ajax-handlers.php loaded (fixed version) at ' . current_time('c'));
}

/**
 * CRITICAL: Get product type handler - THIS WAS MISSING and causing 400 error
 */
add_action('wp_ajax_intersoccer_get_product_type', 'intersoccer_ajax_get_product_type');
add_action('wp_ajax_nopriv_intersoccer_get_product_type', 'intersoccer_ajax_get_product_type');
function intersoccer_ajax_get_product_type() {
    // Clean output buffers
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: get_product_type called with data: ' . json_encode($_POST));
    }

    // Verify nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: get_product_type nonce verification failed');
        }
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    // Validate product ID
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (!$product_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: get_product_type missing product_id');
        }
        wp_send_json_error(['message' => __('Invalid product ID.', 'intersoccer-product-variations')], 400);
        wp_die();
    }

    // Get product type using existing function
    $product_type = intersoccer_get_product_type($product_id);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: get_product_type returning: ' . $product_type . ' for product ' . $product_id);
    }
    
    wp_send_json_success(['product_type' => $product_type]);
    wp_die();
}

/**
 * Get course metadata handler - needed by variation-details.js
 */
add_action('wp_ajax_intersoccer_get_course_metadata', 'intersoccer_ajax_get_course_metadata');
add_action('wp_ajax_nopriv_intersoccer_get_course_metadata', 'intersoccer_ajax_get_course_metadata');
function intersoccer_ajax_get_course_metadata() {
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: get_course_metadata called with data: ' . json_encode($_POST));
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'intersoccer_nonce')) {
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);

    if (!$variation_id) {
        wp_send_json_error(['message' => __('Invalid variation ID.', 'intersoccer-product-variations')], 400);
        wp_die();
    }

    // Get course metadata
    $start_date = get_post_meta($variation_id, '_course_start_date', true);
    $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
    $weekly_discount = (float) get_post_meta($variation_id, '_course_weekly_discount', true);

    // Calculate remaining weeks
    $remaining_weeks = $total_weeks;
    if (class_exists('InterSoccer_Course') && $start_date && $total_weeks) {
        $remaining_weeks = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    } elseif ($start_date && $total_weeks) {
        // Fallback calculation
        $start_time = strtotime($start_date);
        $current_time = current_time('timestamp');
        if ($start_time && $current_time >= $start_time) {
            $weeks_passed = floor(($current_time - $start_time) / WEEK_IN_SECONDS);
            $remaining_weeks = max(0, $total_weeks - $weeks_passed);
        }
    }

    $metadata = [
        'start_date' => $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '',
        'total_weeks' => $total_weeks,
        'weekly_discount' => $weekly_discount,
        'remaining_weeks' => $remaining_weeks
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: get_course_metadata returning: ' . json_encode($metadata));
    }

    wp_send_json_success($metadata);
    wp_die();
}

/**
 * Calculate dynamic price handler - needed by variation-details.js
 */
add_action('wp_ajax_intersoccer_calculate_dynamic_price', 'intersoccer_ajax_calculate_dynamic_price');
add_action('wp_ajax_nopriv_intersoccer_calculate_dynamic_price', 'intersoccer_ajax_calculate_dynamic_price');
function intersoccer_ajax_calculate_dynamic_price() {
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: calculate_dynamic_price called with data: ' . json_encode($_POST));
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'intersoccer_nonce')) {
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $variation_id = absint($_POST['variation_id'] ?? 0);
    $remaining_weeks = isset($_POST['remaining_weeks']) ? absint($_POST['remaining_weeks']) : null;

    if (!$variation_id) {
        wp_send_json_error(['message' => __('Invalid variation ID.', 'intersoccer-product-variations')], 400);
        wp_die();
    }

    $variation = wc_get_product($variation_id);
    if (!$variation) {
        wp_send_json_error(['message' => __('Variation not found.', 'intersoccer-product-variations')], 404);
        wp_die();
    }

    // Use the proper course calculation method (handles session rates, holidays, etc.)
    $product_type = InterSoccer_Product_Types::get_product_type($product_id);
    
    if ($product_type === 'course' && class_exists('InterSoccer_Course')) {
        $calculated_price = InterSoccer_Course::calculate_price($product_id, $variation_id, $remaining_weeks);
    } else {
        // Fallback for non-courses or if class not available
        $calculated_price = $variation->get_price();
    }

    // Return properly formatted price HTML with WooCommerce structure
    // Format: <span class="price"><span class="woocommerce-Price-amount">...</span></span>
    $response = [
        'price' => '<span class="price">' . wc_price($calculated_price) . '</span>',
        'raw_price' => $calculated_price,
        'base_price' => $variation->get_price(),
        'remaining_weeks' => $remaining_weeks
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: calculate_dynamic_price returning: ' . json_encode($response));
    }

    wp_send_json_success($response);
    wp_die();
}

/**
 * Update session data handler - needed by variation-details.js
 */
add_action('wp_ajax_intersoccer_update_session_data', 'intersoccer_ajax_update_session_data');
add_action('wp_ajax_nopriv_intersoccer_update_session_data', 'intersoccer_ajax_update_session_data');
function intersoccer_ajax_update_session_data() {
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: update_session_data called with data: ' . json_encode($_POST));
    }

    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'intersoccer_nonce')) {
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $assigned_attendee = sanitize_text_field($_POST['assigned_attendee'] ?? '');
    $camp_days = isset($_POST['camp_days']) ? array_map('sanitize_text_field', $_POST['camp_days']) : [];
    $remaining_weeks = isset($_POST['remaining_weeks']) ? absint($_POST['remaining_weeks']) : null;

    // Store in session or transient
    $session_data = [
        'product_id' => $product_id,
        'assigned_attendee' => $assigned_attendee,
        'camp_days' => $camp_days,
        'remaining_weeks' => $remaining_weeks,
        'timestamp' => current_time('mysql')
    ];

    // Store in transient (expires in 1 hour)
    $user_id = get_current_user_id() ?: 'guest_' . session_id();
    set_transient('intersoccer_session_' . $user_id, $session_data, HOUR_IN_SECONDS);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: update_session_data stored: ' . json_encode($session_data));
    }

    wp_send_json_success(['message' => __('Session updated.', 'intersoccer-product-variations'), 'data' => $session_data]);
    wp_die();
}

/**
 * Get user players (enhanced version) - keep existing but improve
 */
add_action('wp_ajax_intersoccer_get_user_players', 'intersoccer_get_user_players');
add_action('wp_ajax_nopriv_intersoccer_get_user_players', 'intersoccer_get_user_players');
function intersoccer_get_user_players() {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: intersoccer_get_user_players called at ' . current_time('c'));
    }

    // Verify nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Nonce verification failed');
        }
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    // Check user authentication
    $user_id = get_current_user_id();
    if (!$user_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: User not logged in');
        }
        wp_send_json_error(['message' => __('You must be logged in.', 'intersoccer-product-variations')], 403);
        wp_die();
    }

    // Get players from user meta
    $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
    
    // Ensure players are valid and sanitized
    $valid_players = [];
    foreach ($players as $index => $player) {
        if (is_array($player) && isset($player['first_name']) && isset($player['last_name'])) {
            $valid_players[] = [
                'index' => $index,
                'first_name' => sanitize_text_field($player['first_name']),
                'last_name' => sanitize_text_field($player['last_name']),
                'dob' => sanitize_text_field($player['dob'] ?? ''),
                'gender' => sanitize_text_field($player['gender'] ?? ''),
                'medical_conditions' => sanitize_textarea_field($player['medical_conditions'] ?? '')
            ];
        }
    }
    
    $response = ['players' => $valid_players, 'count' => count($valid_players)];
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Returned ' . count($valid_players) . ' players for user ' . $user_id);
    }
    
    wp_send_json_success($response);
    wp_die();
}

/**
 * Get days of the week for camp products (existing handler - keep)
 */
add_action('wp_ajax_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week');
add_action('wp_ajax_nopriv_intersoccer_get_days_of_week', 'intersoccer_get_days_of_week');
function intersoccer_get_days_of_week() {
    // Clear output buffers
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: intersoccer_get_days_of_week called at ' . current_time('c'));
    }

    // Verify nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Nonce verification failed for days_of_week');
        }
        wp_send_json_error(['message' => __('Invalid nonce.', 'intersoccer-product-variations')], 403);
        return;
    }

    // Validate required parameters
    if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Invalid product_id in get_days_of_week');
        }
        wp_send_json_error(['message' => __('Invalid product ID.', 'intersoccer-product-variations')], 400);
        return;
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;

    // Get parent product ID for attributes
    if ($variation_id) {
        $product = wc_get_product($variation_id);
        if ($product && $product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
        } else {
            $parent_id = $product_id;
        }
    } else {
        $parent_id = $product_id;
    }

    // Get days from pa_days-of-week attribute
    $days = wc_get_product_terms($parent_id, 'pa_days-of-week', ['fields' => 'names']);

    if (empty($days)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: No days found for product ' . $parent_id . ', using fallback');
        }
        $days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"];
    } else {
        // Sort days in proper order
        $day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        usort($days, function ($a, $b) use ($day_order) {
            $pos_a = array_search($a, $day_order);
            $pos_b = array_search($b, $day_order);
            return ($pos_a !== false ? $pos_a : 999) - ($pos_b !== false ? $pos_b : 999);
        });
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Returned days for product ' . $parent_id . ': ' . implode(', ', $days));
    }
    wp_send_json_success(['days' => $days]);
}

/**
 * Get basic course information for frontend display (existing handler - keep)
 */
add_action('wp_ajax_intersoccer_get_course_info', 'intersoccer_get_course_info');
add_action('wp_ajax_nopriv_intersoccer_get_course_info', 'intersoccer_get_course_info');
function intersoccer_get_course_info() {
    // Clear output buffers
    if (ob_get_length()) {
        ob_clean();
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: intersoccer_get_course_info called at ' . current_time('c'));
    }

    // Verify nonce
    check_ajax_referer('intersoccer_nonce', 'nonce');

    // Validate parameters
    if (!isset($_POST['product_id']) || !isset($_POST['variation_id'])) {
        wp_send_json_error(['message' => __('Missing parameters.', 'intersoccer-product-variations')], 400);
        return;
    }

    $product_id = absint($_POST['product_id']);
    $variation_id = absint($_POST['variation_id']);

    // Check if it's actually a course
    $product_type = intersoccer_get_product_type($product_id);
    if ($product_type !== 'course') {
        wp_send_json_success([
            'is_course' => false,
            'start_date' => '',
            'total_weeks' => 0,
            'remaining_sessions' => 0
        ]);
        return;
    }

    // Get basic course data
    $start_date = get_post_meta($variation_id, '_course_start_date', true);
    $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);

    // Use the course class if available for remaining sessions
    $remaining_sessions = 0;
    if (class_exists('InterSoccer_Course') && $start_date && $total_weeks) {
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
    }

    $response = [
        'is_course' => true,
        'start_date' => $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '',
        'total_weeks' => $total_weeks,
        'remaining_sessions' => $remaining_sessions
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Course info for ' . $variation_id . ': ' . json_encode($response));
    }
    wp_send_json_success($response);
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('InterSoccer: Fixed ajax-handlers.php loaded with all required handlers');
}
?>