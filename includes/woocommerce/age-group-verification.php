<?php
/**
 * Child age vs program age group (pa_age-group) validation.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve pa_age-group term name for display (variation first, then unambiguous parent).
 *
 * @param int      $product_id   Parent product ID.
 * @param int      $variation_id Variation ID.
 * @return string|null Term name or null.
 */
function intersoccer_get_variation_age_group_label($product_id, $variation_id) {
    if (!$variation_id) {
        return intersoccer_get_product_age_group($product_id);
    }

    $slug = get_post_meta($variation_id, 'attribute_pa_age-group', true);
    if (!is_string($slug) || $slug === '') {
        $variation = wc_get_product($variation_id);
        if ($variation) {
            $slug = $variation->get_attribute('pa_age-group');
        }
    }

    if (is_string($slug) && $slug !== '') {
        $term = get_term_by('slug', $slug, 'pa_age-group');
        if ($term && !is_wp_error($term)) {
            $name = $term->name;
            if (function_exists('icl_object_id') && defined('ICL_LANGUAGE_CODE')) {
                $translated_id = icl_object_id($term->term_id, 'pa_age-group', false, ICL_LANGUAGE_CODE);
                if ($translated_id && (int) $translated_id !== (int) $term->term_id) {
                    $translated = get_term($translated_id, 'pa_age-group');
                    if ($translated && !is_wp_error($translated)) {
                        $name = $translated->name;
                    }
                }
            }
            return $name;
        }
        return $slug;
    }

    $terms = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names']);
    if (is_array($terms) && count($terms) === 1) {
        return $terms[0];
    }

    return null;
}

/**
 * Raw slug for pa_age-group on the variation (for parsing when name differs).
 *
 * @param int $variation_id Variation ID.
 * @return string
 */
function intersoccer_get_variation_age_group_slug($variation_id) {
    if (!$variation_id) {
        return '';
    }
    $slug = get_post_meta($variation_id, 'attribute_pa_age-group', true);
    if (is_string($slug) && $slug !== '') {
        return $slug;
    }
    $variation = wc_get_product($variation_id);
    if ($variation) {
        $attr = $variation->get_attribute('pa_age-group');
        if (is_string($attr) && $attr !== '') {
            return sanitize_title($attr);
        }
    }
    return '';
}

/**
 * Parse inclusive min/max ages from an age-group label or slug.
 *
 * @param string $label_or_slug Term name, slug fragment, or storefront string.
 * @return array{min: int|null, max: int|null}|null Null when unparseable.
 */
