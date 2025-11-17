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
        // Try WooCommerce taxonomy approach first (most reliable - reads directly from database)
        $product_id = $product->get_id();
        
        // Clear any object cache to ensure we get fresh data
        clean_post_cache($product_id);
        wp_cache_delete($product_id, 'posts');
        wp_cache_delete($product_id, 'post_meta');
        
        $activity_terms = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'all']);
        
        if (!empty($activity_terms) && !is_wp_error($activity_terms)) {
            foreach ($activity_terms as $term) {
                $slug = self::normalize_activity_slug($term, 'pa_activity-type');
                if ($slug) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("InterSoccer: Detected product type '{$slug}' for product {$product_id} from term '{$term->name}' (slug: {$term->slug})");
                    }
                    return $slug;
                }
            }
        }

        // Fallback to attribute object approach (refresh product object first)
        $product = wc_get_product($product_id); // Get fresh product object
        if (!$product) {
            return null;
        }
        
        $attributes = $product->get_attributes();
        
        if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
            $attribute = $attributes['pa_activity-type'];
            
            if ($attribute->is_taxonomy()) {
                $terms = $attribute->get_terms();
                
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        $slug = self::normalize_activity_slug($term, 'pa_activity-type');
                        if ($slug) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("InterSoccer: Detected product type '{$slug}' for product {$product_id} from attribute term '{$term->name}' (slug: {$term->slug})");
                            }
                            return $slug;
                        }
                    }
                }
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Could not detect product type for product {$product_id} from attributes");
        }
        
        return null;
    }

    /**
     * Normalize a translated activity-type term back to its canonical slug.
     *
     * @param WP_Term|object $term
     * @param string $taxonomy
     * @return string|null
     */
    private static function normalize_activity_slug($term, $taxonomy) {
        if (!$term || !isset($term->slug)) {
            return null;
        }

        $canonical = ['camp', 'course', 'birthday', 'tournament'];
        $slug = strtolower($term->slug);
        $name = isset($term->name) ? strtolower(trim($term->name)) : '';

        // Check if slug matches canonical
        if (in_array($slug, $canonical, true)) {
            return $slug;
        }

        // Translation map: canonical -> language variants (slugs and names)
        $translation_map = [
            'camp' => ['camp', 'camps', 'camp de vacances', 'lager', 'campeggio'],
            'course' => ['course', 'cours', 'kurs', 'corso', 'stage'],
            'birthday' => ['birthday', 'anniversaire', 'geburtstag', 'compleanno'],
            'tournament' => ['tournament', 'tournoi', 'turnier', 'torneo'],
        ];

        // Check if slug or name matches any translation variant
        foreach ($translation_map as $canonical_type => $variants) {
            foreach ($variants as $variant) {
                if ($slug === $variant || $name === $variant || 
                    strpos($slug, $variant) !== false || strpos($name, $variant) !== false) {
                    return $canonical_type;
                }
            }
        }

        // Try WPML default language term if available
        if (function_exists('apply_filters')) {
            $default_lang = apply_filters('wpml_default_language', null);
            if ($default_lang && function_exists('apply_filters')) {
                $default_term_id = apply_filters('wpml_object_id', $term->term_id, $taxonomy, false, $default_lang);
                if ($default_term_id) {
                    $default_term = get_term($default_term_id, $taxonomy);
                    if ($default_term && !is_wp_error($default_term)) {
                        $default_slug = strtolower($default_term->slug);
                        $default_name = isset($default_term->name) ? strtolower(trim($default_term->name)) : '';
                        
                        // Check canonical slug
                        if (in_array($default_slug, $canonical, true)) {
                            return $default_slug;
                        }
                        
                        // Check translation variants
                        foreach ($translation_map as $canonical_type => $variants) {
                            foreach ($variants as $variant) {
                                if ($default_slug === $variant || $default_name === $variant ||
                                    strpos($default_slug, $variant) !== false || strpos($default_name, $variant) !== false) {
                                    return $canonical_type;
                                }
                            }
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
     * @param bool $force_redetect If true, ignore existing metadata and re-detect from attributes.
     */
    public static function update_product_type_on_save($product_id, $force_redetect = false) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $product_id)) {
            return;
        }

        // Clear cache first
        $transient_key = 'intersoccer_type_' . $product_id;
        delete_transient($transient_key);
        
        // Get old value for comparison
        $old_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        
        // If forcing redetect, temporarily delete the metadata so get_product_type() will re-detect
        if ($force_redetect && $old_type) {
            delete_post_meta($product_id, '_intersoccer_product_type');
        }
        
        $product_type = self::get_product_type($product_id);
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            
            // Log if type changed
            if (defined('WP_DEBUG') && WP_DEBUG && $old_type !== $product_type) {
                error_log("InterSoccer: Product type updated for product {$product_id}: '{$old_type}' -> '{$product_type}'" . ($force_redetect ? ' (forced redetect)' : ''));
            }
        } elseif ($old_type) {
            // If we can't detect a type but one exists, log it
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("InterSoccer: Warning - Could not detect product type for product {$product_id}, but old type was '{$old_type}'");
            }
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
 * Hook to update product type after product meta is processed (ensures attributes are saved)
 */
add_action('woocommerce_process_product_meta', function($product_id) {
    // Force redetect from attributes to ensure correct type is saved
    InterSoccer_Product_Types::update_product_type_on_save($product_id, true);
}, 20, 1);

/**
 * Hook to update product type when product object is saved (catches attribute saves)
 */
add_action('woocommerce_after_product_object_save', function($product) {
    if ($product && method_exists($product, 'get_id')) {
        $product_id = $product->get_id();
        // Force redetect from attributes when product object is saved
        InterSoccer_Product_Types::update_product_type_on_save($product_id, true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Product object saved for product {$product_id}, updating type");
        }
    }
}, 20, 1);

/**
 * Hook to update product type when product is updated (catches attribute saves via AJAX)
 */
add_action('woocommerce_update_product', function($product_id) {
    // Force redetect from attributes when product is updated
    InterSoccer_Product_Types::update_product_type_on_save($product_id, true);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("InterSoccer: Product updated for product {$product_id}, updating type");
    }
}, 20, 1);

/**
 * Hook to update product type on shutdown (catches attribute saves that might not trigger other hooks)
 * This ensures we catch attribute saves even if they happen via AJAX or other methods
 */
add_action('shutdown', function() {
    // Only run if we're in admin and processing a product save
    if (!is_admin() || !isset($_POST['post_type']) || $_POST['post_type'] !== 'product') {
        return;
    }
    
    // Check if product ID is in POST
    $product_id = isset($_POST['post_ID']) ? intval($_POST['post_ID']) : 0;
    if (!$product_id) {
        return;
    }
    
    // Only run once per request to avoid multiple updates
    static $updated = false;
    if ($updated) {
        return;
    }
    
    // Check if attributes were saved (indicated by presence of attribute-related POST data)
    $has_attributes = false;
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'attribute_') === 0 || strpos($key, 'product_attributes') !== false) {
            $has_attributes = true;
            break;
        }
    }
    
    if ($has_attributes) {
        $updated = true;
        InterSoccer_Product_Types::update_product_type_on_save($product_id, true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Shutdown hook - updating product type for product {$product_id} after attribute save");
        }
    }
}, 999);

/**
 * Hook to update product type when variation is saved (attributes might change on parent)
 * Use a later priority to ensure parent product attributes are saved first
 */
add_action('woocommerce_save_product_variation', function($variation_id, $loop) {
    $parent_id = wp_get_post_parent_id($variation_id);
    if ($parent_id) {
        // Use a short delay to ensure parent product attributes are fully saved
        // Force redetect from attributes (ignore existing metadata)
        // This ensures the parent product type is updated when variation attributes change
        add_action('shutdown', function() use ($parent_id) {
            InterSoccer_Product_Types::update_product_type_on_save($parent_id, true);
        }, 999);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Variation {$variation_id} saved, scheduling parent product {$parent_id} type update");
        }
    }
}, 99, 2);

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