<?php
/**
 * Stripe express checkout (Apple Pay / Google Pay) player gate.
 *
 * Product pages that need a player hide the Stripe express buttons until a
 * player is chosen, using the same player picker and button-state event as
 * the add-to-cart button. The server also refuses an express checkout for a
 * camp/course/birthday line that has no assigned player.
 *
 * Builds on #71 (session stash of the chosen player). No "select a player"
 * notice is shown in the cart or at checkout: the express path fails with a
 * generic payment message only.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Body class set on product pages whose express buttons wait for a player.
 *
 * @return string
 */
function intersoccer_express_gate_body_class_name() {
    return 'intersoccer-express-needs-player';
}

/**
 * Stripe containers to hide while a player is missing.
 * Covers the current Express Checkout Element and the older Payment Request button.
 *
 * @return string[]
 */
function intersoccer_express_gate_selectors() {
    return [
        '#wc-stripe-express-checkout-element',
        '#wc-stripe-express-checkout-button-separator',
        '#wc-stripe-payment-request-wrapper',
        '#wc-stripe-payment-request-button-separator',
        '.wc-stripe-product-checkout-container',
    ];
}

/**
 * Whether the gate applies to a product (same rule as the add-to-cart player check).
 *
 * @param int $product_id Parent product ID.
 * @return bool
 */
function intersoccer_express_gate_applies_to_product($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0 || !function_exists('intersoccer_product_requires_attendee')) {
        return false;
    }
    return (bool) intersoccer_product_requires_attendee($product_id);
}

/**
 * Add the gate class on product pages that need a player, so buttons are hidden from first paint.
 *
 * @param array $classes
 * @return array
 */
add_filter('body_class', 'intersoccer_express_gate_body_class');
function intersoccer_express_gate_body_class($classes) {
    if (!function_exists('is_product') || !is_product()) {
        return $classes;
    }
    $product_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    if (intersoccer_express_gate_applies_to_product($product_id)) {
        $classes[] = intersoccer_express_gate_body_class_name();
    }
    return $classes;
}

/**
 * CSS that hides the express buttons while the body class is set.
 *
 * Uses visibility + zero height rather than display:none: Stripe Elements
 * needs a laid-out container to mount its iframe, and a display:none parent
 * can leave the buttons blank or unmounted. The rule sits on body, so it also
 * covers a container Stripe renders or re-mounts later.
 *
 * @return string
 */
function intersoccer_express_gate_css() {
    $selectors = [];
    // Hidden while no player is chosen, and while the cart already holds program lines.
    foreach (['intersoccer-express-needs-player', 'intersoccer-express-cart-has-programs'] as $body_class) {
        $prefix = 'body.' . $body_class . ' ';
        foreach (intersoccer_express_gate_selectors() as $sel) {
            $selectors[] = $prefix . $sel;
        }
    }
    return implode(",\n", $selectors) . " {\n"
        . "    visibility: hidden !important;\n"
        . "    opacity: 0 !important;\n"
        . "    pointer-events: none !important;\n"
        . "    height: 0 !important;\n"
        . "    min-height: 0 !important;\n"
        . "    margin: 0 !important;\n"
        . "    padding: 0 !important;\n"
        . "    overflow: hidden !important;\n"
        . "}\n";
}

add_action('wp_head', 'intersoccer_express_gate_print_css', 20);
function intersoccer_express_gate_print_css() {
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    echo '<style id="intersoccer-express-gate-css">' . "\n" . intersoccer_express_gate_css() . '</style>' . "\n";
}

/**
 * Generic message for a refused express payment (no "select a player" wording).
 *
 * @return string
 */
function intersoccer_express_gate_message() {
    return __('We could not complete this payment. Please go back to the product page and try again.', 'intersoccer-product-variations');
}

/**
 * Cart keys of lines that need a player but have none.
 *
 * @param array $cart_contents WC cart contents (key => item).
 * @return string[]
 */
function intersoccer_express_gate_missing_player_keys(array $cart_contents) {
    $missing = [];
    foreach ($cart_contents as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        if (!intersoccer_express_gate_applies_to_product($product_id)) {
            continue;
        }
        if (function_exists('intersoccer_cart_item_has_assigned_player') && intersoccer_cart_item_has_assigned_player($item)) {
            continue;
        }
        $missing[] = (string) $key;
    }
    return $missing;
}

/**
 * Current cart contents, or an empty array outside a cart context.
 *
 * @return array
 */
function intersoccer_express_gate_cart_contents() {
    if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart && method_exists(WC()->cart, 'get_cart')) {
        return (array) WC()->cart->get_cart();
    }
    return [];
}

