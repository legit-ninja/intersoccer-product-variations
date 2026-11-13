<?php
/**
 * Apply an allowlisted WooCommerce coupon from a front-end URL parameter.
 *
 * The allowlist option is empty by default, so nothing is applied until an
 * admin configures it. Codes that are missing, too long, or not on the list
 * are ignored without a WooCommerce coupon lookup and without shopper notices.
 *
 * A rejected apply (invalid, expired, or not valid for the current cart) is
 * remembered in the session as a code plus cart fingerprint. The same cart is
 * not retried on later page loads. The code stays stored so a changed cart can
 * try once more. WooCommerce's own coupon validation decides eligibility.
 *
 * Coupon codes are normalised with wc_format_coupon_code, then limited to
 * INTERSOCCER_URL_COUPON_MAX_LENGTH characters and to letters, numbers,
 * hyphens, and underscores.
 *
 * This does not write order meta, item meta, or order notes.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('INTERSOCCER_URL_COUPON_MAX_LENGTH')) {
    define('INTERSOCCER_URL_COUPON_MAX_LENGTH', 32);
}

if (!defined('INTERSOCCER_URL_COUPON_ALLOWLIST_OPTION')) {
    define('INTERSOCCER_URL_COUPON_ALLOWLIST_OPTION', 'intersoccer_url_coupon_allowlist');
}

if (!defined('INTERSOCCER_URL_COUPON_SESSION_KEY')) {
    define('INTERSOCCER_URL_COUPON_SESSION_KEY', 'intersoccer_url_coupon_code');
}

if (!defined('INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY')) {
    define('INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY', 'intersoccer_url_coupon_failed');
}

if (!function_exists('intersoccer_url_coupon_normalize')) {
    /**
     * Normalise a raw code the same way at runtime and on save.
     *
     * Empty, oversized, or junk values return an empty string.
     *
     * @param mixed $raw
     * @return string
     */
    function intersoccer_url_coupon_normalize($raw) {
        if (is_array($raw) || is_object($raw) || is_bool($raw) || $raw === null) {
            return '';
        }
        if (!is_scalar($raw)) {
            return '';
        }

        $raw = (string) $raw;
        if (function_exists('wp_unslash')) {
            $unslashed = wp_unslash($raw);
            if (!is_string($unslashed)) {
                return '';
            }
            $raw = $unslashed;
        }

        if (!function_exists('wc_format_coupon_code')) {
            return '';
        }

        $code = wc_format_coupon_code($raw);
        if (!is_string($code)) {
            return '';
        }
        $code = trim($code);
        if ($code === '' || strlen($code) > INTERSOCCER_URL_COUPON_MAX_LENGTH) {
            return '';
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $code)) {
            return '';
        }

        return $code;
    }
}

if (!function_exists('intersoccer_url_coupon_sanitize_allowlist')) {
    /**
     * Sanitise the admin allowlist. One code per line, or comma-separated.
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    function intersoccer_url_coupon_sanitize_allowlist($raw) {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\n|\r|,/', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            $code = intersoccer_url_coupon_normalize($item);
            if ($code === '') {
                continue;
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
    }
}

if (!function_exists('intersoccer_url_coupon_get_allowlist')) {
    /**
     * Allowlisted codes keyed by normalised code. Empty when unset.
     *
     * @return array<string,string>
     */
    function intersoccer_url_coupon_get_allowlist() {
        $stored = get_option(INTERSOCCER_URL_COUPON_ALLOWLIST_OPTION, []);
        $codes = intersoccer_url_coupon_sanitize_allowlist($stored);
        if ($codes === []) {
            return [];
        }
        $keyed = array_combine($codes, $codes);
        return is_array($keyed) ? $keyed : [];
    }
}

if (!function_exists('intersoccer_url_coupon_is_allowlisted')) {
    /**
     * @param string $code Normalised code.
     * @return bool
     */
    function intersoccer_url_coupon_is_allowlisted($code) {
        if ($code === '') {
            return false;
        }
        $allowlist = intersoccer_url_coupon_get_allowlist();
        return isset($allowlist[$code]);
    }
}

