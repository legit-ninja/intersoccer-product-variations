<?php
/**
 * Girls-only programs: require female assigned player at add-to-cart.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity-type markers that mean "girls only" (not generic camp/event terms).
 *
 * @return string[] Lowercase markers matched against term slug and name.
 */
function intersoccer_get_girls_only_activity_markers() {
    return [
        'girls-only',
        'girls_only',
        'girlsonly',
        'girls only',
        "girl's only",
        'girl’s only',
        'filles',
        'madchen',
        'mädchen',
    ];
}

/**
 * Product attribute taxonomies whose terms flag a girls-only session (e.g. Woo "Girl's Only" attribute).
 * Filter to add your catalog slug if different (e.g. pa_audience).
 *
 * @return string[]
 */
function intersoccer_get_girls_only_attribute_taxonomies() {
    $defaults = ['pa_girls-only', 'pa_girls_only', 'pa_girl-only', 'pa_girl_only'];
    return apply_filters('intersoccer_girls_only_attribute_taxonomies', $defaults);
}

/**
 * Whether this attribute taxonomy is a dedicated yes/no (or exclusive) girls-only selector.
 *
 * @param string $taxonomy e.g. pa_girls-only
 */
function intersoccer_taxonomy_is_girls_only_switch_attribute($taxonomy) {
    $tax = strtolower((string) $taxonomy);
    if (preg_match('/^pa_girls?[\s_-]?only$/', $tax) || preg_match('/^pa_girls?_?only$/', $tax)) {
        return true;
    }
    return (bool) apply_filters('intersoccer_taxonomy_is_girls_only_switch_attribute', false, $taxonomy);
}

/**
 * For switch-style girls-only attributes: selected term means girls-only unless explicitly co-ed / no.
 *
 * @param string $slug Term slug.
 * @param string $name Term name.
 */
function intersoccer_switch_term_indicates_girls_only_session($slug, $name) {
    $slug_c = strtolower(trim((string) $slug));
    $name_c = strtolower(trim((string) $name));
    if (function_exists('remove_accents')) {
        $slug_c = strtolower(remove_accents($slug_c));
        $name_c = strtolower(remove_accents($name_c));
    }
    $hay = trim($slug_c . ' ' . $name_c);
    if ($hay === '') {
        return false;
    }
    if (preg_match('/\b(no|non|nee|mixed|mixte|coed|co-ed|co_ed|jungs|garcons|garçons|open|alle)\b/iu', $hay)) {
        return false;
    }
    return true;
}

/**
 * Collect term slug/name pairs for a Woo attribute taxonomy (parent terms + variation selection).
 *
 * @param int    $product_id   Parent product ID.
 * @param int    $variation_id Variation ID (0 if none).
 * @param string $taxonomy    e.g. pa_activity-type, pa_girls-only
 * @return array<int, array{slug: string, name: string}>
 */
function intersoccer_collect_taxonomy_term_pairs_for_line($product_id, $variation_id, $taxonomy) {
    $out = [];
    $seen = [];

    $push = static function ($slug, $name) use (&$out, &$seen) {
        $slug = is_string($slug) ? trim($slug) : '';
        $name = is_string($name) ? trim($name) : '';
        if ($slug === '' && $name === '') {
            return;
        }
        $key = strtolower($slug . '|' . $name);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $out[] = ['slug' => $slug, 'name' => $name];
    };

    $product_id = (int) $product_id;
    if ($product_id <= 0 || !is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return $out;
    }

    $terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'all']);
    if (is_array($terms)) {
        foreach ($terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $slug = isset($term->slug) ? (string) $term->slug : '';
            $name = isset($term->name) ? (string) $term->name : '';
            $push($slug, $name);
        }
    }

    $variation_id = (int) $variation_id;
    if ($variation_id > 0) {
        $meta_key = 'attribute_' . $taxonomy;
        $vslug = get_post_meta($variation_id, $meta_key, true);
        if (is_string($vslug) && $vslug !== '') {
            $t = get_term_by('slug', $vslug, $taxonomy);
            if ($t && !is_wp_error($t)) {
                $push((string) $t->slug, (string) $t->name);
            } else {
                $push($vslug, $vslug);
            }
        }
        $variation = wc_get_product($variation_id);
        if ($variation) {
            $attr = $variation->get_attribute($taxonomy);
            if (is_string($attr) && $attr !== '') {
                $maybe_slug = sanitize_title($attr);
                $t = get_term_by('slug', $maybe_slug, $taxonomy);
                if ($t && !is_wp_error($t)) {
                    $push((string) $t->slug, (string) $t->name);
                } else {
                    $push($maybe_slug, $attr);
                }
            }
        }
    }

    return $out;
}

/**
 * Whether a single activity-type slug or label indicates girls-only.
 *
 * @param string $slug Term slug (may be empty).
 * @param string $name Term name (may be empty).
 * @return bool
 */