/**
 * Whether the shopper may start an express add-to-cart for this product.
 * Reuses the posted player or the #71 session stash.
 *
 * @param int $product_id
 * @return bool
 */
function intersoccer_express_gate_add_to_cart_allowed($product_id) {
    $product_id = (int) $product_id;
    if (!intersoccer_express_gate_applies_to_product($product_id)) {
        return true;
    }
    if ((int) get_current_user_id() <= 0) {
        return false;
    }
    if (function_exists('intersoccer_has_posted_player_assignment') && intersoccer_has_posted_player_assignment()) {
        return true;
    }
    if (function_exists('intersoccer_restore_stashed_player_into_post') && intersoccer_restore_stashed_player_into_post($product_id)) {
        return function_exists('intersoccer_has_posted_player_assignment') && intersoccer_has_posted_player_assignment();
    }
    return false;
}

/**
 * Stripe's product-page express add-to-cart (wc-ajax=wc_stripe_add_to_cart).
 *
 * Older Stripe releases call WC()->cart->add_to_cart() here without
 * woocommerce_add_to_cart_validation, and only send addon-/wc_ form fields,
 * so a missing player was never checked. Runs before Stripe's handler.
 * Stripe empties the cart on this request anyway; we do the same before
 * refusing, so the payment sheet cannot charge for whatever was in the cart.
 */
add_action('wc_ajax_wc_stripe_add_to_cart', 'intersoccer_express_gate_stripe_add_to_cart', 5);
function intersoccer_express_gate_stripe_add_to_cart() {
    $nonce = isset($_POST['security']) ? sanitize_text_field(wp_unslash((string) $_POST['security'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'wc-stripe-add-to-cart')) {
        return; // Let Stripe's own nonce check reject it.
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (intersoccer_express_gate_add_to_cart_allowed($product_id)) {
        return;
    }
    if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart && method_exists(WC()->cart, 'empty_cart')) {
        WC()->cart->empty_cart();
    }
    intersoccer_warning('Express checkout add-to-cart refused: no player for product ' . $product_id);
    wp_send_json_error(['message' => intersoccer_express_gate_message()]);
}

/**
 * Whether a classic checkout POST came from a Stripe express button.
 *
 * @return bool
 */
function intersoccer_express_gate_is_classic_express_request() {
    foreach (['express_checkout_type', 'payment_request_type'] as $field) {
        if (!empty($_POST[$field])) {
            return true;
        }
    }
    return false;
}

/**
 * Classic checkout (older Stripe express creates the order via process_checkout).
 * Only express requests are checked; normal checkout gets no new notice.
 *
 * @param array    $data
 * @param WP_Error $errors
 */
add_action('woocommerce_after_checkout_validation', 'intersoccer_express_gate_classic_checkout', 20, 2);
function intersoccer_express_gate_classic_checkout($data, $errors) {
    if (!intersoccer_express_gate_is_classic_express_request()) {
        return;
    }
    intersoccer_express_gate_add_checkout_error(intersoccer_express_gate_cart_contents(), $errors);
}

/**
 * Add the generic error when any line is missing a player.
 *
 * @param array    $cart_contents
 * @param WP_Error $errors
 * @return bool True when an error was added.
 */
function intersoccer_express_gate_add_checkout_error(array $cart_contents, $errors) {
    if (!is_object($errors) || !method_exists($errors, 'add')) {
        return false;
    }
    $missing = intersoccer_express_gate_missing_player_keys($cart_contents);
    if (empty($missing)) {
        return false;
    }
    intersoccer_warning('Express checkout refused: ' . count($missing) . ' line(s) without a player');
    $errors->add('intersoccer_payment_not_completed', intersoccer_express_gate_message());
    return true;
}

/**
 * Store API checkout (/wc/store/v1/checkout), which Stripe's Express Checkout
 * Element uses to create the order. Runs before payment is taken.
 *
 * @param WC_Order        $order
 * @param WP_REST_Request $request
 * @throws Exception When a line needs a player and has none.
 */
add_action('woocommerce_store_api_checkout_update_order_from_request', 'intersoccer_express_gate_store_api_checkout', 20, 2);
function intersoccer_express_gate_store_api_checkout($order, $request = null) {
    intersoccer_express_gate_assert_cart_has_players(intersoccer_express_gate_cart_contents());
}

/**
 * Throw the generic Store API error when any line is missing a player.
 *
 * @param array $cart_contents
 * @throws Exception
 */
function intersoccer_express_gate_assert_cart_has_players(array $cart_contents) {
    $missing = intersoccer_express_gate_missing_player_keys($cart_contents);
    if (empty($missing)) {
        return;
    }
    intersoccer_warning('Store API checkout refused: ' . count($missing) . ' line(s) without a player');
    $route_exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
    if (class_exists($route_exception)) {
        throw new $route_exception('intersoccer_payment_not_completed', intersoccer_express_gate_message(), 400);
    }
    throw new Exception(intersoccer_express_gate_message());
}

/* ===========================================================================
 * Single-day camp days and other product-form fields on the express path.
 * Stripe 10.4.0's wc_stripe_add_to_cart and the Store API checkout skip the
 * classic add-to-cart validation and do not post camp_days[] or late pickup.
 * ========================================================================= */

/**
 * Booking-type attribute value for a variation (meta first, then posted/cart attributes).
 *
 * @param int   $variation_id
 * @param array $attributes Posted or cart variation attributes.
 * @return string
 */
function intersoccer_days_gate_booking_type($variation_id, array $attributes = []) {
    $keys = ['attribute_pa_booking-type', 'attribute_pa_booking_type', 'attribute_booking-type', 'attribute_booking_type'];
    $variation_id = (int) $variation_id;
    if ($variation_id > 0 && function_exists('get_post_meta')) {
        foreach ($keys as $key) {
            $value = (string) get_post_meta($variation_id, $key, true);
            if ($value !== '') {
                return $value;
            }
        }
    }
    foreach ($keys as $key) {
        if (!empty($attributes[$key]) && is_scalar($attributes[$key])) {
            return (string) $attributes[$key];
        }
    }
    return '';
}

/**
 * Whether a product/variation is a single-day camp booking.
 *
 * @param int   $product_id
 * @param int   $variation_id
 * @param array $attributes
 * @return bool
 */
function intersoccer_days_gate_is_single_day_camp($product_id, $variation_id, array $attributes = []) {
    if (!function_exists('intersoccer_is_camp') || !intersoccer_is_camp((int) $product_id)) {
        return false;
    }
    if (!function_exists('intersoccer_is_single_day_booking_type')) {
        return false;
    }
    return intersoccer_is_single_day_booking_type(intersoccer_days_gate_booking_type($variation_id, $attributes));
}

/**
 * Variation attributes posted with a request (attribute_* fields).
 *
 * @return array
 */
function intersoccer_days_gate_posted_attributes() {
    $out = [];
    foreach ($_POST as $key => $value) {
        if (is_string($key) && strpos($key, 'attribute_') === 0 && is_scalar($value)) {
            $out[$key] = sanitize_text_field(wp_unslash((string) $value));
        }
    }
    if (!empty($_POST['attributes']) && is_array($_POST['attributes'])) {
        foreach ($_POST['attributes'] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $k = strpos($key, 'attribute_') === 0 ? $key : 'attribute_' . $key;
                $out[$k] = sanitize_text_field(wp_unslash((string) $value));
            }
        }
    }
    return $out;
}

