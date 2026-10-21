<?php
/**
 * Compare-and-swap WooCommerce session saves for requests that did not mutate the cart.
 *
 * Prevents slow product-page AJAX / wc-ajax handlers from overwriting a newer cart committed
 * by a concurrent add-to-cart in the same customer session.
 *
 * @package InterSoccer\ProductVariations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session keys that represent cart commercial state (see WC_Cart_Session::set_session).
 *
 * @return string[]
 */
function intersoccer_pv_wc_session_cart_state_keys() {
    $keys = [
        'cart',
        'cart_totals',
        'cart_hash',
        'applied_coupons',
        'coupon_discount_totals',
        'coupon_discount_tax_totals',
        'removed_cart_contents',
        'order_awaiting_payment',
        'store_api_draft_order',
        'shipping_method_counts',
        'previous_shipping_methods',
        'chosen_shipping_methods',
    ];

    /**
     * @param string[] $keys
     */
    return apply_filters('intersoccer_pv_wc_session_cart_state_keys', $keys);
}

/**
 * @return bool
 */
function intersoccer_pv_wc_session_request_changed_cart() {
    return !empty($GLOBALS['intersoccer_pv_wc_session_cart_mutated']);
}

/**
 * @return void
 */
function intersoccer_pv_wc_session_mark_cart_mutated() {
    $GLOBALS['intersoccer_pv_wc_session_cart_mutated'] = true;
}

/**
 * @return void
 */
function intersoccer_pv_wc_session_register_cart_mutation_hooks() {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    $mark = 'intersoccer_pv_wc_session_mark_cart_mutated';
    add_action('woocommerce_add_to_cart', $mark, 0);
    add_action('woocommerce_cart_item_removed', $mark, 0);
    add_action('woocommerce_cart_item_restored', $mark, 0);
    add_action('woocommerce_after_cart_item_quantity_update', $mark, 0);
    add_action('woocommerce_applied_coupon', $mark, 0);
    add_action('woocommerce_removed_coupon', $mark, 0);
    add_action('woocommerce_cart_emptied', $mark, 0);
    add_action('woocommerce_remove_cart_item', $mark, 0);
    add_action('woocommerce_update_cart_action_cart_updated', $mark, 0);
}

/**
 * Whether CAS must defer to WooCommerce's normal save (add-to-cart, cart/checkout AJAX, REST).
 *
 * @return bool
 */
function intersoccer_pv_wc_session_cas_save_is_blocked() {
    if (function_exists('intersoccer_pv_wc_session_volatile_refresh_is_blocked')) {
        return intersoccer_pv_wc_session_volatile_refresh_is_blocked();
    }
    if (!empty($_REQUEST['add-to-cart'])) {
        return true;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    return false;
}

/**
 * Decode a WC session handler _data bundle (per-key serialized values).
 *
 * @param array<string, mixed> $bundle
 * @return array<string, mixed>
 */
function intersoccer_pv_wc_session_decode_bundle(array $bundle) {
    $decoded = [];
    foreach ($bundle as $key => $value) {
        $decoded[$key] = maybe_unserialize($value);
    }
    return $decoded;
}

/**
 * Encode decoded values into a WC session _data bundle.
 *
 * @param array<string, mixed> $decoded
 * @return array<string, mixed>
 */
function intersoccer_pv_wc_session_encode_bundle(array $decoded) {
    $bundle = [];
    foreach ($decoded as $key => $value) {
        if ($value === null) {
            continue;
        }
        $bundle[$key] = maybe_serialize($value);
    }
    return $bundle;
}

/**
 * @param mixed $cart
 * @return int
 */
function intersoccer_pv_wc_session_cart_line_count($cart) {
    return is_array($cart) ? count($cart) : 0;
}

/**
 * Record cart state at request start for stale-overwrite diagnostics.
 *
 * @param mixed $session WC_Session or test double.
 * @return void
 */
function intersoccer_pv_wc_session_snapshot_cart_from_session($session) {
    if (!$session || !method_exists($session, 'get')) {
        return;
    }
    $cart = $session->get('cart', []);
    $GLOBALS['intersoccer_pv_wc_session_cart_snapshot'] = [
        'lines' => intersoccer_pv_wc_session_cart_line_count($cart),
        'cart' => is_array($cart) ? $cart : [],
    ];
}

/**
 * WooCommerce fires woocommerce_load_cart_from_session with no arguments (WC 3.2+).
 *
 * @param mixed $session Optional session instance (tests may pass explicitly).
 * @return void
 */
function intersoccer_pv_wc_session_snapshot_cart_on_load($session = null) {
    if ($session === null && function_exists('WC') && WC() && WC()->session) {
        $session = WC()->session;
    }
    intersoccer_pv_wc_session_snapshot_cart_from_session($session);
}

/**
 * @return string
 */
function intersoccer_pv_wc_session_request_kind() {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return 'rest';
    }
    if (!empty($_REQUEST['add-to-cart'])) {
        return 'add_to_cart';
    }
    if (!empty($_REQUEST['wc-ajax'])) {
        return 'wc_ajax:' . sanitize_key((string) wp_unslash($_REQUEST['wc-ajax']));
    }
    if (!empty($_REQUEST['action'])) {
        return 'admin_ajax:' . sanitize_key((string) wp_unslash($_REQUEST['action']));
    }
    return 'page';
}