function intersoccer_parse_age_group_bounds($label_or_slug) {
    $s = trim((string) $label_or_slug);
    if ($s === '') {
        return null;
    }

    $try = static function ($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        // N+ or N+ years (minimum age only).
        if (preg_match('/(\d+)\s*\+(?:\s*(?:y|yr|yrs|years?))?/i', $text, $m)) {
            return ['min' => (int) $m[1], 'max' => null];
        }

        // N-M with optional y/years e.g. "5-13y (Full Day)", "6-8 years", "6-8-years".
        if (preg_match('/(\d+)\s*-\s*(\d+)(?:\s*y(?:ears?)?|\s*-\s*years?|\s*\()?/i', $text, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a <= 25 && $b <= 25) {
                return ['min' => min($a, $b), 'max' => max($a, $b)];
            }
        }

        // Plain N-M when both look like child ages (avoids year ranges like 2025-2026).
        if (preg_match('/(\d+)\s*-\s*(\d+)/', $text, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a <= 25 && $b <= 25) {
                return ['min' => min($a, $b), 'max' => max($a, $b)];
            }
        }

        // "N to M" / "N à M"
        if (preg_match('/(\d+)\s*(?:to|à|-)\s*(\d+)/iu', $text, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            return ['min' => min($a, $b), 'max' => max($a, $b)];
        }

        // U10 / U-10 → under N → max age N-1 (no minimum).
        if (preg_match('/\bU\s*-?\s*(\d+)\b/i', $text, $m)) {
            $u = (int) $m[1];
            if ($u < 1) {
                return null;
            }
            return ['min' => null, 'max' => $u - 1];
        }

        return null;
    };

    $bounds = $try($s);
    if ($bounds !== null) {
        return $bounds;
    }

    // Slug-style: digits separated by hyphen without space.
    $normalized = str_replace(['_', ' '], '-', $s);
    return $try($normalized);
}

/**
 * Normalize month token from camp-terms slug for date parsing.
 *
 * @param string $token e.g. "october".
 * @return string
 */
function intersoccer_pv_normalize_camp_month_token($token) {
    $t = strtolower(trim($token));
    if ($t === '') {
        return $t;
    }
    return ucfirst($t);
}

/**
 * Extract program year from pa_program-season label/slug (e.g. "Autumn camps 2025").
 *
 * @param string $season Raw season string.
 * @return int Year (fallback: current year in site timezone).
 */
function intersoccer_pv_year_from_program_season($season) {
    $season = trim((string) $season);
    if ($season !== '' && preg_match('/\b(19|20)\d{2}\b/', $season, $year_matches)) {
        return (int) $year_matches[0];
    }
    if ($season !== '' && is_numeric($season)) {
        return (int) $season;
    }
    return (int) date('Y');
}

/**
 * Get pa_program-season display string (variation first, then single parent term).
 *
 * @param int $product_id   Parent ID.
 * @param int $variation_id Variation ID.
 * @return string
 */
function intersoccer_get_variation_program_season_string($product_id, $variation_id) {
    if ($variation_id) {
        $slug = get_post_meta($variation_id, 'attribute_pa_program-season', true);
        if (!is_string($slug) || $slug === '') {
            $v = wc_get_product($variation_id);
            if ($v) {
                $slug = $v->get_attribute('pa_program-season');
            }
        }
        if (is_string($slug) && $slug !== '') {
            $term = get_term_by('slug', $slug, 'pa_program-season');
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
            return $slug;
        }
    }
    $seasons = wc_get_product_terms($product_id, 'pa_program-season', ['fields' => 'names']);
    if (is_array($seasons) && count($seasons) === 1) {
        return $seasons[0];
    }
    return '';
}

/**
 * Parse camp start date (Y-m-d) from pa_camp-terms slug and season (ported from roster logic).
 *
 * @param string $camp_terms_slug Camp terms slug.
 * @param string $season          Season string for year extraction.
 * @return string|null
 */
function intersoccer_parse_camp_start_date_from_terms($camp_terms_slug, $season) {
    $camp_terms = trim((string) $camp_terms_slug);
    if ($camp_terms === '' || $camp_terms === 'N/A') {
        return null;
    }

    $year = intersoccer_pv_year_from_program_season($season);

    $make_ymd = static function ($month_token, $day, $y) {
        $month = intersoccer_pv_normalize_camp_month_token($month_token);
        $day = (int) $day;
        $t = strtotime($month . ' ' . $day . ' ' . (int) $y);
        if ($t === false) {
            return null;
        }
        return date('Y-m-d', $t);
    };

    if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        return $make_ymd($matches[2], $matches[3], $year);
    }

    if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        return $make_ymd($matches[2], $matches[3], $year);
    }

    if (preg_match('/^\s*\w+\s*:\s*(\w+)\s+(\d{1,2})\s*$/i', $camp_terms, $matches)) {
        return $make_ymd($matches[1], $matches[2], $year);
    }

    if (preg_match('/^\s*(\w+)\s+(\d{1,2})\s*$/i', $camp_terms, $matches)) {
        return $make_ymd($matches[1], $matches[2], $year);
    }

    return null;
}

/**
 * Parse a loose date string to Y-m-d.
 *
 * @param string $value Attribute or meta value.
 * @return string|null
 */
function intersoccer_parse_loose_date_to_ymd($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $ts = strtotime($value . ' 00:00:00');
        return $ts ? date('Y-m-d', $ts) : null;
    }
    $t = strtotime($value);
    if ($t === false) {
        return null;
    }
    return date('Y-m-d', $t);
}

/**
 * Resolve tournament/birthday reference date from attributes.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID.
 * @return string|null Y-m-d
 */
function intersoccer_get_event_date_from_product_attributes($product_id, $variation_id) {
    $candidates = [];
    $variation = $variation_id ? wc_get_product($variation_id) : null;
    $parent = wc_get_product($product_id);

    foreach (['pa_date', 'date'] as $attr) {
        if ($variation) {
            $candidates[] = $variation->get_attribute($attr);
        }
        if ($parent) {
            $candidates[] = $parent->get_attribute($attr);
        }
    }

    foreach ($candidates as $raw) {
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $ymd = intersoccer_parse_loose_date_to_ymd($raw);
        if ($ymd) {
            return $ymd;
        }
    }

    return null;
}

/**
 * Camp terms slug from variation (or parent).
 *
 * @param int $product_id   Parent ID.
 * @param int $variation_id Variation ID.
 * @return string
 */