/**
 * Variation ID for an express add-to-cart request (posted, or matched from attributes).
 *
 * @param int   $product_id
 * @param array $attributes
 * @return int
 */
function intersoccer_days_gate_request_variation_id($product_id, array $attributes) {
    if (!empty($_POST['variation_id'])) {
        return absint($_POST['variation_id']);
    }
    if ($attributes && class_exists('WC_Data_Store') && function_exists('wc_get_product')) {
        $product = wc_get_product((int) $product_id);
        if ($product) {
            try {
                $store = WC_Data_Store::load('product');
                return (int) $store->find_matching_product_variation($product, $attributes);
            } catch (Exception $e) {
                return 0;
            }
        }
    }
    return 0;
}

/* ---------------------------------------------------------------------------
 * Session stash of the product-form fields express checkout does not post
 * (same pattern as the player stash from #64): camp_days, late_pickup_type,
 * late_pickup_days. Late pickup cost is never stashed or trusted; it is
 * recomputed from type/days in add_cart_item_data, as on the normal path.
 * ------------------------------------------------------------------------- */

/**
 * @param int $product_id
 * @return string
 */
function intersoccer_express_fields_session_key($product_id) {
    return 'intersoccer_express_fields_' . absint($product_id);
}

/**
 * Normalize picked camp days for a variation (canonical weekday labels, offered days only).
 *
 * @param array $days
 * @param int   $variation_id
 * @return string[]
 */
