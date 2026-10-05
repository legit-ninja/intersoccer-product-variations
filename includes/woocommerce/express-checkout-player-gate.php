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
    $prefix = 'body.' . intersoccer_express_gate_body_class_name() . ' ';
    $selectors = array_map(function ($sel) use ($prefix) {
        return $prefix . $sel;
    }, intersoccer_express_gate_selectors());
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
