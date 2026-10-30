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

/**
 * Remember product post_status before wp_insert_post updates the row (same request).
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 * @return void
 */
add_action('transition_post_status', 'intersoccer_attr_capture_product_pre_save_status', 10, 3);
function intersoccer_attr_capture_product_pre_save_status($new_status, $old_status, $post) {
    if (!is_object($post) || ($post->post_type ?? '') !== 'product') {
        return;
    }
    $product_id = (int) ($post->ID ?? 0);
    if ($product_id <= 0) {
        return;
    }
    intersoccer_attr_set_product_pre_save_status($product_id, (string) $old_status);
}

/**
 * @param int    $product_id
 * @param string $status
 * @return void
 */
function intersoccer_attr_set_product_pre_save_status($product_id, $status) {
    if (!isset($GLOBALS['intersoccer_attr_product_pre_save_status'])) {
        $GLOBALS['intersoccer_attr_product_pre_save_status'] = [];
    }
    $GLOBALS['intersoccer_attr_product_pre_save_status'][(int) $product_id] = (string) $status;
}

/**
 * Product status immediately before the current admin save (not the post-save DB row).
 *
 * @param int $product_id
 * @return string
 */
function intersoccer_attr_product_pre_save_status($product_id) {
    $product_id = (int) $product_id;
    if (!empty($GLOBALS['intersoccer_attr_product_pre_save_status'][$product_id])) {
        return (string) $GLOBALS['intersoccer_attr_product_pre_save_status'][$product_id];
    }
    if (!empty($_POST['original_post_status']) && !empty($_POST['post_ID'])
        && (int) $_POST['post_ID'] === $product_id) {
        return sanitize_key((string) wp_unslash($_POST['original_post_status']));
    }

    return (string) get_post_status($product_id);
}

/**
 * @param int $product_id
 * @return bool True when the product was already live before this save request.
 */
function intersoccer_attr_product_stored_status_is_live($product_id) {
    $previous = intersoccer_attr_product_pre_save_status($product_id);

    return in_array($previous, ['publish', 'future', 'private'], true);
}

/**
 * @param int $product_id
 * @return string
 */
function intersoccer_attr_product_save_notice_transient_key($product_id) {
    return 'intersoccer_attr_notice_' . (int) get_current_user_id() . '_' . (int) $product_id;
}

/**
 * @param int    $product_id
 * @param string $message
 * @param string $type `error` or `warning`.
 * @return void
 */
function intersoccer_attr_store_product_save_notice($product_id, $message, $type = 'error') {
    $ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
    set_transient(
        intersoccer_attr_product_save_notice_transient_key($product_id),
        [
            'message' => (string) $message,
            'type' => (string) $type,
        ],
        $ttl
    );
}

/**
 * @param int $product_id
 * @return void
 */
function intersoccer_attr_clear_product_save_notice($product_id) {
    delete_transient(intersoccer_attr_product_save_notice_transient_key($product_id));
}

/**
 * @param int $product_id
 * @return array{message:string,type:string}|null
 */
function intersoccer_attr_read_and_clear_product_save_notice($product_id) {
    $key = intersoccer_attr_product_save_notice_transient_key($product_id);
    $payload = get_transient($key);
    delete_transient($key);
    if (!is_array($payload) || empty($payload['message'])) {
        return null;
    }

    return [
        'message' => (string) $payload['message'],
        'type' => (string) ($payload['type'] ?? 'error'),
    ];
}

/**
 * Show a persistent attribute enforcement notice on the product edit screen.
 */
add_action('admin_notices', 'intersoccer_attr_admin_product_save_notice');
function intersoccer_attr_admin_product_save_notice() {
    if (!is_admin() || !function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || ($screen->id ?? '') !== 'product') {
        return;
    }
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id <= 0) {
        return;
    }
    $payload = intersoccer_attr_read_and_clear_product_save_notice($post_id);
    if ($payload === null) {
        return;
    }
    $class = ($payload['type'] === 'warning') ? 'notice notice-warning' : 'notice notice-error';
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($payload['message']) . '</p></div>';
}