/**
 * @param string|int $customer_id
 * @return array{session_value: string, session_expiry: int}|null
 */
function intersoccer_pv_wc_session_read_persisted_row($customer_id) {
    $filtered = apply_filters('intersoccer_pv_wc_session_persisted_row_read', false, $customer_id);
    if ($filtered !== false) {
        return is_array($filtered) ? $filtered : null;
    }
    if (!function_exists('WC') || !WC()->session) {
        return null;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_sessions';
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT session_value, session_expiry FROM {$table} WHERE session_key = %s",
            (string) $customer_id
        ),
        ARRAY_A
    );
    if (!is_array($row) || !isset($row['session_value'])) {
        return null;
    }
    return [
        'session_value' => (string) $row['session_value'],
        'session_expiry' => isset($row['session_expiry']) ? (int) $row['session_expiry'] : 0,
    ];
}

/**
 * @param string|int $customer_id
 * @param string     $old_blob
 * @param string     $new_blob
 * @param int        $expiry
 * @return bool True when the row was updated or inserted.
 */
function intersoccer_pv_wc_session_cas_write_row($customer_id, $old_blob, $new_blob, $expiry) {
    $filtered = apply_filters(
        'intersoccer_pv_wc_session_persisted_row_cas_write',
        null,
        $customer_id,
        $old_blob,
        $new_blob,
        $expiry
    );
    if ($filtered !== null) {
        return (bool) $filtered;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_sessions';
    if ($old_blob === '') {
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (session_key, session_value, session_expiry) VALUES (%s, %s, %d)
				ON DUPLICATE KEY UPDATE session_value = VALUES(session_value), session_expiry = VALUES(session_expiry)",
                (string) $customer_id,
                $new_blob,
                $expiry
            )
        );
        return true;
    }
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET session_value = %s, session_expiry = %d WHERE session_key = %s AND session_value = %s",
            $new_blob,
            $expiry,
            (string) $customer_id,
            $old_blob
        )
    );
    return (int) $updated > 0;
}

/**
 * @param WC_Session $session
 * @return array{0: array<string, mixed>, 1: bool, 2: int}|null
 */
function intersoccer_pv_wc_session_reflection_get_handler_state($session) {
    if (method_exists($session, 'pv_get_data_bundle')) {
        return [
            $session->pv_get_data_bundle(),
            $session->pv_is_dirty(),
            method_exists($session, 'pv_get_session_expiration') ? (int) $session->pv_get_session_expiration() : time() + 3600,
        ];
    }
    if (!$session instanceof WC_Session_Handler) {
        return null;
    }
    $ref = new ReflectionClass($session);
    $data_prop = $ref->getProperty('_data');
    $data_prop->setAccessible(true);
    $dirty_prop = $ref->getProperty('_dirty');
    $dirty_prop->setAccessible(true);
    $exp_prop = $ref->getProperty('_session_expiration');
    $exp_prop->setAccessible(true);
    return [
        (array) $data_prop->getValue($session),
        (bool) $dirty_prop->getValue($session),
        (int) $exp_prop->getValue($session),
    ];
}

/**
 * @param WC_Session $session
 * @param array<string, mixed> $bundle
 * @return void
 */
function intersoccer_pv_wc_session_reflection_set_handler_state($session, array $bundle, bool $dirty) {
    if (method_exists($session, 'pv_set_data_bundle')) {
        $session->pv_set_data_bundle($bundle);
        $session->pv_set_dirty($dirty);
        return;
    }
    if (!$session instanceof WC_Session_Handler) {
        return;
    }
    $ref = new ReflectionClass($session);
    $data_prop = $ref->getProperty('_data');
    $data_prop->setAccessible(true);
    $dirty_prop = $ref->getProperty('_dirty');
    $dirty_prop->setAccessible(true);
    $data_prop->setValue($session, $bundle);
    $dirty_prop->setValue($session, $dirty);
}