if (!function_exists('intersoccer_url_coupon_request_is_safe_get')) {
    /**
     * Front-end GET only. Admin, AJAX, REST, cron, and POST are skipped.
     *
     * @return bool
     */
    function intersoccer_url_coupon_request_is_safe_get() {
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        return $method === 'GET';
    }
}

if (!function_exists('intersoccer_url_coupon_session')) {
    /**
     * @return object|null
     */
    function intersoccer_url_coupon_session() {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = WC();
        if (!$wc || !isset($wc->session) || !is_object($wc->session)) {
            return null;
        }
        if (!method_exists($wc->session, 'get') || !method_exists($wc->session, 'set')) {
            return null;
        }
        return $wc->session;
    }
}

if (!function_exists('intersoccer_url_coupon_session_string')) {
    /**
     * @param object $session
     * @param string $key
     * @return string
     */
    function intersoccer_url_coupon_session_string($session, $key) {
        $value = $session->get($key);
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return '';
    }
}

if (!function_exists('intersoccer_url_coupon_store_code')) {
    /**
     * Store an accepted code and keep a guest session cookie alive.
     *
     * @param string $code
     * @return void
     */
    function intersoccer_url_coupon_store_code($code) {
        $session = intersoccer_url_coupon_session();
        if (!$session) {
            return;
        }

        $existing = intersoccer_url_coupon_normalize(intersoccer_url_coupon_session_string($session, INTERSOCCER_URL_COUPON_SESSION_KEY));
        if ($existing !== $code) {
            $session->set(INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY, '');
        }
        $session->set(INTERSOCCER_URL_COUPON_SESSION_KEY, $code);
        if (method_exists($session, 'set_customer_session_cookie')) {
            $session->set_customer_session_cookie(true);
        }
    }
}

if (!function_exists('intersoccer_url_coupon_capture_query_arg')) {
    /**
     * Read ?coupon= on a safe front-end GET and store it when allowlisted.
     *
     * @return void
     */
    function intersoccer_url_coupon_capture_query_arg() {
        if (!intersoccer_url_coupon_request_is_safe_get() || !isset($_GET['coupon'])) {
            return;
        }

        $code = intersoccer_url_coupon_normalize($_GET['coupon']);
        if ($code === '' || !intersoccer_url_coupon_is_allowlisted($code)) {
            return;
        }

        intersoccer_url_coupon_store_code($code);
    }
}

if (!function_exists('intersoccer_url_coupon_strip_target')) {
    /**
     * Current request path with the coupon parameter removed.
     *
     * Other query arguments, including tracking parameters, are preserved.
     * Returns an empty string when there is nothing to strip.
     *
     * @return string
     */
    function intersoccer_url_coupon_strip_target() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $qpos = strpos($uri, '?');
        if ($uri === '' || $qpos === false) {
            return '';
        }

        $path = substr($uri, 0, $qpos);
        $query = substr($uri, $qpos + 1);
        $hash = '';
        $hpos = strpos($query, '#');
        if ($hpos !== false) {
            $hash = substr($query, $hpos);
            $query = substr($query, 0, $hpos);
        }

        $args = [];
        parse_str($query, $args);
        if (!is_array($args) || !array_key_exists('coupon', $args)) {
            return '';
        }
        unset($args['coupon']);

        $target = $path;
        if ($args !== []) {
            $target .= '?' . http_build_query($args);
        }
        if ($hash !== '') {
            $target .= $hash;
        }
        if ($target === $uri) {
            return '';
        }

        return $target;
    }
}

