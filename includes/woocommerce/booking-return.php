<?php
/**
 * Return a parent to the same product, with the options they already picked.
 *
 * The return address travels in a same-site `redirect` query argument.
 * Player Management may call intersoccer_pv_validate_return_url() when this plugin is active.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_pv_validate_return_url')) {
    /**
     * Safe return URL, or an empty string when the value must not be followed.
     *
     * Allows same-host http(s) URLs and site-relative paths. Rejects off-site
     * hosts, protocol-relative URLs, non-http schemes, backslashes, and CR/LF.
     *
     * @param mixed $url
     * @return string
     */
    function intersoccer_pv_validate_return_url($url) {
        if (!is_string($url)) {
            return '';
        }
        if (function_exists('wp_unslash')) {
            $unslashed = wp_unslash($url);
            if (!is_string($unslashed)) {
                return '';
            }
            $url = $unslashed;
        }
        $url = trim($url);
        if ($url === '' || intersoccer_pv_return_url_is_forbidden($url)) {
            return '';
        }

        $candidate = $url;
        if (function_exists('wp_validate_redirect')) {
            $candidate = wp_validate_redirect($url, '');
            if (!is_string($candidate) || $candidate === '' || intersoccer_pv_return_url_is_forbidden($candidate)) {
                return '';
            }
        }

        if (!intersoccer_pv_return_url_host_allowed($candidate)) {
            return '';
        }

        return $candidate;
    }
}

