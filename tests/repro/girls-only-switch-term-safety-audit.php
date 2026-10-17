<?php
/**
 * Read-only safety audit: every girls-only switch attribute term (all WPML languages)
 * through intersoccer_switch_term_indicates_girls_only_session() on the mounted PV plugin.
 *
 * Run (sql-sandbox):
 *   docker compose run --rm -v $PV:/var/www/html/wp-content/plugins/intersoccer-product-variations:ro \
 *     -v $WT/tests/repro:/repro:ro wpcli eval-file /repro/girls-only-switch-term-safety-audit.php
 *
 * Writes JSON lines to stdout (one object per row). No DB writes.
 */
if (!function_exists('intersoccer_taxonomy_is_girls_only_switch_attribute')) {
    fwrite(STDERR, "PV girls-only-verification not loaded\n");
    exit(1);
}

$switch_taxonomies = [];
if (function_exists('wc_get_attribute_taxonomies')) {
    foreach (wc_get_attribute_taxonomies() as $att) {
        $name = is_object($att) ? ($att->attribute_name ?? '') : '';
        if ($name === '') {
            continue;
        }
        $tax = 'pa_' . wc_sanitize_taxonomy_name($name);
        if (intersoccer_taxonomy_is_girls_only_switch_attribute($tax)) {
            $switch_taxonomies[$tax] = true;
        }
    }
}
foreach (intersoccer_get_girls_only_attribute_taxonomies() as $tax) {
    if (intersoccer_taxonomy_is_girls_only_switch_attribute($tax)) {
        $switch_taxonomies[$tax] = true;
    }
}
$switch_taxonomies = array_keys($switch_taxonomies);
sort($switch_taxonomies);
$switch_taxonomies = array_values(array_filter($switch_taxonomies, static function ($tax) {
    return taxonomy_exists($tax);
}));

$lang_codes = ['en'];
if (function_exists('apply_filters')) {
    $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
    if (is_array($active) && $active !== []) {
        $lang_codes = array_keys($active);
    }
}
sort($lang_codes);

$emit = static function (array $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
};

foreach ($switch_taxonomies as $taxonomy) {
    foreach ($lang_codes as $lang) {
        if (function_exists('do_action')) {
            do_action('wpml_switch_language', $lang);
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            $emit([
                'kind'     => 'error',
                'taxonomy' => $taxonomy,
                'language' => $lang,
                'message'  => $terms->get_error_message(),
            ]);
            continue;
        }
        foreach ($terms as $term) {
            $slug = (string) $term->slug;
            $name = (string) $term->name;
            $emit([
                'kind'     => 'term',
                'taxonomy' => $taxonomy,
                'language' => $lang,
                'term_id'  => (int) $term->term_id,
                'slug'     => $slug,
                'name'     => $name,
                'switch'   => (bool) intersoccer_switch_term_indicates_girls_only_session($slug, $name),
            ]);
        }
    }
}

// Products / variations with canonical girls-only flag or switch attribute selection.
global $wpdb;
$prefix = $wpdb->prefix;
$meta_rows = $wpdb->get_results(
    "SELECT post_id, meta_value FROM {$prefix}postmeta
     WHERE meta_key = '_intersoccer_canonical_girls_only' AND meta_value IN ('1','yes','true')
     ORDER BY post_id",
    ARRAY_A
);
$seen_product = [];
foreach ($meta_rows as $row) {
    $post_id = (int) $row['post_id'];
    if (isset($seen_product[$post_id])) {
        continue;
    }
    $seen_product[$post_id] = true;
    $post = get_post($post_id);
    if (!$post) {
        continue;
    }
    $product_id = (int) $post_id;
    $variation_id = 0;
    if ($post->post_type === 'product_variation') {
        $variation_id = $product_id;
        $product_id = (int) wp_get_post_parent_id($post_id);
    }
    $lang = function_exists('apply_filters')
        ? (string) apply_filters('wpml_element_language_code', null, [
            'element_id'   => $post_id,
            'element_type' => 'post_' . $post->post_type,
        ])
        : 'en';
    foreach ($switch_taxonomies as $taxonomy) {
        $pairs = intersoccer_collect_taxonomy_term_pairs_for_line($product_id, $variation_id, $taxonomy);
        if ($pairs === []) {
            continue;
        }
        foreach ($pairs as $pair) {
            $slug = (string) ($pair['slug'] ?? '');
            $name = (string) ($pair['name'] ?? '');
            $emit([
                'kind'         => 'flagged_line',
                'taxonomy'     => $taxonomy,
                'language'     => $lang,
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'slug'         => $slug,
                'name'         => $name,
                'switch'       => (bool) intersoccer_switch_term_indicates_girls_only_session($slug, $name),
                'line_girls_only' => (bool) intersoccer_line_is_girls_only_program($product_id, $variation_id),
            ]);
        }
    }
}