/**
 * Admin-facing label for a pa_* taxonomy (not the slug).
 *
 * @param string $taxonomy
 * @return string
 */
function intersoccer_attr_admin_label_for_taxonomy($taxonomy) {
    $slug = function_exists('intersoccer_attr_slug_from_taxonomy')
        ? intersoccer_attr_slug_from_taxonomy((string) $taxonomy)
        : null;
    $def = ($slug && function_exists('intersoccer_attr_definition'))
        ? intersoccer_attr_definition($slug)
        : null;
    if ($def && !empty($def['wc_label'])) {
        return (string) $def['wc_label'];
    }

    return (string) $taxonomy;
}

/**
 * True when the product is a WPML translation (not the default-language original).
 *
 * @param int $product_id
 * @return bool
 */
function intersoccer_attr_is_wpml_translation_product($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0 || !function_exists('apply_filters')) {
        return false;
    }
    $details = apply_filters('wpml_element_language_details', null, [
        'element_id' => $product_id,
        'element_type' => 'post_product',
    ]);
    if (is_object($details) && !empty($details->source_language_code)) {
        return true;
    }
    if (is_array($details) && !empty($details['source_language_code'])) {
        return true;
    }
    $default_lang = apply_filters('wpml_default_language', null);
    if (!$default_lang) {
        return false;
    }
    $original_id = (int) apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);

    return $original_id > 0 && $original_id !== $product_id;
}

/**
 * Classic WooCommerce product data meta box save (not Quick Edit, bulk, REST, or WPML editor).
 *
 * @param int $product_id
 * @return bool
 */
function intersoccer_attr_is_classic_product_edit_form_save($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
        return false;
    }
    if (empty($_POST['action']) || sanitize_key((string) wp_unslash($_POST['action'])) !== 'editpost') {
        return false;
    }
    if ((int) ($_POST['post_ID'] ?? 0) !== $product_id) {
        return false;
    }
    if (empty($_POST['woocommerce_meta_nonce'])) {
        return false;
    }

    return (bool) wp_verify_nonce(
        sanitize_text_field((string) wp_unslash($_POST['woocommerce_meta_nonce'])),
        'woocommerce_save_data'
    );
}

/**
 * @param mixed  $raw      Posted attribute_values entry (array of term IDs or pipe string).
 * @param string $taxonomy pa_* taxonomy.
 * @return array<int,int>
 */