function intersoccer_days_gate_normalize_days(array $days, $variation_id) {
    if (function_exists('intersoccer_normalize_posted_camp_days')) {
        return intersoccer_normalize_posted_camp_days($days, (int) $variation_id);
    }
    $out = [];
    foreach ($days as $d) {
        $d = sanitize_text_field(wp_unslash((string) $d));
        if ($d !== '') {
            $out[] = $d;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Clean the stashable fields from a request-like array.
 *
 * @param array $source       Usually $_POST.
 * @param int   $variation_id
 * @return array{camp_days:string[],late_pickup_type:string,late_pickup_days:string[]}
 */
function intersoccer_express_fields_from_request(array $source, $variation_id) {
    $days = isset($source['camp_days']) && is_array($source['camp_days']) ? $source['camp_days'] : [];
    $lp_type = isset($source['late_pickup_type']) && is_scalar($source['late_pickup_type'])
        ? sanitize_text_field(wp_unslash((string) $source['late_pickup_type'])) : '';
    if (!in_array($lp_type, ['full-week', 'single-days', 'none'], true)) {
        $lp_type = '';
    }
    $lp_days = [];
    if ($lp_type === 'single-days' && isset($source['late_pickup_days']) && is_array($source['late_pickup_days'])) {
        foreach ($source['late_pickup_days'] as $day) {
            $day = sanitize_text_field(wp_unslash((string) $day));
            if ($day !== '') {
                $lp_days[] = $day;
            }
        }
    }
    return [
        'camp_days' => intersoccer_days_gate_normalize_days($days, (int) $variation_id),
        'late_pickup_type' => $lp_type,
        'late_pickup_days' => array_values(array_unique($lp_days)),
    ];
}

/**
 * Stash product-form fields for a product/variation. Nothing to keep clears the stash.
 *
 * @param int   $product_id
 * @param int   $variation_id
 * @param array $source Request-like array (camp_days[], late_pickup_type, late_pickup_days[]).
 * @return bool True when something was stored.
 */
function intersoccer_stash_express_fields($product_id, $variation_id, array $source) {
    if (!function_exists('WC') || !WC() || !WC()->session) {
        return false;
    }
    $product_id = absint($product_id);
    $variation_id = absint($variation_id);
    if ($product_id <= 0 || $variation_id <= 0) {
        return false;
    }
    $fields = intersoccer_express_fields_from_request($source, $variation_id);
    if (empty($fields['camp_days']) && $fields['late_pickup_type'] === '') {
        intersoccer_clear_stashed_express_fields($product_id);
        return false;
    }
    WC()->session->set(intersoccer_express_fields_session_key($product_id), $fields + [
        'variation_id' => $variation_id,
        'user_id' => (int) get_current_user_id(),
        'stashed_at' => time(),
    ]);
    return true;
}

/**
 * Back-compat wrapper: stash picked days only (keeps any stashed late pickup choice).
 *
 * @param int   $product_id
 * @param int   $variation_id
 * @param array $days
 * @return bool
 */
function intersoccer_stash_selected_days($product_id, $variation_id, array $days) {
    $current = intersoccer_get_stashed_express_fields($product_id, $variation_id);
    $source = ['camp_days' => $days];
    if (!empty($current['late_pickup_type'])) {
        $source['late_pickup_type'] = $current['late_pickup_type'];
        $source['late_pickup_days'] = $current['late_pickup_days'];
    }
    return intersoccer_stash_express_fields($product_id, $variation_id, $source) && !empty(intersoccer_get_stashed_selected_days($product_id, $variation_id));
}

/**
 * @param int $product_id
 * @return void
 */
function intersoccer_clear_stashed_express_fields($product_id) {
    if (!function_exists('WC') || !WC() || !WC()->session) {
        return;
    }
    $key = intersoccer_express_fields_session_key($product_id);
    if (method_exists(WC()->session, '__unset')) {
        WC()->session->__unset($key);
    } else {
        WC()->session->set($key, null);
    }
}

/**
 * Valid stashed fields for a product and variation (same TTL and user binding as the player stash).
 *
 * @param int $product_id
 * @param int $variation_id
 * @return array Empty when nothing valid is stashed.
 */
function intersoccer_get_stashed_express_fields($product_id, $variation_id) {
    if (!function_exists('WC') || !WC() || !WC()->session) {
        return [];
    }
    $data = WC()->session->get(intersoccer_express_fields_session_key($product_id));
    if (!is_array($data)) {
        return [];
    }
    $ttl = function_exists('intersoccer_selected_player_stash_ttl') ? intersoccer_selected_player_stash_ttl() : 45 * 60;
    $stashed_at = isset($data['stashed_at']) ? (int) $data['stashed_at'] : 0;
    if ($stashed_at <= 0 || (time() - $stashed_at) > $ttl || (int) ($data['user_id'] ?? 0) !== (int) get_current_user_id()) {
        intersoccer_clear_stashed_express_fields($product_id);
        return [];
    }
    // Fields only apply to the variation they were picked for (week/venue/age).
    if ((int) $variation_id > 0 && (int) ($data['variation_id'] ?? 0) !== (int) $variation_id) {
        return [];
    }
    return intersoccer_express_fields_from_request($data, (int) ($data['variation_id'] ?? $variation_id));
}

/**
 * @param int $product_id
 * @param int $variation_id
 * @return string[]
 */
function intersoccer_get_stashed_selected_days($product_id, $variation_id) {
    $fields = intersoccer_get_stashed_express_fields($product_id, $variation_id);
    return $fields['camp_days'] ?? [];
}

/**
 * Copy stashed fields into $_POST where the request posted none.
 *
 * @param int $product_id
 * @param int $variation_id
 * @return bool True when anything was restored.
 */
function intersoccer_restore_stashed_express_fields_into_post($product_id, $variation_id) {
    $fields = intersoccer_get_stashed_express_fields($product_id, $variation_id);
    if (empty($fields)) {
        return false;
    }
    $restored = false;
    if (!empty($fields['camp_days']) && (empty($_POST['camp_days']) || !is_array($_POST['camp_days']))) {
        $_POST['camp_days'] = $fields['camp_days'];
        $restored = true;
    }
    if ($fields['late_pickup_type'] !== '' && !isset($_POST['late_pickup_type'])) {
        $_POST['late_pickup_type'] = $fields['late_pickup_type'];
        if ($fields['late_pickup_type'] === 'single-days') {
            $_POST['late_pickup_days'] = $fields['late_pickup_days'];
        }
        $restored = true;
    }
    return $restored;
}

/**
 * Days-only restore used by the classic validation and add_cart_item_data paths.
 *
 * @param int $product_id
 * @param int $variation_id
 * @return bool
 */
function intersoccer_restore_stashed_days_into_post($product_id, $variation_id) {
    if (!empty($_POST['camp_days']) && is_array($_POST['camp_days'])) {
        return false;
    }
    $days = intersoccer_get_stashed_selected_days($product_id, $variation_id);
    if (empty($days)) {
        return false;
    }
    $_POST['camp_days'] = $days;
    return true;
}

/**
 * Product-page AJAX: stash camp days and late pickup choice whenever they change.
 */
add_action('wp_ajax_intersoccer_store_express_fields', 'intersoccer_store_express_fields_callback');
add_action('wp_ajax_nopriv_intersoccer_store_express_fields', 'intersoccer_store_express_fields_callback');
function intersoccer_store_express_fields_callback() {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'intersoccer_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    if ($product_id <= 0) {
        wp_send_json_error(['message' => 'Missing product_id']);
    }
    $stored = intersoccer_stash_express_fields($product_id, $variation_id, $_POST);
    if (!$stored) {
        intersoccer_clear_stashed_express_fields($product_id);
    }
    wp_send_json_success(['product_id' => $product_id, 'cleared' => !$stored]);
}

add_action('woocommerce_add_to_cart', 'intersoccer_clear_stashed_express_fields_after_add', 20, 6);
function intersoccer_clear_stashed_express_fields_after_add($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    intersoccer_clear_stashed_express_fields((int) $product_id);
}

/* ---------------------------------------------------------------------------
 * Express add-to-cart and checkout gates.
 * ------------------------------------------------------------------------- */

/**
 * Whether an express add-to-cart may go ahead as far as picked days are concerned.
 * Restores stashed days into $_POST so add_cart_item_data stores them on the line.
 *
 * @param int   $product_id
 * @param int   $variation_id
 * @param array $attributes
 * @return bool
 */
function intersoccer_express_days_gate_add_to_cart_allowed($product_id, $variation_id, array $attributes = []) {
    if (!intersoccer_days_gate_is_single_day_camp($product_id, $variation_id, $attributes)) {
        return true;
    }
    $posted = function_exists('intersoccer_get_posted_camp_days') ? intersoccer_get_posted_camp_days((int) $variation_id) : [];
    if (!empty($posted)) {
        return true;
    }
    if (intersoccer_restore_stashed_days_into_post($product_id, $variation_id)) {
        $posted = function_exists('intersoccer_get_posted_camp_days') ? intersoccer_get_posted_camp_days((int) $variation_id) : (array) $_POST['camp_days'];
        return !empty($posted);
    }
    return false;
}

/**
 * Run the normal add-to-cart validation (woocommerce_add_to_cart_validation) for an express request.
 * Covers player, single-day days, late pickup, age group and girls-only, and anything added later.
 * Notices raised by the validators are dropped: the express path only shows the generic message.
 *
 * @param int   $product_id
 * @param int   $quantity
 * @param int   $variation_id
 * @param array $attributes
 * @return bool
 */
function intersoccer_express_run_add_to_cart_validation($product_id, $quantity, $variation_id, array $attributes) {
    $passed = (bool) apply_filters('woocommerce_add_to_cart_validation', true, (int) $product_id, max(1, (int) $quantity), (int) $variation_id, $attributes);
    if (!$passed && function_exists('wc_clear_notices')) {
        wc_clear_notices();
    }
    return $passed;
}

/**
 * Stripe product-page express add-to-cart (wc_stripe_add_to_cart). Runs after the player gate
 * (priority 5) and before Stripe's handler: restores the stashed product-form fields into $_POST
 * so the normal add_cart_item_data hooks store them, then runs the normal validation.
 */
add_action('wc_ajax_wc_stripe_add_to_cart', 'intersoccer_express_days_gate_stripe_add_to_cart', 6);
function intersoccer_express_days_gate_stripe_add_to_cart() {
    $nonce = isset($_POST['security']) ? sanitize_text_field(wp_unslash((string) $_POST['security'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'wc-stripe-add-to-cart')) {
        return; // Let Stripe's own nonce check reject it.
    }
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $quantity = isset($_POST['qty']) ? absint($_POST['qty']) : 1;
    $attributes = intersoccer_days_gate_posted_attributes();
    $variation_id = intersoccer_days_gate_request_variation_id($product_id, $attributes);
    if ($variation_id > 0 && empty($_POST['variation_id'])) {
        $_POST['variation_id'] = $variation_id;
    }
    if (!intersoccer_express_gate_applies_to_product($product_id) && !intersoccer_days_gate_is_single_day_camp($product_id, $variation_id, $attributes)) {
        return;
    }
    if (function_exists('intersoccer_restore_stashed_express_fields_into_post')) {
        intersoccer_restore_stashed_express_fields_into_post($product_id, $variation_id);
    }
    $days_ok = intersoccer_express_days_gate_add_to_cart_allowed($product_id, $variation_id, $attributes);
    $valid = $days_ok && intersoccer_express_run_add_to_cart_validation($product_id, $quantity, $variation_id, $attributes);
    if ($valid) {
        return;
    }
    if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart && method_exists(WC()->cart, 'empty_cart')) {
        WC()->cart->empty_cart();
    }
    intersoccer_warning('Express checkout add-to-cart refused for product ' . $product_id . ($days_ok ? ': add-to-cart validation failed' : ': no days picked for single-day camp'));
    wp_send_json_error(['message' => intersoccer_express_gate_message()]);
}

/**
 * Re-run the normal add-to-cart validation for each program line already in the cart,
 * using the line's own data as the "posted" form. Used by the Store API checkout.
 *
 * @param array $cart_contents
 * @return string[] Keys of lines that fail.
 */
function intersoccer_express_cart_lines_failing_validation(array $cart_contents) {
    $failing = [];
    $saved_post = $_POST;
    foreach ($cart_contents as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $product_id = (int) ($item['product_id'] ?? 0);
        $variation_id = (int) ($item['variation_id'] ?? 0);
        if (!intersoccer_express_gate_applies_to_product($product_id)) {
            continue;
        }
        $post = [];
        if (!empty($item['assigned_player_id'])) {
            $post['assigned_player_id'] = (string) $item['assigned_player_id'];
        }
        if (isset($item['assigned_player']) && $item['assigned_player'] !== '' && $item['assigned_player'] !== null) {
            $post['player_assignment'] = (string) $item['assigned_player'];
        }
        if (!empty($item['camp_days']) && is_array($item['camp_days'])) {
            $post['camp_days'] = $item['camp_days'];
        }
        if (!empty($item['late_pickup_type'])) {
            $post['late_pickup_type'] = (string) $item['late_pickup_type'];
            $post['late_pickup_days'] = isset($item['late_pickup_days']) && is_array($item['late_pickup_days']) ? $item['late_pickup_days'] : [];
        }
        $_POST = $post;
        $attributes = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];
        if (!intersoccer_express_run_add_to_cart_validation($product_id, (int) ($item['quantity'] ?? 1), $variation_id, $attributes)) {
            $failing[] = (string) $key;
        }
    }
    $_POST = $saved_post;
    return $failing;
}

/**
 * Cart keys of single-day camp lines with no picked days.
 *
 * @param array $cart_contents
 * @return string[]
 */
function intersoccer_express_days_gate_missing_days_keys(array $cart_contents) {
    $missing = [];
    foreach ($cart_contents as $key => $item) {
        if (!is_array($item)) {
            continue;
        }
        $product_id = (int) ($item['product_id'] ?? 0);
        $variation_id = (int) ($item['variation_id'] ?? 0);
        $attributes = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];
        if (!intersoccer_days_gate_is_single_day_camp($product_id, $variation_id, $attributes)) {
            continue;
        }
        if (!empty($item['camp_days']) && is_array($item['camp_days'])) {
            continue;
        }
        $missing[] = (string) $key;
    }
    return $missing;
}

