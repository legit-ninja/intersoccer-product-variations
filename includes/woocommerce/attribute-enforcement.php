<?php
/**
 * Enforce InterSoccer attribute registry on WooCommerce admin.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $slug Bare attribute slug without pa_ prefix.
 * @return bool
 */
function intersoccer_attr_is_allowed_slug($slug) {
    $slug = sanitize_title((string) $slug);
    if ($slug === '') {
        return false;
    }
    if (function_exists('intersoccer_attr_registry_slug_for_wc_name')) {
        return intersoccer_attr_registry_slug_for_wc_name($slug) !== null;
    }
    return function_exists('intersoccer_attr_definition') && intersoccer_attr_definition($slug) !== null;
}

/**
 * Global WC attributes not defined in the InterSoccer registry.
 *
 * @return array<int,string>
 */
function intersoccer_attr_unregistered_wc_attributes() {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return [];
    }

    $drift = [];
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $slug = (string) $attribute->attribute_name;
        if (!intersoccer_attr_is_allowed_slug($slug)) {
            $drift[] = $slug;
        }
    }
    return $drift;
}

/**
 * Allowed attribute slugs for a product type (parent + variation scopes).
 *
 * @param string $product_type
 * @return array<int,string> Bare slugs without pa_ prefix.
 */
function intersoccer_attr_allowed_slugs_for_product_type($product_type) {
    $type = strtolower((string) $product_type);
    $templates = intersoccer_attr_product_type_templates();
    if (!isset($templates[$type])) {
        return [];
    }

    $slugs = array_merge($templates[$type]['parent'], $templates[$type]['variation']);
    return array_values(array_unique($slugs));
}

/**
 * Block creation of WooCommerce global attributes outside the registry.
 */
add_action('woocommerce_attribute_added', 'intersoccer_attr_block_unregistered_attribute_creation', 1, 2);
function intersoccer_attr_block_unregistered_attribute_creation($attribute_id, $attribute) {
    if (function_exists('intersoccer_attr_sync_in_progress') && intersoccer_attr_sync_in_progress()) {
        return;
    }

    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $slug = '';
    if (is_array($attribute)) {
        $slug = (string) ($attribute['attribute_name'] ?? $attribute['slug'] ?? '');
    } elseif (is_object($attribute)) {
        $slug = (string) ($attribute->attribute_name ?? $attribute->slug ?? '');
    }

    if ($slug === '' || intersoccer_attr_is_allowed_slug($slug)) {
        return;
    }

    if (function_exists('wc_delete_attribute')) {
        wc_delete_attribute((int) $attribute_id);
    }

    add_settings_error(
        'intersoccer_attr_enforcement',
        'intersoccer_attr_blocked',
        sprintf(
            /* translators: %s: attribute slug */
            __('Attribute "%s" is not part of the InterSoccer contract. Use Products → Attributes → Sync InterSoccer Attributes.', 'intersoccer-product-variations'),
            esc_html($slug)
        ),
        'error'
    );
}

/**
 * Validate product attributes on save for InterSoccer program products.
 */
add_action('woocommerce_admin_process_product_object', 'intersoccer_attr_validate_product_on_save', 20, 1);
function intersoccer_attr_validate_product_on_save($product) {
    if (!($product instanceof WC_Product) || !current_user_can('edit_product', $product->get_id())) {
        return;
    }

    $product_id = (int) $product->get_id();
    $product_type = function_exists('intersoccer_get_product_type')
        ? strtolower((string) intersoccer_get_product_type($product_id))
        : '';

    if (!in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
        return;
    }

    $allowed = intersoccer_attr_allowed_slugs_for_product_type($product_type);
    $unexpected = [];

    foreach ($product->get_attributes() as $attribute_name => $attribute) {
        $slug = str_replace('pa_', '', (string) $attribute_name);
        if (!intersoccer_attr_is_allowed_slug($slug)) {
            $unexpected[] = $slug;
            continue;
        }
        if (!in_array($slug, $allowed, true) && $slug !== 'note') {
            $unexpected[] = $slug;
        }
    }

    if (!empty($unexpected)) {
        $unexpected = array_values(array_unique($unexpected));
        add_settings_error(
            'intersoccer_attr_enforcement',
            'intersoccer_attr_product_' . $product_id,
            sprintf(
                /* translators: 1: product type, 2: comma-separated attribute slugs */
                __('Product has attributes outside the %1$s template: %2$s. Remove them or contact an administrator.', 'intersoccer-product-variations'),
                esc_html($product_type),
                esc_html(implode(', ', $unexpected))
            ),
            'warning'
        );
    }

    if (in_array($product_type, ['camp', 'course'], true)) {
        intersoccer_attr_recommend_girls_only_attribute($product, $product_type);
    }
}

/**
 * Recommend pa_girls-only instead of dual pa_activity-type girls markers.
 *
 * @param WC_Product $product
 * @param string     $product_type
 * @return void
 */
function intersoccer_attr_recommend_girls_only_attribute($product, $product_type) {
    $product_id = (int) $product->get_id();
    $pairs = function_exists('intersoccer_collect_activity_type_terms_for_line')
        ? intersoccer_collect_activity_type_terms_for_line($product_id, 0)
        : [];

    $has_girls_activity_term = false;
    foreach ($pairs as $pair) {
        if (function_exists('intersoccer_activity_type_term_is_girls_only')
            && intersoccer_activity_type_term_is_girls_only($pair['slug'] ?? '', $pair['name'] ?? '')) {
            $has_girls_activity_term = true;
            break;
        }
    }

    $has_girls_only_attr = false;
    foreach (intersoccer_get_girls_only_attribute_taxonomies() as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        $terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'all']);
        if (!empty($terms) && !is_wp_error($terms)) {
            $has_girls_only_attr = true;
            break;
        }
    }

    if ($has_girls_activity_term && !$has_girls_only_attr) {
        add_settings_error(
            'intersoccer_attr_enforcement',
            'intersoccer_attr_girls_only_migration_' . $product_id,
            __('This product uses a girls-only Activity Type term. Prefer the dedicated Girls Only attribute for new and edited products.', 'intersoccer-product-variations'),
            'warning'
        );
    }
}

/**
 * Surface registry drift on the attributes admin screen.
 */
add_action('admin_notices', 'intersoccer_attr_enforcement_drift_notice');
function intersoccer_attr_enforcement_drift_notice() {
    if (!function_exists('get_current_screen') || !current_user_can('manage_woocommerce')) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['product_page_product_attributes', 'product'], true)) {
        return;
    }

    settings_errors('intersoccer_attr_enforcement');

    $drift = intersoccer_attr_unregistered_wc_attributes();
    if (empty($drift)) {
        return;
    }

    echo '<div class="notice notice-warning"><p>';
    echo esc_html__(
        'WooCommerce has global attributes outside the InterSoccer contract:',
        'intersoccer-product-variations'
    );
    echo ' <strong>' . esc_html(implode(', ', $drift)) . '</strong>. ';
    echo esc_html__(
        'Remove unused attributes or register them in the attribute registry before use on program products.',
        'intersoccer-product-variations'
    );
    echo '</p></div>';
}
