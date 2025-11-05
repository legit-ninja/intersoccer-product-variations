<?php
/**
 * File: product-types.php
 * Description: Handles product type detection and core functionality for InterSoccer events
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Product Types Handler
 * 
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main product type detection function - delegates to class method
 * @param int $product_id
 * @return string|null 'camp', 'course', 'birthday', 'tournament', or null
 */
if (!function_exists('intersoccer_get_product_type')) {
function intersoccer_get_product_type($product_id) {
    return InterSoccer_Product_Types::get_product_type($product_id);
    }
}

/**
 * Class to handle product type detection and related utilities.
 */
if (!class_exists('InterSoccer_Product_Types')) {
class InterSoccer_Product_Types {

    /**
     * Get product type for a given product ID.
     *
     * @param int $product_id The product ID.
     * @return string|null Product type ('camp', 'course', 'birthday', 'tournament', or null).
     */
    public static function get_product_type($product_id) {
        if (!$product_id) {
            return null;
        }

        // Check transient cache first
        $transient_key = 'intersoccer_type_' . $product_id;
        $cached_type = get_transient($transient_key);
        if (false !== $cached_type) {
            return $cached_type ?: null;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            set_transient($transient_key, '', HOUR_IN_SECONDS);
            return null;
        }

        // Check existing meta first
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type && in_array($product_type, ['camp', 'course', 'birthday', 'tournament'])) {
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        // Detect from pa_activity-type attribute
        $product_type = self::detect_type_from_attribute($product);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        // Fallback to categories
        $product_type = self::detect_type_from_category($product_id);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        set_transient($transient_key, '', HOUR_IN_SECONDS);
        return null;
    }

    /**
     * Detect product type from pa_activity-type attribute.
     *
     * @param WC_Product $product The product object.
     * @return string|null Product type or null.
     */
    private static function detect_type_from_attribute($product) {
        // Try WooCommerce taxonomy approach first
        $product_id = $product->get_id();
        $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']);
        
        if (!empty($activity_types)) {
            $type = strtolower(trim($activity_types[0]));
            if (in_array($type, ['camp', 'course', 'birthday', 'tournament'])) {
                return $type;
            }
        }

        // Fallback to attribute object approach
        $attributes = $product->get_attributes();
        
        if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
            $attribute = $attributes['pa_activity-type'];
            
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (in_array($term->slug, ['camp', 'course', 'birthday', 'tournament'], true)) {
                            return $term->slug;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Detect product type from categories.
     *
     * @param int $product_id The product ID.
     * @return string|null Product type or null.
     */
    private static function detect_type_from_category($product_id) {
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        if (is_wp_error($categories)) {
            return null;
        }

        if (in_array('camps', $categories, true)) {
            return 'camp';
        } elseif (in_array('courses', $categories, true)) {
            return 'course';
        } elseif (in_array('birthdays', $categories, true)) {
            return 'birthday';
        } elseif (in_array('tournaments', $categories, true)) {
            return 'tournament';
        }
        
        return null;
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

        // Clear cache first
        $transient_key = 'intersoccer_type_' . $product_id;
        delete_transient($transient_key);
        
        $product_type = self::get_product_type($product_id);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
        }
    }
}
} // End class_exists check for InterSoccer_Product_Types

/**
 * Check if product is a camp
 * @param int $product_id
 * @return bool
 */
if (!function_exists('intersoccer_is_camp')) {
function intersoccer_is_camp($product_id) {
        return intersoccer_get_product_type($product_id) === 'camp';
    }
}

/**
 * Check if product is a course
 * @param int $product_id
 * @return bool
 */
if (!function_exists('intersoccer_is_course')) {
function intersoccer_is_course($product_id) {
    return intersoccer_get_product_type($product_id) === 'course';
    }
}

/**
 * Check if product is a birthday
 * @param int $product_id
 * @return bool
 */
function intersoccer_is_birthday($product_id) {
    return intersoccer_get_product_type($product_id) === 'birthday';
}

/**
 * Check if product is a tournament
 * @param int $product_id
 * @return bool
 */
function intersoccer_is_tournament($product_id) {
    return intersoccer_get_product_type($product_id) === 'tournament';
}

/**
 * Get product venue
 * @param int $product_id
 * @return string|null
 */
function intersoccer_get_product_venue($product_id) {
    $venues = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names']);
    return !empty($venues) ? $venues[0] : null;
}

/**
 * Get product season
 * @param int $product_id
 * @return string|null
 */
function intersoccer_get_product_season($product_id) {
    $seasons = wc_get_product_terms($product_id, 'pa_program-season', ['fields' => 'names']);
    return !empty($seasons) ? $seasons[0] : null;
}

/**
 * Get product age group
 * @param int $product_id
 * @return string|null
 */
function intersoccer_get_product_age_group($product_id) {
    $age_groups = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names']);
    return !empty($age_groups) ? $age_groups[0] : null;
}

/**
 * Get product canton/region
 * @param int $product_id
 * @return string|null
 */
function intersoccer_get_product_canton($product_id) {
    $cantons = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names']);
    return !empty($cantons) ? $cantons[0] : null;
}

/**
 * Get product city
 * @param int $product_id
 * @return string|null
 */
function intersoccer_get_product_city($product_id) {
    $cities = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names']);
    return !empty($cities) ? $cities[0] : null;
}

/**
 * Initialize product type hooks and taxonomies
 */
add_action('init', function() {
    // Register custom product attributes if they don't exist
    $attributes = [
        'pa_activity-type' => 'Activity Type',
        'pa_intersoccer-venues' => 'InterSoccer Venues',
        'pa_program-season' => 'Program Season',
        'pa_age-group' => 'Age Group',
        'pa_canton-region' => 'Canton / Region',
        'pa_city' => 'City',
        'pa_booking-type' => 'Booking Type',
        'pa_course-day' => 'Course Day',
        'pa_camp-terms' => 'Camp Terms',
        'pa_days-of-week' => 'Days of Week'
    ];
    
    foreach ($attributes as $slug => $name) {
        if (!taxonomy_exists($slug)) {
            register_taxonomy($slug, 'product', [
                'label' => $name,
                'public' => true,
                'hierarchical' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => ['slug' => $slug],
            ]);
        }
    }
});

/**
 * Hook to update product type on save.
 */
add_action('save_post_product', ['InterSoccer_Product_Types', 'update_product_type_on_save'], 10, 1);

/**
 * Debug function to log product type information
 * @param int $product_id
 */
function intersoccer_debug_product_info($product_id) {
    if (!WP_DEBUG_LOG) {
        return;
    }
    
    $type = intersoccer_get_product_type($product_id);
    $venue = intersoccer_get_product_venue($product_id);
    $season = intersoccer_get_product_season($product_id);
    $age_group = intersoccer_get_product_age_group($product_id);
    $canton = intersoccer_get_product_canton($product_id);
    $city = intersoccer_get_product_city($product_id);
}