/**
 * Classic express checkout: generic error when a single-day camp line has no days.
 */
add_action('woocommerce_after_checkout_validation', 'intersoccer_express_days_gate_classic_checkout', 21, 2);
function intersoccer_express_days_gate_classic_checkout($data, $errors) {
    if (!function_exists('intersoccer_express_gate_is_classic_express_request') || !intersoccer_express_gate_is_classic_express_request()) {
        return;
    }
    if (!is_object($errors) || !method_exists($errors, 'add')) {
        return;
    }
    $missing = intersoccer_express_days_gate_missing_days_keys(intersoccer_express_gate_cart_contents());
    if (empty($missing)) {
        return;
    }
    intersoccer_warning('Express checkout refused: ' . count($missing) . ' single-day camp line(s) without days');
    $errors->add('intersoccer_payment_not_completed', intersoccer_express_gate_message());
}

/**
 * Throw the generic checkout error.
 *
 * @param string $log
 * @throws Exception
 */
function intersoccer_days_gate_refuse($log) {
    intersoccer_warning($log);
    $route_exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
    if (class_exists($route_exception)) {
        throw new $route_exception('intersoccer_payment_not_completed', intersoccer_express_gate_message(), 400);
    }
    throw new Exception(intersoccer_express_gate_message());
}

