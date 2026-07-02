<?php
/**
 * Sync InterSoccer attribute registry to WooCommerce global attributes and seed default terms.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensure WooCommerce attribute taxonomies exist for every registry entry.
 *
 * @param bool $seed_terms Whether to insert missing default terms.
 * @return array{created:array,existing:array,terms_created:array,errors:array}
 */
function intersoccer_attr_sync_to_woocommerce($seed_terms = true) {
    $result = [
        'created' => [],
        'existing' => [],
        'terms_created' => [],
        'errors' => [],
    ];

    if (!function_exists('wc_create_attribute')) {
        $result['errors'][] = __('WooCommerce is not available.', 'intersoccer-product-variations');
        return $result;
    }

    foreach (intersoccer_attr_registry() as $slug => $def) {
        $existing_id = intersoccer_attr_wc_attribute_id($slug);

        if ($existing_id) {
            $result['existing'][] = $slug;
            intersoccer_attr_ensure_taxonomy_registered($slug);
        } else {
            $created = wc_create_attribute([
                'name' => (string) $def['wc_label'],
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($created)) {
                $result['errors'][] = sprintf(
                    '%s: %s',
                    $slug,
                    $created->get_error_message()
                );
                continue;
            }

            $result['created'][] = $slug;
            delete_transient('wc_attribute_taxonomies');
            intersoccer_attr_ensure_taxonomy_registered($slug);
        }

        if ($seed_terms && !empty($def['default_terms'])) {
            $term_result = intersoccer_attr_seed_default_terms($slug);
            $result['terms_created'] = array_merge($result['terms_created'], $term_result);
        }
    }

    delete_transient('wc_attribute_taxonomies');
    if (function_exists('wc_recount_all_terms')) {
        wc_recount_all_terms();
    }

    return $result;
}

/**
 * @param string $slug
 * @return int|null
 */
function intersoccer_attr_wc_attribute_id($slug) {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return null;
    }

    foreach (wc_get_attribute_taxonomies() as $attribute) {
        if ($attribute->attribute_name === $slug) {
            return (int) $attribute->attribute_id;
        }
    }

    return null;
}

/**
 * Register taxonomy after wc_create_attribute when needed.
 *
 * @param string $slug
 * @return void
 */
function intersoccer_attr_ensure_taxonomy_registered($slug) {
    $taxonomy = intersoccer_attr_taxonomy($slug);
    if (taxonomy_exists($taxonomy)) {
        return;
    }

    $def = intersoccer_attr_definition($slug);
    if (!$def) {
        return;
    }

    register_taxonomy($taxonomy, 'product', [
        'label' => (string) $def['wc_label'],
        'public' => true,
        'hierarchical' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'query_var' => true,
        'rewrite' => ['slug' => $taxonomy],
    ]);
}

/**
 * @param string $slug
 * @return array<int,string> Created term slugs.
 */
function intersoccer_attr_seed_default_terms($slug) {
    $def = intersoccer_attr_definition($slug);
    if (!$def || empty($def['default_terms'])) {
        return [];
    }

    $taxonomy = intersoccer_attr_taxonomy($slug);
    if (!taxonomy_exists($taxonomy)) {
        intersoccer_attr_ensure_taxonomy_registered($slug);
    }

    $created = [];
    foreach ($def['default_terms'] as $term) {
        $name = (string) ($term['name'] ?? '');
        $term_slug = (string) ($term['slug'] ?? sanitize_title($name));
        if ($name === '') {
            continue;
        }

        $existing = term_exists($term_slug, $taxonomy);
        if (!$existing) {
            $existing = term_exists($name, $taxonomy);
        }

        if (!$existing) {
            $inserted = wp_insert_term($name, $taxonomy, ['slug' => $term_slug]);
            if (!is_wp_error($inserted)) {
                $created[] = $term_slug;
            }
        }
    }

    return $created;
}

/**
 * Audit catalog and registry drift.
 *
 * @return array<string,mixed>
 */
function intersoccer_attr_audit() {
    $audit = [
        'contract_version' => INTERSOCCER_ATTRIBUTE_CONTRACT_VERSION,
        'missing_wc_attributes' => [],
        'registered_attributes' => [],
        'legacy_taxonomy_in_use' => [],
        'legacy_meta_key_counts' => [],
        'missing_default_terms' => [],
        'summary' => [
            'total_registry' => count(intersoccer_attr_registry()),
            'registered' => 0,
            'missing' => 0,
            'legacy_hits' => 0,
        ],
    ];

    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (intersoccer_attr_wc_attribute_id($slug)) {
            $audit['registered_attributes'][] = $slug;
        } else {
            $audit['missing_wc_attributes'][] = $slug;
        }

        if (!empty($def['default_terms'])) {
            $taxonomy = intersoccer_attr_taxonomy($slug);
            foreach ($def['default_terms'] as $term) {
                $term_slug = (string) ($term['slug'] ?? '');
                if ($term_slug !== '' && taxonomy_exists($taxonomy) && !term_exists($term_slug, $taxonomy)) {
                    $audit['missing_default_terms'][] = $taxonomy . ':' . $term_slug;
                }
            }
        }
    }

    $audit['summary']['registered'] = count($audit['registered_attributes']);
    $audit['summary']['missing'] = count($audit['missing_wc_attributes']);

    global $wpdb;
    foreach (intersoccer_attr_registry() as $slug => $def) {
        foreach ($def['legacy_taxonomy_aliases'] ?? [] as $legacy_tax) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_value != ''",
                'attribute_' . $wpdb->esc_like($legacy_tax)
            ));
            if ($count > 0) {
                $audit['legacy_taxonomy_in_use'][$legacy_tax] = $count;
                $audit['summary']['legacy_hits'] += $count;
            }
        }

        foreach ($def['legacy_meta_keys'] ?? [] as $legacy_key) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
                $legacy_key
            ));
            if ($count > 0) {
                $audit['legacy_meta_key_counts'][$legacy_key] = $count;
                $audit['summary']['legacy_hits'] += $count;
            }
        }
    }

    return $audit;
}

/**
 * Lightweight init hook: ensure taxonomies exist (does not seed terms on every request).
 *
 * @return void
 */
function intersoccer_attr_register_taxonomies_on_init() {
    foreach (intersoccer_attr_registry() as $slug => $def) {
        $taxonomy = intersoccer_attr_taxonomy($slug);
        if (!taxonomy_exists($taxonomy)) {
            intersoccer_attr_ensure_taxonomy_registered($slug);
        }
    }
}

add_action('init', 'intersoccer_attr_register_taxonomies_on_init', 5);
