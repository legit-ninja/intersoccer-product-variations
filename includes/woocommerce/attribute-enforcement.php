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

    $slugs = array_merge(
        $templates[$type]['parent'],
        $templates[$type]['parent_optional'] ?? [],
        $templates[$type]['variation']
    );
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

    $shape_errors = intersoccer_attr_product_term_shape_violations($product);
    if (!empty($shape_errors)) {
        add_settings_error(
            'intersoccer_attr_enforcement',
            'intersoccer_attr_term_shape_' . $product_id,
            sprintf(
                /* translators: %s: semicolon-separated term-shape errors */
                __('Term-shape violations (taxonomy standard): %s', 'intersoccer-product-variations'),
                esc_html(implode('; ', $shape_errors))
            ),
            'error'
        );
    }

    $missing_parent = intersoccer_attr_missing_required_parent_facets_from_product($product, $product_type);
    $wants_publish = ($product->get_status() === 'publish');
    if ($wants_publish && (!empty($missing_parent) || !empty($shape_errors))) {
        $product->set_status('draft');
        $parts = [];
        if (!empty($missing_parent)) {
            $parts[] = sprintf(
                /* translators: %s: comma-separated attribute taxonomies */
                __('Missing required parent attributes: %s.', 'intersoccer-product-variations'),
                implode(', ', $missing_parent)
            );
        }
        if (!empty($shape_errors)) {
            $parts[] = __('Assigned terms violate the taxonomy standard.', 'intersoccer-product-variations');
        }
        add_settings_error(
            'intersoccer_attr_enforcement',
            'intersoccer_attr_publish_blocked_' . $product_id,
            __('Publish blocked.', 'intersoccer-product-variations') . ' ' . implode(' ', $parts),
            'error'
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

/**
 * Attribute slugs whose catalog terms must match the taxonomy standard locked lists.
 *
 * Year is shape-checked (bare 20xx) rather than limited to the seeded default years.
 * Venues, canton, city, camp-terms, course-times, age-group stay free-form.
 *
 * @return array<int,string>
 */
function intersoccer_attr_locked_term_attribute_slugs() {
    return [
        'activity-type',
        'program-season',
        'girls-only',
        'days-of-week',
        'course-day',
        'booking-type',
        'camp-times',
    ];
}

/**
 * @param string $slug Bare attribute slug.
 * @return array<int,string>
 */
function intersoccer_attr_default_term_slugs($slug) {
    $def = function_exists('intersoccer_attr_definition') ? intersoccer_attr_definition($slug) : null;
    if (!$def || empty($def['default_terms']) || !is_array($def['default_terms'])) {
        return [];
    }
    $slugs = [];
    foreach ($def['default_terms'] as $term) {
        if (!empty($term['slug'])) {
            $slugs[] = strtolower((string) $term['slug']);
        }
    }
    return $slugs;
}

/**
 * @param string $slug Bare attribute slug.
 * @return array<int,string> Lowercase default names.
 */
function intersoccer_attr_default_term_names($slug) {
    $def = function_exists('intersoccer_attr_definition') ? intersoccer_attr_definition($slug) : null;
    if (!$def || empty($def['default_terms']) || !is_array($def['default_terms'])) {
        return [];
    }
    $names = [];
    foreach ($def['default_terms'] as $term) {
        if (!empty($term['name'])) {
            $names[] = strtolower(trim((string) $term['name']));
        }
    }
    return $names;
}

/**
 * EN default-language slug for a free-form (or locked) term name.
 *
 * @param string $name
 * @return string
 */
function intersoccer_attr_english_term_slug($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $name = remove_accents($name);
    }
    return sanitize_title($name);
}

/**
 * Required parent taxonomies that block publish (birthday uses activity-type only).
 *
 * @param string $product_type
 * @return array<int,string> pa_* taxonomies
 */
function intersoccer_attr_required_parent_taxonomies($product_type) {
    $type = strtolower((string) $product_type);
    if (!function_exists('intersoccer_attr_required')) {
        return [];
    }
    $required = intersoccer_attr_required($type, 'parent');
    if ($type === 'birthday') {
        $required = array_values(array_filter(
            $required,
            static function ($taxonomy) {
                return !in_array($taxonomy, [
                    'pa_intersoccer-venues',
                    'pa_program-season',
                    'pa_program-year',
                ], true);
            }
        ));
    }
    return $required;
}

/**
 * Missing required parent taxonomies given the assigned pa_* list.
 *
 * Empty return means publish is allowed (for facet completeness).
 *
 * @param array<int,string> $assigned_taxonomies pa_* taxonomies that have terms.
 * @param string            $product_type
 * @return array<int,string>
 */
function intersoccer_attr_parent_facets_block_publish(array $assigned_taxonomies, $product_type) {
    $required = intersoccer_attr_required_parent_taxonomies($product_type);
    if ($required === []) {
        return [];
    }
    $assigned = [];
    foreach ($assigned_taxonomies as $taxonomy) {
        $taxonomy = (string) $taxonomy;
        if ($taxonomy === '') {
            continue;
        }
        if (strpos($taxonomy, 'pa_') !== 0) {
            $taxonomy = 'pa_' . $taxonomy;
        }
        $assigned[] = $taxonomy;
    }
    return array_values(array_diff($required, $assigned));
}

/**
 * @param WC_Product $product
 * @param string     $product_type
 * @return array<int,string>
 */
function intersoccer_attr_missing_required_parent_facets_from_product($product, $product_type) {
    if (!is_object($product) || !method_exists($product, 'get_attributes')) {
        return intersoccer_attr_required_parent_taxonomies($product_type);
    }

    $assigned = [];
    foreach ($product->get_attributes() as $attribute_name => $attribute) {
        $has_terms = false;
        if (is_object($attribute) && method_exists($attribute, 'get_options')) {
            $has_terms = !empty($attribute->get_options());
        } else {
            $has_terms = !empty($attribute);
        }
        if (!$has_terms) {
            continue;
        }
        $taxonomy = (string) $attribute_name;
        if (strpos($taxonomy, 'pa_') !== 0) {
            $taxonomy = 'pa_' . $taxonomy;
        }
        $assigned[] = $taxonomy;
    }

    return intersoccer_attr_parent_facets_block_publish($assigned, $product_type);
}

/**
 * Validate a new term name/slug against the taxonomy standard.
 *
 * @param string $taxonomy pa_* taxonomy.
 * @param string $name     Term name.
 * @param string $slug     Optional proposed slug.
 * @return true|WP_Error
 */
function intersoccer_attr_validate_new_term($taxonomy, $name, $slug = '') {
    $taxonomy = (string) $taxonomy;
    $name = trim((string) $name);
    $slug = trim((string) $slug);

    if ($name === '') {
        return new WP_Error(
            'empty_term',
            __('Term name is required.', 'intersoccer-product-variations')
        );
    }

    $attr_slug = function_exists('intersoccer_attr_slug_from_taxonomy')
        ? intersoccer_attr_slug_from_taxonomy($taxonomy)
        : null;
    if (!$attr_slug) {
        return new WP_Error(
            'unregistered_taxonomy',
            __('Taxonomy is not registered.', 'intersoccer-product-variations')
        );
    }

    if ($slug === '') {
        $slug = intersoccer_attr_english_term_slug($name);
    } else {
        $slug = intersoccer_attr_english_term_slug($slug);
    }

    if ($attr_slug === 'program-season') {
        if (function_exists('intersoccer_pm_is_year_qualified_season_label')
            && (intersoccer_pm_is_year_qualified_season_label($name)
                || intersoccer_pm_is_year_qualified_season_label($slug))) {
            return new WP_Error(
                'year_qualified_season',
                __('Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.', 'intersoccer-product-variations')
            );
        }
    }

    if ($attr_slug === 'program-year') {
        $bare = function_exists('intersoccer_pm_normalize_program_year')
            ? intersoccer_pm_normalize_program_year($name)
            : (preg_match('/^(20\d{2})$/', $name) ? $name : '');
        $name_is_bare = (bool) preg_match('/^20\d{2}$/', $name);
        $slug_is_bare = (bool) preg_match('/^20\d{2}$/', $slug);
        if (!$name_is_bare && !$slug_is_bare) {
            return new WP_Error(
                'non_bare_year',
                __('Program year must be a bare calendar year (e.g. 2026). Year is pa_program-year only.', 'intersoccer-product-variations')
            );
        }
        if ($bare === '') {
            return new WP_Error(
                'non_bare_year',
                __('Program year must be a bare calendar year (e.g. 2026). Year is pa_program-year only.', 'intersoccer-product-variations')
            );
        }
        return true;
    }

    if (in_array($attr_slug, intersoccer_attr_locked_term_attribute_slugs(), true)) {
        $allowed_slugs = intersoccer_attr_default_term_slugs($attr_slug);
        $allowed_names = intersoccer_attr_default_term_names($attr_slug);
        $slug_l = strtolower($slug);
        $name_l = strtolower($name);
        $name_as_slug = intersoccer_attr_english_term_slug($name);
        if (!in_array($slug_l, $allowed_slugs, true)
            && !in_array($name_l, $allowed_names, true)
            && !in_array($name_as_slug, $allowed_slugs, true)) {
            return new WP_Error(
                'term_not_in_registry',
                sprintf(
                    /* translators: 1: attribute slug, 2: allowed term slugs */
                    __('"%1$s" terms must be one of: %2$s.', 'intersoccer-product-variations'),
                    $attr_slug,
                    implode(', ', $allowed_slugs)
                )
            );
        }
    }

    return true;
}

/**
 * Term-shape violations on a product's assigned terms (classic WC cannot bypass PM).
 *
 * @param WC_Product|object $product
 * @return array<int,string>
 */
function intersoccer_attr_product_term_shape_violations($product) {
    $violations = [];
    if (!is_object($product) || !method_exists($product, 'get_id')) {
        return $violations;
    }
    $product_id = (int) $product->get_id();
    if ($product_id <= 0 || !function_exists('wc_get_product_terms')) {
        return $violations;
    }

    $slugs = array_merge(
        intersoccer_attr_locked_term_attribute_slugs(),
        ['program-year']
    );
    foreach ($slugs as $slug) {
        $taxonomy = function_exists('intersoccer_attr_taxonomy')
            ? intersoccer_attr_taxonomy($slug)
            : ('pa_' . $slug);
        if (function_exists('taxonomy_exists') && !taxonomy_exists($taxonomy)) {
            continue;
        }
        $terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'all']);
        if (empty($terms) || is_wp_error($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            $term_name = is_object($term) ? (string) ($term->name ?? '') : '';
            $term_slug = is_object($term) ? (string) ($term->slug ?? '') : '';
            $result = intersoccer_attr_validate_new_term($taxonomy, $term_name, $term_slug);
            if (is_wp_error($result)) {
                $violations[] = $taxonomy . ': ' . $result->get_error_message();
            }
        }
    }

    return $violations;
}