/**
 * Store API checkout (Express Checkout Element): refuse single-day camp lines without days,
 * checking both the cart and the order lines already built from it.
 *
 * @param WC_Order        $order
 * @param WP_REST_Request $request
 * @throws Exception
 */
add_action('woocommerce_store_api_checkout_update_order_from_request', 'intersoccer_express_days_gate_store_api_checkout', 21, 2);
function intersoccer_express_days_gate_store_api_checkout($order, $request = null) {
    $cart = intersoccer_express_gate_cart_contents();
    $missing = intersoccer_express_days_gate_missing_days_keys($cart);
    if (!empty($missing)) {
        intersoccer_days_gate_refuse('Store API checkout refused: ' . count($missing) . ' single-day camp line(s) without days');
    }
    $failing = intersoccer_express_cart_lines_failing_validation($cart);
    if (!empty($failing)) {
        intersoccer_days_gate_refuse('Store API checkout refused: ' . count($failing) . ' line(s) failed add-to-cart validation');
    }
    intersoccer_days_gate_assert_order_has_days($order);
}

/**
 * Final check on order line meta when a classic checkout creates the order (line items exist by now).
 *
 * @param WC_Order $order
 * @param array    $data
 * @throws Exception
 */
add_action('woocommerce_checkout_create_order', 'intersoccer_days_gate_checkout_create_order', 99, 2);
function intersoccer_days_gate_checkout_create_order($order, $data = []) {
    intersoccer_days_gate_assert_order_has_days($order);
}