function intersoccer_attr_term_ids_from_attribute_value($raw, $taxonomy) {
    $taxonomy = (string) $taxonomy;
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
    $val = is_string($raw) ? wp_unslash($raw) : '';
    if ($val === '') {
        return [];
    }
    foreach (array_filter(array_map('trim', explode('|', (string) $val))) as $piece) {
        if (is_numeric($piece)) {
            $ids[] = (int) $piece;
            continue;
        }
        $term = get_term_by('slug', sanitize_title($piece), $taxonomy);
        if (!$term) {
            $term = get_term_by('name', $piece, $taxonomy);
        }
        if ($term && !is_wp_error($term)) {
            $ids[] = (int) $term->term_id;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Term IDs assigned to a taxonomy on the current classic product save request.
 *
 * @param int    $product_id Unused; reserved for future context.
 * @param string $taxonomy   pa_* taxonomy.
 * @return array<int,int>
 */
function intersoccer_attr_posted_taxonomy_term_ids($product_id, $taxonomy) {
    unset($product_id);
    $taxonomy = (string) $taxonomy;
    if (strpos($taxonomy, 'pa_') !== 0) {
        $taxonomy = 'pa_' . $taxonomy;
    }
    $ids = [];
    if (!empty($_POST['tax_input'][$taxonomy]) && is_array($_POST['tax_input'][$taxonomy])) {
        foreach ($_POST['tax_input'][$taxonomy] as $raw) {
            $ids = array_merge($ids, intersoccer_attr_term_ids_from_attribute_value($raw, $taxonomy));
        }
    }
    if (!empty($_POST['attribute_names']) && is_array($_POST['attribute_names'])) {
        $values = isset($_POST['attribute_values']) && is_array($_POST['attribute_values'])
            ? $_POST['attribute_values']
            : [];
        foreach ($_POST['attribute_names'] as $i => $name) {
            $name = function_exists('wc_sanitize_taxonomy_name')
                ? wc_sanitize_taxonomy_name((string) wp_unslash($name))
                : sanitize_title((string) wp_unslash($name));
            $tax = (strpos($name, 'pa_') === 0) ? $name : ('pa_' . $name);
            if ($tax !== $taxonomy || !isset($values[$i])) {
                continue;
            }
            $ids = array_merge($ids, intersoccer_attr_term_ids_from_attribute_value($values[$i], $taxonomy));
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * @return bool True when the request includes posted InterSoccer pa_* attribute data.
 */
function intersoccer_attr_admin_product_save_has_posted_pa_attributes() {
    if (!empty($_POST['tax_input']) && is_array($_POST['tax_input'])) {
        foreach ($_POST['tax_input'] as $taxonomy => $terms) {
            if (strpos((string) $taxonomy, 'pa_') !== 0) {
                continue;
            }
            if (!empty(array_filter((array) $terms, static function ($v) {
                return $v !== '' && $v !== null;
            }))) {
                return true;
            }
        }
    }
    if (!empty($_POST['attribute_names']) && is_array($_POST['attribute_names'])) {
        $values = isset($_POST['attribute_values']) && is_array($_POST['attribute_values'])
            ? $_POST['attribute_values']
            : [];
        foreach ($_POST['attribute_names'] as $i => $name) {
            $name = function_exists('wc_sanitize_taxonomy_name')
                ? wc_sanitize_taxonomy_name((string) wp_unslash($name))
                : sanitize_title((string) wp_unslash($name));
            $tax_check = (strpos($name, 'pa_') === 0) ? $name : ('pa_' . $name);
            if (!isset($values[$i])) {
                continue;
            }
            if (intersoccer_attr_term_ids_from_attribute_value($values[$i], $tax_check) !== []) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param int         $product_id
 * @param string      $product_type
 * @param WC_Product|null $product Fallback when POST has no attribute payload.
 * @return array<int,string> pa_* taxonomies still missing.
 */
function intersoccer_attr_missing_required_parent_facets_from_request($product_id, $product_type, $product = null) {
    if (intersoccer_attr_admin_product_save_has_posted_pa_attributes()) {
        $assigned = [];
        if (!empty($_POST['tax_input']) && is_array($_POST['tax_input'])) {
            foreach ($_POST['tax_input'] as $taxonomy => $terms) {
                $taxonomy = (string) $taxonomy;
                if (strpos($taxonomy, 'pa_') !== 0) {
                    continue;
                }
                if (empty(array_filter((array) $terms, static function ($v) {
                    return $v !== '' && $v !== null;
                }))) {
                    continue;
                }
                $assigned[] = $taxonomy;
            }
        }
        if (!empty($_POST['attribute_names']) && is_array($_POST['attribute_names'])) {
            $values = isset($_POST['attribute_values']) && is_array($_POST['attribute_values'])
                ? $_POST['attribute_values']
                : [];
            foreach ($_POST['attribute_names'] as $i => $name) {
                $name = function_exists('wc_sanitize_taxonomy_name')
                    ? wc_sanitize_taxonomy_name((string) wp_unslash($name))
                    : sanitize_title((string) wp_unslash($name));
                $tax = (strpos($name, 'pa_') === 0) ? $name : ('pa_' . $name);
                if (!isset($values[$i])) {
                    continue;
                }
                if (intersoccer_attr_term_ids_from_attribute_value($values[$i], $tax) !== []) {
                    $assigned[] = $tax;
                }
            }
        }
        $assigned = array_values(array_unique($assigned));

        return intersoccer_attr_parent_facets_block_publish($assigned, $product_type);
    }
    if ($product instanceof WC_Product) {
        return intersoccer_attr_missing_required_parent_facets_from_product($product, $product_type);
    }

    return [];
}

/**
 * Term-shape violations from the save request (posted values when present).
 *
 * @param int         $product_id
 * @param WC_Product|null $product
 * @return array<int,string>
 */
function intersoccer_attr_product_term_shape_violations_from_request($product_id, $product = null) {
    $violations = [];
    $seen = [];
    $slugs = array_merge(
        intersoccer_attr_locked_term_attribute_slugs(),
        ['program-year']
    );
    $use_post = intersoccer_attr_admin_product_save_has_posted_pa_attributes();
    foreach ($slugs as $slug) {
        $taxonomy = function_exists('intersoccer_attr_taxonomy')
            ? intersoccer_attr_taxonomy($slug)
            : ('pa_' . $slug);
        if (function_exists('taxonomy_exists') && !taxonomy_exists($taxonomy)) {
            continue;
        }
        $term_rows = [];
        if ($use_post) {
            foreach (intersoccer_attr_posted_taxonomy_term_ids($product_id, $taxonomy) as $term_id) {
                $term = get_term((int) $term_id, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $term_rows[] = $term;
                }
            }
        } elseif ($product instanceof WC_Product && function_exists('wc_get_product_terms')) {
            $stored = wc_get_product_terms((int) $product->get_id(), $taxonomy, ['fields' => 'all']);
            if (!empty($stored) && !is_wp_error($stored)) {
                $term_rows = $stored;
            }
        }
        foreach ($term_rows as $term) {
            $term_name = is_object($term) ? (string) ($term->name ?? '') : '';
            $term_slug = is_object($term) ? (string) ($term->slug ?? '') : '';
            $result = intersoccer_attr_validate_new_term($taxonomy, $term_name, $term_slug);
            if (is_wp_error($result)) {
                $msg = intersoccer_attr_admin_label_for_taxonomy($taxonomy) . ': ' . $result->get_error_message();
                if (isset($seen[$msg])) {
                    continue;
                }
                $seen[$msg] = true;
                $violations[] = $msg;
            }
        }
    }

    return $violations;
}

/**
 * @param int    $product_id
 * @param string $product_type
 * @param WC_Product|null $product
 * @return array{shape_errors:array<int,string>,missing_parent:array<int,string>,block_publish:bool}
 */
function intersoccer_attr_evaluate_program_product_publish_block($product_id, $product_type, $product = null) {
    if (intersoccer_attr_is_wpml_translation_product($product_id)) {
        return [
            'shape_errors' => [],
            'missing_parent' => [],
            'block_publish' => false,
        ];
    }
    if (!($product instanceof WC_Product) && $product_id > 0 && function_exists('wc_get_product')) {
        $product = wc_get_product($product_id);
    }
    $shape_errors = intersoccer_attr_product_term_shape_violations_from_request($product_id, $product);
    $missing_parent = intersoccer_attr_missing_required_parent_facets_from_request($product_id, $product_type, $product);

    return [
        'shape_errors' => $shape_errors,
        'missing_parent' => $missing_parent,
        'block_publish' => !empty($shape_errors) || !empty($missing_parent),
    ];
}

/**
 * @param array<int,string> $missing_parent pa_* taxonomies.
 * @param array<int,string> $shape_errors
 * @return string
 */
function intersoccer_attr_publish_block_detail_message(array $missing_parent, array $shape_errors) {
    $parts = [];
    if (!empty($missing_parent)) {
        $labels = array_map('intersoccer_attr_admin_label_for_taxonomy', $missing_parent);
        $parts[] = sprintf(
            /* translators: %s: comma-separated attribute labels */
            __('Missing required parent attributes: %s.', 'intersoccer-product-variations'),
            implode(', ', $labels)
        );
    }
    if (!empty($shape_errors)) {
        $parts[] = sprintf(
            /* translators: %s: semicolon-separated term-shape errors */
            __('Term-shape violations (taxonomy standard): %s', 'intersoccer-product-variations'),
            implode('; ', $shape_errors)
        );
    }

    return implode(' ', $parts);
}

/**
 * Block invalid publish before wp_insert_post writes a publish status (WPML twin safety).
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed> $postarr
 * @return array<string,mixed>
 */
add_filter('wp_insert_post_data', 'intersoccer_attr_wp_insert_post_data_enforce_publish_rules', 99, 2);
function intersoccer_attr_wp_insert_post_data_enforce_publish_rules($data, $postarr) {
    if (($data['post_type'] ?? '') !== 'product') {
        return $data;
    }
    if (!is_admin() || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return $data;
    }
    if (function_exists('intersoccer_attr_sync_in_progress') && intersoccer_attr_sync_in_progress()) {
        return $data;
    }
    $requested = sanitize_key((string) ($data['post_status'] ?? ''));
    if (!in_array($requested, ['publish', 'future'], true)) {
        return $data;
    }
    $product_id = (int) ($postarr['ID'] ?? 0);
    if ($product_id <= 0 && !empty($_POST['post_ID'])) {
        $product_id = (int) $_POST['post_ID'];
    }
    if ($product_id <= 0 || !current_user_can('edit_product', $product_id)) {
        return $data;
    }
    if (!intersoccer_attr_is_classic_product_edit_form_save($product_id)) {
        return $data;
    }
    if (intersoccer_attr_is_wpml_translation_product($product_id)) {
        return $data;
    }
    $product_type = function_exists('intersoccer_get_product_type')
        ? strtolower((string) intersoccer_get_product_type($product_id))
        : '';
    if (!in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
        return $data;
    }
    if (intersoccer_attr_product_stored_status_is_live($product_id)) {
        return $data;
    }
    $evaluation = intersoccer_attr_evaluate_program_product_publish_block($product_id, $product_type);
    if (empty($evaluation['block_publish'])) {
        return $data;
    }
    $data['post_status'] = 'draft';
    if (!isset($GLOBALS['intersoccer_attr_publish_blocked_pre_write'])) {
        $GLOBALS['intersoccer_attr_publish_blocked_pre_write'] = [];
    }
    $GLOBALS['intersoccer_attr_publish_blocked_pre_write'][$product_id] = true;
    $detail = intersoccer_attr_publish_block_detail_message(
        $evaluation['missing_parent'],
        $evaluation['shape_errors']
    );
    $blocked_message = __('Publish blocked.', 'intersoccer-product-variations') . ' ' . $detail;
    add_settings_error(
        'intersoccer_attr_enforcement',
        'intersoccer_attr_publish_blocked_' . $product_id,
        $blocked_message,
        'error'
    );
    intersoccer_attr_store_product_save_notice($product_id, $blocked_message, 'error');

    return $data;
}

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

    if (intersoccer_attr_is_wpml_translation_product($product_id)) {
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

    $shape_errors = intersoccer_attr_product_term_shape_violations_from_request($product_id, $product);
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

    $missing_parent = intersoccer_attr_missing_required_parent_facets_from_request($product_id, $product_type, $product);
    $wants_publish = ($product->get_status() === 'publish');
    $has_publish_block = $wants_publish && (!empty($missing_parent) || !empty($shape_errors));

    if (!$has_publish_block) {
        intersoccer_attr_clear_product_save_notice($product_id);
    } else {
        $detail = intersoccer_attr_publish_block_detail_message($missing_parent, $shape_errors);

        if (intersoccer_attr_product_stored_status_is_live($product_id)) {
            if (intersoccer_attr_product_pre_save_status($product_id) !== 'private') {
                $persist_message = __('Saved and kept live, but fix before next publish:', 'intersoccer-product-variations') . ' ' . $detail;
                intersoccer_attr_store_product_save_notice($product_id, $persist_message, 'warning');
            }
        } elseif (empty($GLOBALS['intersoccer_attr_publish_blocked_pre_write'][$product_id])) {
            $product->set_status('draft');
            $blocked_message = __('Publish blocked.', 'intersoccer-product-variations') . ' ' . $detail;
            add_settings_error(
                'intersoccer_attr_enforcement',
                'intersoccer_attr_publish_blocked_' . $product_id,
                $blocked_message,
                'error'
            );
            intersoccer_attr_store_product_save_notice($product_id, $blocked_message, 'error');
        }
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
 * Camp-times is HHMM-HHMM format-checked (not limited to registry defaults).
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
    ];
}

/**
 * Strip WPML/WCML suffix from a camp time slug and return HHMM-HHMM, or empty.
 *
 * @param string $slug Term slug (may include -fr, -de, -en, or -digits).
 * @return string
 */
function intersoccer_attr_camp_time_range_from_slug($slug) {
    $slug = strtolower(trim((string) $slug));
    if ($slug === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{4})(?:-(?:fr|de|en|\d+))?$/', $slug, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * @param string $name Term name (must be literal HHMM-HHMM).
 * @return bool
 */
function intersoccer_attr_strict_camp_time_name_is_valid($name) {
    $name = trim((string) $name);
    if (!preg_match('/^\d{4}-\d{4}$/', $name)) {
        return false;
    }

    return intersoccer_attr_is_valid_camp_time_range(strtolower($name));
}

/**
 * @param string $slug Term slug (HHMM-HHMM or HHMM-HHMM with WPML suffix).
 * @return bool
 */
function intersoccer_attr_camp_time_slug_with_suffix_is_valid($slug) {
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^\d{4}-\d{4}(?:-(?:fr|de|en|\d+))?$/', $slug)) {
        return false;
    }
    $range = intersoccer_attr_camp_time_range_from_slug($slug);

    return $range !== '' && intersoccer_attr_is_valid_camp_time_range($range);
}

/**
 * @param string $range Normalized HHMM-HHMM.
 * @return bool
 */
function intersoccer_attr_is_valid_camp_time_range($range) {
    $range = (string) $range;
    if (!preg_match('/^(\d{2})(\d{2})-(\d{2})(\d{2})$/', $range, $m)) {
        return false;
    }
    $start_h = (int) $m[1];
    $start_m = (int) $m[2];
    $end_h = (int) $m[3];
    $end_m = (int) $m[4];
    if ($start_h > 23 || $end_h > 23 || $start_m > 59 || $end_m > 59) {
        return false;
    }
    $start_minutes = ($start_h * 60) + $start_m;
    $end_minutes = ($end_h * 60) + $end_m;

    return $start_minutes < $end_minutes;
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
    $slug_explicit = ($slug !== '');

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
                __('Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and the Year attribute.', 'intersoccer-product-variations')
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
                __('Program year must be a bare calendar year (e.g. 2026). Use the Year attribute only.', 'intersoccer-product-variations')
            );
        }
        if ($bare === '') {
            return new WP_Error(
                'non_bare_year',
                __('Program year must be a bare calendar year (e.g. 2026). Use the Year attribute only.', 'intersoccer-product-variations')
            );
        }
        return true;
    }

    if ($attr_slug === 'camp-times') {
        if (!intersoccer_attr_strict_camp_time_name_is_valid($name)) {
            return new WP_Error(
                'invalid_camp_time',
                __('Camp times must use HHMM-HHMM format (e.g. 1000-1700) with start before end.', 'intersoccer-product-variations')
            );
        }
        if ($slug_explicit && !intersoccer_attr_camp_time_slug_with_suffix_is_valid($slug)) {
            return new WP_Error(
                'invalid_camp_time',
                __('Camp times must use HHMM-HHMM format (e.g. 1000-1700) with start before end.', 'intersoccer-product-variations')
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
