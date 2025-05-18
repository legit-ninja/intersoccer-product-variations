<?php

/**
 * Plugin Name: InterSoccer Product Variations
 * Description: Custom plugin for InterSoccer Switzerland to manage events and bookings.
 * Version: 1.0.0
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
define('INTERSOCCER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load plugin text domain for translations
add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'intersoccer-product-variations',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

// Include plugin files
$includes = [
    'includes/elementor-widgets.php',
    'includes/woocommerce-modifications.php',
    'includes/admin-product-fields.php',
    'includes/ajax-handlers.php',
    'includes/checkout.php',
];

foreach ($includes as $file) {
    if (file_exists(INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file)) {
        require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file;
//        error_log('InterSoccer: Included ' . $file);
    } else {
        error_log('InterSoccer: Failed to include ' . $file . ' - File not found');
    }
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    // Generate a fresh nonce for each page load
    $nonce = wp_create_nonce('intersoccer_nonce');
    //error_log('InterSoccer: Generated nonce for intersoccer_nonce: ' . $nonce);

    // Enqueue variation-details.js
    wp_enqueue_script(
        'intersoccer-variation-details',
        INTERSOCCER_PLUGIN_URL . 'js/variation-details.js',
        ['jquery'],
        '1.9.' . time(),
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

    // Enqueue styles
    wp_enqueue_style(
        'intersoccer-styles',
        INTERSOCCER_PLUGIN_URL . 'css/styles.css',
        [],
        '1.9.' . time()
    );
});

// Register activation hook
register_activation_hook(__FILE__, function () {
    // Add any activation tasks here
    //error_log('InterSoccer: Plugin activated');
});

// AJAX handler for nonce refresh
add_action('wp_ajax_intersoccer_refresh_nonce', 'intersoccer_refresh_nonce');
add_action('wp_ajax_nopriv_intersoccer_refresh_nonce', 'intersoccer_refresh_nonce');
function intersoccer_refresh_nonce()
{
    if (ob_get_length()) {
        ob_clean();
    }
    $nonce = wp_create_nonce('intersoccer_nonce');
    wp_send_json_success(['nonce' => $nonce]);
}

