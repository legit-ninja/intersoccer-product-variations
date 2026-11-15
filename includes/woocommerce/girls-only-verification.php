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
 * Whole girls-only terms (slug or name). Matching is exact after normalisation,
 * never a substring of a longer label.
 *
 * Spellings already used in this plugin: activity slugs, the Girls Only attribute
 * term "Girl's Only", and the EN/FR/DE order-meta labels Girls Only / Filles
 * uniquement / Nur Mädchen (plus the ASCII and "nur für" / "réservé aux filles"
 * forms the previous whole-phrase check accepted).
 *
 * @return string[]
 */
function intersoccer_get_girls_only_activity_markers() {
    return [
        'girls-only',
        'girls_only',
        'girlsonly',
        'girls only',
        "girl's only",
        'girl’s only',
        "girls' only",
        'only-girls',
        'filles',
        'madchen',
        'maedchen',
        'mädchen',
        'filles uniquement',
        'nur mädchen',
        'nur madchen',
        'nur maedchen',
        'nur für mädchen',
        'réservé aux filles',
        'réservée aux filles',
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
 * Normalize a girls-only switch term slug or label for comparison.
 *
 * @param string $value Raw slug or display name.
 */
function intersoccer_normalize_girls_only_switch_token($value) {
    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $token = strtolower(remove_accents($token));
    }
    $token = preg_replace('/-(de|fr|en|it)$/', '', $token);
    $token = str_replace(["'", '’'], '', $token);
    $token = preg_replace('/[\s_]+/', '-', $token);
    $token = trim((string) $token, '-');
    return $token;
}

/**
 * @param string $token Normalized switch token.
 */
function intersoccer_girls_only_switch_token_has_leading_negation($token) {
    $token = (string) $token;
    if ($token === '') {
        return false;
    }
    $negations = ['no', 'non', 'not', 'nein', 'kein', 'keine', 'pas'];
    foreach ($negations as $neg) {
        if ($token === $neg || strpos($token, $neg . '-') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * For switch-style girls-only attributes: only an explicit "yes" means girls-only.
 *
 * @param string $slug Term slug.
 * @param string $name Term name.
 */
function intersoccer_switch_term_indicates_girls_only_session($slug, $name) {
    $yes_tokens = [
        'yes' => true,
        'ja' => true,
        'oui' => true,
        'girls-only' => true,
        'girlsonly' => true,
        'true' => true,
        '1' => true,
    ];

    foreach ([$slug, $name] as $part) {
        $token = intersoccer_normalize_girls_only_switch_token($part);
        if ($token === '') {
            continue;
        }
        if (intersoccer_girls_only_switch_token_has_leading_negation($token)) {
            return false;
        }
        if (isset($yes_tokens[$token])) {
            return true;
        }
    }

    return false;
}

/**
 * Normalise a term slug or label for an exact girls-only comparison.
 *
 * Lowercase, strip accents, drop straight and curly apostrophes, then remove
 * spaces, hyphens, and underscores so "Girls Only", "girls-only", and
 * "girlsonly" share one key. The whole term is compared; callers must not
 * search for this key inside a longer label.
 *
 * @param string $value Raw slug or display name.
 * @return string
 */
function intersoccer_normalize_girls_only_exact_term($value) {
    $token = strtolower(trim((string) $value));
    if ($token === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $token = strtolower(remove_accents($token));
    }
    $token = str_replace(["'", '’', '‘', '`', '´'], '', $token);
    $collapsed = preg_replace('/[\s_\-]+/', '', $token);
    return is_string($collapsed) ? $collapsed : '';
}

/**
 * Append one slug/name pair, skipping empties and duplicates.
 *
 * @param array<int, array{slug: string, name: string}> $out
 * @param array<string, bool>                           $seen
 * @param mixed                                         $slug
 * @param mixed                                         $name
 */
function intersoccer_append_taxonomy_term_pair(&$out, &$seen, $slug, $name) {
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
}

/**
 * Resolve a slug to its term, or keep the raw slug when the term is missing.
 *
 * @param array<int, array{slug: string, name: string}> $out
 * @param array<string, bool>                           $seen
 * @param string                                        $taxonomy
 * @param string                                        $slug
 * @param string                                        $fallback_name Used when the slug is not a known term.
 */
function intersoccer_append_taxonomy_term_slug(&$out, &$seen, $taxonomy, $slug, $fallback_name) {
    $slug = trim((string) $slug);
    if ($slug === '') {
        return;
    }
    $term = get_term_by('slug', $slug, $taxonomy);
    if ($term && !is_wp_error($term)) {
        intersoccer_append_taxonomy_term_pair(
            $out,
            $seen,
            isset($term->slug) ? (string) $term->slug : $slug,
            isset($term->name) ? (string) $term->name : $fallback_name
        );
        return;
    }
    $name = trim((string) $fallback_name);
    if ($name === '') {
        $name = $slug;
    }
    intersoccer_append_taxonomy_term_pair($out, $seen, $slug, $name);
}

/**
 * Term pairs from the variation's own attribute value.
 *
 * Null when the variation has no value for this taxonomy (empty
 * attribute_<taxonomy> meta and empty get_attribute). An array, possibly
 * empty, means the variation selected a value and parent terms must not be
 * merged in.
 *
 * @param int    $variation_id Variation ID.
 * @param string $taxonomy     e.g. pa_activity-type, pa_girls-only.
 * @return array<int, array{slug: string, name: string}>|null
 */
function intersoccer_collect_variation_taxonomy_term_pairs($variation_id, $taxonomy) {
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0 || !is_string($taxonomy) || $taxonomy === '') {
        return null;
    }

    $out = [];
    $seen = [];
    $has_own = false;

    $meta_key = 'attribute_' . $taxonomy;
    $vslug = get_post_meta($variation_id, $meta_key, true);
    if (is_string($vslug) && $vslug !== '') {
        $has_own = true;
        intersoccer_append_taxonomy_term_slug($out, $seen, $taxonomy, $vslug, $vslug);
    }

    $variation = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
    if ($variation && method_exists($variation, 'get_attribute')) {
        $attr = $variation->get_attribute($taxonomy);
        if (is_string($attr) && $attr !== '') {
            $has_own = true;
            $maybe_slug = function_exists('sanitize_title') ? sanitize_title($attr) : $attr;
            intersoccer_append_taxonomy_term_slug($out, $seen, $taxonomy, $maybe_slug, $attr);
        }
    }

    if (!$has_own) {
        return null;
    }

    return $out;
}

/**
 * Collect term slug/name pairs for one taxonomy on a product line.
 *
 * A variation that has its own value (attribute_<taxonomy> meta or
 * get_attribute) contributes only that value. Parent terms are used when
 * there is no variation, or when the variation has no value for this
 * taxonomy (a product-level attribute).
 *
 * @param int    $product_id   Parent product ID.
 * @param int    $variation_id Variation ID (0 if none).
 * @param string $taxonomy     e.g. pa_activity-type, pa_girls-only
 * @return array<int, array{slug: string, name: string}>
 */
function intersoccer_collect_taxonomy_term_pairs_for_line($product_id, $variation_id, $taxonomy) {
    $product_id = (int) $product_id;
    if ($product_id <= 0 || !is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return [];
    }

    $variation_id = (int) $variation_id;
    if ($variation_id > 0) {
        $own = intersoccer_collect_variation_taxonomy_term_pairs($variation_id, $taxonomy);
        if (is_array($own)) {
            return $own;
        }
    }

    $out = [];
    $seen = [];
    $terms = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'all']);
    if (is_array($terms)) {
        foreach ($terms as $term) {
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $slug = isset($term->slug) ? (string) $term->slug : '';
            $name = isset($term->name) ? (string) $term->name : '';
            intersoccer_append_taxonomy_term_pair($out, $seen, $slug, $name);
        }
    }

    return $out;
}

/**
 * Whether a single activity-type slug or label is a whole girls-only term.
 *
 * @param string $slug Term slug (may be empty).
 * @param string $name Term name (may be empty).
 * @return bool
 */
function intersoccer_activity_type_term_is_girls_only($slug, $name) {
    static $keys = null;
    if ($keys === null) {
        $keys = [];
        foreach (intersoccer_get_girls_only_activity_markers() as $marker) {
            $normalized = intersoccer_normalize_girls_only_exact_term($marker);
            if ($normalized !== '') {
                $keys[$normalized] = true;
            }
        }
    }

    foreach ([$slug, $name] as $part) {
        $token = intersoccer_normalize_girls_only_exact_term($part);
        if ($token !== '' && isset($keys[$token])) {
            return true;
        }
    }

    return false;
}

/**
 * Whether every pair is girls-only, or whether any pair is.
 *
 * Parent fallback for a variation uses the exclusive check so a parent that
 * carries both a girls-only term and another term does not mark the variation
 * girls-only. The line's own values use any-match.
 *
 * @param array<int, array{slug: string, name: string}> $pairs
 * @param bool                                          $is_switch Dedicated yes/no girls-only attribute.
 * @param bool                                          $require_exclusive
 * @return bool
 */
function intersoccer_girls_only_term_pairs_match($pairs, $is_switch, $require_exclusive) {
    if (!is_array($pairs) || $pairs === []) {
        return false;
    }

    $matched = false;
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            if ($require_exclusive) {
                return false;
            }
            continue;
        }
        $slug = isset($pair['slug']) ? $pair['slug'] : '';
        $name = isset($pair['name']) ? $pair['name'] : '';
        $is_girls_only = intersoccer_activity_type_term_is_girls_only($slug, $name);
        if ($is_switch && !$is_girls_only) {
            $is_girls_only = intersoccer_switch_term_indicates_girls_only_session($slug, $name);
        }
        if ($is_girls_only) {
            if (!$require_exclusive) {
                return true;
            }
            $matched = true;
            continue;
        }
        if ($require_exclusive) {
            return false;
        }
    }

    return $matched;
}

