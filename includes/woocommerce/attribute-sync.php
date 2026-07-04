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
 * Whether InterSoccer attribute sync is running (skip enforcement deletes).
 *
 * @return bool
 */
function intersoccer_attr_sync_in_progress() {
    return !empty($GLOBALS['intersoccer_attr_sync_in_progress']);
}

/**
 * Candidate WooCommerce attribute_name values for a registry slug.
 *
 * @param string $canonical_slug Registry slug without pa_ prefix.
 * @return array<int,string>
 */
function intersoccer_attr_wc_slug_candidates($canonical_slug) {
    $canonical_slug = (string) $canonical_slug;
    $candidates = [$canonical_slug];

    $def = function_exists('intersoccer_attr_definition') ? intersoccer_attr_definition($canonical_slug) : null;
    if ($def) {
        foreach ($def['legacy_taxonomy_aliases'] ?? [] as $alias) {
            $candidates[] = str_replace('pa_', '', (string) $alias);
        }
    }

    $hyphen = str_replace('_', '-', $canonical_slug);
    $underscore = str_replace('-', '_', $canonical_slug);
    $candidates[] = $hyphen;
    $candidates[] = $underscore;

    return array_values(array_unique(array_filter($candidates)));
}

/**
 * Map a WooCommerce attribute_name back to a registry slug when possible.
 *
 * @param string $wc_slug attribute_name from WooCommerce.
 * @return string|null Canonical registry slug.
 */
function intersoccer_attr_registry_slug_for_wc_name($wc_slug) {
    $wc_slug = (string) $wc_slug;
    if ($wc_slug === '') {
        return null;
    }

    if (function_exists('intersoccer_attr_definition') && intersoccer_attr_definition($wc_slug)) {
        return $wc_slug;
    }

    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (in_array($wc_slug, intersoccer_attr_wc_slug_candidates($slug), true)) {
            return $slug;
        }
    }

    return null;
}

/**
 * First candidate slug whose pa_* taxonomy is already registered in WordPress.
 *
 * @param string $canonical_slug Registry slug without pa_ prefix.
 * @return string|null WooCommerce attribute_name (no pa_ prefix).
 */
function intersoccer_attr_find_existing_taxonomy_slug($canonical_slug) {
    foreach (intersoccer_attr_wc_slug_candidates($canonical_slug) as $candidate) {
        if (taxonomy_exists('pa_' . $candidate)) {
            return (string) $candidate;
        }
    }

    return null;
}

/**
 * Resolve the pa_* taxonomy name for registry/audit/seed operations.
 *
 * @param string $slug Registry slug without pa_ prefix.
 * @return string Taxonomy name including pa_ prefix.
 */
function intersoccer_attr_resolved_taxonomy($slug) {
    $wc_slug = intersoccer_attr_wc_attribute_name($slug)
        ?: intersoccer_attr_find_existing_taxonomy_slug($slug)
        ?: $slug;

    return 'pa_' . $wc_slug;
}

/**
 * Insert a WooCommerce global attribute row when pa_* exists without a WC registry entry.
 *
 * @param string      $slug    Registry slug without pa_ prefix.
 * @param string|null $wc_slug Optional WooCommerce attribute_name override.
 * @return bool True when a row was inserted or already present.
 */
