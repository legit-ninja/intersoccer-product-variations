<?php
/**
 * File: product-types.php
 * Description: Centralizes product type detection and utilities for InterSoccer WooCommerce products.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class to handle product type detection and related utilities.
 */
class InterSoccer_Product_Types {

    /**
     * Get product type for a given product ID.
     *
     * @param int $product_id The product ID.
     * @return string Product type ('camp', 'course', 'birthday', or '').
     */
    public static function get_product_type($product_id) {
        if (!$product_id) {
            error_log('InterSoccer: Invalid product ID in get_product_type: ' . $product_id);
            return '';
        }

        // Check transient cache
        $transient_key = 'intersoccer_type_' . $product_id;
        $cached_type = get_transient($transient_key);
        if (false !== $cached_type) {
            error_log('InterSoccer: Product type cache hit for product ' . $product_id . ': ' . $cached_type);
            return $cached_type;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            error_log('InterSoccer: Invalid product object for ID: ' . $product_id);
            return '';
        }

        // Check existing meta
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type) {
            error_log('InterSoccer: Product type from meta for product ' . $product_id . ': ' . $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        // Check attribute pa_activity-type
        $product_type = self::detect_type_from_attribute($product);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            error_log('InterSoccer: Detected product type from attribute for product ' . $product_id . ': ' . $product_type);
            return $product_type;
        }

        // Fallback to categories
        $product_type = self::detect_type_from_category($product_id);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            error_log('InterSoccer: Detected product type from category for product ' . $product_id . ': ' . $product_type);
            return $product_type;
        }

        error_log('InterSoccer: Could not determine product type for product ' . $product_id);
        set_transient($transient_key, '', HOUR_IN_SECONDS);
        return '';
    }

    /**
     * Detect product type from pa_activity-type attribute.
     *
     * @param WC_Product $product The product object.
     * @return string Product type or empty string.
     */
    private static function detect_type_from_attribute($product) {
        $attributes = $product->get_attributes();
        if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
            $attribute = $attributes['pa_activity-type'];
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (in_array($term->slug, ['camp', 'course', 'birthday'], true)) {
                            error_log('InterSoccer: Found type from pa_activity-type: ' . $term->slug);
                            return $term->slug;
                        }
                    }
                } else {
                    error_log('InterSoccer: No terms found for pa_activity-type attribute for product ' . $product->get_id());
                }
            } else {
                error_log('InterSoccer: pa_activity-type attribute is not a taxonomy for product ' . $product->get_id());
            }
        }
        return '';
    }

    /**
     * Detect product type from categories.
     *
     * @param int $product_id The product ID.
     * @return string Product type or empty string.
     */
    private static function detect_type_from_category($product_id) {
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        if (is_wp_error($categories)) {
            error_log('InterSoccer: Error fetching categories for product ' . $product_id . ': ' . $categories->get_error_message());
            return '';
        }

        if (in_array('camps', $categories, true)) {
            return 'camp';
        } elseif (in_array('courses', $categories, true)) {
            return 'course';
        } elseif (in_array('birthdays', $categories, true)) {
            return 'birthday';
        }
        return '';
    }

    /**
     * Update product type meta on save.
     *
     * @param int $product_id The product ID.
     */
    public static function update_product_type_on_save($product_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $product_id)) {
            return;
        }

        $product_type = self::get_product_type($product_id);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            $transient_key = 'intersoccer_type_' . $product_id;
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            error_log('InterSoccer: Updated product type meta for product ' . $product_id . ': ' . $product_type);
        }
    }

    /**
     * Display event CPT data in cart.
     *
     * @param array $item_data Cart item data.
     * @param array $cart_item Cart item.
     * @return array Updated item data.
     */
    public static function display_event_cpt_data($item_data, $cart_item) {
        $product_id = $cart_item['product_id'];
        $post_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($post_type && in_array($post_type, ['camp', 'course', 'birthday'])) {
            $event = get_posts([
                'post_type' => $post_type,
                'meta_key' => '_product_id',
                'meta_value' => $product_id,
                'posts_per_page' => 1,
            ]);
            if ($event) {
                $item_data[] = [
                    'key' => __($post_type === 'camp' ? 'Camp' : ($post_type === 'course' ? 'Course' : 'Birthday'), 'intersoccer-player-management'),
                    'value' => esc_html($event[0]->post_title),
                ];
                error_log('InterSoccer: Added CPT data to cart for product ' . $product_id . ': ' . $event[0]->post_title);
            }
        }
        return $item_data;
    }
}

/**
 * Hook to update product type on save.
 */
add_action('save_post_product', ['InterSoccer_Product_Types', 'update_product_type_on_save'], 10, 1);

/**
 * Hook to display event CPT data in cart.
 */
add_filter('woocommerce_get_item_data', ['InterSoccer_Product_Types', 'display_event_cpt_data'], 320, 2);

/**
 * One-time script to update existing product types.
 */
add_action('init', 'intersoccer_update_existing_product_types');
function intersoccer_update_existing_product_types() {
    $flag = 'intersoccer_product_type_update_20250806';
    if (get_option($flag, false)) {
        return;
    }

    wp_schedule_single_event(time(), 'intersoccer_run_product_type_update');
    update_option($flag, true);
    error_log('InterSoccer: Scheduled one-time product type update');
}

/**
 * Action to run product type update.
 */
add_action('intersoccer_run_product_type_update', 'intersoccer_run_product_type_update_callback');
function intersoccer_run_product_type_update_callback() {
    $products = wc_get_products(['limit' => -1]);
    foreach ($products as $product) {
        $product_id = $product->get_id();
        InterSoccer_Product_Types::update_product_type_on_save($product_id);
    }
    error_log('InterSoccer: Completed one-time product type meta update for all products');
}
?>