function intersoccer_activity_type_term_is_girls_only($slug, $name) {
    $slug_cmp = strtolower(trim((string) $slug));
    $name_cmp = strtolower(trim((string) $name));
    if (function_exists('remove_accents')) {
        $slug_cmp = strtolower(remove_accents($slug_cmp));
        $name_cmp = strtolower(remove_accents($name_cmp));
    }

    foreach (intersoccer_get_girls_only_activity_markers() as $marker) {
        $m = strtolower(trim($marker));
        if ($m === '') {
            continue;
        }
        if (function_exists('remove_accents')) {
            $m = strtolower(remove_accents($m));
        }
        if ($slug_cmp === $m || $name_cmp === $m) {
            return true;
        }
        if ($slug_cmp !== '' && strpos($slug_cmp, $m) !== false) {
            return true;
        }
        if ($name_cmp !== '' && strpos($name_cmp, $m) !== false) {
            return true;
        }
    }

    $hay = trim($slug_cmp . ' ' . $name_cmp);
    if ($hay !== '') {
        if (preg_match('/(?:girls?[\s_-]+only|girl[\x27\x{2019}]?s[\s_-]+only|only[\s_-]+girls?|girlsonly|nur[\s_-]*madchen|filles[\s_-]*uniquement|reserve[\s_-]?aux[\s_-]?filles|r\w{1,3}serv\w*\s+aux\s+filles|nur[\s_-]*f[uü]r[\s_-]*madchen)/iu', $hay)) {
            return true;
        }
    }

    return false;
}

/**
 * Collect pa_activity-type term slug/name pairs for parent and optional variation.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID (0 if none).
 * @return array<int, array{slug: string, name: string}>
 */
function intersoccer_collect_activity_type_terms_for_line($product_id, $variation_id) {
    return intersoccer_collect_taxonomy_term_pairs_for_line($product_id, $variation_id, 'pa_activity-type');
}

/**
 * Whether the product line is restricted to female players (girls-only).
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID (0 if none).
 * @return bool
 */
function intersoccer_line_is_girls_only_program($product_id, $variation_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
        return (bool) apply_filters('intersoccer_line_is_girls_only_program', false, $product_id, $variation_id);
    }

    $pairs = intersoccer_collect_activity_type_terms_for_line($product_id, $variation_id);
    $is_girls_only = false;
    foreach ($pairs as $pair) {
        if (intersoccer_activity_type_term_is_girls_only($pair['slug'], $pair['name'])) {
            $is_girls_only = true;
            break;
        }
    }

    if (!$is_girls_only) {
        foreach (intersoccer_get_girls_only_attribute_taxonomies() as $tax) {
            if (!is_string($tax) || $tax === '' || $tax === 'pa_activity-type' || !taxonomy_exists($tax)) {
                continue;
            }
            $extra = intersoccer_collect_taxonomy_term_pairs_for_line($product_id, $variation_id, $tax);
            foreach ($extra as $pair) {
                if (intersoccer_taxonomy_is_girls_only_switch_attribute($tax)) {
                    if (intersoccer_activity_type_term_is_girls_only($pair['slug'], $pair['name'])
                        || intersoccer_switch_term_indicates_girls_only_session($pair['slug'], $pair['name'])) {
                        $is_girls_only = true;
                        break 2;
                    }
                } elseif (intersoccer_activity_type_term_is_girls_only($pair['slug'], $pair['name'])) {
                    $is_girls_only = true;
                    break 2;
                }
            }
        }
    }

    if (!$is_girls_only) {
        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        if (!is_wp_error($cats) && is_array($cats)) {
            foreach ($cats as $cat_slug) {
                if (is_string($cat_slug) && strtolower($cat_slug) === 'girls-only') {
                    $is_girls_only = true;
                    break;
                }
            }
        }
    }

    if (!$is_girls_only) {
        $title_blob = '';
        $pt = get_post_field('post_title', $product_id, 'raw');
        if (is_string($pt) && $pt !== '') {
            $title_blob .= $pt . ' ';
        }
        if ((int) $variation_id > 0) {
            $vt = get_post_field('post_title', (int) $variation_id, 'raw');
            if (is_string($vt) && $vt !== '') {
                $title_blob .= $vt . ' ';
            }
        }
        $tb = strtolower(trim($title_blob));
        if ($tb !== '' && function_exists('remove_accents')) {
            $tb = strtolower(remove_accents($tb));
        }
        if ($tb !== '' && preg_match('/\bgirls?\s+only\b|\bgirl[\x27\x{2019}]?s\s+only\b|\bfilles\s+uniquement\b|\bnur\s+(madchen|mädchen|maedchen)\b|\bgirls?\s*-\s*only\b/iu', $tb)) {
            $is_girls_only = true;
        }
    }

    return (bool) apply_filters('intersoccer_line_is_girls_only_program', $is_girls_only, $product_id, $variation_id);
}