function intersoccer_get_camp_terms_slug($product_id, $variation_id) {
    if ($variation_id) {
        $slug = get_post_meta($variation_id, 'attribute_pa_camp-terms', true);
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }
        $v = wc_get_product($variation_id);
        if ($v) {
            $a = $v->get_attribute('pa_camp-terms');
            if (is_string($a) && $a !== '') {
                return sanitize_title($a);
            }
        }
    }
    $parent = wc_get_product($product_id);
    if ($parent) {
        $a = $parent->get_attribute('pa_camp-terms');
        if (is_string($a) && $a !== '') {
            return sanitize_title($a);
        }
    }
    return '';
}

/**
 * Program reference date (first day) for age calculation.
 *
 * @param int    $product_id   Parent product ID.
 * @param int    $variation_id Variation ID.
 * @param string $product_type camp|course|birthday|tournament
 * @return string|null Y-m-d or null if unknown.
 */
function intersoccer_get_program_reference_date($product_id, $variation_id, $product_type) {
    $computed = null;

    switch ($product_type) {
        case 'course':
            if ($variation_id && function_exists('intersoccer_get_course_meta')) {
                $start = intersoccer_get_course_meta($variation_id, '_course_start_date', '');
                if (is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    $computed = $start;
                }
            }
            break;

        case 'camp':
            $camp_terms = intersoccer_get_camp_terms_slug($product_id, $variation_id);
            $season = intersoccer_get_variation_program_season_string($product_id, $variation_id);
            $computed = intersoccer_parse_camp_start_date_from_terms($camp_terms, $season);
            break;

        case 'tournament':
        case 'birthday':
            $computed = intersoccer_get_event_date_from_product_attributes($product_id, $variation_id);
            break;

        default:
            $computed = null;
    }

    /**
     * Filter: override or supply program reference date (Y-m-d).
     *
     * @param string|null $date         Computed date or null.
     * @param int         $product_id   Parent product ID.
     * @param int         $variation_id Variation ID.
     * @param string      $product_type Product type slug.
     */
    return apply_filters('intersoccer_program_reference_date', $computed, $product_id, $variation_id, $product_type);
}

/**
 * Integer age in completed years on a reference date.
 *
 * @param string $dob_ymd Date of birth Y-m-d.
 * @param string $ref_ymd Reference date Y-m-d.
 * @return int|null Null if invalid.
 */