/**
 * Merge in-memory session with persisted cart keys (DB wins for cart state keys).
 *
 * @param array<string, mixed> $local_decoded
 * @param array<string, mixed> $db_decoded
 * @return array<string, mixed>
 */
function intersoccer_pv_wc_session_merge_local_with_db_cart_keys(array $local_decoded, array $db_decoded) {
    $cart_keys = array_fill_keys(intersoccer_pv_wc_session_cart_state_keys(), true);
    $merged = $local_decoded;
    foreach ($db_decoded as $key => $value) {
        if (isset($cart_keys[$key])) {
            $merged[$key] = $value;
        }
    }
    foreach ($cart_keys as $key => $_true) {
        if (!array_key_exists($key, $merged) && array_key_exists($key, $db_decoded)) {
            $merged[$key] = $db_decoded[$key];
        }
    }
    return $merged;
}

/**
 * @param WC_Session $session
 * @return void
 */
function intersoccer_pv_wc_session_cas_persist($session) {
    $state = intersoccer_pv_wc_session_reflection_get_handler_state($session);
    if ($state === null) {
        if ($session instanceof WC_Session_Handler) {
            $session->save_data();
        }
        return;
    }
    [$local_bundle, $dirty, $expiry] = $state;
    if (!$dirty || !method_exists($session, 'get_customer_id')) {
        return;
    }
    $customer_id = $session->get_customer_id();
    if ($customer_id === '' || $customer_id === null) {
        if ($session instanceof WC_Session_Handler) {
            $session->save_data();
        }
        return;
    }

    $local_decoded = intersoccer_pv_wc_session_decode_bundle($local_bundle);
    $lines_local = intersoccer_pv_wc_session_cart_line_count($local_decoded['cart'] ?? null);

    $max_attempts = (int) apply_filters('intersoccer_pv_wc_session_cas_max_attempts', 10);
    $prevented_stale = false;
    $lines_out = $lines_local;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        if (function_exists('intersoccer_wc_session_store_cache_delete_row')) {
            intersoccer_wc_session_store_cache_delete_row($customer_id);
        }
        $row = intersoccer_pv_wc_session_read_persisted_row($customer_id);
        if ($row === null) {
            if ($session instanceof WC_Session_Handler) {
                $session->save_data();
            } elseif (method_exists($session, 'pv_fallback_save_data')) {
                $session->pv_fallback_save_data();
            }
            return;
        }

        $stored_blob = $row['session_value'];
        $stored_bundle = maybe_unserialize($stored_blob);
        if (!is_array($stored_bundle)) {
            $stored_bundle = [];
        }
        $db_decoded = intersoccer_pv_wc_session_decode_bundle($stored_bundle);
        $lines_db = intersoccer_pv_wc_session_cart_line_count($db_decoded['cart'] ?? null);

        $merged_decoded = intersoccer_pv_wc_session_merge_local_with_db_cart_keys($local_decoded, $db_decoded);
        $lines_out = intersoccer_pv_wc_session_cart_line_count($merged_decoded['cart'] ?? null);
        $snap_cart = $GLOBALS['intersoccer_pv_wc_session_cart_snapshot']['cart'] ?? null;
        if ($lines_db > $lines_local || (is_array($snap_cart) && ($db_decoded['cart'] ?? []) !== $snap_cart)) {
            $prevented_stale = true;
        }

        $merged_bundle = intersoccer_pv_wc_session_encode_bundle($merged_decoded);
        $new_blob = maybe_serialize($merged_bundle);

        if ($new_blob === $stored_blob) {
            intersoccer_pv_wc_session_reflection_set_handler_state($session, $merged_bundle, false);
            if (function_exists('intersoccer_wc_session_store_cache_delete_row')) {
                intersoccer_wc_session_store_cache_delete_row($customer_id);
            }
            if (defined('WC_SESSION_CACHE_GROUP') && class_exists('WC_Cache_Helper') && function_exists('wp_cache_set')) {
                $prefix = WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP);
                wp_cache_set($prefix . $customer_id, $merged_bundle, WC_SESSION_CACHE_GROUP, max(1, $expiry - time()));
            }
            intersoccer_pv_wc_session_log_save_outcome($prevented_stale, $attempt + 1, false);
            return;
        }

        $expiry = $row['session_expiry'] > 0 ? (int) $row['session_expiry'] : $expiry;
        if (intersoccer_pv_wc_session_cas_write_row($customer_id, $stored_blob, $new_blob, $expiry)) {
            intersoccer_pv_wc_session_reflection_set_handler_state($session, $merged_bundle, false);
            if (defined('WC_SESSION_CACHE_GROUP') && class_exists('WC_Cache_Helper') && function_exists('wp_cache_set')) {
                $prefix = WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP);
                wp_cache_set($prefix . $customer_id, $merged_bundle, WC_SESSION_CACHE_GROUP, max(1, $expiry - time()));
            }
            intersoccer_pv_wc_session_log_save_outcome($prevented_stale, $attempt + 1, false);
            return;
        }
        $local_decoded = $merged_decoded;
        $local_bundle = $merged_bundle;
        $lines_local = $lines_out;
    }

    if (function_exists('intersoccer_wc_session_store_cache_delete_row')) {
        intersoccer_wc_session_store_cache_delete_row($customer_id);
    }
    $final_row = intersoccer_pv_wc_session_read_persisted_row($customer_id);
    if ($final_row !== null) {
        $final_stored_bundle = maybe_unserialize($final_row['session_value']);
        if (!is_array($final_stored_bundle)) {
            $final_stored_bundle = [];
        }
        $final_db_decoded = intersoccer_pv_wc_session_decode_bundle($final_stored_bundle);
        $final_lines_db = intersoccer_pv_wc_session_cart_line_count($final_db_decoded['cart'] ?? null);
        if ($final_lines_db > $lines_local) {
            $prevented_stale = true;
        }
        $local_decoded = intersoccer_pv_wc_session_merge_local_with_db_cart_keys($local_decoded, $final_db_decoded);
        $lines_out = intersoccer_pv_wc_session_cart_line_count($local_decoded['cart'] ?? null);
        $final_merged_bundle = intersoccer_pv_wc_session_encode_bundle($local_decoded);
        intersoccer_pv_wc_session_reflection_set_handler_state($session, $final_merged_bundle, true);
        $expiry = $final_row['session_expiry'] > 0 ? (int) $final_row['session_expiry'] : $expiry;
    }

    if ($session instanceof WC_Session_Handler) {
        $session->save_data();
    } elseif (method_exists($session, 'pv_fallback_save_data')) {
        $session->pv_fallback_save_data();
    }
    intersoccer_pv_wc_session_log_save_outcome($prevented_stale, $max_attempts, true);
}

