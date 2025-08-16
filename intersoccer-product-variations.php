<?php
/**
 * Plugin Name: InterSoccer Product Variations
 * Description: Custom plugin for InterSoccer Switzerland to manage events and bookings.
 * Version: 1.4.19
 * Author: Jeremy Lee
 * Text Domain: intersoccer-product-variations
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
add_action('admin_init', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('InterSoccer Product Variations requires WooCommerce to be installed and active.', 'intersoccer-product-variations') . 
                 '</p></div>';
        });
        deactivate_plugins(plugin_basename(__FILE__));
        return;
    }
});

// Load plugin text domain for translations
add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'intersoccer-product-variations',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

// Include plugin files in correct order (dependencies first)
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    $includes = [
        // Core dependencies first
        'includes/woocommerce/product-types.php',
        'includes/woocommerce/product-camp.php',
        'includes/woocommerce/product-course.php',
        
        // Calculation and discount logic
        'includes/woocommerce/cart-calculations.php',
        'includes/woocommerce/checkout-calculations.php',
        'includes/woocommerce/discounts.php',
        'includes/woocommerce/discount-messages.php', // New: Custom messages integration
        
        // Admin and UI
        'includes/woocommerce/admin-ui.php',
        'includes/admin-product-fields.php',
        
        // AJAX and frontend
        'includes/ajax-handlers.php',
        // 'includes/checkout.php',
        'includes/elementor-widgets.php',
    ];

    foreach ($includes as $file) {
        $file_path = INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
            error_log('InterSoccer: Successfully included ' . $file);
        } else {
            error_log('InterSoccer: Failed to include ' . $file . ' - File not found at: ' . $file_path);
        }
    }
    
    error_log('InterSoccer: All plugin files loaded');
}, 5);

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    // Generate a fresh nonce for each page load
    $nonce = wp_create_nonce('intersoccer_nonce');
    error_log('InterSoccer: Generated nonce for intersoccer_nonce: ' . $nonce);

    // Conditionally enqueue variation-details.js only on product pages
    if (is_product()) {
        wp_enqueue_script(
            'intersoccer-variation-details',
            INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/variation-details.js',
            ['jquery'],
            '1.9.1',
            true
        );

        // Localize script with AJAX data
        wp_localize_script(
            'intersoccer-variation-details',
            'intersoccerCheckout',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => $nonce,
                'user_id' => get_current_user_id(),
                'server_time' => current_time('c'),
                'nonce_refresh_url' => admin_url('admin-ajax.php?action=intersoccer_refresh_nonce'),
            ]
        );
    }

    // Enqueue styles
    wp_enqueue_style(
        'intersoccer-styles',
        INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'css/styles.css',
        [],
        '1.9.1'
    );
});

// Register activation hook
register_activation_hook(__FILE__, function () {
    error_log('InterSoccer: Plugin activated');
    
    // Set flag to flush permalinks
    update_option('intersoccer_flush_permalinks', true);
    
    // Initialize default discount rules if they don't exist
    $existing_rules = get_option('intersoccer_discount_rules', []);
    if (empty($existing_rules)) {
        $default_rules = [
            'camp_2nd_child' => [
                'id' => 'camp_2nd_child',
                'name' => 'Camp 2nd Child Discount',
                'type' => 'camp',
                'condition' => '2nd_child',
                'rate' => 20,
                'active' => true
            ],
            'camp_3rd_plus_child' => [
                'id' => 'camp_3rd_plus_child',
                'name' => 'Camp 3rd+ Child Discount',
                'type' => 'camp',
                'condition' => '3rd_plus_child',
                'rate' => 25,
                'active' => true
            ],
            'course_2nd_child' => [
                'id' => 'course_2nd_child',
                'name' => 'Course 2nd Child Discount',
                'type' => 'course',
                'condition' => '2nd_child',
                'rate' => 20,
                'active' => true
            ],
            'course_3rd_plus_child' => [
                'id' => 'course_3rd_plus_child',
                'name' => 'Course 3rd+ Child Discount',
                'type' => 'course',
                'condition' => '3rd_plus_child',
                'rate' => 30,
                'active' => true
            ],
            'course_same_season' => [
                'id' => 'course_same_season',
                'name' => 'Same Season Course Discount',
                'type' => 'course',
                'condition' => 'same_season_course',
                'rate' => 50,
                'active' => true
            ]
        ];
        update_option('intersoccer_discount_rules', $default_rules);
        error_log('InterSoccer: Initialized default discount rules');
    }
});

// AJAX handler for nonce refresh
add_action('wp_ajax_intersoccer_refresh_nonce', 'intersoccer_refresh_nonce');
add_action('wp_ajax_nopriv_intersoccer_refresh_nonce', 'intersoccer_refresh_nonce');
function intersoccer_refresh_nonce() {
    if (ob_get_length()) {
        ob_clean();
    }
    $nonce = wp_create_nonce('intersoccer_nonce');
    wp_send_json_success(['nonce' => $nonce]);
}

// Add custom user roles
add_action('init', function () {
    // Add roles if they don't exist
    if (!get_role('coach')) {
        add_role('coach', __('Coach', 'intersoccer-product-variations'), array(
            'read' => true, 
            'edit_posts' => true
        ));
    }
    
    if (!get_role('organizer')) {
        add_role('organizer', __('Organizer', 'intersoccer-product-variations'), array(
            'read' => true, 
            'edit_posts' => true
        ));
    }
});

// Register endpoint for player management
add_action('init', function () {
    add_rewrite_endpoint('manage-players', EP_ROOT | EP_PAGES);
});

// Add menu item to WooCommerce account with high priority
add_filter('woocommerce_account_menu_items', function ($items) {
    $new_items = [];
    $inserted = false;
    foreach ($items as $key => $label) {
        $new_items[$key] = $label;
        if ($key === 'dashboard' && !$inserted) {
            $new_items['manage-players'] = __('Manage Players', 'intersoccer-product-variations');
            $inserted = true;
        }
    }
    if (!$inserted) {
        $new_items['manage-players'] = __('Manage Players', 'intersoccer-product-variations');
    }
    return $new_items;
}, 10);

// Flush permalinks on activation
add_action('init', function () {
    if (get_option('intersoccer_flush_permalinks')) {
        flush_rewrite_rules();
        delete_option('intersoccer_flush_permalinks');
        error_log('InterSoccer: Flushed rewrite rules');
    }
});

// Increase AJAX variation threshold for better performance
add_filter('woocommerce_ajax_variation_threshold', function($qty, $product) {
    return 500; // Increase threshold for variations
}, 10, 2);

// Show variation price in frontend
add_filter('woocommerce_show_variation_price', function () {
    return true;
});

// Add error logging for debugging
if (WP_DEBUG && WP_DEBUG_LOG) {
    add_action('wp_footer', function() {
        if (is_product()) {
            global $post;
            $product_id = $post->ID;
            $product_type = intersoccer_get_product_type($product_id);
            error_log('InterSoccer: Product page loaded - ID: ' . $product_id . ', Type: ' . ($product_type ?: 'unknown'));
        }
    });
}

// Enhanced variation data for course calculations
add_filter('woocommerce_available_variation', function($data, $product, $variation) {
    $product_type = intersoccer_get_product_type($variation->get_parent_id() ?: $variation->get_id());
    
    if ($product_type === 'course') {
        $variation_id = $variation->get_id();
        $start_date = get_post_meta($variation_id, '_course_start_date', true);
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        
        // Add formatted start date
        $data['course_start_date'] = $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '';
        
        // Calculate end date if needed
        $end_date = get_post_meta($variation_id, '_end_date', true);
        if (!$end_date && $start_date && $total_weeks > 0) {
            $end_date = InterSoccer_Course::calculate_end_date($variation_id, $total_weeks);
        }
        $data['end_date'] = $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '';
        
        // Add formatted holiday dates
        $data['course_holiday_dates'] = array_map(function($date) {
            return date_i18n('F j, Y', strtotime($date));
        }, $holidays);
        
        // Calculate remaining sessions
        $remaining_sessions = InterSoccer_Course::calculate_remaining_sessions($variation_id, $total_weeks);
        $data['remaining_sessions'] = $remaining_sessions;
        
        // Add discount note
        $data['discount_note'] = InterSoccer_Course::calculate_discount_note($variation_id, $remaining_sessions);
        
        error_log('InterSoccer: Enhanced variation ' . $variation_id . ' data: start=' . $data['course_start_date'] . ', end=' . $data['end_date'] . ', sessions=' . $data['remaining_sessions']);
    } elseif ($product_type === 'camp') {
        $variation_id = $variation->get_id();
        $booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
        
        $data['booking_type'] = $booking_type;
        $data['is_single_day_camp'] = ($booking_type === 'single-days');
        
        // Add camp-specific discount note
        $data['discount_note'] = InterSoccer_Camp::calculate_discount_note($variation_id, []);
        
        error_log('InterSoccer: Enhanced camp variation ' . $variation_id . ' data: booking_type=' . $booking_type);
    }
    
    return $data;
}, 10, 3);

// Add validation for camp single-day selections
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity, $variation_id = null) {
    if ($variation_id) {
        $product_type = intersoccer_get_product_type($product_id);
        if ($product_type === 'camp') {
            $passed = InterSoccer_Camp::validate_single_day($passed, $product_id, $quantity);
        }
    }
    return $passed;
}, 10, 4);

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    error_log('InterSoccer: Plugin deactivated');
});

// Add admin notice for successful activation
add_action('admin_notices', function() {
    if (get_transient('intersoccer_activation_notice')) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>' . __('InterSoccer Product Variations plugin activated successfully!', 'intersoccer-product-variations') . '</p>';
        echo '</div>';
        delete_transient('intersoccer_activation_notice');
    }
});

// Set activation notice transient
register_activation_hook(__FILE__, function() {
    set_transient('intersoccer_activation_notice', true, 30);
});

error_log('InterSoccer: Main plugin file loaded successfully');
?>