/**
 * Order line IDs/keys of single-day camp lines whose "Days Selected" meta is empty.
 *
 * @param WC_Order|object $order
 * @return array
 */
function intersoccer_days_gate_order_lines_missing_days($order) {
    if (!is_object($order) || !method_exists($order, 'get_items')) {
        return [];
    }
    $missing = [];
    foreach ((array) $order->get_items() as $key => $item) {
        if (!is_object($item) || !method_exists($item, 'get_product_id')) {
            continue;
        }
        $product_id = (int) $item->get_product_id();
        $variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
        $attributes = [];
        $booking_meta = method_exists($item, 'get_meta') ? $item->get_meta('attribute_pa_booking-type', true) : '';
        if (is_string($booking_meta) && $booking_meta !== '') {
            $attributes['attribute_pa_booking-type'] = $booking_meta;
        }
        if (!intersoccer_days_gate_is_single_day_camp($product_id, $variation_id, $attributes)) {
            continue;
        }
        $days = method_exists($item, 'get_meta') ? $item->get_meta('Days Selected', true) : '';
        if (is_array($days)) {
            $days = implode(',', $days);
        }
        if (trim((string) $days, " ,") === '') {
            $missing[] = $key;
        }
    }
    return $missing;
}

/**
 * Refuse the order when any single-day camp line has no days in its meta.
 *
 * @param WC_Order|object $order
 * @throws Exception
 */
