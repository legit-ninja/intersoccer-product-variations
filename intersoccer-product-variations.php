<?php
/**
 * Plugin Name: InterSoccer Product Variations
 * Description: Custom plugin for InterSoccer Switzerland to manage events and bookings.
 * Version: 1.11.3
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
        dirname(plugin_basename(__FILE__)) . '/lang'
    );
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Text domain loaded: ' . (is_textdomain_loaded('intersoccer-product-variations') ? 'yes' : 'no'));
    }
});

// Register strings for WPML translation on init to ensure WPML is loaded
add_action('init', function () {
    if (function_exists('icl_register_string')) {
        // Day names
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($days as $day) {
            icl_register_string('intersoccer-product-variations', $day, $day);
        }

        // Other strings
        $strings = [
            '%d Weeks Remaining',
            '%d Day(s) Selected',
            'Late Pickup Type',
            'Single Day',
            'Full Week',
            'Select Late Pickup Days',
            'Late Pickup Cost:',
            'Course Start Date (MM-DD-YYYY)',
            'Total Weeks Duration',
            'Session Rate (CHF per day/session)',
            'Holiday/Skip Dates',
            'Remove',
            'Add Holiday Date',
            'Late Pick Up Options',
            'Enable Late Pick Up',
            'Allow customers to add late pick up options for this camp variation.',
            'Invalid nonce.',
            'Invalid product ID.',
            'Invalid variation ID.',
            'Variation not found.',
            'Session updated.',
            'You must be logged in.',
            'Missing parameters.',
            '%d%% Family Discount (%s Child)',
            '%d%% Combo Discount (%s Child)',
            '50% Same-Season Course Discount',
            'Assigned Attendee',
            'Days Selected',
            '%d of %d',
            'Sessions Remaining',
            'Start Date',
            'End Date',
            'Holidays',
            'Discount Applied',
            'Sessions Remaining:',
            'Camp',
            'Course',
            'Birthday',
            'Update Order Details',
            'You do not have permission to access this page.',
            'Update Processing Orders with Parent Attributes',
            'Select orders to update with new visible, non-variation parent product attributes for reporting and analytics. Use \"Remove Assigned Player\" to delete the unwanted assigned_player field from orders. Use \"Fix Incorrect Attributes\" to correct orders with unwanted attributes (e.g., all days of the week).',
            'Order ID',
            'Customer',
            'Date',
            'Total',
            'Remove Assigned Player Field',
            'Fix Incorrect Attributes (e.g., remove Days-of-week)',
            'Update Selected Orders',
            'No Processing orders found.',
            'Please select at least one order.',
            'Updating orders...',
            'Orders updated successfully!',
            'Error:',
            'An error occurred while updating orders.',
            'No orders selected.',
            'Orders updated successfully.',
            'Event Documents',
            'Document Name',
            'Choose File',
            'Download Limit',
            'Days Until Expiry',
            'Add Document',
            'Select PDF Document',
            'Use this file',
            'Please select at least one day for this single-day camp.',
            '%d%% Camp Combo Discount (Child %d)',
            '%d%% Course Multi-Child Discount (Child %d)',
            '50%% Same Season Course Discount (Child %d, %s)',
            'Discount Information',
            'Discounts Applied',
            'saved',
            '20% Sibling Discount Applied',
            'Second child camp discount',
            'You saved 20% on this camp because you have multiple children enrolled in camps.',
            '25% Multi-Child Discount Applied',
            'Third or additional child camp discount',
            'You saved 25% on this camp for your third (or additional) child enrolled in camps.',
            '20% Course Sibling Discount',
            'Second child course discount',
            'You saved 20% on this course because you have multiple children enrolled in courses.',
            '30% Multi-Child Course Discount',
            'Third or additional child course discount',
            'You saved 30% on this course for your third (or additional) child enrolled in courses.',
            '50% Same Season Course Discount',
            'Same child, multiple courses in same season',
            'You saved 50% on this course because your child is enrolled in multiple courses this season.',
            '%d%% Sibling Camp Discount',
            '%d%% Multi-Child Camp Discount',
            '%d%% Sibling Course Discount',
            '%d%% Multi-Child Course Discount',
            '50%% Same Season Course Discount (%s)',
            'Manage Discounts',
            'InterSoccer Discounts',
            'Scan Orders missing data',
            'Find Order Issues',
            'Variation Health Checker',
            'Variation Health',
            'Bulk Repair Order Details',
            'Bulk Repair Order',
            'Name',
            'Type',
            'Condition',
            'Discount Rate (%)',
            'Active',
            'None',
            'Yes',
            'No',
            'Activate',
            'Deactivate',
            'Delete',
            'Add, edit, or delete discount rules for Camps, Courses, or other products. Rules apply automatically based on cart conditions (e.g., sibling bookings). For manual coupons, use <a href="',
            'Add New Discount',
            'General',
            '2nd Child',
            '3rd or Additional Child',
            'Same Season Course (Same Child)',
            'Saving...',
            'Discount saved successfully!',
            'An error occurred while saving the discount:',
            'Please select an action and at least one discount.',
            'Processing...',
            'Action completed successfully!',
            'An error occurred while processing the action:',
            'Manage Discounts & Messages',
            'Messages',
            '3rd+ Child',
            'Cart Message:',
            'Admin Description:',
            'Customer Note:',
            'Save All',
            'Name and discount rate are required.',
            'Discount saved.',
            'Invalid action or no discounts selected.',
            'Action completed.',
            'Order',
            'Items & Types',
            'Missing Fields Summary',
            'Risk Level',
            'Updated metadata for %d orders.',
            'Failed to update: %s',
            'Order Metadata Update Tool',
            'Find and update orders that are missing metadata fields needed for accurate rosters and reports.',
            'Order Statuses to Check',
            'Processing',
            'Completed',
            'On Hold',
            'Number of Orders to Scan',
            'Higher numbers may take longer to process but will find more orders with missing data.',
            'Find Orders Missing Metadata',
            'Note:',
            'This will scan your selected orders and show which ones are missing metadata fields like player details, seasons, activity types, etc.',
            'Orders Missing Metadata',
            'Update Progress',
            'Great! No orders found that need metadata updates.',
            'Scan Results',
            'Select All Low Risk',
            'Deselect All',
            'Export Analysis to CSV',
            'You have selected high-risk orders. These may have unexpected results. Continue?',
            'Product ID',
            'Variation ID',
            'Attributes',
            'Health Status',
            'Refresh Attributes',
            'No attributes',
            'Course end dates recalculated successfully.',
            'InterSoccer Variation Health Dashboard',
            'Use this dashboard to check and fix variation issues, such as recalculating course end dates.',
            'Recalculate Course End Dates',
            'Scan and check health of product variations. Use the filter to show only unhealthy ones.',
            'Show unhealthy variations only',
            'Filter',
            'Please select an action and at least one variation.',
            'Attributes refreshed successfully!',
            'An error occurred while refreshing attributes:',
            'Automated Order Metadata Update',
            'Automatically find and update orders missing metadata. This tool can process hundreds or thousands of orders efficiently.',
            '1. Configure Scan',
            'Order Statuses to Process',
            'Orders to Scan',
            'Higher numbers find more orders but take longer to scan initially.',
            'Processing Speed',
            'Conservative is safer for slower servers. Fast processes more orders per batch but may timeout.',
            '🚀 Start Automated Update',
            '⏹️ Stop Processing',
            '2. Processing Progress',
            '3. Results',
            '📥 Download Results Log',
            '🔄 Process More Orders',
            'Late Pickup Type',
            'Single Day',
            'Full Week',
            'Select Late Pickup Days',
            'Late Pickup Cost:',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Please select at least one day for late pick up when choosing Single Day(s).',
            'Late Pickup Cost',
            'Full Week',
            'Late Pickup',
            'The number of selected days must match the quantity.',
            'Settings saved.',
            'Late Pick Up',
            'Late Pick Up Settings',
            '50%% Same Season Course Discount (%s)',
            '%d%% Multi-Child Course Discount',
            '%d%% Sibling Course Discount',
            '%d%% Multi-Child Camp Discount',
            '%d%% Sibling Camp Discount',
            'saved',
            'Discounts Applied',
            'Discount Information',
            '50%% Same Season Course Discount (Child %d, %s)',
            '%d%% Course Multi-Child Discount (Child %d)',
            '%d%% Camp Combo Discount (Child %d)',
            'Discount',
            'Assigned Attendee',
            'Allow customers to add late pick up options for this camp variation.',
            'Enable Late Pick Up',
            'Late Pick Up Options',
            'Session Rate (CHF per day/session)',
            'Please select at least one day for this single-day camp.',
            'You saved 50% on this course because your child is enrolled in multiple courses this season.',
            'Same child, multiple courses in same season',
            '50% Same Season Course Discount',
            'You saved 30% on this course for your third (or additional) child enrolled in courses.',
            'Third or additional child course discount',
            '30% Multi-Child Course Discount',
            'You saved 20% on this course because you have multiple children enrolled in courses.',
            'Second child course discount',
            '20% Course Sibling Discount',
            'You saved 25% on this camp for your third (or additional) child enrolled in camps.',
            'Third or additional child camp discount',
            '25% Multi-Child Discount Applied',
            'You saved 20% on this camp because you have multiple children enrolled in camps.',
            'Second child camp discount',
            '20% Sibling Discount Applied',
            '50% Same Season Course Discount',
            '%s Camp Sibling Discount',
            '%s Course Sibling Discount',
            'Coach',
            'Organizer',
            'Manage Players',
        ];

        foreach ($strings as $string) {
            icl_register_string('intersoccer-product-variations', $string, $string);
        }

        // Register attribute labels for translation
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        foreach ($attribute_taxonomies as $tax) {
            icl_register_string('intersoccer-product-variations', $tax->attribute_name, $tax->attribute_label);
        }
    }
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
    'includes/woocommerce/late-pickup-settings.php',
    'includes/woocommerce/late-pickup.php',
    'fix-course-holidays.php',
];

foreach ($includes as $file) {
    if (file_exists(INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file)) {
        require_once INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR . $file;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Included ' . $file);
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Failed to include ' . $file . ' - File not found');
        }
    }
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    $nonce = wp_create_nonce('intersoccer_nonce');
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Generated nonce for intersoccer_nonce: ' . $nonce);
    }

    if (is_product()) {
        wp_enqueue_script(
            'intersoccer-variation-details',
            INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_URL . 'js/variation-details.js',
            ['jquery'],
            '1.4.54',
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
        '1.4.54'
    );
});

// Register activation hook
register_activation_hook(__FILE__, function () {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Plugin activated');
    }
    // if (!wp_next_scheduled('intersoccer_expire_products')) {
    //     wp_schedule_event(time(), 'daily', 'intersoccer_expire_products');
    // }
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function () {
    // wp_clear_scheduled_hook('intersoccer_expire_products');
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Plugin deactivated');
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

// Add custom roles
add_action('init', function () {
    add_role('coach', __('Coach', 'intersoccer-product-variations'), ['read' => true, 'edit_posts' => true]);
    add_role('organizer', __('Organizer', 'intersoccer-product-variations'), ['read' => true, 'edit_posts' => true]);
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
    }
});

// Increase AJAX variation threshold
add_filter('woocommerce_ajax_variation_threshold', function ($qty, $product) {
    return 500;
}, 10, 2);

// Show variation price
add_filter('woocommerce_show_variation_price', function () {
    return true;
});

// Found Variation Show Start and End dates
add_filter('woocommerce_available_variation', function($data, $product, $variation) {
    $product_type = intersoccer_get_product_type($variation->get_parent_id() ?: $variation->get_id());
    if ($product_type === 'course') {
        $variation_id = $variation->get_id();
        $course_start_date = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
        $data['course_start_date'] = $course_start_date ? date_i18n('F j, Y', strtotime($course_start_date)) : '';
        $end_date = get_post_meta($variation_id, '_end_date', true);
        $total_weeks = (int) intersoccer_get_course_meta($variation_id, '_course_total_weeks', 0);
        $holidays = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
        $parent_id = $variation->get_parent_id() ?: $variation_id;
        $course_day_slug = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'slugs'])[0] ?? 'monday';
        $day_map = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
        $course_day_num = $day_map[$course_day_slug] ?? 1;

        // Calculate end date if not set
        if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || !strtotime($end_date)) {
            if ($data['course_start_date'] && $total_weeks > 0) {
                $start = new DateTime($course_start_date);
                $holiday_set = array_flip($holidays);
                $sessions_needed = $total_weeks;
                $current_date = clone $start;
                $weeks_counted = 0;
                $days_checked = 0;
                while ($weeks_counted < $sessions_needed && $days_checked < ($total_weeks * 7 * 2)) {
                    if ($current_date->format('N') == $course_day_num && !isset($holiday_set[$current_date->format('Y-m-d')])) {
                        $weeks_counted++;
                    }
                    $current_date->add(new DateInterval('P1D'));
                    $days_checked++;
                }
                $end_date = $current_date->sub(new DateInterval('P1D'))->format('Y-m-d');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Calculated end_date for variation ' . $variation_id . ': ' . $end_date);
                }
            } else {
                $end_date = '';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer: Cannot calculate end_date for variation ' . $variation_id . ': missing start_date or total_weeks');
                }
            }
        }
        $data['end_date'] = $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '';
        
        $data['course_holiday_dates'] = array_map(function($date) {
            return date_i18n('F j, Y', strtotime($date));
        }, $holidays);
        $data['remaining_sessions'] = calculate_remaining_sessions($variation_id, $total_weeks);
        $data['discount_note'] = calculate_discount_note($variation_id, $data['remaining_sessions']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Variation ' . $variation_id . ' data: start=' . $data['course_start_date'] . ', end=' . $data['end_date'] . ', holidays=' . json_encode($data['course_holiday_dates']) . ', sessions=' . $data['remaining_sessions'] . ', discount=' . $data['discount_note']);
        }
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
        $discount_note = intersoccer_get_discount_message('course_same_season', 'cart_message', intersoccer_translate_string('50% Same Season Course Discount', 'intersoccer-product-variations', '50% Same Season Course Discount'));
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Calculated discount_note for variation ' . $variation_id . ': ' . $discount_note);
    }
    return $discount_note;
}
?>