/**
 * Normalize stored player gender to a coarse bucket.
 *
 * @param string $raw Value from player profile.
 * @return string One of female, male, other, unknown, or empty string when input is empty.
 */
function intersoccer_normalize_player_gender_bucket($raw) {
    $s = strtolower(trim((string) $raw));
    if ($s === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $s = strtolower(remove_accents($s));
    }

    static $female = null;
    static $male = null;
    static $other = null;
    if ($female === null) {
        $female = [
            'female', 'f', 'woman', 'girl', 'fille', 'filles', 'femme', 'feminin', 'féminin', 'feminine',
            'weiblich', 'frau', 'donna', 'mujer', 'madame', 'mme',
        ];
        $male = [
            'male', 'm', 'man', 'boy', 'garcon', 'garçon', 'homme', 'masculin', 'masculine', 'mannlich', 'männlich',
            'uomo', 'hombre', 'monsieur',
        ];
        $other = [
            'other', 'autre', 'autres', 'divers', 'andere', 'non-binary', 'nonbinary', 'x', 'prefer not', 'no answer',
            'keine angabe',
        ];
    }

    if (in_array($s, $female, true)) {
        return 'female';
    }
    if (in_array($s, $male, true)) {
        return 'male';
    }
    if (in_array($s, $other, true)) {
        return 'other';
    }

    if (preg_match('/\b(femme|feminin|féminin|feminine|weiblich|frau|female|filles)\b/iu', $s)) {
        return 'female';
    }
    if (preg_match('/\b(homme|masculin|masculine|mannlich|männlich|mann|male|garcon|garçon)\b/iu', $s)) {
        return 'male';
    }
    if (preg_match('/\b(autre|autres|divers|andere|other|nonbinary|non-binary)\b/iu', $s)) {
        return 'other';
    }

    return 'unknown';
}

/**
 * Validate assigned player gender for a girls-only product line.
 *
 * @param int $user_id      Customer user ID.
 * @param int $player_index intersoccer_players index.
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID.
 * @return true|WP_Error
 */
function intersoccer_validate_line_player_girls_only($user_id, $player_index, $product_id, $variation_id) {
    /**
     * Skip girls-only checks when true.
     *
     * @param bool $skip
     * @param int  $user_id
     * @param int  $player_index
     * @param int  $product_id
     * @param int  $variation_id
     */
    $skip = (bool) apply_filters('intersoccer_skip_girls_only_validation', false, $user_id, $player_index, $product_id, $variation_id);
    if ($skip) {
        return true;
    }

    if (!intersoccer_line_is_girls_only_program($product_id, (int) $variation_id)) {
        return true;
    }

    if (!function_exists('intersoccer_get_player_details')) {
        return true;
    }

    $player = intersoccer_get_player_details($user_id, $player_index);
    $gender_raw = isset($player['gender']) ? trim((string) $player['gender']) : '';

    if ($gender_raw === '') {
        $manage = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('manage-players') : '';
        $msg = $manage
            ? sprintf(
                /* translators: %s: URL to Manage Players */
                __('This session is for girls only. The selected attendee needs gender set to female in your account. Update under Manage Players: %s', 'intersoccer-product-variations'),
                $manage
            )
            : __('This session is for girls only. The selected attendee needs gender set to female in your account. Please update Manage Players before booking.', 'intersoccer-product-variations');
        return new WP_Error('intersoccer_girls_only_gender_required', $msg);
    }

    $bucket = intersoccer_normalize_player_gender_bucket($gender_raw);
    if ($bucket === 'female') {
        return true;
    }

    return new WP_Error(
        'intersoccer_girls_only_wrong_gender',
        __('This session is for girls only. Please choose a female attendee or pick a different session.', 'intersoccer-product-variations')
    );
}

/**
 * Add-to-cart: validate player gender for girls-only programs when player_assignment is posted.
 *
 * @param bool  $passed
 * @param int   $product_id
 * @param int   $quantity
 * @param int   $variation_id
 * @param array $variations
 * @param mixed $cart_item_data WooCommerce cart item data (optional).
 * @return bool
 */
function intersoccer_validate_player_girls_only_on_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = null) {
    if (!$passed) {
        return false;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return $passed;
    }

    $is_go = intersoccer_line_is_girls_only_program((int) $product_id, (int) $variation_id);
    if (!$is_go) {
        return $passed;
    }

    if (!function_exists('intersoccer_get_posted_player_assignment_index')) {
        return $passed;
    }
    $player_index = intersoccer_get_posted_player_assignment_index();
    if ($player_index === null) {
        return $passed;
    }
    $result = intersoccer_validate_line_player_girls_only($user_id, $player_index, (int) $product_id, absint($variation_id));
    if (is_wp_error($result)) {
        wc_add_notice($result->get_error_message(), 'error');
        return false;
    }

    return $passed;
}

add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_player_girls_only_on_add_to_cart', 21, 6);
