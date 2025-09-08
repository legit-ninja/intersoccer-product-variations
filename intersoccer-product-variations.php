<?php
/**
 * Plugin Name: InterSoccer Product Variations
 * Description: Custom plugin for InterSoccer Switzerland to manage events and bookings.
 * Version: 1.4.54
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
    error_log('InterSoccer: Plugin activated');
    if (!wp_next_scheduled('intersoccer_expire_products')) {
        wp_schedule_event(time(), 'daily', 'intersoccer_expire_products');
    }
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('intersoccer_expire_products');
    error_log('InterSoccer: Plugin deactivated, cleared expire_products cron');
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
    add_role('coach', __('Coach', 'intersoccer-player-management'), ['read' => true, 'edit_posts' => true]);
    add_role('organizer', __('Organizer', 'intersoccer-player-management'), ['read' => true, 'edit_posts' => true]);
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
add_filter('woocommerce_ajax_variation_threshold', function ($qty, $product) {
    return 500;
}, 10, 2);

// Show variation price
add_filter('woocommerce_show_variation_price', function () {
    return true;
});

// Found Variation Show Start and End dates
add_filter('woocommerce_available_variation', function ($data, $product, $variation) {
    $product_type = intersoccer_get_product_type($variation->get_parent_id() ?: $variation->get_id());
    if ($product_type === 'course') {
        $variation_id = $variation->get_id();
        $data['course_start_date'] = get_post_meta($variation_id, '_course_start_date', true) ? date_i18n('F j, Y', strtotime(get_post_meta($variation_id, '_course_start_date', true))) : '';
        global $wpdb;
        $end_date = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_end_date' ORDER BY meta_id DESC LIMIT 1",
            $variation_id
        ));
        $total_weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
        $holidays = get_post_meta($variation_id, '_course_holiday_dates', true) ?: [];
        $parent_id = $variation->get_parent_id() ?: $variation_id;
        $course_day = wc_get_product_terms($parent_id, 'pa_course-day', ['fields' => 'names'])[0] ?? 'Monday';

        if ($variation_id == 25721 || $variation_id == 29665) {
            $stock_status = get_post_meta($variation_id, '_stock_status', true) ?: 'default_instock';
            $var_status = get_post_status($variation_id);
            error_log('InterSoccer: Variation ' . $variation_id . ' - Status: ' . $var_status . ', End date: ' . ($end_date ?: 'missing') . ', Start date: ' . $data['course_start_date'] . ', Total weeks: ' . $total_weeks . ', Holidays: ' . json_encode($holidays) . ', Stock status: ' . $stock_status);
        }

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
                update_post_meta($variation_id, '_end_date', $end_date);
                error_log('InterSoccer: Calculated and saved end_date for variation ' . $variation_id . ': ' . $end_date);
            } else {
                $end_date = '';
                error_log('InterSoccer: Cannot calculate end_date for variation ' . $variation_id . ': missing start_date or total_weeks');
            }
        }
        $data['end_date'] = $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '';
        $data['course_holiday_dates'] = array_map(function ($date) {
            return date_i18n('F j, Y', strtotime($date));
        }, $holidays);
        $data['remaining_sessions'] = calculate_remaining_sessions($variation_id, $total_weeks);
        $data['discount_note'] = calculate_discount_note($variation_id, $data['remaining_sessions']);
        error_log('Variation ' . $variation_id . ' data: start=' . $data['course_start_date'] . ', end=' . $data['end_date'] . ', holidays=' . json_encode($data['course_holiday_dates']) . ', sessions=' . $data['remaining_sessions'] . ', discount=' . $data['discount_note']);
    }
    return $data;
}, 10, 3);

// Helper function to update stock and post status
function intersoccer_update_product_status($product_id, $stock_status, $post_status) {
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    // Update parent post status
    $updated = wp_update_post([
        'ID' => $product_id,
        'post_status' => $post_status,
    ]);
    if (is_wp_error($updated)) {
        error_log('InterSoccer: Error updating post_status for ' . $product_id . ': ' . $updated->get_error_message());
    }

    // Update parent stock (handle NULL default)
    $current_stock = get_post_meta($product_id, '_stock_status', true);
    if ($current_stock !== $stock_status) {
        update_post_meta($product_id, '_stock_status', $stock_status);
    }
    update_post_meta($product_id, '_stock', $stock_status === 'outofstock' ? 0 : '');
    error_log('InterSoccer: Updated product ' . $product_id . ' to post_status=' . $post_status . ', stock_status=' . $stock_status . ' (was ' . $current_stock . ')');

    // Update variations
    foreach ($product->get_available_variations() as $variation) {
        $variation_id = $variation['variation_id'];
        $var_updated = wp_update_post([
            'ID' => $variation_id,
            'post_status' => $post_status,
        ]);
        if (is_wp_error($var_updated)) {
            error_log('InterSoccer: Error updating variation status for ' . $variation_id . ': ' . $var_updated->get_error_message());
        }
        $current_var_stock = get_post_meta($variation_id, '_stock_status', true);
        if ($current_var_stock !== $stock_status) {
            update_post_meta($variation_id, '_stock_status', $stock_status);
        }
        update_post_meta($variation_id, '_stock', $stock_status === 'outofstock' ? 0 : '');
        if ($variation_id == 25721 || $variation_id == 29665) {
            error_log('InterSoccer: Updated variation ' . $variation_id . ' to post_status=' . $post_status . ', stock_status=' . $stock_status . ' (was ' . $current_var_stock . ')');
        }
    }

    // WPML sync
    if (defined('ICL_SITEPRESS_VERSION')) {
        global $sitepress;
        $trid = $sitepress->get_element_trid($product_id, 'post_product');
        $translations = $sitepress->get_element_translations($trid, 'post_product');
        foreach ($translations as $translation) {
            if ($translation->element_id != $product_id) {
                wp_update_post([
                    'ID' => $translation->element_id,
                    'post_status' => $post_status,
                ]);
                $trans_stock = get_post_meta($translation->element_id, '_stock_status', true);
                if ($trans_stock !== $stock_status) {
                    update_post_meta($translation->element_id, '_stock_status', $stock_status);
                }
                update_post_meta($translation->element_id, '_stock', $stock_status === 'outofstock' ? 0 : '');
                error_log('InterSoccer: Synced translation product ' . $translation->element_id . ' to post_status=' . $post_status . ', stock_status=' . $stock_status . ' (was ' . $trans_stock . ')');
            }
        }
    }
}

// Reuse hook: Reset stock and post status on attribute/meta changes
add_action('woocommerce_update_product_variation', 'intersoccer_reset_status_on_attribute_change', 10, 2);
add_action('updated_post_meta', 'intersoccer_reset_status_on_meta_change', 10, 4);
function intersoccer_reset_status_on_attribute_change($variation_id, $variation) {
    $parent_id = $variation->get_parent_id();
    if (!$parent_id) return;

    $product_type = intersoccer_get_product_type($parent_id);
    if ($product_type !== 'course' && $product_type !== 'camp') return;

    $old_camp_terms = get_post_meta($parent_id, '_old_camp_terms', true);
    $new_camp_terms = wc_get_product_terms($parent_id, 'pa_camp-terms', ['fields' => 'names'])[0] ?? '';
    $old_start_date = get_post_meta($variation_id, '_old_course_start_date', true);
    $new_start_date = get_post_meta($variation_id, '_course_start_date', true);

    if ($product_type === 'camp' && $old_camp_terms && $old_camp_terms !== $new_camp_terms) {
        intersoccer_update_product_status($parent_id, 'instock', 'publish');
        delete_post_meta($parent_id, '_old_camp_terms');
        error_log('InterSoccer Reuse: Reset product ' . $parent_id . ' to publish/instock due to camp-terms change from ' . $old_camp_terms . ' to ' . $new_camp_terms);
    }
    if ($product_type === 'course' && $old_start_date && $old_start_date !== $new_start_date) {
        intersoccer_update_product_status($parent_id, 'instock', 'publish');
        delete_post_meta($variation_id, '_old_course_start_date');
        error_log('InterSoccer Reuse: Reset product ' . $parent_id . ' to publish/instock due to course start_date change from ' . $old_start_date . ' to ' . $new_start_date);
    }
}

function intersoccer_reset_status_on_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key === 'pa_camp-terms' || $meta_key === '_course_start_date') {
        $product_type = intersoccer_get_product_type($post_id);
        if ($product_type === 'course' || $product_type === 'camp') {
            update_post_meta($post_id, '_old_' . str_replace('pa_', '', $meta_key), $meta_value);
            error_log('InterSoccer: Stored old ' . $meta_key . ' for product ' . $post_id . ' for reuse check');
        }
    }
}

// Auto-expire products based on end date
add_filter('woocommerce_product_query', function ($query) {
    if (is_admin() || !$query->is_main_query()) {
        return $query;
    }

    $today = current_time('Y-m-d');

    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => ['variable'],
            ],
        ],
        'post_status' => ['publish', 'draft'], // Include drafts
    ];
    $product_ids = get_posts($args);

    foreach ($product_ids as $product_id) {
        $prod_status = get_post_status($product_id);
        if ($product_id == 25217 || $product_id == 25216) {
            error_log('InterSoccer: Processing product ' . $product_id . ' (Status: ' . $prod_status . ') for expiration');
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            continue;
        }

        $product_type = intersoccer_get_product_type($product_id);
        if (empty($product_type)) {
            error_log('InterSoccer: Skipping product ' . $product_id . ' due to empty type (taxonomy issue in status: ' . $prod_status . ')');
            continue;
        }
        $all_variations_expired = true;

        foreach ($product->get_available_variations() as $variation) {
            $variation_id = $variation['variation_id'];
            $end_date = '';

            if ($variation_id == 29665 || $variation_id == 25721) {
                $var_status = get_post_status($variation_id);
                error_log('InterSoccer: Checking expiration for variation ' . $variation_id . ' (Product ' . $product_id . ', Type: ' . $product_type . ', Var Status: ' . $var_status . ')');
            }

            if ($product_type === 'course') {
                global $wpdb;
                $end_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_end_date' ORDER BY meta_id DESC LIMIT 1",
                    $variation_id
                ));
                if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                    error_log('InterSoccer: Invalid or missing end_date for course variation ' . $variation_id . ' (Status: ' . get_post_status($variation_id) . ')');
                    $all_variations_expired = false;
                    continue;
                }
            } elseif ($product_type === 'camp') {
                $cache_key = 'intersoccer_camp_end_date_' . $product_id;
                $end_date = get_transient($cache_key);
                if ($end_date === false) {
                    $camp_terms = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names']);
                    error_log('InterSoccer: All camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '): ' . json_encode($camp_terms));
                    if (empty($camp_terms)) {
                        error_log('InterSoccer: No camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '), treating as non-expired');
                        $all_variations_expired = false;
                        $end_date = 'future'; // Cache non-expired
                    } else {
                        $max_end_date = '';
                        foreach ($camp_terms as $term) {
                            error_log('InterSoccer: Parsing camp term "' . $term . '" for product ' . $product_id . ' (Status: ' . $prod_status . ')');
                            if (preg_match('/([A-Za-z]+ \d{1,2})(?:\s*-\s*([A-Za-z]+\s*\d{1,2}))?(?:\s*\(\d+ days\))?$/i', $term, $matches)) {
                                $end_str = !empty($matches[2]) ? trim($matches[2]) : trim($matches[1]);
                                if (strpos($end_str, '-') !== false) {
                                    $parts = explode('-', $end_str);
                                    $month = trim($parts[0]);
                                    $day = trim($parts[1]);
                                    $end_str = $month . ' ' . $day;
                                }
                                $year = date('Y');
                                $dt = DateTime::createFromFormat('F j Y', $end_str . ' ' . $year);
                                if ($dt && $dt->format('Y-m-d') !== false) {
                                    $parsed_date = $dt->format('Y-m-d');
                                    if (strtotime($parsed_date) > strtotime($max_end_date ?: '1970-01-01')) {
                                        $max_end_date = $parsed_date;
                                    }
                                    error_log('InterSoccer: Parsed end_date ' . $parsed_date . ' from term "' . $term . '"');
                                } else {
                                    error_log('InterSoccer: DateTime parse failed for "' . $end_str . '" from term "' . $term . '"');
                                }
                            } else {
                                error_log('InterSoccer: Regex parse failed for term "' . $term . '"');
                            }
                        }

                        $end_date = $max_end_date;
                        if (!$end_date) {
                            error_log('InterSoccer: No valid end_date parsed from any camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '), treating as non-expired');
                            $all_variations_expired = false;
                            $end_date = 'future';
                        } else {
                            error_log('InterSoccer: Max end_date for product ' . $product_id . ' (Status: ' . $prod_status . '): ' . $end_date);
                        }
                    }
                    set_transient($cache_key, $end_date, HOUR_IN_SECONDS); // Cache for 1 hour
                } else {
                    error_log('InterSoccer: Cached end_date for product ' . $product_id . ': ' . $end_date);
                }
                continue; // Use cached $end_date for variation
            }

            if ($variation_id == 29665 || $variation_id == 25721) {
                error_log('InterSoccer: Variation ' . $variation_id . ' end_date: ' . ($end_date ?: 'none') . ', Today: ' . $today . ', Expired: ' . (strtotime($end_date) < strtotime($today) ? 'yes' : 'no'));
            }

            if ($end_date && strtotime($end_date) < strtotime($today)) {
                error_log('InterSoccer: Variation ' . $variation_id . ' expired (end_date: ' . $end_date . ')');
            } else {
                $all_variations_expired = false;
                error_log('InterSoccer: Variation ' . $variation_id . ' active (end_date: ' . $end_date . ' >= today)');
            }
        }

        if ($all_variations_expired) {
            intersoccer_update_product_status($product_id, 'outofstock', 'draft');
            error_log('InterSoccer: Product ' . $product_id . ' (was ' . $prod_status . ') set to draft/out-of-stock (all variations expired)');
        } else {
            intersoccer_update_product_status($product_id, 'instock', 'publish');
            error_log('InterSoccer: Product ' . $product_id . ' (was ' . $prod_status . ') remains publish/in-stock (active variations found)');
        }
    }

    // Exclude out-of-stock and draft from frontend
    $meta_query = $query->get('meta_query') ?: [];
    $meta_query[] = [
        'key' => '_stock_status',
        'value' => 'instock',
        'compare' => '=',
    ];
    $query->set('meta_query', $meta_query);
    $query->set('post_status', 'publish'); // Frontend only published

    return $query;
}, 10, 1);

// Cron job (mirror, include drafts, with caching)
add_action('intersoccer_expire_products', function () {
    $today = current_time('Y-m-d');
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => ['variable'],
            ],
        ],
        'post_status' => ['publish', 'draft'],
    ];
    $product_ids = get_posts($args);

    foreach ($product_ids as $product_id) {
        $prod_status = get_post_status($product_id);
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            continue;
        }

        $product_type = intersoccer_get_product_type($product_id);
        if (empty($product_type)) {
            error_log('InterSoccer Cron: Skipping product ' . $product_id . ' due to empty type (taxonomy issue in status: ' . $prod_status . ')');
            continue;
        }
        $all_variations_expired = true;

        foreach ($product->get_available_variations() as $variation) {
            $variation_id = $variation['variation_id'];
            $end_date = '';

            if ($variation_id == 29665 || $variation_id == 25721) {
                $var_status = get_post_status($variation_id);
                error_log('InterSoccer Cron: Checking expiration for variation ' . $variation_id . ' (Product ' . $product_id . ', Type: ' . $product_type . ', Var Status: ' . $var_status . ')');
            }

            if ($product_type === 'course') {
                global $wpdb;
                $end_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_end_date' ORDER BY meta_id DESC LIMIT 1",
                    $variation_id
                ));
                if (!$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                    error_log('InterSoccer Cron: Invalid or missing end_date for course variation ' . $variation_id . ' (Status: ' . get_post_status($variation_id) . ')');
                    $all_variations_expired = false;
                    continue;
                }
            } elseif ($product_type === 'camp') {
                $cache_key = 'intersoccer_camp_end_date_' . $product_id;
                $end_date = get_transient($cache_key);
                if ($end_date === false) {
                    $camp_terms = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names']);
                    error_log('InterSoccer Cron: All camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '): ' . json_encode($camp_terms));
                    if (empty($camp_terms)) {
                        error_log('InterSoccer Cron: No camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '), treating as non-expired');
                        $all_variations_expired = false;
                        $end_date = 'future';
                    } else {
                        $max_end_date = '';
                        foreach ($camp_terms as $term) {
                            error_log('InterSoccer Cron: Parsing camp term "' . $term . '" for product ' . $product_id . ' (Status: ' . $prod_status . ')');
                            if (preg_match('/([A-Za-z]+ \d{1,2})(?:\s*-\s*([A-Za-z]+\s*\d{1,2}))?(?:\s*\(\d+ days\))?$/i', $term, $matches)) {
                                $end_str = !empty($matches[2]) ? trim($matches[2]) : trim($matches[1]);
                                if (strpos($end_str, '-') !== false) {
                                    $parts = explode('-', $end_str);
                                    $month = trim($parts[0]);
                                    $day = trim($parts[1]);
                                    $end_str = $month . ' ' . $day;
                                }
                                $year = date('Y');
                                $dt = DateTime::createFromFormat('F j Y', $end_str . ' ' . $year);
                                if ($dt && $dt->format('Y-m-d') !== false) {
                                    $parsed_date = $dt->format('Y-m-d');
                                    if (strtotime($parsed_date) > strtotime($max_end_date ?: '1970-01-01')) {
                                        $max_end_date = $parsed_date;
                                    }
                                    error_log('InterSoccer Cron: Parsed end_date ' . $parsed_date . ' from term "' . $term . '"');
                                } else {
                                    error_log('InterSoccer Cron: DateTime parse failed for "' . $end_str . '" from term "' . $term . '"');
                                }
                            } else {
                                error_log('InterSoccer Cron: Regex parse failed for term "' . $term . '"');
                            }
                        }

                        $end_date = $max_end_date;
                        if (!$end_date) {
                            error_log('InterSoccer Cron: No valid end_date parsed from any camp terms for product ' . $product_id . ' (Status: ' . $prod_status . '), treating as non-expired');
                            $all_variations_expired = false;
                            $end_date = 'future';
                        } else {
                            error_log('InterSoccer Cron: Max end_date for product ' . $product_id . ' (Status: ' . $prod_status . '): ' . $end_date);
                        }
                    }
                    set_transient($cache_key, $end_date, DAY_IN_SECONDS); // Cache for 1 day in cron
                } else {
                    error_log('InterSoccer Cron: Cached end_date for product ' . $product_id . ': ' . $end_date);
                }
                continue;
            }

            if ($variation_id == 29665 || $variation_id == 25721) {
                error_log('InterSoccer Cron: Variation ' . $variation_id . ' end_date: ' . ($end_date ?: 'none') . ', Today: ' . $today . ', Expired: ' . (strtotime($end_date) < strtotime($today) ? 'yes' : 'no'));
            }

            if ($end_date && strtotime($end_date) < strtotime($today)) {
                error_log('InterSoccer Cron: Variation ' . $variation_id . ' expired (end_date: ' . $end_date . ')');
            } else {
                $all_variations_expired = false;
                error_log('InterSoccer Cron: Variation ' . $variation_id . ' active (end_date: ' . $end_date . ' >= today)');
            }
        }

        if ($all_variations_expired) {
            intersoccer_update_product_status($product_id, 'outofstock', 'draft');
            error_log('InterSoccer Cron: Product ' . $product_id . ' (was ' . $prod_status . ') set to draft/out-of-stock (all variations expired)');
        } else {
            intersoccer_update_product_status($product_id, 'instock', 'publish');
            error_log('InterSoccer Cron: Product ' . $product_id . ' (was ' . $prod_status . ') remains publish/in-stock (active variations found)');
        }
    }
});

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