if (!function_exists('intersoccer_pv_return_url_is_forbidden')) {
    /**
     * @param string $url
     * @return bool
     */
    function intersoccer_pv_return_url_is_forbidden($url) {
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return true;
        }
        $decoded = rawurldecode($url);
        if (!is_string($decoded) || preg_match('/[\x00-\x1F\x7F\\\\]/', $decoded)) {
            return true;
        }
        $trim = ltrim($decoded);
        if ($trim === '' || strpos($trim, '//') === 0) {
            return true;
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*)\:#i', $trim, $match)) {
            $scheme = strtolower($match[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_pv_return_url_host_allowed')) {
    /**
     * @param string $url
     * @return bool
     */
    function intersoccer_pv_return_url_host_allowed($url) {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($host === '') {
            $path = isset($parts['path']) ? (string) $parts['path'] : '';
            return $path !== '' && $path[0] === '/' && (!isset($path[1]) || $path[1] !== '/');
        }
        if (isset($parts['scheme'])) {
            $scheme = strtolower((string) $parts['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return false;
            }
        }
        $site_host = intersoccer_pv_return_site_host();
        if ($site_host === '') {
            return false;
        }
        return $host === $site_host;
    }
}

if (!function_exists('intersoccer_pv_return_site_host')) {
    /**
     * @return string
     */
    function intersoccer_pv_return_site_host() {
        $bases = [];
        if (function_exists('home_url')) {
            $bases[] = home_url('/');
        }
        if (function_exists('site_url')) {
            $bases[] = site_url('/');
        }
        foreach ($bases as $base) {
            if (!is_string($base) || $base === '') {
                continue;
            }
            $host = parse_url($base, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }
        return '';
    }
}

if (!function_exists('intersoccer_pv_request_return_url')) {
    /**
     * Validated `redirect` value from the current request, or an empty string.
     *
     * @return string
     */
    function intersoccer_pv_request_return_url() {
        foreach (['_POST', '_GET'] as $source) {
            $bag = $source === '_POST' ? $_POST : $_GET;
            if (!isset($bag['redirect']) || !is_string($bag['redirect'])) {
                continue;
            }
            $safe = intersoccer_pv_validate_return_url($bag['redirect']);
            if ($safe !== '') {
                return $safe;
            }
        }
        return '';
    }
}

if (!function_exists('intersoccer_pv_is_booking_return_request')) {
    /**
     * @return bool
     */
    function intersoccer_pv_is_booking_return_request() {
        return isset($_GET['bf_return']) && (string) $_GET['bf_return'] === '1';
    }
}

if (!function_exists('intersoccer_pv_current_product_id')) {
    /**
     * @return int
     */
    function intersoccer_pv_current_product_id() {
        if (function_exists('is_product') && is_product() && function_exists('get_queried_object_id')) {
            $queried = (int) get_queried_object_id();
            if ($queried > 0) {
                return $queried;
            }
        }
        global $product;
        if (is_object($product) && method_exists($product, 'get_id')) {
            return (int) $product->get_id();
        }
        return 0;
    }
}

if (!function_exists('intersoccer_pv_product_permalink')) {
    /**
     * Product permalink in the current language.
     *
     * @param int $product_id
     * @return string
     */
    function intersoccer_pv_product_permalink($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0 || !function_exists('get_permalink')) {
            return '';
        }
        $permalink = get_permalink($product_id);
        if (!is_string($permalink) || $permalink === '') {
            return '';
        }
        $lang = apply_filters('wpml_current_language', null);
        if (!is_string($lang) || $lang === '') {
            $lang = null;
        }
        $filtered = apply_filters('wpml_permalink', $permalink, $lang);
        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }
        return $permalink;
    }
}

if (!function_exists('intersoccer_pv_request_attribute_selections')) {
    /**
     * attribute_* values already on the request URL.
     *
     * @return array<string,string>
     */
    function intersoccer_pv_request_attribute_selections() {
        $out = [];
        foreach ($_GET as $key => $value) {
            if (!is_string($key) || strpos($key, 'attribute_') !== 0 || is_array($value)) {
                continue;
            }
            $clean = intersoccer_pv_return_query_token($value);
            if ($clean === '') {
                continue;
            }
            $out[$key] = $clean;
        }
        return $out;
    }
}

if (!function_exists('intersoccer_pv_posted_attribute_selections')) {
    /**
     * @return array<string,string>
     */
    function intersoccer_pv_posted_attribute_selections() {
        $out = [];
        foreach ($_POST as $key => $value) {
            if (!is_string($key) || strpos($key, 'attribute_') !== 0 || is_array($value)) {
                continue;
            }
            $clean = intersoccer_pv_return_query_token($value);
            if ($clean === '') {
                continue;
            }
            $out[$key] = $clean;
        }
        return $out;
    }
}

if (!function_exists('intersoccer_pv_request_camp_days')) {
    /**
     * @return string[]
     */
    function intersoccer_pv_request_camp_days() {
        $raw = [];
        if (isset($_GET['camp_days'])) {
            $raw = $_GET['camp_days'];
        }
        if (!is_array($raw)) {
            if (is_scalar($raw) && (string) $raw !== '') {
                $raw = explode(',', (string) $raw);
            } else {
                $raw = [];
            }
        }
        $days = [];
        foreach ($raw as $day) {
            $clean = intersoccer_pv_return_query_token($day);
            if ($clean !== '' && !in_array($clean, $days, true)) {
                $days[] = $clean;
            }
        }
        return $days;
    }
}

if (!function_exists('intersoccer_pv_return_query_token')) {
    /**
     * @param mixed $value
     * @return string
     */
    function intersoccer_pv_return_query_token($value) {
        if (!is_scalar($value)) {
            return '';
        }
        $text = function_exists('wp_unslash') ? wp_unslash((string) $value) : (string) $value;
        if (function_exists('sanitize_text_field')) {
            $text = sanitize_text_field($text);
        } else {
            $text = trim(strip_tags($text));
        }
        if (!is_string($text) || $text === '' || strlen($text) > 80) {
            return '';
        }
        if (preg_match('/[\r\n<>"\'\\\\]/', $text)) {
            return '';
        }
        return $text;
    }
}

if (!function_exists('intersoccer_pv_session_coupon_code')) {
    /**
     * Pending URL coupon from the WooCommerce session, never from the request.
     *
     * The pending session value wins. The remembered applied code is used only
     * when nothing is still pending, so a code that already moved to the cart
     * can be carried through login.
     *
     * @return string
     */
    function intersoccer_pv_session_coupon_code() {
        if (!function_exists('WC')) {
            return '';
        }
        $wc = WC();
        if (!$wc || !isset($wc->session) || !is_object($wc->session) || !method_exists($wc->session, 'get')) {
            return '';
        }
        $pending_key = defined('INTERSOCCER_URL_COUPON_SESSION_KEY') ? INTERSOCCER_URL_COUPON_SESSION_KEY : 'intersoccer_url_coupon_code';
        $applied_key = defined('INTERSOCCER_URL_COUPON_APPLIED_SESSION_KEY') ? INTERSOCCER_URL_COUPON_APPLIED_SESSION_KEY : 'intersoccer_url_coupon_applied';
        $code = intersoccer_pv_normalize_session_coupon($wc->session->get($pending_key));
        if ($code === '') {
            $code = intersoccer_pv_normalize_session_coupon($wc->session->get($applied_key));
        }
        return $code;
    }
}

if (!function_exists('intersoccer_pv_normalize_session_coupon')) {
    /**
     * Same character rules as the URL-coupon normaliser. The real helper wins when it is loaded.
     *
     * @param mixed $raw
     * @return string
     */
    function intersoccer_pv_normalize_session_coupon($raw) {
        if (function_exists('intersoccer_url_coupon_normalize')) {
            $code = intersoccer_url_coupon_normalize($raw);
            return is_string($code) ? $code : '';
        }
        if (!is_scalar($raw)) {
            return '';
        }
        $code = strtolower(trim((string) $raw));
        $max = defined('INTERSOCCER_URL_COUPON_MAX_LENGTH') ? (int) INTERSOCCER_URL_COUPON_MAX_LENGTH : 32;
        if ($code === '' || strlen($code) > $max || !preg_match('/^[a-z0-9_-]+$/', $code)) {
            return '';
        }
        return $code;
    }
}

if (!function_exists('intersoccer_pv_append_query_pairs')) {
    /**
     * Append query pairs. Duplicate keys are kept so camp_days[] can repeat.
     *
     * @param string               $url
     * @param array<int,array{0:string,1:string}> $pairs
     * @return string
     */
    function intersoccer_pv_append_query_pairs($url, array $pairs) {
        $parts = [];
        foreach ($pairs as $pair) {
            if (!isset($pair[0], $pair[1])) {
                continue;
            }
            $parts[] = rawurlencode((string) $pair[0]) . '=' . rawurlencode((string) $pair[1]);
        }
        if ($parts === []) {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . implode('&', $parts) . $fragment;
    }
}

if (!function_exists('intersoccer_pv_booking_return_url')) {
    /**
     * Product URL plus the selections to restore, marked with bf_return=1.
     *
     * @param int                  $product_id
     * @param array<string,mixed>  $selections attributes, camp_days, quantity. Coupon is never read from here.
     * @return string
     */
    function intersoccer_pv_booking_return_url($product_id = 0, array $selections = []) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            $product_id = intersoccer_pv_current_product_id();
        }
        $permalink = intersoccer_pv_product_permalink($product_id);
        if ($permalink === '') {
            return '';
        }

        if (isset($selections['attributes']) && is_array($selections['attributes'])) {
            $attributes = $selections['attributes'];
        } else {
            $attributes = intersoccer_pv_request_attribute_selections();
        }
        if (array_key_exists('camp_days', $selections)) {
            $days = is_array($selections['camp_days']) ? $selections['camp_days'] : [];
        } else {
            $days = intersoccer_pv_request_camp_days();
        }
        $quantity = array_key_exists('quantity', $selections) ? (int) $selections['quantity'] : 0;
        if ($quantity <= 0 && isset($_GET['quantity']) && is_scalar($_GET['quantity'])) {
            $quantity = (int) $_GET['quantity'];
        }

        $pairs = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || strpos($key, 'attribute_') !== 0) {
                continue;
            }
            $token = intersoccer_pv_return_query_token($value);
            if ($token === '') {
                continue;
            }
            $pairs[] = [$key, $token];
        }
        foreach ($days as $day) {
            $token = intersoccer_pv_return_query_token($day);
            if ($token === '') {
                continue;
            }
            $pairs[] = ['camp_days[]', $token];
        }
        if ($quantity > 0) {
            $pairs[] = ['quantity', (string) $quantity];
        }
        $coupon = intersoccer_pv_session_coupon_code();
        if ($coupon !== '') {
            $pairs[] = ['coupon', $coupon];
        }
        $pairs[] = ['bf_return', '1'];

        return intersoccer_pv_append_query_pairs($permalink, $pairs);
    }
}

