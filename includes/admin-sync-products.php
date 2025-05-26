<?php
/**
 * Admin Sync Products to Events
 * Changes (Updated):
 * - Ensured sync-product-to-event.php is included only when needed.
 * - Reinforced intersoccer_sync_product_to_event() calls with valid product objects.
 * - Fixed include error for sync-product-render.php with error handling.
 * - Replaced invalid wc_get_product_term() with wc_get_product_terms().
 * - Deferred WooCommerce product queries to init hook to prevent early text domain loading.
 * - Added pagination, AJAX handlers, and filters for product categories/attributes.
 * - Integrated CSV-derived attributes from wc-product-export-3-5-2025.csv.
 * - Added capacity alerts based on 2024 data from FINAL Summer Camps Numbers 2024.xlsx.
 * Testing:
 * - Navigate to Players & Orders > Sync Products, verify pagination (20 products/page).
 * - Apply filters for category and attributes, ensure correct product filtering.
 * - Click Quick Edit, confirm form loads without errors.
 * - Test bulk sync, confirm events are created in The Events Calendar.
 * - Ensure variations for a product, verify new variations appear in WooCommerce.
 * - Check capacity alerts for high-attendance camps (e.g., Nyon Week 4).
 * - Verify no translation loading notices or include errors in server logs.
 * - Ensure no fatal errors from sync-product-to-event.php inclusion.
 */

defined('ABSPATH') or die('No script kiddies please!');

add_action('init', 'intersoccer_register_sync_products_actions');
function intersoccer_register_sync_products_actions() {
    require_once plugin_dir_path(__FILE__) . 'sync-product-to-event.php';
}

