<?php
/**
 * Reusable Campaign Offers: time-boxed WooCommerce percent coupons plus checkout fields.
 *
 * Monetary discount is the native coupon amount. Campaign PHP shows/validates
 * joining name + guardian email and enforces offer window / kill switch.
 * Sibling set_price is unchanged and may stack with the coupon.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_campaign_exclusive_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_campaign_exclusive_keys() {
        return [
            'camp_sibling',
            'camp_progressive',
            'course_sibling',
            'course_same_season',
            'tournament_sibling',
            'tournament_multi_day',
            'first_order_referral',
        ];
    }
}

if (!function_exists('intersoccer_normalize_coupon_code')) {
    /**
     * @param string $code
     * @return string
     */
    function intersoccer_normalize_coupon_code($code) {
        return strtolower(trim((string) $code));
    }
}

if (!function_exists('intersoccer_get_default_campaign_offers')) {
    /**
     * Autumn 2026 Friends & Siblings example records (not hardcoded in the engine).
     *
     * @return array<string,array>
     */
    function intersoccer_get_default_campaign_offers() {
        $joining_label = __('Who is your child joining?', 'intersoccer-product-variations');
        $joining_placeholder = __('Friend or sibling name', 'intersoccer-product-variations');
        $joining_error = __('Please enter who your child is joining.', 'intersoccer-product-variations');

        return [
            'autumn15' => [
                'id' => 'autumn15',
                'enabled' => false,
                'name' => __('Autumn 2026 — AUTUMN15 (solo)', 'intersoccer-product-variations'),
                'code' => 'AUTUMN15',
                'percent' => 15,
                'max_cap_percent' => 20,
                'product_ids' => [],
                'excluded_product_ids' => [],
                'product_categories' => [],
                'excluded_product_categories' => [],
                'product_tags' => [],
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-11-30 23:59:59',
                'requires_group_field' => false,
                'group_field_label' => $joining_label,
                'group_field_placeholder' => $joining_placeholder,
                'group_field_error' => $joining_error,
                'exclusive_with' => [],
                'coupon_id' => 0,
            ],
            'together20' => [
                'id' => 'together20',
                'enabled' => false,
                'name' => __('Autumn 2026 — TOGETHER20 (with friend/sibling)', 'intersoccer-product-variations'),
                'code' => 'TOGETHER20',
                'percent' => 20,
                'max_cap_percent' => 20,
                'product_ids' => [],
                'excluded_product_ids' => [],
                'product_categories' => [],
                'excluded_product_categories' => [],
                'product_tags' => [],
                'starts_at' => '2026-08-01 00:00:00',
                'ends_at' => '2026-11-30 23:59:59',
                'requires_group_field' => true,
                'group_field_label' => $joining_label,
                'group_field_placeholder' => $joining_placeholder,
                'group_field_error' => $joining_error,
                'exclusive_with' => [],
                'coupon_id' => 0,
            ],
        ];
    }
}