/**
 * Block unregistered / illegal term inserts from classic WC attribute UI.
 *
 * @param string|WP_Error $term
 * @param string          $taxonomy
 * @return string|WP_Error
 */
function intersoccer_attr_pre_insert_term($term, $taxonomy) {
    if (is_wp_error($term)) {
        return $term;
    }
    if (function_exists('intersoccer_attr_sync_in_progress') && intersoccer_attr_sync_in_progress()) {
        return $term;
    }
    $taxonomy = (string) $taxonomy;
    if (strpos($taxonomy, 'pa_') !== 0) {
        return $term;
    }
    if (!function_exists('intersoccer_attr_slug_from_taxonomy') || !intersoccer_attr_slug_from_taxonomy($taxonomy)) {
        return $term;
    }
    $validated = intersoccer_attr_validate_new_term($taxonomy, (string) $term);
    if (is_wp_error($validated)) {
        return $validated;
    }
    return $term;
}
add_filter('pre_insert_term', 'intersoccer_attr_pre_insert_term', 10, 2);

/**
 * Force EN default-language slugs on InterSoccer attribute terms.
 *
 * @param array<string,mixed> $data
 * @param string              $taxonomy
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function intersoccer_attr_insert_term_english_slug($data, $taxonomy, $args = []) {
    if (function_exists('intersoccer_attr_sync_in_progress') && intersoccer_attr_sync_in_progress()) {
        return $data;
    }
    $taxonomy = (string) $taxonomy;
    if (strpos($taxonomy, 'pa_') !== 0) {
        return $data;
    }
    $attr_slug = function_exists('intersoccer_attr_slug_from_taxonomy')
        ? intersoccer_attr_slug_from_taxonomy($taxonomy)
        : null;
    if (!$attr_slug) {
        return $data;
    }

    $name = isset($data['name']) ? (string) $data['name'] : '';
    if ($attr_slug === 'program-year' && function_exists('intersoccer_pm_normalize_program_year')) {
        $year = intersoccer_pm_normalize_program_year($name);
        if ($year !== '' && (bool) preg_match('/^20\d{2}$/', $name)) {
            $data['name'] = $year;
            $data['slug'] = $year;
            return $data;
        }
    }

    $en_slug = intersoccer_attr_english_term_slug($name);
    if ($en_slug !== '') {
        $data['slug'] = $en_slug;
    }
    return $data;
}
add_filter('wp_insert_term_data', 'intersoccer_attr_insert_term_english_slug', 10, 3);