function intersoccer_render_sync_products_tab() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-player-management'));
    }

    wp_enqueue_script('intersoccer-sync-products', plugin_dir_url(__FILE__) . '../js/sync-products.js', ['jquery'], '1.3', true);
    wp_localize_script('intersoccer-sync-products', 'intersoccerSync', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('intersoccer_sync_products'),
    ]);

    $products_per_page = 20;
    $current_page = max(1, absint($_GET['paged'] ?? 1));
    $offset = ($current_page - 1) * $products_per_page;
    $filters = [
        'category' => absint($_GET['product_cat'] ?? 0),
        'booking_type' => sanitize_text_field($_GET['booking_type'] ?? ''),
        'venue' => sanitize_text_field($_GET['venue'] ?? ''),
    ];

    $args = [
        'limit' => $products_per_page,
        'offset' => $offset,
        'status' => 'publish',
        'type' => ['simple', 'variable'],
    ];
    if ($filters['category']) {
        $args['category'] = [$filters['category']];
    }
    if ($filters['booking_type'] || $filters['venue']) {
        $args['tax_query'] = [
            'relation' => 'AND',
        ];
        if ($filters['booking_type']) {
            $args['tax_query'][] = [
                'taxonomy' => 'pa_booking-type',
                'field' => 'slug',
                'terms' => $filters['booking_type'],
            ];
        }
        if ($filters['venue']) {
            $args['tax_query'][] = [
                'taxonomy' => 'pa_intersoccer-venues',
                'field' => 'slug',
                'terms' => $filters['venue'],
            ];
        }
    }

    $products = wc_get_products($args);
    $total_products = count(wc_get_products(['limit' => -1, 'status' => 'publish']));
    $total_pages = ceil($total_products / $products_per_page);

    $capacity_limits = [
        'Summer Week 4: July 14-18 (5 days) - Nyon' => 52,
        // From FINAL Summer Camps Numbers 2024.xlsx
    ];

    $render_file = plugin_dir_path(__FILE__) . 'sync-product-render.php';
    if (!file_exists($render_file)) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Error: sync-product-render.php is missing.', 'intersoccer-player-management') . '</p></div>';
    }

    ?>
    <div class="wrap">
        <h1><?php _e('Sync Products to Events', 'intersoccer-player-management'); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="intersoccer-player-management">
            <input type="hidden" name="tab" value="sync-products">
            <p>
                <label for="product_cat"><?php _e('Category:', 'intersoccer-player-management'); ?></label>
                <select id="product_cat" name="product_cat">
                    <option value=""><?php _e('All Categories', 'intersoccer-player-management'); ?></option>
                    <?php foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]) as $cat): ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($filters['category'], $cat->term_id); ?>><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="booking_type"><?php _e('Booking Type:', 'intersoccer-player-management'); ?></label>
                <select id="booking_type" name="booking_type">
                    <option value=""><?php _e('All Booking Types', 'intersoccer-player-management'); ?></option>
                    <?php foreach (get_terms(['taxonomy' => 'pa_booking-type', 'hide_empty' => false]) as $term): ?>
                        <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($filters['booking_type'], $term->slug); ?>><?php echo esc_html($term->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="venue"><?php _e('Venue:', 'intersoccer-player-management'); ?></label>
                <select id="venue" name="venue">
                    <option value=""><?php _e('All Venues', 'intersoccer-player-management'); ?></option>
                    <?php foreach (get_terms(['taxonomy' => 'pa_intersoccer-venues', 'hide_empty' => false]) as $venue): ?>
                        <option value="<?php echo esc_attr($venue->slug); ?>" <?php selected($filters['venue'], $venue->slug); ?>><?php echo esc_html($venue->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <input type="submit" class="button" value="<?php _e('Filter', 'intersoccer-player-management'); ?>">
            </p>
        </form>
        <form method="post">
            <?php wp_nonce_field('intersoccer_bulk_sync', 'bulk_sync_nonce'); ?>
            <div class="tablenav top">
                <input type="checkbox" id="select-all-products">
                <input type="submit" name="bulk_sync" class="button" value="<?php _e('Sync Selected', 'intersoccer-player-management'); ?>">
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-products-table"></th>
                        <th><?php _e('Product Name', 'intersoccer-player-management'); ?></th>
                        <th><?php _e('Type', 'intersoccer-player-management'); ?></th>
                        <th><?php _e('Synced Event', 'intersoccer-player-management'); ?></th>
                        <th><?php _e('Actions', 'intersoccer-player-management'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $event_id = get_post_meta($product->get_id(), '_tribe_event_id', true);
                        $event_title = $event_id ? get_the_title($event_id) : __('Not Synced', 'intersoccer-player-management');
                        $capacity_alert = '';
                        $terms = wc_get_product_terms($product->get_id(), 'pa_camp-terms', ['fields' => 'names']);
                        $venue_terms = wc_get_product_terms($product->get_id(), 'pa_intersoccer-venues', ['fields' => 'names']);
                        $venue = !empty($venue_terms) ? $venue_terms[0] : '';
                        foreach ($terms as $term) {
                            $key = $term . ' - ' . $venue;
                            if (isset($capacity_limits[$key])) {
                                $orders = wc_get_orders(['meta_key' => '_product_id', 'meta_value' => $product->get_id()]);
                                $registrations = count($orders);
                                if ($registrations >= $capacity_limits[$key]) {
                                    $capacity_alert = '<span style="color: red;">' . sprintf(__('Capacity Warning: %d/%d', 'intersoccer-player-management'), $registrations, $capacity_limits[$key]) . '</span>';
                                }
                            }
                        }
                        ?>
                        <tr data-product-id="<?php echo esc_attr($product->get_id()); ?>" data-is-variation="0">
                            <td><input type="checkbox" class="product-checkbox" name="product_ids[]" value="<?php echo esc_attr($product->get_id()); ?>"></td>
                            <td><?php echo esc_html($product->get_name()); ?> <?php echo $capacity_alert; ?></td>
                            <td><?php echo esc_html($product->get_type()); ?></td>
                            <td class="synced-event"><?php echo esc_html($event_title); ?></td>
                            <td>
                                <a href="#" class="quick-edit-link"><?php _e('Quick Edit', 'intersoccer-player-management'); ?></a> |
                                <a href="#" class="ensure-variations" data-product-id="<?php echo esc_attr($product->get_id()); ?>"><?php _e('Ensure Variations', 'intersoccer-player-management'); ?></a>
                            </td>
                        </tr>
                        <tr class="quick-edit-row" style="display: none;">
                            <td colspan="5">
                                <?php
                                if (file_exists($render_file)) {
                                    include $render_file;
                                } else {
                                    echo '<div class="error"><p>' . esc_html__('Quick Edit form unavailable.', 'intersoccer-player-management') . '</p></div>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    printf(
                        __('Showing %d-%d of %d products', 'intersoccer-player-management'),
                        $offset + 1,
                        min($offset + $products_per_page, $total_products),
                        $total_products
                    );
                    if ($total_pages > 1):
                        ?>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a class="prev-page" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1)); ?>">«</a>
                            <?php else: ?>
                                <span class="prev-page disabled">«</span>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a class="page-number" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>" <?php echo $i === $current_page ? 'class="current"' : ''; ?>><?php echo esc_html($i); ?></a>
                            <?php endfor; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <a class="next-page" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1)); ?>">»</a>
                            <?php else: ?>
                                <span class="next-page disabled">»</span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <?php
}