if (!function_exists('intersoccer_campaign_parse_id_list')) {
    /**
     * @param mixed $value
     * @return array<int,int>
     */
    function intersoccer_campaign_parse_id_list($value) {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = absint($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('intersoccer_campaign_normalize_datetime')) {
    /**
     * @param mixed $value
     * @return string Empty or Y-m-d H:i:s
     */
    function intersoccer_campaign_normalize_datetime($value) {
        $raw = trim(str_replace('T', ' ', (string) $value));
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return '';
        }
        return $raw;
    }
}

if (!function_exists('intersoccer_normalize_campaign_offer')) {
    /**
     * @param mixed $offer
     * @return array|null
     */
    function intersoccer_normalize_campaign_offer($offer) {
        if (!is_array($offer)) {
            return null;
        }

        $id = isset($offer['id']) ? sanitize_key($offer['id']) : '';
        $code = strtoupper(sanitize_text_field($offer['code'] ?? ''));
        $code = preg_replace('/[^A-Z0-9_\-]/', '', $code);
        if ($id === '') {
            $id = $code !== '' ? sanitize_key(strtolower($code)) : '';
        }
        if ($id === '' || $code === '') {
            return null;
        }

        $allowed_exclusive = intersoccer_campaign_exclusive_keys();
        $exclusive = [];
        $raw_exclusive = $offer['exclusive_with'] ?? [];
        if (is_string($raw_exclusive)) {
            $raw_exclusive = preg_split('/[\s,]+/', $raw_exclusive, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($raw_exclusive)) {
            foreach ($raw_exclusive as $key) {
                $key = sanitize_key($key);
                if (in_array($key, $allowed_exclusive, true)) {
                    $exclusive[] = $key;
                }
            }
            $exclusive = array_values(array_unique($exclusive));
        }

        $percent = min(max(floatval($offer['percent'] ?? 0), 0), 100);
        $cap = min(max(floatval($offer['max_cap_percent'] ?? 20), 0), 100);

        return [
            'id' => $id,
            'enabled' => !empty($offer['enabled']),
            'name' => sanitize_text_field($offer['name'] ?? $code),
            'code' => $code,
            'percent' => $percent,
            'max_cap_percent' => $cap,
            'product_ids' => intersoccer_campaign_parse_id_list($offer['product_ids'] ?? []),
            'excluded_product_ids' => intersoccer_campaign_parse_id_list($offer['excluded_product_ids'] ?? []),
            'product_categories' => intersoccer_campaign_parse_id_list($offer['product_categories'] ?? []),
            'excluded_product_categories' => intersoccer_campaign_parse_id_list($offer['excluded_product_categories'] ?? []),
            'product_tags' => intersoccer_campaign_parse_id_list($offer['product_tags'] ?? []),
            'starts_at' => intersoccer_campaign_normalize_datetime($offer['starts_at'] ?? ''),
            'ends_at' => intersoccer_campaign_normalize_datetime($offer['ends_at'] ?? ''),
            'requires_group_field' => !empty($offer['requires_group_field']),
            'group_field_label' => sanitize_text_field($offer['group_field_label'] ?? ''),
            'group_field_placeholder' => sanitize_text_field($offer['group_field_placeholder'] ?? ''),
            'group_field_error' => sanitize_text_field($offer['group_field_error'] ?? ''),
            'exclusive_with' => $exclusive,
            'coupon_id' => absint($offer['coupon_id'] ?? 0),
        ];
    }
}

if (!function_exists('intersoccer_normalize_campaign_offers')) {
    /**
     * @param mixed $offers
     * @return array<string,array>
     */
    function intersoccer_normalize_campaign_offers($offers) {
        if (!is_array($offers)) {
            return [];
        }
        $normalized = [];
        foreach ($offers as $offer) {
            $row = intersoccer_normalize_campaign_offer($offer);
            if ($row === null) {
                continue;
            }
            $normalized[$row['id']] = $row;
        }
        return $normalized;
    }
}

if (!function_exists('intersoccer_campaign_offers_globally_enabled')) {
    /**
     * @return bool
     */
    function intersoccer_campaign_offers_globally_enabled() {
        $value = get_option('intersoccer_campaign_offers_enabled', true);
        return (bool) $value;
    }
}

if (!function_exists('intersoccer_get_campaign_offers')) {
    /**
     * @return array<string,array>
     */
    function intersoccer_get_campaign_offers() {
        $stored = get_option('intersoccer_campaign_offers', null);
        if (!is_array($stored) || $stored === []) {
            return intersoccer_normalize_campaign_offers(intersoccer_get_default_campaign_offers());
        }
        return intersoccer_normalize_campaign_offers($stored);
    }
}

if (!function_exists('intersoccer_find_campaign_offer_by_code')) {
    /**
     * @param string $code
     * @param array<string,array>|null $offers
     * @return array|null
     */
    function intersoccer_find_campaign_offer_by_code($code, $offers = null) {
        $norm = intersoccer_normalize_coupon_code($code);
        if ($norm === '') {
            return null;
        }
        if ($offers === null) {
            $offers = intersoccer_get_campaign_offers();
        }
        foreach ($offers as $offer) {
            if (intersoccer_normalize_coupon_code($offer['code'] ?? '') === $norm) {
                return $offer;
            }
        }
        return null;
    }
}

if (!function_exists('intersoccer_is_campaign_coupon_code')) {
    /**
     * True for any configured campaign code (enabled or not) so native coupon
     * money and disable-sibling-with-coupons do not treat it as a foreign coupon.
     *
     * @param string $code
     * @param array<string,array>|null $offers
     * @return bool
     */
    function intersoccer_is_campaign_coupon_code($code, $offers = null) {
        return intersoccer_find_campaign_offer_by_code($code, $offers) !== null;
    }
}

if (!function_exists('intersoccer_campaign_timezone')) {
    /**
     * @return DateTimeZone
     */
    function intersoccer_campaign_timezone() {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        $string = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        try {
            return new DateTimeZone($string ?: 'UTC');
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }
}

if (!function_exists('intersoccer_campaign_parse_datetime')) {
    /**
     * @param string $value Y-m-d H:i:s in site timezone
     * @return DateTimeImmutable|null
     */
    function intersoccer_campaign_parse_datetime($value) {
        $value = intersoccer_campaign_normalize_datetime($value);
        if ($value === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, intersoccer_campaign_timezone());
        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}

if (!function_exists('intersoccer_campaign_offer_in_window')) {
    /**
     * @param array $offer
     * @param DateTimeInterface|null $now
     * @return bool
     */
    function intersoccer_campaign_offer_in_window(array $offer, $now = null) {
        $tz = intersoccer_campaign_timezone();
        if (!$now instanceof DateTimeInterface) {
            $now = new DateTimeImmutable('now', $tz);
        }
        $start = intersoccer_campaign_parse_datetime($offer['starts_at'] ?? '');
        $end = intersoccer_campaign_parse_datetime($offer['ends_at'] ?? '');
        if ($start && $now < $start) {
            return false;
        }
        if ($end && $now > $end) {
            return false;
        }
        return true;
    }
}

if (!function_exists('intersoccer_campaign_product_is_eligible')) {
    /**
     * Empty allowlists mean all products except exclusions.
     *
     * @param array $offer
     * @param int   $product_id Parent product ID
     * @param array<int,int> $category_ids
     * @param array<int,int> $tag_ids
     * @return bool
     */
    function intersoccer_campaign_product_is_eligible(array $offer, $product_id, array $category_ids = [], array $tag_ids = []) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }

        $excluded_ids = array_map('intval', $offer['excluded_product_ids'] ?? []);
        if (in_array($product_id, $excluded_ids, true)) {
            return false;
        }

        $excluded_cats = array_map('intval', $offer['excluded_product_categories'] ?? []);
        if ($excluded_cats && array_intersect($category_ids, $excluded_cats)) {
            return false;
        }

        $allow_ids = array_map('intval', $offer['product_ids'] ?? []);
        $allow_cats = array_map('intval', $offer['product_categories'] ?? []);
        $allow_tags = array_map('intval', $offer['product_tags'] ?? []);
        $has_allowlist = $allow_ids || $allow_cats || $allow_tags;
        if (!$has_allowlist) {
            return true;
        }

        if ($allow_ids && in_array($product_id, $allow_ids, true)) {
            return true;
        }
        if ($allow_cats && array_intersect($category_ids, $allow_cats)) {
            return true;
        }
        if ($allow_tags && array_intersect($tag_ids, $allow_tags)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('intersoccer_resolve_capped_percent')) {
    /**
     * Higher percent wins, then cap. Inputs are 0–1 fractions.
     *
     * @param array<string,float> $candidates
     * @param float $cap_fraction 0–1
     * @return float
     */
    function intersoccer_resolve_capped_percent(array $candidates, $cap_fraction) {
        $values = [];
        foreach ($candidates as $value) {
            $value = floatval($value);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        if (!$values) {
            return 0.0;
        }
        $max = max($values);
        $cap_fraction = floatval($cap_fraction);
        if ($cap_fraction > 0 && $max > $cap_fraction) {
            return $cap_fraction;
        }
        return $max;
    }
}

if (!function_exists('intersoccer_campaign_filter_exclusive_sources')) {
    /**
     * @param array<string,float> $sources
     * @param array<int,string>   $exclusive_with
     * @return array<string,float>
     */
    function intersoccer_campaign_filter_exclusive_sources(array $sources, array $exclusive_with) {
        foreach ($exclusive_with as $key) {
            if ($key === 'first_order_referral') {
                continue;
            }
            unset($sources[$key]);
        }
        return $sources;
    }
}

if (!function_exists('intersoccer_cart_has_non_campaign_coupons')) {
    /**
     * @param object|null $cart
     * @return bool
     */
    function intersoccer_cart_has_non_campaign_coupons($cart) {
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_applied_coupons')) {
            return false;
        }
        foreach ((array) $cart->get_applied_coupons() as $code) {
            if (!intersoccer_is_campaign_coupon_code($code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_get_applied_campaign_offer')) {
    /**
     * First matching applied campaign coupon that is enabled and in window.
     *
     * @param object|null $cart
     * @return array|null
     */
    function intersoccer_get_applied_campaign_offer($cart = null) {
        if (!intersoccer_campaign_offers_globally_enabled()) {
            return null;
        }
        if ($cart === null && function_exists('WC') && WC() && isset(WC()->cart)) {
            $cart = WC()->cart;
        }
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_applied_coupons')) {
            return null;
        }

        $codes = [];
        foreach ((array) $cart->get_applied_coupons() as $code) {
            $codes[] = intersoccer_normalize_coupon_code($code);
        }

        foreach (intersoccer_get_campaign_offers() as $offer) {
            if (empty($offer['enabled'])) {
                continue;
            }
            if (!in_array(intersoccer_normalize_coupon_code($offer['code']), $codes, true)) {
                continue;
            }
            if (!intersoccer_campaign_offer_in_window($offer)) {
                continue;
            }
            return $offer;
        }

        return null;
    }
}

if (!function_exists('intersoccer_campaign_term_ids_for_product')) {
    /**
     * @param int    $product_id
     * @param string $taxonomy
     * @return array<int,int>
     */
    function intersoccer_campaign_term_ids_for_product($product_id, $taxonomy) {
        if (function_exists('wc_get_product_term_ids')) {
            return array_map('intval', wc_get_product_term_ids((int) $product_id, $taxonomy));
        }
        return [];
    }
}

if (!function_exists('intersoccer_campaign_line_source_key')) {
    /**
     * @param string $product_type
     * @param string $kind sibling|progressive|same_season|multi_day
     * @return string
     */
    function intersoccer_campaign_line_source_key($product_type, $kind) {
        $map = [
            'camp' => [
                'sibling' => 'camp_sibling',
                'progressive' => 'camp_progressive',
            ],
            'course' => [
                'sibling' => 'course_sibling',
                'same_season' => 'course_same_season',
            ],
            'tournament' => [
                'sibling' => 'tournament_sibling',
                'multi_day' => 'tournament_multi_day',
            ],
        ];
        return $map[$product_type][$kind] ?? $kind;
    }
}

if (!function_exists('intersoccer_apply_campaign_offer_to_cart')) {
    /**
     * No-op: campaign money is the native WooCommerce coupon amount.
     *
     * @param object $cart WC_Cart
     * @return void
     */
    function intersoccer_apply_campaign_offer_to_cart($cart) {
    }
}

if (!function_exists('intersoccer_campaign_max_applied_line_percent')) {
    /**
     * Highest line discount fraction currently on the cart (0–1).
     *
     * @param object|null $cart
     * @return float
     */
    function intersoccer_campaign_max_applied_line_percent($cart = null) {
        if ($cart === null && function_exists('WC') && WC() && isset(WC()->cart)) {
            $cart = WC()->cart;
        }
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return 0.0;
        }
        $max = 0.0;
        foreach ($cart->get_cart() as $item) {
            $base = floatval($item['base_price'] ?? 0);
            $amount = floatval($item['discount_amount'] ?? 0);
            if ($base > 0) {
                $max = max($max, $amount / $base);
            }
        }
        return $max;
    }
}

if (!function_exists('intersoccer_campaign_remaining_cap_percent')) {
    /**
     * Remaining room under the offer cap as 0–100 percent points.
     *
     * @param array $offer
     * @param float $applied_fraction 0–1
     * @return float
     */
    function intersoccer_campaign_remaining_cap_percent(array $offer, $applied_fraction) {
        $cap = floatval($offer['max_cap_percent'] ?? 20);
        $applied = max(0, floatval($applied_fraction) * 100);
        return max(0, $cap - $applied);
    }
}

if (!function_exists('intersoccer_campaign_filter_first_order_percent')) {
    /**
     * CRS hook: shrink or zero first-order referral % when a campaign coupon is on the cart.
     *
     * @param float       $percent 0–100
     * @param object|null $cart
     * @return float
     */
    function intersoccer_campaign_filter_first_order_percent($percent, $cart = null) {
        $offer = intersoccer_get_applied_campaign_offer($cart);
        if (!$offer) {
            return $percent;
        }
        $exclusive = $offer['exclusive_with'] ?? [];
        if (in_array('first_order_referral', $exclusive, true)) {
            return 0.0;
        }
        $remaining = intersoccer_campaign_remaining_cap_percent(
            $offer,
            intersoccer_campaign_max_applied_line_percent($cart)
        );
        return min(floatval($percent), $remaining);
    }
}

if (!function_exists('intersoccer_campaign_sync_coupon')) {
    /**
     * Upsert a WooCommerce percent coupon matching the offer rate.
     *
     * @param array $offer
     * @return array
     */
    function intersoccer_campaign_sync_coupon(array $offer) {
        if (!class_exists('WC_Coupon')) {
            return $offer;
        }

        $code = $offer['code'];
        $coupon_id = absint($offer['coupon_id'] ?? 0);
        if (!$coupon_id && function_exists('wc_get_coupon_id_by_code')) {
            $coupon_id = absint(wc_get_coupon_id_by_code($code));
        }

        $coupon = $coupon_id ? new WC_Coupon($coupon_id) : new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_description($offer['name']);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(floatval($offer['percent']));
        $coupon->set_individual_use(false);
        $coupon->set_product_ids($offer['product_ids']);
        $coupon->set_excluded_product_ids($offer['excluded_product_ids']);
        $coupon->set_product_categories($offer['product_categories']);
        $coupon->set_excluded_product_categories($offer['excluded_product_categories']);

        $end = intersoccer_campaign_parse_datetime($offer['ends_at'] ?? '');
        if ($end && method_exists($coupon, 'set_date_expires')) {
            $coupon->set_date_expires($end->getTimestamp());
        }

        $coupon_id = $coupon->save();
        if ($coupon_id) {
            $offer['coupon_id'] = (int) $coupon_id;
        }

        return $offer;
    }
}

if (!function_exists('intersoccer_campaign_coupon_is_valid')) {
    /**
     * @param bool            $valid
     * @param WC_Coupon       $coupon
     * @param mixed           $discount
     * @return bool
     */
    function intersoccer_campaign_coupon_is_valid($valid, $coupon, $discount = null) {
        if (!$valid) {
            return $valid;
        }
        $code = (is_object($coupon) && method_exists($coupon, 'get_code')) ? $coupon->get_code() : '';
        if (!intersoccer_is_campaign_coupon_code($code)) {
            return $valid;
        }
        if (!intersoccer_campaign_offers_globally_enabled()) {
            return false;
        }
        $offer = intersoccer_find_campaign_offer_by_code($code);
        if (!$offer || empty($offer['enabled'])) {
            return false;
        }
        if (!intersoccer_campaign_offer_in_window($offer)) {
            return false;
        }
        return $valid;
    }
}

if (!function_exists('intersoccer_campaign_group_codes_requiring_field')) {
    /**
     * @return array<int,string> lowercase codes
     */
    function intersoccer_campaign_group_codes_requiring_field() {
        $codes = [];
        foreach (intersoccer_get_campaign_offers() as $offer) {
            if (!empty($offer['enabled']) && !empty($offer['requires_group_field'])) {
                $codes[] = intersoccer_normalize_coupon_code($offer['code']);
            }
        }
        return $codes;
    }
}

if (!function_exists('intersoccer_campaign_translate')) {
    /**
     * @param string $name
     * @param string $value
     * @return string
     */
    function intersoccer_campaign_translate($name, $value) {
        if ($value === '') {
            return $value;
        }
        if (function_exists('icl_t')) {
            return icl_t('intersoccer-product-variations', $name, $value);
        }
        return $value;
    }
}

add_filter('woocommerce_coupon_is_valid', 'intersoccer_campaign_coupon_is_valid', 10, 3);
add_filter('intersoccer_referral_first_order_discount_percent', 'intersoccer_campaign_filter_first_order_percent', 10, 2);

add_action('init', 'intersoccer_campaign_register_wpml_strings', 20);
function intersoccer_campaign_register_wpml_strings() {
    if (!function_exists('icl_register_string')) {
        return;
    }
    $defaults = intersoccer_get_default_campaign_offers();
    $together = $defaults['together20'] ?? [];
    foreach ([
        'Who is your child joining?' => $together['group_field_label'] ?? 'Who is your child joining?',
        'Friend or sibling name' => $together['group_field_placeholder'] ?? 'Friend or sibling name',
        'Please enter who your child is joining.' => $together['group_field_error'] ?? 'Please enter who your child is joining.',
        'Guardian\'s email' => 'Guardian\'s email',
        'parent@example.com' => 'parent@example.com',
        'Please enter the other guardian\'s email address.' => 'Please enter the other guardian\'s email address.',
        '%d%% Campaign Discount (%s)' => '%d%% Campaign Discount (%s)',
        'Campaign Offers' => 'Campaign Offers',
        'Joining With' => 'Joining With',
        'Guardian email' => 'Guardian email',
        'Campaign leads' => 'Campaign leads',
        'Child name' => 'Child name',
        'Source order' => 'Source order',
        'Existing customer' => 'Existing customer',
        'Converted' => 'Converted',
        'Download CSV' => 'Download CSV',
        'No campaign leads yet.' => 'No campaign leads yet.',
    ] as $name => $value) {
        icl_register_string('intersoccer-product-variations', $name, $value);
    }
    foreach (intersoccer_get_campaign_offers() as $offer) {
        foreach (['group_field_label', 'group_field_placeholder', 'group_field_error'] as $field) {
            if (!empty($offer[$field])) {
                icl_register_string('intersoccer-product-variations', $offer['id'] . '_' . $field, $offer[$field]);
            }
        }
    }
}

add_action('admin_init', 'intersoccer_bootstrap_campaign_offers');
function intersoccer_bootstrap_campaign_offers() {
    if (get_option('intersoccer_campaign_offers_enabled', null) === null) {
        add_option('intersoccer_campaign_offers_enabled', true);
    }
    $existing = get_option('intersoccer_campaign_offers', null);
    if (!is_array($existing) || $existing === []) {
        $offers = intersoccer_normalize_campaign_offers(intersoccer_get_default_campaign_offers());
        foreach ($offers as $id => $offer) {
            $offers[$id] = intersoccer_campaign_sync_coupon($offer);
        }
        update_option('intersoccer_campaign_offers', $offers);
        return;
    }

    $changed = false;
    foreach ($existing as $id => $offer) {
        if (!is_array($offer)) {
            continue;
        }
        $synced = intersoccer_campaign_sync_coupon($offer);
        if ((int) ($synced['coupon_id'] ?? 0) !== (int) ($offer['coupon_id'] ?? 0)) {
            $changed = true;
        }
        $existing[$id] = $synced;
    }
    if ($changed) {
        update_option('intersoccer_campaign_offers', $existing);
    }
}