/**
 * Girls-only decision for one taxonomy on a product line.
 *
 * Own variation value wins. A variation with no value for this taxonomy falls
 * back to the parent product's terms, and only when those terms are exclusively
 * girls-only. A simple product uses its own terms (any exact girls-only term).
 *
 * @param int    $product_id
 * @param int    $variation_id
 * @param string $taxonomy
 * @param bool   $is_switch
 * @return bool
 */
function intersoccer_line_taxonomy_terms_are_girls_only($product_id, $variation_id, $taxonomy, $is_switch) {
    if (!is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return false;
    }

    $variation_id = (int) $variation_id;
    if ($variation_id > 0) {
        $own = intersoccer_collect_variation_taxonomy_term_pairs($variation_id, $taxonomy);
        if (is_array($own)) {
            return intersoccer_girls_only_term_pairs_match($own, $is_switch, false);
        }
    }

    $pairs = intersoccer_collect_taxonomy_term_pairs_for_line($product_id, 0, $taxonomy);
    $parent_fallback = $variation_id > 0;
    return intersoccer_girls_only_term_pairs_match($pairs, $is_switch, $parent_fallback);
}

/**
 * Collect pa_activity-type pairs for a line (variation value, else parent terms).
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

    $is_girls_only = intersoccer_line_taxonomy_terms_are_girls_only(
        $product_id,
        $variation_id,
        'pa_activity-type',
        false
    );

    if (!$is_girls_only) {
        foreach (intersoccer_get_girls_only_attribute_taxonomies() as $tax) {
            if (!is_string($tax) || $tax === '' || $tax === 'pa_activity-type' || !taxonomy_exists($tax)) {
                continue;
            }
            if (intersoccer_line_taxonomy_terms_are_girls_only(
                $product_id,
                $variation_id,
                $tax,
                intersoccer_taxonomy_is_girls_only_switch_attribute($tax)
            )) {
                $is_girls_only = true;
                break;
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
        // Whole phrases only. A mixed title such as "Garçons et filles" does not match.
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
 * Existing customer-facing girls-only decision for one gender value.
 *
 * Empty gender uses the gender-required notice. Any other non-female value uses
 * the wrong-gender notice.
 *
 * @param string $gender_raw Stored gender, or empty when the attendee cannot be checked.
 * @return true|WP_Error
 */
