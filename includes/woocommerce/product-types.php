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
 * @return string|null 'camp', 'course', 'birthday', or null
 */
function intersoccer_get_product_type($product_id) {
    return InterSoccer_Product_Types::get_product_type($product_id);
}

/**
 * Class to handle product type detection and related utilities.
 */
class InterSoccer_Product_Types {

    /**
     * Get product type for a given product ID.
     *
     * @param int $product_id The product ID.
     * @return string|null Product type ('camp', 'course', 'birthday', or null).
     */
    public static function get_product_type($product_id) {
        if (!$product_id) {
            error_log('InterSoccer: Invalid product ID in get_product_type: ' . $product_id);
            return null;
        }

        // Check transient cache first
        $transient_key = 'intersoccer_type_' . $product_id;
        $cached_type = get_transient($transient_key);
        if (false !== $cached_type) {
            error_log('InterSoccer: Found cached type for product ' . $product_id . ': ' . ($cached_type ?: 'null'));
            return $cached_type ?: null;
        }

        error_log('InterSoccer: No cache found for product ' . $product_id . ', detecting type...');

        $product = wc_get_product($product_id);
        if (!$product) {
            error_log('InterSoccer: Invalid product object for ID: ' . $product_id);
            set_transient($transient_key, '', HOUR_IN_SECONDS);
            return null;
        }

        error_log('InterSoccer: Product object found for ID: ' . $product_id . ', type: ' . $product->get_type());

        // Check existing meta first
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type && in_array($product_type, ['camp', 'course', 'birthday'])) {
            error_log('InterSoccer: Found saved meta type for product ' . $product_id . ': ' . $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        error_log('InterSoccer: No saved meta found for product ' . $product_id . ', checking attributes...');

        // Detect from pa_activity-type attribute
        $product_type = self::detect_type_from_attribute($product);
        if ($product_type) {
            error_log('InterSoccer: Detected type from attribute for product ' . $product_id . ': ' . $product_type);
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        error_log('InterSoccer: No attribute detection for product ' . $product_id . ', checking categories...');

        // Fallback to categories
        $product_type = self::detect_type_from_category($product_id);
        if ($product_type) {
            error_log('InterSoccer: Detected type from category for product ' . $product_id . ': ' . $product_type);
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            set_transient($transient_key, $product_type, HOUR_IN_SECONDS);
            return $product_type;
        }

        error_log('InterSoccer: Could not determine product type for product ' . $product_id);
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
        error_log('InterSoccer: Checking pa_activity-type attribute for product ' . $product->get_id());
        
        // Try WooCommerce taxonomy approach first
        $product_id = $product->get_id();
        $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']);
        
        error_log('InterSoccer: wc_get_product_terms result for pa_activity-type: ' . json_encode($activity_types));
        
        if (!empty($activity_types)) {
            $type = strtolower(trim($activity_types[0]));
            error_log('InterSoccer: First activity type: "' . $type . '"');
            if (in_array($type, ['camp', 'course', 'birthday'])) {
                error_log('InterSoccer: Found type from pa_activity-type taxonomy: ' . $type);
                return $type;
            } else {
                error_log('InterSoccer: Activity type "' . $type . '" not in allowed types [camp, course, birthday]');
            }
        } else {
            error_log('InterSoccer: No activity types found via wc_get_product_terms');
        }

        // Fallback to attribute object approach
        $attributes = $product->get_attributes();
        error_log('InterSoccer: Product attributes: ' . json_encode(array_keys($attributes)));
        
        if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
            $attribute = $attributes['pa_activity-type'];
            error_log('InterSoccer: pa_activity-type attribute found, is_taxonomy: ' . ($attribute->is_taxonomy() ? 'true' : 'false'));
            
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                error_log('InterSoccer: Attribute terms: ' . json_encode($terms));
                
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        error_log('InterSoccer: Checking term: ' . $term->slug);
                        if (in_array($term->slug, ['camp', 'course', 'birthday'], true)) {
                            error_log('InterSoccer: Found type from pa_activity-type attribute: ' . $term->slug);
                            return $term->slug;
                        }
                    }
                } else {
                    error_log('InterSoccer: Terms is not an array');
                }
            } else {
                error_log('InterSoccer: pa_activity-type attribute is not taxonomy-based');
            }
        } else {
            error_log('InterSoccer: pa_activity-type attribute not found or not valid WC_Product_Attribute');
        }
        
        error_log('InterSoccer: No type detected from attributes');
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
            error_log('InterSoccer: Error fetching categories for product ' . $product_id . ': ' . $categories->get_error_message());
            return null;
        }

        if (in_array('camps', $categories, true)) {
            return 'camp';
        } elseif (in_array('courses', $categories, true)) {
            return 'course';
        } elseif (in_array('birthdays', $categories, true)) {
            return 'birthday';
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
            error_log('InterSoccer: Updated product type meta for product ' . $product_id . ': ' . $product_type);
        }
    }
}

/**
 * Check if product is a camp
 * @param int $product_id
 * @return bool
 */
function intersoccer_is_camp($product_id) {
    $result = intersoccer_get_product_type($product_id) === 'camp';
    error_log('InterSoccer: intersoccer_is_camp(' . $product_id . ') = ' . ($result ? 'true' : 'false'));
    return $result;
}

/**
 * Check if product is a course
 * @param int $product_id
 * @return bool
 */
function intersoccer_is_course($product_id) {
    return intersoccer_get_product_type($product_id) === 'course';
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
            error_log('InterSoccer: Registered taxonomy ' . $slug);
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
    
    error_log("InterSoccer Product Debug - ID: {$product_id}");
    error_log("  Type: {$type}");
    error_log("  Venue: {$venue}");
    error_log("  Season: {$season}");
    error_log("  Age Group: {$age_group}");
    error_log("  Canton: {$canton}");
    error_log("  City: {$city}");
}