/**
 * Log (warning level: WP_DEBUG only, WC logs) only when a stale cart write was prevented,
 * a CAS retry happened or the WC fallback save ran. No session key, customer or cart data.
 */
function intersoccer_pv_wc_session_log_save_outcome($prevented_stale, $attempts, $fallback) {
    if ((!$prevented_stale && $attempts <= 1 && !$fallback) || !function_exists('intersoccer_warning')) {
        return;
    }
    intersoccer_warning(sprintf('PV session CAS save kind=%s prevented_stale=%d attempts=%d fallback=%d',
        intersoccer_pv_wc_session_request_kind(), $prevented_stale ? 1 : 0, (int) $attempts, $fallback ? 1 : 0));
}

/**
 * Router hooked at shutdown priority 20 (replaces WC_Session_Handler::save_data for eligible requests).
 *
 * @return void
 */
function intersoccer_pv_wc_session_shutdown_save_router() {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    if (apply_filters('intersoccer_pv_disable_session_cart_cas_save', false)) {
        if (WC()->session instanceof WC_Session_Handler) {
            WC()->session->save_data();
        }
        return;
    }

    $session = WC()->session;
    if (intersoccer_pv_wc_session_request_changed_cart() || intersoccer_pv_wc_session_cas_save_is_blocked()) {
        if ($session instanceof WC_Session_Handler) {
            $session->save_data();
        } elseif (method_exists($session, 'pv_fallback_save_data')) {
            $session->pv_fallback_save_data();
        }
        return;
    }

    $state = intersoccer_pv_wc_session_reflection_get_handler_state($session);
    if ($state === null) {
        if ($session instanceof WC_Session_Handler) {
            $session->save_data();
        }
        return;
    }
    if (!$state[1]) {
        return;
    }

    intersoccer_pv_wc_session_cas_persist($session);
}

/**
 * @return void
 */
function intersoccer_pv_wc_session_cart_safe_save_bootstrap() {
    static $booted = false;
    if ($booted || !function_exists('WC') || !WC()->session) {
        return;
    }
    $session = WC()->session;
    if (!$session instanceof WC_Session_Handler) {
        return;
    }
    $booted = true;
    intersoccer_pv_wc_session_register_cart_mutation_hooks();
    remove_action('shutdown', [$session, 'save_data'], 20);
    add_action('shutdown', 'intersoccer_pv_wc_session_shutdown_save_router', 20);
}

add_action('woocommerce_init', 'intersoccer_pv_wc_session_cart_safe_save_bootstrap', 999);
add_action('woocommerce_load_cart_from_session', 'intersoccer_pv_wc_session_snapshot_cart_on_load', 0);