if (!function_exists('intersoccer_pv_url_has_redirect_arg')) {
    /**
     * @param mixed $url
     * @return bool
     */
    function intersoccer_pv_url_has_redirect_arg($url) {
        return is_string($url) && $url !== '' && preg_match('/(?:\?|&)redirect=/', $url) === 1;
    }
}

if (!function_exists('intersoccer_pv_account_url_with_return')) {
    /**
     * Add redirect=<return URL> to a My Account URL. The destination page stays the same.
     *
     * @param string              $account_url
     * @param int                 $product_id
     * @param array<string,mixed> $selections
     * @return string
     */
    function intersoccer_pv_account_url_with_return($account_url, $product_id = 0, array $selections = []) {
        if (!is_string($account_url) || $account_url === '') {
            return '';
        }
        if (intersoccer_pv_url_has_redirect_arg($account_url)) {
            return $account_url;
        }
        $return = intersoccer_pv_booking_return_url($product_id, $selections);
        if ($return === '') {
            return $account_url;
        }
        return intersoccer_pv_append_query_pairs($account_url, [['redirect', $return]]);
    }
}

if (!function_exists('intersoccer_pv_user_has_players')) {
    /**
     * @param mixed $players
     * @return bool
     */
    function intersoccer_pv_user_has_players($players) {
        if (!is_array($players)) {
            return false;
        }
        foreach ($players as $player) {
            if (is_array($player)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_pv_add_another_child_html')) {
    /**
     * Link for a logged-in parent who already has a child. Empty otherwise.
     *
     * @return string
     */
    function intersoccer_pv_add_another_child_html() {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0 || !function_exists('intersoccer_get_user_players') || !function_exists('intersoccer_pv_manage_players_url')) {
            return '';
        }
        $players = intersoccer_get_user_players($user_id);
        if (!intersoccer_pv_user_has_players($players)) {
            return '';
        }
        $url = intersoccer_pv_manage_players_url();
        if (!is_string($url) || $url === '') {
            return '';
        }
        $html = '<p class="intersoccer-add-another-child"><a class="intersoccer-booking-return-link" href="'
            . esc_url($url) . '">'
            . esc_html(__('Add another child', 'intersoccer-product-variations'))
            . '</a></p>';
        if (!function_exists('wp_kses')) {
            return $html;
        }
        return wp_kses($html, [
            'p' => ['class' => []],
            'a' => [
                'href' => [],
                'class' => [],
            ],
        ]);
    }
}

if (!function_exists('intersoccer_pv_add_to_cart_return_selections')) {
    /**
     * Selections from the add-to-cart request, for PV's own failed-validation redirects.
     *
     * @param mixed $variations
     * @param int   $quantity
     * @param int   $variation_id
     * @return array{attributes:array<string,string>,camp_days:array<int,string>,quantity:int}
     */
    function intersoccer_pv_add_to_cart_return_selections($variations = null, $quantity = 0, $variation_id = 0) {
        $attributes = [];
        if (is_array($variations)) {
            foreach ($variations as $key => $value) {
                if (!is_string($key) || strpos($key, 'attribute_') !== 0 || !is_scalar($value)) {
                    continue;
                }
                $token = intersoccer_pv_return_query_token($value);
                if ($token !== '') {
                    $attributes[$key] = $token;
                }
            }
        }
        if ($attributes === []) {
            $attributes = intersoccer_pv_posted_attribute_selections();
        }
        $days = [];
        if (function_exists('intersoccer_get_posted_camp_days')) {
            $posted = intersoccer_get_posted_camp_days((int) $variation_id);
            if (is_array($posted)) {
                $days = $posted;
            }
        } elseif (isset($_POST['camp_days']) && is_array($_POST['camp_days'])) {
            foreach ($_POST['camp_days'] as $day) {
                $token = intersoccer_pv_return_query_token($day);
                if ($token !== '') {
                    $days[] = $token;
                }
            }
        }
        $qty = (int) $quantity;
        if ($qty <= 0 && isset($_POST['quantity']) && is_scalar($_POST['quantity'])) {
            $qty = (int) $_POST['quantity'];
        }
        return [
            'attributes' => $attributes,
            'camp_days' => $days,
            'quantity' => $qty,
        ];
    }
}

if (!function_exists('intersoccer_pv_matching_variation_id')) {
    /**
     * Variation that matches the attribute_* query args, or 0 when none does.
     *
     * @param int $product_id
     * @return int
     */
    function intersoccer_pv_matching_variation_id($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0 || !function_exists('wc_get_product') || !class_exists('WC_Product_Data_Store_CPT')) {
            return 0;
        }
        $attributes = intersoccer_pv_request_attribute_selections();
        if ($attributes === []) {
            return 0;
        }
        $product = wc_get_product($product_id);
        if (!is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
            return 0;
        }
        $store = new WC_Product_Data_Store_CPT();
        if (!method_exists($store, 'find_matching_product_variation')) {
            return 0;
        }
        return (int) $store->find_matching_product_variation($product, $attributes);
    }
}

if (!function_exists('intersoccer_pv_attribute_request_key')) {
    /**
     * @param string $name
     * @return string
     */
    function intersoccer_pv_attribute_request_key($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        if (strpos($name, 'attribute_') === 0) {
            return $name;
        }
        return 'attribute_' . $name;
    }
}

if (!function_exists('intersoccer_pv_variation_attribute_options')) {
    /**
     * Allowed values for each variation attribute, keyed as attribute_*.
     *
     * @param object $product
     * @return array<string,array<int,string>>
     */
    function intersoccer_pv_variation_attribute_options($product) {
        $options = [];
        if (!is_object($product)) {
            return $options;
        }
        if (method_exists($product, 'get_variation_attributes')) {
            $raw = $product->get_variation_attributes();
            if (is_array($raw)) {
                foreach ($raw as $name => $values) {
                    $key = intersoccer_pv_attribute_request_key($name);
                    if ($key === '' || !is_array($values)) {
                        continue;
                    }
                    $clean = [];
                    foreach ($values as $value) {
                        if (!is_scalar($value)) {
                            continue;
                        }
                        $token = intersoccer_pv_return_query_token($value);
                        if ($token !== '') {
                            $clean[] = $token;
                        }
                    }
                    if ($clean !== []) {
                        $options[$key] = $clean;
                    }
                }
            }
        }
        return $options;
    }
}

if (!function_exists('intersoccer_pv_attribute_value_is_allowed')) {
    /**
     * @param string $value
     * @param array<int,string> $allowed
     * @return bool
     */
    function intersoccer_pv_attribute_value_is_allowed($value, array $allowed) {
        $value = strtolower((string) $value);
        foreach ($allowed as $option) {
            if (strtolower((string) $option) === $value) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_pv_return_attribute_state')) {
    /**
     * How the carried attributes sit on this product.
     *
     * - none: no attributes were carried
     * - matched: they identify a variation
     * - partial: every carried value is a valid option, but not enough to match yet
     * - invalid: a value is unknown, or a complete set matches no variation
     *
     * @param int $product_id
     * @return string
     */
    function intersoccer_pv_return_attribute_state($product_id) {
        if (!intersoccer_pv_is_booking_return_request()) {
            return 'none';
        }
        $attributes = intersoccer_pv_request_attribute_selections();
        if ($attributes === []) {
            return 'none';
        }
        if (!function_exists('wc_get_product')) {
            return 'matched';
        }
        $product = wc_get_product((int) $product_id);
        if (!is_object($product) || !method_exists($product, 'is_type')) {
            return 'matched';
        }
        if (!$product->is_type('variable')) {
            return 'invalid';
        }
        $options = intersoccer_pv_variation_attribute_options($product);
        if ($options !== []) {
            foreach ($attributes as $key => $value) {
                if (!isset($options[$key]) || !intersoccer_pv_attribute_value_is_allowed($value, $options[$key])) {
                    return 'invalid';
                }
            }
        }
        if (intersoccer_pv_matching_variation_id((int) $product_id) > 0) {
            return 'matched';
        }
        if ($options !== [] && count($attributes) < count($options)) {
            return 'partial';
        }
        if (!class_exists('WC_Product_Data_Store_CPT')) {
            return 'matched';
        }
        return 'invalid';
    }
}

if (!function_exists('intersoccer_pv_return_attributes_mismatch')) {
    /**
     * True when a return visit carried attributes that are invalid or unmatched.
     *
     * A valid partial subset (for example venue only) is not a mismatch.
     *
     * @param int $product_id
     * @return bool
     */
    function intersoccer_pv_return_attributes_mismatch($product_id) {
        return intersoccer_pv_return_attribute_state($product_id) === 'invalid';
    }
}

if (!function_exists('intersoccer_pv_return_player_is_eligible')) {
    /**
     * Existing girls-only and age rules. An unknown player is not eligible.
     *
     * @param int $user_id
     * @param int $player_index
     * @param int $product_id
     * @param int $variation_id
     * @return bool
     */
    function intersoccer_pv_return_player_is_eligible($user_id, $player_index, $product_id, $variation_id) {
        if (function_exists('intersoccer_validate_line_player_girls_only')) {
            $girls_only = intersoccer_validate_line_player_girls_only($user_id, $player_index, $product_id, $variation_id);
            if (function_exists('is_wp_error') && is_wp_error($girls_only)) {
                return false;
            }
        }
        if (function_exists('intersoccer_validate_line_player_age')) {
            $age = intersoccer_validate_line_player_age($user_id, $player_index, $product_id, $variation_id);
            if (function_exists('is_wp_error') && is_wp_error($age)) {
                return false;
            }
        }
        return (bool) apply_filters(
            'intersoccer_pv_return_player_eligible',
            true,
            $user_id,
            $player_index,
            $product_id,
            $variation_id
        );
    }
}

if (!function_exists('intersoccer_pv_return_preselect_value')) {
    /**
     * Player select value when a return visit has exactly one eligible player.
     *
     * @param int $product_id
     * @return string
     */
    function intersoccer_pv_return_preselect_value($product_id) {
        if (!intersoccer_pv_is_booking_return_request()) {
            return '';
        }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0 || !function_exists('intersoccer_get_user_players')) {
            return '';
        }
        $players = intersoccer_get_user_players($user_id);
        if (!is_array($players)) {
            return '';
        }
        $valid = [];
        foreach ($players as $index => $player) {
            if (!is_array($player)) {
                continue;
            }
            $first = isset($player['first_name']) ? trim((string) $player['first_name']) : '';
            $last = isset($player['last_name']) ? trim((string) $player['last_name']) : '';
            if ($first === '' || $last === '') {
                continue;
            }
            $valid[$index] = $player;
        }
        if (count($valid) !== 1) {
            return '';
        }
        $index = array_key_first($valid);
        if ($index === null) {
            return '';
        }
        $product_id = (int) $product_id;
        $variation_id = 0;
        if (intersoccer_pv_request_attribute_selections() !== []) {
            $variation_id = intersoccer_pv_matching_variation_id($product_id);
            if ($variation_id <= 0) {
                return '';
            }
        }
        if (!intersoccer_pv_return_player_is_eligible($user_id, (int) $index, $product_id, $variation_id)) {
            return '';
        }
        $player = $valid[$index];
        $player_id = isset($player['player_id']) ? trim((string) $player['player_id']) : '';
        if ($player_id !== '') {
            return $player_id;
        }
        return (string) $index;
    }
}

if (!function_exists('intersoccer_pv_booking_return_has_saved_state')) {
    /**
     * True when the return visit still carries a product option or day to restore.
     *
     * The bf_return flag, a quantity (including the default of 1), and a
     * session coupon on their own are not saved choices.
     *
     * @return bool
     */
    function intersoccer_pv_booking_return_has_saved_state() {
        if (intersoccer_pv_request_attribute_selections() !== []) {
            return true;
        }
        return intersoccer_pv_request_camp_days() !== [];
    }
}

if (!function_exists('intersoccer_pv_booking_return_notice_html')) {
    /**
     * @param int $product_id
     * @return string
     */
    function intersoccer_pv_booking_return_notice_html($product_id) {
        if (!intersoccer_pv_is_booking_return_request()) {
            return '';
        }
        if (!intersoccer_pv_booking_return_has_saved_state()) {
            return '';
        }
        $state = intersoccer_pv_return_attribute_state($product_id);
        if ($state === 'invalid') {
            $text = __('We couldn\'t keep your choices. Please pick them again.', 'intersoccer-product-variations');
            $class = 'woocommerce-info intersoccer-booking-return-note';
        } elseif ($state === 'partial') {
            $text = __('Please finish choosing your options to continue.', 'intersoccer-product-variations');
            $class = 'woocommerce-info intersoccer-booking-return-note';
        } else {
            $text = __('Your choices are saved. Check them and add to cart.', 'intersoccer-product-variations');
            $class = 'woocommerce-message intersoccer-booking-return-note';
        }
        return '<div class="' . esc_attr($class) . '" role="status">' . esc_html($text) . '</div>';
    }
}

if (!function_exists('intersoccer_pv_echo_booking_return_notice')) {
    /**
     * @param int $product_id
     * @return void
     */
    function intersoccer_pv_echo_booking_return_notice($product_id = 0) {
        static $shown = false;
        if ($shown) {
            return;
        }
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            $product_id = intersoccer_pv_current_product_id();
        }
        $html = intersoccer_pv_booking_return_notice_html($product_id);
        if ($html === '') {
            return;
        }
        $shown = true;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

if (!function_exists('intersoccer_pv_filter_account_redirect')) {
    /**
     * Honour a validated redirect request, otherwise leave WooCommerce's target alone.
     *
     * @param string $redirect
     * @return string
     */
    function intersoccer_pv_filter_account_redirect($redirect) {
        $safe = intersoccer_pv_request_return_url();
        if ($safe !== '') {
            return $safe;
        }
        return $redirect;
    }
}

if (!function_exists('intersoccer_pv_print_return_redirect_field')) {
    /**
     * Hidden redirect input for login and registration forms that do not post one.
     *
     * @return void
     */
    function intersoccer_pv_print_return_redirect_field() {
        $safe = intersoccer_pv_request_return_url();
        if ($safe === '') {
            return;
        }
        echo '<input type="hidden" name="redirect" value="' . esc_attr($safe) . '" />';
    }
}

if (!function_exists('intersoccer_pv_register_booking_return_hooks')) {
    /**
     * @return void
     */
    function intersoccer_pv_register_booking_return_hooks() {
        add_filter('woocommerce_login_redirect', 'intersoccer_pv_filter_account_redirect', 10, 2);
        add_filter('woocommerce_registration_redirect', 'intersoccer_pv_filter_account_redirect', 10, 1);
        add_filter('login_redirect', 'intersoccer_pv_filter_account_redirect', 10, 3);
        add_action('woocommerce_before_add_to_cart_form', 'intersoccer_pv_echo_booking_return_notice', 5);
        add_action('woocommerce_login_form_end', 'intersoccer_pv_print_return_redirect_field');
        add_action('woocommerce_register_form_end', 'intersoccer_pv_print_return_redirect_field');
        add_action('login_form', 'intersoccer_pv_print_return_redirect_field');
    }
}

if (!defined('INTERSOCCER_PV_BOOKING_RETURN_LOADED')) {
    define('INTERSOCCER_PV_BOOKING_RETURN_LOADED', true);
    intersoccer_pv_register_booking_return_hooks();
}