function intersoccer_girls_only_validation_result_for_gender($gender_raw) {
    $gender_raw = trim((string) $gender_raw);
    if ($gender_raw === '') {
        $msg = function_exists('intersoccer_pv_girls_only_gender_required_message')
            ? intersoccer_pv_girls_only_gender_required_message()
            : __(
                'This session is for girls only. The selected attendee needs gender set to female in your account. Please update Manage Players before booking.',
                'intersoccer-product-variations'
            );
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

    return intersoccer_girls_only_validation_result_for_gender($gender_raw);
}

/**
 * Resolve the attendee posted with add-to-cart.
 *
 * Storefront dropdowns post the player UUID. Older clients post a numeric
 * intersoccer_players index. A non-empty value that matches neither is unresolved.
 *
 * @param int $user_id Customer user ID.
 * @return array{state:string,gender?:string,index?:int|string|null}
 */
function intersoccer_resolve_posted_girls_only_attendee($user_id) {
    $values = [];
    foreach (['player_assignment', 'assigned_attendee', 'assigned_player_id'] as $field) {
        if (!isset($_POST[$field])) {
            continue;
        }
        $val = trim((string) wp_unslash($_POST[$field]));
        if ($val !== '') {
            $values[] = $val;
        }
    }
    if ($values === []) {
        return ['state' => 'absent'];
    }

    $user_id = (int) $user_id;
    foreach ($values as $val) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            continue;
        }

        $row = null;
        $index = null;
        if ($user_id > 0 && function_exists('intersoccer_get_player_by_id')) {
            $by_id = intersoccer_get_player_by_id($user_id, $val);
            if (is_array($by_id)) {
                if (isset($by_id['player']) && is_array($by_id['player'])) {
                    $row = $by_id['player'];
                    if (array_key_exists('key', $by_id) && $by_id['key'] !== null && $by_id['key'] !== '') {
                        $index = $by_id['key'];
                    }
                } elseif (
                    array_key_exists('gender', $by_id)
                    || array_key_exists('player_gender', $by_id)
                    || array_key_exists('player_id', $by_id)
                ) {
                    $row = $by_id;
                }
            }
        }

        if (!is_array($row)) {
            // UUID was posted and does not match this customer's players. Do not
            // fall through to another posted index.
            return ['state' => 'unresolved'];
        }

        $gender = isset($row['gender']) ? trim((string) $row['gender']) : '';
        if ($gender === '' && isset($row['player_gender'])) {
            $gender = trim((string) $row['player_gender']);
        }

        return [
            'state' => 'uuid',
            'gender' => $gender,
            'index' => $index,
        ];
    }

    if (function_exists('intersoccer_get_posted_player_assignment_index')) {
        $index = intersoccer_get_posted_player_assignment_index();
        if ($index !== null) {
            return ['state' => 'index', 'index' => $index];
        }
    } else {
        foreach ($values as $val) {
            if (preg_match('/^\d+$/', $val)) {
                return ['state' => 'index', 'index' => absint($val)];
            }
        }
    }

    return ['state' => 'unresolved'];
}