function intersoccer_days_gate_assert_order_has_days($order) {
    $missing = intersoccer_days_gate_order_lines_missing_days($order);
    if (!empty($missing)) {
        intersoccer_days_gate_refuse('Order creation refused: ' . count($missing) . ' single-day camp line(s) without Days Selected');
    }
}


/* ===========================================================================
 * Hide product-page express buttons when the cart already holds program lines.
 * The express sheet empties the cart and buys one line, which would drop the
 * sibling / multi-week discounts the shopper already has in the cart.
 * ========================================================================= */

/**
 * @return string
 */
function intersoccer_express_gate_cart_programs_class_name() {
    return 'intersoccer-express-cart-has-programs';
}

/**
 * Whether the cart holds any camp/course/birthday (player-required) line.
 *
 * @param array $cart_contents
 * @return bool
 */
function intersoccer_express_gate_cart_has_program_lines(array $cart_contents) {
    foreach ($cart_contents as $item) {
        if (is_array($item) && intersoccer_express_gate_applies_to_product((int) ($item['product_id'] ?? 0))) {
            return true;
        }
    }
    return false;
}

add_filter('body_class', 'intersoccer_express_gate_cart_programs_body_class', 11);
function intersoccer_express_gate_cart_programs_body_class($classes) {
    if (!function_exists('is_product') || !is_product()) {
        return $classes;
    }
    if (intersoccer_express_gate_cart_has_program_lines(intersoccer_express_gate_cart_contents())) {
        $classes[] = intersoccer_express_gate_cart_programs_class_name();
    }
    return $classes;
}

/**
 * Server-side twin of the body class: refuse express add-to-cart while the cart holds program lines.
 * Runs first so the cart (and its discounts) is never emptied by Stripe.
 */
add_action('wc_ajax_wc_stripe_add_to_cart', 'intersoccer_express_gate_refuse_when_cart_has_programs', 4);
function intersoccer_express_gate_refuse_when_cart_has_programs() {
    $nonce = isset($_POST['security']) ? sanitize_text_field(wp_unslash((string) $_POST['security'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'wc-stripe-add-to-cart')) {
        return;
    }
    if (!intersoccer_express_gate_cart_has_program_lines(intersoccer_express_gate_cart_contents())) {
        return;
    }
    intersoccer_warning('Express checkout add-to-cart refused: cart already holds program lines');
    wp_send_json_error(['message' => intersoccer_express_gate_message()]);
}

/* ===========================================================================
 * Fallback: write "Assigned Attendee" from assigned_player_id when an order item is saved.
 * ========================================================================= */

/**
 * Fill the Assigned Attendee meta of a line from its assigned_player_id when it is missing.
 *
 * @param WC_Order_Item_Product|object $item
 * @param int                          $customer_id Order customer.
 * @return bool True when the meta was written.
 */
function intersoccer_fill_assigned_attendee_from_player_id($item, $customer_id) {
    if (!is_object($item) || !method_exists($item, 'get_meta') || !method_exists($item, 'update_meta_data')) {
        return false;
    }
    $attendee = $item->get_meta('Assigned Attendee', true);
    if (is_string($attendee) && trim($attendee) !== '') {
        return false;
    }
    $player_id = (string) $item->get_meta('assigned_player_id', true);
    if ($player_id === '' || (int) $customer_id <= 0 || !function_exists('intersoccer_get_player_by_id')) {
        return false;
    }
    $player = intersoccer_get_player_by_id((int) $customer_id, $player_id);
    if (!is_array($player)) {
        return false;
    }
    $name = trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $item->update_meta_data('Assigned Attendee', $name);
    return true;
}

add_action('woocommerce_before_order_item_object_save', 'intersoccer_assigned_attendee_fallback_on_item_save', 20, 1);
function intersoccer_assigned_attendee_fallback_on_item_save($item) {
    if (!is_object($item) || !method_exists($item, 'get_product_id') || !method_exists($item, 'get_order')) {
        return;
    }
    if (!intersoccer_express_gate_applies_to_product((int) $item->get_product_id())) {
        return;
    }
    $order = $item->get_order();
    $customer_id = ($order && method_exists($order, 'get_customer_id')) ? (int) $order->get_customer_id() : 0;
    intersoccer_fill_assigned_attendee_from_player_id($item, $customer_id);
}