if (!function_exists('intersoccer_url_coupon_redirect_target_for_request')) {
    /**
     * Stripped URL for this request, or empty when a redirect must not run.
     *
     * An allowlisted code is kept in the URL when the Woo session is not ready,
     * so the parameter is not discarded before it can be stored.
     *
     * @return string
     */
    function intersoccer_url_coupon_redirect_target_for_request() {
        if (!intersoccer_url_coupon_request_is_safe_get() || !isset($_GET['coupon'])) {
            return '';
        }

        $code = intersoccer_url_coupon_normalize($_GET['coupon']);
        if ($code !== '' && intersoccer_url_coupon_is_allowlisted($code) && !intersoccer_url_coupon_session()) {
            return '';
        }

        return intersoccer_url_coupon_strip_target();
    }
}

if (!function_exists('intersoccer_url_coupon_maybe_redirect')) {
    /**
     * Redirect a safe front-end GET so the coupon parameter is not left in the URL.
     *
     * @return void
     */
    function intersoccer_url_coupon_maybe_redirect() {
        $target = intersoccer_url_coupon_redirect_target_for_request();
        if ($target === '' || !function_exists('wp_safe_redirect')) {
            return;
        }

        wp_safe_redirect($target, 302);
        exit;
    }
}

if (!function_exists('intersoccer_url_coupon_cart')) {
    /**
     * @return object|null
     */
    function intersoccer_url_coupon_cart() {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = WC();
        if (!$wc || !isset($wc->cart) || !is_object($wc->cart)) {
            return null;
        }
        return $wc->cart;
    }
}

if (!function_exists('intersoccer_url_coupon_cart_is_empty')) {
    /**
     * @param object $cart
     * @return bool
     */
    function intersoccer_url_coupon_cart_is_empty($cart) {
        if (method_exists($cart, 'is_empty')) {
            return (bool) $cart->is_empty();
        }
        if (method_exists($cart, 'get_cart')) {
            $items = $cart->get_cart();
            return !is_array($items) || $items === [];
        }
        return true;
    }
}

