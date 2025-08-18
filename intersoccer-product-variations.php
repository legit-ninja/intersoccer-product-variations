<?php
/**
 * Plugin Name: InterSoccer Product Variations
 * Description: Custom plugin for InterSoccer Switzerland to manage events and bookings.
 * Version: 1.4.41
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
    'includes/woocommerce/admin-ui.php',
    'includes/woocommerce/product-types.php',
    'includes/woocommerce/product-course.php',
    'includes/woocommerce/product-camp.php',
    'includes/woocommerce/discounts.php',
    'includes/woocommerce/cart-calculations.php',
    'includes/woocommerce/checkout-calculations.php',
    'includes/woocommerce/discount-messages.php',
    'includes/admin-product-fields.php',
    'includes/ajax-handlers.php',
    'includes/checkout.php',
];

foreach ($includes as $file) {
    if (file_exists(INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file)) {
        require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file;
        error_log('InterSoccer: Included ' . $file);
    } else {
        error_log('InterSoccer: Failed to include ' . $file . ' - File not found');
    }
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    $nonce = wp_create_nonce('intersoccer_nonce');
    error_log('InterSoccer: Generated nonce for intersoccer_nonce: ' . $nonce);

    if (is_product()) {
        wp_enqueue_script(
            'intersoccer-variation-details',
            INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/variation-details.js',
            ['jquery'],
            '1.4.1',
            true
        );

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

    wp_enqueue_style(
        'intersoccer-styles',
        INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'css/styles.css',
        [],
        '1.4.1'
    );
});

// Register activation hook
register_activation_hook(__FILE__, function () {
    error_log('InterSoccer: Plugin activated');
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

// Add custom roles
add_action('init', function () {
    add_role('coach', __('Coach', 'intersoccer-player-management'), array('read' => true, 'edit_posts' => true));
    add_role('organizer', __('Organizer', 'intersoccer-player-management'), array('read' => true, 'edit_posts' => true));
});

// Register endpoint
add_action('init', function () {
    add_rewrite_endpoint('manage-players', EP_ROOT | EP_PAGES);
});

// Add menu item with high priority (after Dashboard)
add_filter('woocommerce_account_menu_items', function ($items) {
    $new_items = [];
    $inserted = false;
    foreach ($items as $key => $label) {
        $new_items[$key] = $label;
        if ($key === 'dashboard' && !$inserted) {
            $new_items['manage-players'] = __('Manage Players', 'intersoccer-player-management');
            $inserted = true;
        }
    }
    if (!$inserted) {
        $new_items['manage-players'] = __('Manage Players', 'intersoccer-player-management');
    }
    return $new_items;
}, 10);

// Flush permalinks on activation
add_action('init', function () {
    if (get_option('intersoccer_flush_permalinks')) {
        flush_rewrite_rules();
        delete_option('intersoccer_flush_permalinks');
    }
});

// Increase AJAX variation threshold
function custom_wc_ajax_variation_threshold($qty, $product) {
    return 500;
}
add_filter('woocommerce_ajax_variation_threshold', 'custom_wc_ajax_variation_threshold', 10, 2);

// Show variation price
add_filter('woocommerce_show_variation_price', function () {
    return true;
});

// Found Variation Show Start and End dates
add_filter('woocommerce_available_variation', function($data, $product, $variation) {
    $product_type = intersoccer_get_product_type($variation->get_parent_id() ?: $variation->get_id());
    if ($product_type === 'course') {
        $variation_id = $variation->get_id();
        $data['course_start_date'] = get_post_meta($variation_id, '_course_start_date', true) ? date_i18n('F j, Y', strtotime(get_post_meta($variation_id, '_course_start_date', true))) : '';
        $end_date = get_post_meta($variation_id, '_end_date', true);
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $parent_id = $variation->get_parent_id() ?: $variation_id;
        $course_day = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names'])[0] ?? 'Monday';

        if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !strtotime($end_date)) {
            if ($data['course_start_date'] && $total_weeks > 0) {
                $start = new DateTime(get_post_meta($variation_id, '_course_start_date', true));
                $holiday_set = array_flip($holidays);
                $sessions_needed = $total_weeks;
                $current_date = clone $start;
                $weeks_counted = 0;
                $days_checked = 0;
                while ($weeks_counted < $sessions_needed && $days_checked < ($total_weeks * 7 * 2)) {
                    if ($current_date->format('l') === $course_day && !isset($holiday_set[$current_date->format('Y-m-d')])) {
                        $weeks_counted++;
                    }
                    $current_date->add(new DateInterval('P1D'));
                    $days_checked++;
                }
                $end_date = $current_date->sub(new DateInterval('P1D'))->format('Y-m-d');
                error_log('InterSoccer: Calculated end_date for variation ' . $variation_id . ': ' . $end_date);
            } else {
                $end_date = '';
                error_log('InterSoccer: Cannot calculate end_date for variation ' . $variation_id . ': missing start_date or total_weeks');
            }
        }
        $data['end_date'] = $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '';
        
        $data['course_holiday_dates'] = array_map(function($date) {
            return date_i18n('F j, Y', strtotime($date));
        }, $holidays);
        $data['remaining_sessions'] = calculate_remaining_sessions($variation_id, $total_weeks);
        $data['discount_note'] = calculate_discount_note($variation_id, $data['remaining_sessions']);
        error_log('Variation ' . $variation_id . ' data: start=' . $data['course_start_date'] . ', end=' . $data['end_date'] . ', holidays=' . json_encode($data['course_holiday_dates']) . ', sessions=' . $data['remaining_sessions'] . ', discount=' . $data['discount_note']);
    }
    return $data;
}, 10, 3);

function calculate_discount_note($variation_id, $remaining_sessions) {
    $discount_note = '';
    $cart = WC()->cart->get_cart();
    $season = get_post_meta($variation_id, 'attribute_pa_program-season', true) ?: 'unknown';
    $grouped_items = [];
    foreach ($cart as $key => $item) {
        $item_season = get_post_meta($item['variation_id'] ?: $item['product_id'], 'attribute_pa_program-season', true) ?: 'unknown';
        if ($item_season === $season && intersoccer_get_product_type($item['product_id']) === 'course') {
            $grouped_items[$key] = $item;
        }
    }
    $course_index = array_search($variation_id, array_column($grouped_items, 'variation_id') ?: array_column($grouped_items, 'product_id'));
    if ($course_index !== false && $course_index == 1) {
        $discount_note = intersoccer_get_discount_message('course_same_season', 'cart_message', '50% Same Season Course Discount');
    }
    error_log('InterSoccer: Calculated discount_note for variation ' . $variation_id . ': ' . $discount_note);
    return $discount_note;
}
?>