/**
 * Add-to-cart: validate player gender for girls-only programs when an attendee is posted.
 *
 * A player UUID is resolved to that player's record. A numeric index still uses
 * the existing index lookup. A posted value that cannot be resolved is rejected
 * with the same notice as an attendee whose gender is missing.
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

    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
        return $passed;
    }

    $product_id = (int) $product_id;
    $variation_id = absint($variation_id);
    if (!intersoccer_line_is_girls_only_program($product_id, $variation_id)) {
        return $passed;
    }

    $resolved = intersoccer_resolve_posted_girls_only_attendee($user_id);
    if ($resolved['state'] === 'absent') {
        return $passed;
    }

    $player_index = 0;
    if (array_key_exists('index', $resolved) && $resolved['index'] !== null && $resolved['index'] !== '') {
        $player_index = $resolved['index'];
    }
    $skip = (bool) apply_filters(
        'intersoccer_skip_girls_only_validation',
        false,
        $user_id,
        $player_index,
        $product_id,
        $variation_id
    );
    if ($skip) {
        return $passed;
    }

    if ($resolved['state'] === 'index') {
        $result = intersoccer_validate_line_player_girls_only($user_id, $resolved['index'], $product_id, $variation_id);
    } elseif ($resolved['state'] === 'uuid') {
        $result = intersoccer_girls_only_validation_result_for_gender($resolved['gender']);
    } else {
        $result = intersoccer_girls_only_validation_result_for_gender('');
    }

    if (is_wp_error($result)) {
        wc_add_notice($result->get_error_message(), 'error');
        return false;
    }

    return $passed;
}

add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_player_girls_only_on_add_to_cart', 21, 6);