function intersoccer_attr_reconcile_wc_attribute($slug, $wc_slug = null) {
    if (intersoccer_attr_wc_attribute_id($slug)) {
        return true;
    }

    $def = intersoccer_attr_definition($slug);
    if (!$def) {
        return false;
    }

    $wc_slug = $wc_slug ?: intersoccer_attr_find_existing_taxonomy_slug($slug) ?: $slug;
    $taxonomy = 'pa_' . $wc_slug;
    if (!taxonomy_exists($taxonomy)) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
    $existing_row = $wpdb->get_row(
        $wpdb->prepare("SELECT attribute_id FROM {$table} WHERE attribute_name = %s", $wc_slug)
    );
    if ($existing_row) {
        delete_transient('wc_attribute_taxonomies');
        return true;
    }

    $inserted = $wpdb->insert(
        $table,
        [
            'attribute_name' => $wc_slug,
            'attribute_label' => (string) $def['wc_label'],
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 0,
        ],
        ['%s', '%s', '%s', '%s', '%d']
    );

    if (!$inserted) {
        return false;
    }

    delete_transient('wc_attribute_taxonomies');
    if (function_exists('wc_register_attribute_taxonomies')) {
        wc_register_attribute_taxonomies();
    }

    return true;
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
        'existing_via_alias' => [],
        'reconciled' => [],
        'terms_created' => [],
        'errors' => [],
    ];

    if (!function_exists('wc_create_attribute')) {
        $result['errors'][] = __('WooCommerce is not available.', 'intersoccer-product-variations');
        return $result;
    }

    $GLOBALS['intersoccer_attr_sync_in_progress'] = true;

    foreach (intersoccer_attr_registry() as $slug => $def) {
        $existing_id = intersoccer_attr_wc_attribute_id($slug);
        $resolved_wc_slug = intersoccer_attr_wc_attribute_name($slug);

        if (!$existing_id) {
            $orphan_slug = intersoccer_attr_find_existing_taxonomy_slug($slug);
            if ($orphan_slug && intersoccer_attr_reconcile_wc_attribute($slug, $orphan_slug)) {
                $result['reconciled'][] = $slug;
                $existing_id = intersoccer_attr_wc_attribute_id($slug);
                $resolved_wc_slug = intersoccer_attr_wc_attribute_name($slug) ?: $orphan_slug;
            }
        }

        if ($existing_id) {
            if (!in_array($slug, $result['reconciled'], true)) {
                $result['existing'][] = $slug;
            }
            if ($resolved_wc_slug !== null && $resolved_wc_slug !== $slug) {
                $result['existing_via_alias'][] = $slug . '→' . $resolved_wc_slug;
            }
            intersoccer_attr_ensure_taxonomy_registered($slug, $resolved_wc_slug);
        } else {
            $created = wc_create_attribute([
                'name' => (string) $def['wc_label'],
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($created)) {
                $orphan_slug = intersoccer_attr_find_existing_taxonomy_slug($slug);
                if ($orphan_slug && intersoccer_attr_reconcile_wc_attribute($slug, $orphan_slug)) {
                    $result['reconciled'][] = $slug;
                    $resolved_wc_slug = intersoccer_attr_wc_attribute_name($slug) ?: $orphan_slug;
                    intersoccer_attr_ensure_taxonomy_registered($slug, $resolved_wc_slug);
                } else {
                    $result['errors'][] = sprintf(
                        '%s: %s',
                        $slug,
                        $created->get_error_message()
                    );
                    continue;
                }
            } else {
                $result['created'][] = $slug;
                delete_transient('wc_attribute_taxonomies');
                intersoccer_attr_ensure_taxonomy_registered($slug);
            }
        }

        if ($seed_terms && !empty($def['default_terms'])) {
            $term_result = intersoccer_attr_seed_default_terms($slug);
            $result['terms_created'] = array_merge($result['terms_created'], $term_result);
        }
    }

    unset($GLOBALS['intersoccer_attr_sync_in_progress']);

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
function intersoccer_attr_wc_attribute_name($canonical_slug) {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return null;
    }

    foreach (intersoccer_attr_wc_slug_candidates($canonical_slug) as $candidate) {
        foreach (wc_get_attribute_taxonomies() as $attribute) {
            if ($attribute->attribute_name === $candidate) {
                return (string) $candidate;
            }
        }
    }

    return null;
}

function intersoccer_attr_wc_attribute_id($slug) {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return null;
    }

    $resolved = intersoccer_attr_wc_attribute_name($slug);
    if ($resolved === null) {
        return null;
    }

    foreach (wc_get_attribute_taxonomies() as $attribute) {
        if ($attribute->attribute_name === $resolved) {
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
function intersoccer_attr_ensure_taxonomy_registered($slug, $wc_slug = null) {
    $wc_slug = $wc_slug ?: intersoccer_attr_wc_attribute_name($slug) ?: $slug;
    $taxonomy = 'pa_' . $wc_slug;

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

    $wc_slug = intersoccer_attr_wc_attribute_name($slug)
        ?: intersoccer_attr_find_existing_taxonomy_slug($slug)
        ?: $slug;
    $taxonomy = 'pa_' . $wc_slug;
    if (!taxonomy_exists($taxonomy)) {
        intersoccer_attr_ensure_taxonomy_registered($slug, $wc_slug);
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
        'orphaned_taxonomies' => [],
        'summary' => [
            'total_registry' => count(intersoccer_attr_registry()),
            'registered' => 0,
            'missing' => 0,
            'legacy_hits' => 0,
        ],
    ];

    foreach (intersoccer_attr_registry() as $slug => $def) {
        $wc_id = intersoccer_attr_wc_attribute_id($slug);
        $orphan_slug = intersoccer_attr_find_existing_taxonomy_slug($slug);

        if ($wc_id) {
            $audit['registered_attributes'][] = $slug;
        } else {
            $audit['missing_wc_attributes'][] = $slug;
            if ($orphan_slug) {
                $audit['orphaned_taxonomies'][$slug] = 'pa_' . $orphan_slug;
            }
        }

        if (!empty($def['default_terms'])) {
            $taxonomy = intersoccer_attr_resolved_taxonomy($slug);
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
    $audit['alias_resolved'] = [];

    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (in_array($slug, $audit['missing_wc_attributes'], true)) {
            continue;
        }
        $wc_name = intersoccer_attr_wc_attribute_name($slug);
        if ($wc_name !== null && $wc_name !== $slug) {
            $audit['alias_resolved'][$slug] = $wc_name;
        }
    }

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
        $wc_slug = intersoccer_attr_wc_attribute_name($slug);
        if (!$wc_slug) {
            continue;
        }
        intersoccer_attr_ensure_taxonomy_registered($slug, $wc_slug);
    }
}

add_action('init', 'intersoccer_attr_register_taxonomies_on_init', 5);