if (!function_exists('intersoccer_url_coupon_already_applied')) {
    /**
     * @param object $cart
     * @param string $code
     * @return bool
     */
    function intersoccer_url_coupon_already_applied($cart, $code) {
        if (method_exists($cart, 'has_discount') && $cart->has_discount($code)) {
            return true;
        }
        if (!method_exists($cart, 'get_applied_coupons')) {
            return false;
        }
        foreach ((array) $cart->get_applied_coupons() as $applied) {
            if (intersoccer_url_coupon_normalize($applied) === $code) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_url_coupon_cart_fingerprint')) {
    /**
     * Stable token for the current cart contents and subtotal.
     *
     * A failed apply is not repeated until this token changes.
     *
     * @param object $cart
     * @return string
     */
    function intersoccer_url_coupon_cart_fingerprint($cart) {
        $parts = [];
        $items = method_exists($cart, 'get_cart') ? $cart->get_cart() : [];
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $key => $item) {
            $qty = 1;
            $product_id = 0;
            $variation_id = 0;
            if (is_array($item)) {
                $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
                $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                $variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
            }
            $parts[] = (string) $key . ':' . $product_id . ':' . $variation_id . ':' . $qty;
        }
        sort($parts);

        $subtotal = '';
        if (method_exists($cart, 'get_subtotal')) {
            $subtotal_value = $cart->get_subtotal();
            if (is_scalar($subtotal_value)) {
                $subtotal = (string) $subtotal_value;
            }
        }

        return md5(implode('|', $parts) . '|' . $subtotal);
    }
}

if (!function_exists('intersoccer_url_coupon_copy_notices')) {
    /**
     * @param mixed $notices
     * @return array
     */
    function intersoccer_url_coupon_copy_notices($notices) {
        if (!is_array($notices)) {
            return [];
        }
        $copy = [];
        foreach ($notices as $type => $items) {
            if (is_array($items)) {
                $copy[$type] = array_merge([], $items);
            } else {
                $copy[$type] = $items;
            }
        }
        return $copy;
    }
}

if (!function_exists('intersoccer_url_coupon_call_without_new_notices')) {
    /**
     * Run a callback and drop notices it added. Existing notices stay.
     *
     * Manual coupon entry does not use this wrapper.
     *
     * @param callable $callback
     * @return mixed
     */
    function intersoccer_url_coupon_call_without_new_notices($callback) {
        $can_restore = function_exists('wc_get_notices') && function_exists('wc_set_notices');
        $before = null;
        if ($can_restore) {
            $current = wc_get_notices();
            if (is_array($current)) {
                $before = intersoccer_url_coupon_copy_notices($current);
            }
        }

        $result = call_user_func($callback);

        if ($can_restore && is_array($before)) {
            wc_set_notices($before);
        }

        return $result;
    }
}

if (!function_exists('intersoccer_url_coupon_clear_stored_code')) {
    /**
     * @param object $session
     * @return void
     */
    function intersoccer_url_coupon_clear_stored_code($session) {
        $session->set(INTERSOCCER_URL_COUPON_SESSION_KEY, '');
        $session->set(INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY, '');
    }
}

if (!function_exists('intersoccer_url_coupon_apply_stored_code')) {
    /**
     * Apply the stored allowlisted code when the cart has an item.
     *
     * @return void
     */
    function intersoccer_url_coupon_apply_stored_code() {
        if (function_exists('is_admin') && is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $session = intersoccer_url_coupon_session();
        $cart = intersoccer_url_coupon_cart();
        if (!$session || !$cart || !method_exists($cart, 'apply_coupon')) {
            return;
        }

        $code = intersoccer_url_coupon_normalize(intersoccer_url_coupon_session_string($session, INTERSOCCER_URL_COUPON_SESSION_KEY));
        if ($code === '') {
            return;
        }
        if (!intersoccer_url_coupon_is_allowlisted($code)) {
            intersoccer_url_coupon_clear_stored_code($session);
            return;
        }
        if (intersoccer_url_coupon_cart_is_empty($cart)) {
            return;
        }
        if (intersoccer_url_coupon_already_applied($cart, $code)) {
            intersoccer_url_coupon_clear_stored_code($session);
            return;
        }

        $fingerprint = $code . '|' . intersoccer_url_coupon_cart_fingerprint($cart);
        $failed = intersoccer_url_coupon_session_string($session, INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY);
        if ($failed !== '' && $failed === $fingerprint) {
            return;
        }

        $applied = intersoccer_url_coupon_call_without_new_notices(function () use ($cart, $code) {
            return (bool) $cart->apply_coupon($code);
        });

        if ($applied) {
            intersoccer_url_coupon_clear_stored_code($session);
            if (method_exists($cart, 'calculate_totals')) {
                $cart->calculate_totals();
            }
            return;
        }

        $session->set(INTERSOCCER_URL_COUPON_FAILED_SESSION_KEY, $fingerprint);
    }
}

if (!function_exists('intersoccer_url_coupon_maybe_apply')) {
    /**
     * Apply hook. Ignores re-entry from calculate_totals during an apply.
     *
     * @return void
     */
    function intersoccer_url_coupon_maybe_apply() {
        static $depth = 0;
        if ($depth > 0) {
            return;
        }
        $depth++;
        try {
            intersoccer_url_coupon_apply_stored_code();
        } finally {
            $depth--;
        }
    }
}

if (!defined('INTERSOCCER_URL_COUPON_LOADED')) {
    define('INTERSOCCER_URL_COUPON_LOADED', true);
    add_action('init', 'intersoccer_url_coupon_capture_query_arg', 20);
    add_action('woocommerce_cart_loaded_from_session', 'intersoccer_url_coupon_maybe_apply', 20);
    add_action('woocommerce_add_to_cart', 'intersoccer_url_coupon_maybe_apply', 15);
    add_action('woocommerce_before_cart', 'intersoccer_url_coupon_maybe_apply', 1);
    add_action('woocommerce_before_checkout_form', 'intersoccer_url_coupon_maybe_apply', 1);
    add_action('template_redirect', 'intersoccer_url_coupon_maybe_redirect', 1);
}