function intersoccer_age_on_date($dob_ymd, $ref_ymd) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref_ymd)) {
        return null;
    }
    try {
        $dob = new DateTimeImmutable($dob_ymd, new DateTimeZone('UTC'));
        $ref = new DateTimeImmutable($ref_ymd, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    if ($dob > $ref) {
        return null;
    }
    return $dob->diff($ref)->y;
}

/**
 * Validate assigned player age for a product line.
 *
 * @param int $user_id      Customer user ID.
 * @param int $player_index intersoccer_players index.
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID.
 * @return true|WP_Error
 */
function intersoccer_validate_line_player_age($user_id, $player_index, $product_id, $variation_id) {
    /**
     * Skip all age-group checks when true.
     *
     * @param bool $skip
     * @param int  $user_id
     * @param int  $player_index
     * @param int  $product_id
     * @param int  $variation_id
     */
    if (apply_filters('intersoccer_skip_age_group_validation', false, $user_id, $player_index, $product_id, $variation_id)) {
        return true;
    }

    $product_type = function_exists('intersoccer_get_product_type')
        ? intersoccer_get_product_type($product_id)
        : null;

    if (!$product_type || !in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
        return true;
    }

    if (!$variation_id) {
        return new WP_Error(
            'intersoccer_age_no_variation',
            __('This booking requires a variation; age could not be verified.', 'intersoccer-product-variations')
        );
    }

    $label = intersoccer_get_variation_age_group_label($product_id, $variation_id);
    $slug = intersoccer_get_variation_age_group_slug($variation_id);
    $bounds = null;
    if ($label) {
        $bounds = intersoccer_parse_age_group_bounds($label);
    }
    if ($bounds === null && $slug !== '') {
        $bounds = intersoccer_parse_age_group_bounds($slug);
    }

    if ($bounds === null) {
        intersoccer_warning('InterSoccer age check: unparseable pa_age-group for product ' . $product_id . ' variation ' . $variation_id . ' label=' . ($label ?: '') . ' slug=' . $slug);
        return new WP_Error(
            'intersoccer_age_group_unparseable',
            __('The age group for this program could not be read. Please contact us or choose another session.', 'intersoccer-product-variations')
        );
    }

    $ref = intersoccer_get_program_reference_date($product_id, $variation_id, $product_type);
    if (!$ref) {
        intersoccer_warning('InterSoccer age check: no reference date for product ' . $product_id . ' variation ' . $variation_id . ' type ' . $product_type);
        return new WP_Error(
            'intersoccer_age_no_reference_date',
            __('The program start date could not be determined, so age cannot be verified. Please contact us or try another option.', 'intersoccer-product-variations')
        );
    }

    if (!function_exists('intersoccer_get_player_details')) {
        return true;
    }

    $player = intersoccer_get_player_details($user_id, $player_index);
    $dob_raw = isset($player['dob']) ? trim((string) $player['dob']) : '';

    $dob_ymd = null;
    if ($dob_raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob_raw)) {
        $dob_ymd = $dob_raw;
    } elseif ($dob_raw !== '') {
        $dob_ymd = intersoccer_parse_loose_date_to_ymd($dob_raw);
    }

    if (!$dob_ymd) {
        $manage = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('manage-players') : '';
        $msg = $manage
            ? sprintf(
                /* translators: %s: URL to Manage Players */
                __('The selected attendee needs a valid date of birth. Update it under Manage Players: %s', 'intersoccer-product-variations'),
                $manage
            )
            : __('The selected attendee needs a valid date of birth. Please update it in your account before booking.', 'intersoccer-product-variations');
        return new WP_Error('intersoccer_age_no_dob', $msg);
    }

    $age = intersoccer_age_on_date($dob_ymd, $ref);
    if ($age === null) {
        return new WP_Error(
            'intersoccer_age_invalid_dob',
            __('The attendee date of birth is invalid or is after the program start date.', 'intersoccer-product-variations')
        );
    }

    $min = $bounds['min'];
    $max = $bounds['max'];
    $matches = true;
    if ($min !== null && $age < $min) {
        $matches = false;
    }
    if ($max !== null && $age > $max) {
        $matches = false;
    }

    /**
     * Filter: final say on whether age matches (after bounds check).
     *
     * @param bool $matches      Whether age is within bounds.
     * @param int  $age          Age on reference date.
     * @param int  $user_id
     * @param int  $player_index
     * @param int  $product_id
     * @param int  $variation_id
     * @param array{min: int|null, max: int|null} $bounds
     * @param string $ref       Reference date Y-m-d.
     */
    $matches = (bool) apply_filters(
        'intersoccer_player_age_matches_program',
        $matches,
        $age,
        $user_id,
        $player_index,
        $product_id,
        $variation_id,
        $bounds,
        $ref
    );

    if (!$matches) {
        return new WP_Error(
            'intersoccer_age_out_of_range',
            sprintf(
                /* translators: 1: child age on program start, 2: allowed age range description */
                __('This attendee is %1$d years old on the program start date, which is outside the allowed age range (%2$s) for this session.', 'intersoccer-product-variations'),
                $age,
                $label ? $label : $slug
            )
        );
    }

    return true;
}

/**
 * Add-to-cart: validate player age when player_assignment is posted.
 *
 * @param bool  $passed
 * @param int   $product_id
 * @param int   $quantity
 * @param int   $variation_id
 * @param array $variations
 * @param mixed $cart_item_data WooCommerce cart item data (optional).
 * @return bool
 */
function intersoccer_validate_player_age_on_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = null) {
    if (!$passed) {
        return false;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return $passed;
    }

    if (empty($variation_id)) {
        return $passed;
    }

    $product_type = intersoccer_get_product_type($product_id);
    if (!$product_type || !in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
        return $passed;
    }

    if (!isset($_POST['player_assignment']) || $_POST['player_assignment'] === '') {
        return $passed;
    }

    $player_index = absint($_POST['player_assignment']);
    $result = intersoccer_validate_line_player_age($user_id, $player_index, $product_id, (int) $variation_id);
    if (is_wp_error($result)) {
        wc_add_notice($result->get_error_message(), 'error');
        return false;
    }

    return $passed;
}

/**
 * Checkout: re-validate each cart line (tamper protection).
 */
function intersoccer_validate_cart_player_ages_at_checkout() {
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
        $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;
        if (!$product_id || !$variation_id) {
            continue;
        }

        $product_type = intersoccer_get_product_type($product_id);
        if (!$product_type || !in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
            continue;
        }

        if (!array_key_exists('assigned_player', $cart_item)) {
            continue;
        }

        $player_index = $cart_item['assigned_player'];
        if ($player_index === null || $player_index === '') {
            continue;
        }

        $player_index = absint($player_index);
        $result = intersoccer_validate_line_player_age($user_id, $player_index, $product_id, $variation_id);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        }
    }
}

add_filter('woocommerce_add_to_cart_validation', 'intersoccer_validate_player_age_on_add_to_cart', 20, 6);
add_action('woocommerce_checkout_process', 'intersoccer_validate_cart_player_ages_at_checkout', 10);