add_action('wp_ajax_intersoccer_save_and_sync_product', 'intersoccer_save_and_sync_product');
function intersoccer_save_and_sync_product() {
    check_ajax_referer('intersoccer_sync_products', 'nonce');
    $product_id = absint($_POST['product_id']);
    $is_variation = absint($_POST['is_variation']);
    $attributes = array_map('sanitize_text_field', $_POST['attributes'] ?? []);

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => __('Invalid product.', 'intersoccer-player-management')]);
    }

    foreach ($attributes as $attribute => $value) {
        if ($value) {
            wp_set_object_terms($product_id, $value, $attribute, false);
        }
    }

    $event_data = [
        'start_date' => $attributes['event_start_date'] ?? '',
        'end_date' => $attributes['event_end_date'] ?? '',
    ];
    $event_id = intersoccer_sync_product_to_event($product, $event_data);
    if ($event_id) {
        update_post_meta($product_id, '_tribe_event_id', $event_id);
        wp_send_json_success(['event_title' => get_the_title($event_id)]);
    } else {
        wp_send_json_error(['message' => __('Failed to sync product to event.', 'intersoccer-player-management')]);
    }
}

add_action('wp_ajax_intersoccer_ensure_variations', 'intersoccer_ensure_variations');
function intersoccer_ensure_variations() {
    check_ajax_referer('intersoccer_sync_products', 'nonce');
    $product_id = absint($_POST['product_id']);
    $product = wc_get_product($product_id);
    if (!$product || $product->get_type() !== 'variable') {
        wp_send_json_error(['message' => __('Invalid variable product.', 'intersoccer-player-management')]);
    }

    $attributes = $product->get_attributes();
    $created = [];
    $skipped = [];
    foreach ($attributes as $attribute) {
        if ($attribute->get_variation()) {
            $terms = $attribute->get_terms();
            foreach ($terms as $term) {
                $variation_data = [
                    'attributes' => [$attribute->get_name() => $term->slug],
                    'status' => 'publish',
                ];
                $existing_variations = $product->get_children();
                $variation_exists = false;
                foreach ($existing_variations as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation->get_attributes()[$attribute->get_name()] === $term->slug) {
                        $variation_exists = true;
                        $skipped[] = $term->name;
                        break;
                    }
                }
                if (!$variation_exists) {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($product_id);
                    $variation->set_attributes($variation_data['attributes']);
                    $variation->set_status($variation_data['status']);
                    $variation->save();
                    $created[] = $term->name;
                }
            }
        }
    }
    wp_send_json_success([
        'message' => __('Variations ensured.', 'intersoccer-player-management'),
        'created' => $created,
        'skipped' => $skipped,
    ]);
}
?>
