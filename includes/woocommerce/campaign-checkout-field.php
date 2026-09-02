<?php
/**
 * Classic cart/checkout “who is your child joining?” + guardian email for campaign offers.
 *
 * Server-side checkout validation is authoritative. JS only shows/hides the fields.
 * Cart values persist in the Woo session so Proceed to checkout can prefill.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_campaign_joining_meta_key')) {
    /**
     * Order meta key for exports.
     *
     * @return string
     */
    function intersoccer_campaign_joining_meta_key() {
        return '_intersoccer_campaign_joining';
    }
}

if (!function_exists('intersoccer_campaign_joining_email_meta_key')) {
    /**
     * Order meta key for the other family's guardian email.
     *
     * @return string
     */
    function intersoccer_campaign_joining_email_meta_key() {
        return '_intersoccer_campaign_joining_email';
    }
}

if (!function_exists('intersoccer_campaign_joining_fails_validation')) {
    /**
     * @param array|null $offer
     * @param mixed      $joining
     * @return bool
     */
    function intersoccer_campaign_joining_fails_validation($offer, $joining) {
        if (!$offer || empty($offer['requires_group_field'])) {
            return false;
        }
        return trim((string) $joining) === '';
    }
}

if (!function_exists('intersoccer_campaign_joining_email_fails_validation')) {
    /**
     * @param array|null $offer
     * @param mixed      $email
     * @return bool
     */
    function intersoccer_campaign_joining_email_fails_validation($offer, $email) {
        if (!$offer || empty($offer['requires_group_field'])) {
            return false;
        }
        $clean = function_exists('sanitize_email')
            ? sanitize_email((string) $email)
            : trim((string) $email);
        if ($clean === '') {
            return true;
        }
        if (function_exists('is_email')) {
            return !is_email($clean);
        }
        return filter_var($clean, FILTER_VALIDATE_EMAIL) === false;
    }
}

if (!function_exists('intersoccer_campaign_active_group_offer')) {
    /**
     * @return array|null
     */
    function intersoccer_campaign_active_group_offer() {
        $offer = intersoccer_get_applied_campaign_offer();
        if (!$offer || empty($offer['requires_group_field'])) {
            return null;
        }
        return $offer;
    }
}

if (!function_exists('intersoccer_campaign_add_checkout_error')) {
    /**
     * @param object|null $errors
     * @param string      $code
     * @param string      $message
     */
    function intersoccer_campaign_add_checkout_error($errors, $code, $message) {
        if (is_object($errors) && method_exists($errors, 'add')) {
            $errors->add($code, $message);
            return;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }
    }
}

if (!function_exists('intersoccer_campaign_strip_optional_label')) {
    /**
     * Drop WooCommerce “(optional)” on campaign joining fields. Keep required false
     * so hidden AUTUMN15 fields do not HTML5-block checkout.
     *
     * @param string $field
     * @param string $key
     * @param array  $args
     * @param mixed  $value
     * @return string
     */
    function intersoccer_campaign_strip_optional_label($field, $key, $args = [], $value = null) {
        unset($args, $value);
        if (!in_array((string) $key, ['intersoccer_campaign_joining', 'intersoccer_campaign_joining_email'], true)) {
            return $field;
        }
        return (string) preg_replace('/(?:&nbsp;|\s)*<span class="optional">.*?<\/span>/s', '', (string) $field);
    }
}

if (!function_exists('intersoccer_campaign_sanitize_joining_pair')) {
    /**
     * @param mixed $joining
     * @param mixed $email
     * @return array{joining:string,email:string}
     */
    function intersoccer_campaign_sanitize_joining_pair($joining, $email) {
        $joining = function_exists('sanitize_text_field')
            ? sanitize_text_field((string) $joining)
            : trim((string) $joining);
        $email = function_exists('sanitize_email')
            ? sanitize_email((string) $email)
            : trim((string) $email);

        return [
            'joining' => $joining,
            'email' => $email,
        ];
    }
}

if (!function_exists('intersoccer_campaign_joining_session')) {
    /**
     * @param object|null $session
     * @return object|null
     */
    function intersoccer_campaign_joining_session($session = null) {
        if ($session !== null) {
            return $session;
        }
        if (!function_exists('WC')) {
            return null;
        }
        $wc = WC();
        if (!$wc || !isset($wc->session) || !is_object($wc->session)) {
            return null;
        }
        return $wc->session;
    }
}

if (!function_exists('intersoccer_campaign_set_joining_session')) {
    /**
     * @param mixed       $joining
     * @param mixed       $email
     * @param object|null $session
     * @return array{joining:string,email:string}
     */
    function intersoccer_campaign_set_joining_session($joining, $email, $session = null) {
        $pair = intersoccer_campaign_sanitize_joining_pair($joining, $email);
        $store = intersoccer_campaign_joining_session($session);
        if ($store && method_exists($store, 'set')) {
            $store->set('intersoccer_campaign_joining', $pair['joining']);
            $store->set('intersoccer_campaign_joining_email', $pair['email']);
        }
        return $pair;
    }
}

if (!function_exists('intersoccer_campaign_get_joining_session')) {
    /**
     * @param object|null $session
     * @return array{joining:string,email:string}
     */
    function intersoccer_campaign_get_joining_session($session = null) {
        $store = intersoccer_campaign_joining_session($session);
        $joining = '';
        $email = '';
        if ($store && method_exists($store, 'get')) {
            $joining = (string) $store->get('intersoccer_campaign_joining', '');
            $email = (string) $store->get('intersoccer_campaign_joining_email', '');
        }
        return [
            'joining' => $joining,
            'email' => $email,
        ];
    }
}

if (!function_exists('intersoccer_campaign_persist_joining_from_request')) {
    /**
     * @param array<string,mixed>|null $source
     * @param object|null              $session
     * @return array{joining:string,email:string}|null
     */
    function intersoccer_campaign_persist_joining_from_request($source = null, $session = null) {
        if ($source === null) {
            $source = $_POST;
        }
        if (!is_array($source)) {
            return null;
        }
        if (!isset($source['intersoccer_campaign_joining']) && !isset($source['intersoccer_campaign_joining_email'])) {
            return null;
        }

        $joining = isset($source['intersoccer_campaign_joining']) ? $source['intersoccer_campaign_joining'] : '';
        $email = isset($source['intersoccer_campaign_joining_email']) ? $source['intersoccer_campaign_joining_email'] : '';
        if (function_exists('wp_unslash')) {
            $joining = wp_unslash($joining);
            $email = wp_unslash($email);
        }

        return intersoccer_campaign_set_joining_session($joining, $email, $session);
    }
}

if (!function_exists('intersoccer_campaign_joining_field_args')) {
    /**
     * Shared cart/checkout field args. required stays false so hidden AUTUMN15
     * inputs do not HTML5-block submit.
     *
     * @return array<string,array<string,mixed>>
     */
    function intersoccer_campaign_joining_field_args() {
        $offers = function_exists('intersoccer_get_campaign_offers')
            ? intersoccer_get_campaign_offers()
            : [];
        $label = __('Who is your child joining?', 'intersoccer-product-variations');
        $placeholder = __('Friend or sibling name', 'intersoccer-product-variations');
        $email_label = intersoccer_campaign_translate('Guardian\'s email', __('Guardian\'s email', 'intersoccer-product-variations'));
        $email_placeholder = intersoccer_campaign_translate('parent@example.com', __('parent@example.com', 'intersoccer-product-variations'));
        $group_offer = intersoccer_campaign_active_group_offer();
        if ($group_offer) {
            $label = intersoccer_campaign_translate($group_offer['id'] . '_group_field_label', $group_offer['group_field_label'] ?: $label);
            $placeholder = intersoccer_campaign_translate($group_offer['id'] . '_group_field_placeholder', $group_offer['group_field_placeholder'] ?: $placeholder);
        } else {
            foreach ($offers as $offer) {
                if (!empty($offer['requires_group_field']) && !empty($offer['group_field_label'])) {
                    $label = intersoccer_campaign_translate($offer['id'] . '_group_field_label', $offer['group_field_label']);
                    $placeholder = intersoccer_campaign_translate($offer['id'] . '_group_field_placeholder', $offer['group_field_placeholder']);
                    break;
                }
            }
            $label = intersoccer_campaign_translate('Who is your child joining?', $label);
            $placeholder = intersoccer_campaign_translate('Friend or sibling name', $placeholder);
        }

        return [
            'intersoccer_campaign_joining' => [
                'type' => 'text',
                'label' => $label,
                'placeholder' => $placeholder,
                'required' => false,
                'class' => ['form-row-wide', 'intersoccer-campaign-joining'],
                'priority' => 120,
                'custom_attributes' => [
                    'autocomplete' => 'off',
                ],
            ],
            'intersoccer_campaign_joining_email' => [
                'type' => 'email',
                'label' => $email_label,
                'placeholder' => $email_placeholder,
                'required' => false,
                'class' => ['form-row-wide', 'intersoccer-campaign-joining'],
                'priority' => 121,
                'custom_attributes' => [
                    'autocomplete' => 'off',
                ],
            ],
        ];
    }
}

if (!function_exists('intersoccer_campaign_checkout_get_value')) {
    /**
     * Prefill checkout from session when the posted/stored value is empty.
     *
     * @param mixed       $value
     * @param string      $input
     * @param object|null $session
     * @return mixed
     */
    function intersoccer_campaign_checkout_get_value($value, $input, $session = null) {
        if (!in_array((string) $input, ['intersoccer_campaign_joining', 'intersoccer_campaign_joining_email'], true)) {
            return $value;
        }
        if ($value !== null && trim((string) $value) !== '') {
            return $value;
        }
        $stored = intersoccer_campaign_get_joining_session($session);
        if ((string) $input === 'intersoccer_campaign_joining_email') {
            return $stored['email'];
        }
        return $stored['joining'];
    }
}

add_filter('woocommerce_form_field', 'intersoccer_campaign_strip_optional_label', 10, 4);
add_filter('woocommerce_checkout_get_value', 'intersoccer_campaign_checkout_get_value', 10, 2);
add_filter('woocommerce_checkout_fields', 'intersoccer_campaign_checkout_fields');
function intersoccer_campaign_checkout_fields($fields) {
    $args = intersoccer_campaign_joining_field_args();
    $fields['order']['intersoccer_campaign_joining'] = $args['intersoccer_campaign_joining'];
    $fields['order']['intersoccer_campaign_joining_email'] = $args['intersoccer_campaign_joining_email'];

    return $fields;
}

if (!function_exists('intersoccer_campaign_render_cart_joining_fields')) {
    /**
     * Always output the two fields on classic cart; JS shows them for group coupons.
     */
    function intersoccer_campaign_render_cart_joining_fields() {
        if (!function_exists('woocommerce_form_field')) {
            return;
        }
        $args = intersoccer_campaign_joining_field_args();
        $session = intersoccer_campaign_get_joining_session();
        echo '<div class="intersoccer-campaign-joining-fields">';
        woocommerce_form_field(
            'intersoccer_campaign_joining',
            $args['intersoccer_campaign_joining'],
            $session['joining']
        );
        woocommerce_form_field(
            'intersoccer_campaign_joining_email',
            $args['intersoccer_campaign_joining_email'],
            $session['email']
        );
        echo '</div>';
    }
}
add_action('woocommerce_after_cart_table', 'intersoccer_campaign_render_cart_joining_fields');

if (!function_exists('intersoccer_campaign_persist_joining_from_cart_update')) {
    /**
     * @param mixed $updated
     */
    function intersoccer_campaign_persist_joining_from_cart_update($updated) {
        unset($updated);
        intersoccer_campaign_persist_joining_from_request();
    }
}
add_action('woocommerce_update_cart_action_cart_updated', 'intersoccer_campaign_persist_joining_from_cart_update');

if (!function_exists('intersoccer_campaign_ajax_save_joining')) {
    /**
     * Guest-safe cart persist. Nonce only; do not create a WP user.
     */
    function intersoccer_campaign_ajax_save_joining() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('intersoccer_campaign_joining', 'nonce');
        } elseif (!function_exists('wp_verify_nonce') || !wp_verify_nonce($nonce, 'intersoccer_campaign_joining')) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => 'Invalid nonce'], 403);
            }
            return;
        }

        $pair = intersoccer_campaign_persist_joining_from_request();
        if ($pair === null) {
            $pair = intersoccer_campaign_get_joining_session();
        }
        if (function_exists('wp_send_json_success')) {
            wp_send_json_success($pair);
        }
    }
}
add_action('wp_ajax_intersoccer_save_campaign_joining', 'intersoccer_campaign_ajax_save_joining');
add_action('wp_ajax_nopriv_intersoccer_save_campaign_joining', 'intersoccer_campaign_ajax_save_joining');

add_action('woocommerce_after_checkout_validation', 'intersoccer_campaign_validate_joining_field', 10, 2);
function intersoccer_campaign_validate_joining_field($data, $errors) {
    $offer = intersoccer_campaign_active_group_offer();
    if (!$offer) {
        return;
    }

    $joining = '';
    if (isset($data['intersoccer_campaign_joining'])) {
        $joining = sanitize_text_field($data['intersoccer_campaign_joining']);
    } elseif (isset($_POST['intersoccer_campaign_joining'])) {
        $joining = sanitize_text_field(wp_unslash($_POST['intersoccer_campaign_joining']));
    }

    if (intersoccer_campaign_joining_fails_validation($offer, $joining)) {
        $message = $offer['group_field_error'] ?: __('Please enter who your child is joining.', 'intersoccer-product-variations');
        $message = intersoccer_campaign_translate($offer['id'] . '_group_field_error', $message);
        intersoccer_campaign_add_checkout_error($errors, 'intersoccer_campaign_joining', $message);
    }

    $email = '';
    if (isset($data['intersoccer_campaign_joining_email'])) {
        $email = $data['intersoccer_campaign_joining_email'];
    } elseif (isset($_POST['intersoccer_campaign_joining_email'])) {
        $email = wp_unslash($_POST['intersoccer_campaign_joining_email']);
    }

    if (intersoccer_campaign_joining_email_fails_validation($offer, $email)) {
        $message = intersoccer_campaign_translate(
            'Please enter the other guardian\'s email address.',
            __('Please enter the other guardian\'s email address.', 'intersoccer-product-variations')
        );
        intersoccer_campaign_add_checkout_error($errors, 'intersoccer_campaign_joining_email', $message);
    }
}

add_action('woocommerce_checkout_update_order_meta', 'intersoccer_campaign_save_joining_meta', 10, 1);
function intersoccer_campaign_save_joining_meta($order_id) {
    $joining = '';
    if (isset($_POST['intersoccer_campaign_joining'])) {
        $joining = wp_unslash($_POST['intersoccer_campaign_joining']);
    }

    $email = '';
    if (isset($_POST['intersoccer_campaign_joining_email'])) {
        $email = wp_unslash($_POST['intersoccer_campaign_joining_email']);
    }

    $pair = intersoccer_campaign_sanitize_joining_pair($joining, $email);
    if (isset($_POST['intersoccer_campaign_joining']) || isset($_POST['intersoccer_campaign_joining_email'])) {
        intersoccer_campaign_set_joining_session($pair['joining'], $pair['email']);
    }
    $joining = $pair['joining'];
    $email = $pair['email'];

    if ($joining === '' && $email === '') {
        return;
    }

    if (function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order && method_exists($order, 'update_meta_data')) {
            if ($joining !== '') {
                $order->update_meta_data(intersoccer_campaign_joining_meta_key(), $joining);
            }
            if ($email !== '') {
                $order->update_meta_data(intersoccer_campaign_joining_email_meta_key(), $email);
            }
            $order->save();
            if (function_exists('intersoccer_campaign_link_joining_user')) {
                intersoccer_campaign_link_joining_user($order);
            }
            return;
        }
    }

    if (function_exists('update_post_meta')) {
        if ($joining !== '') {
            update_post_meta((int) $order_id, intersoccer_campaign_joining_meta_key(), $joining);
        }
        if ($email !== '') {
            update_post_meta((int) $order_id, intersoccer_campaign_joining_email_meta_key(), $email);
        }
    }
    if (function_exists('wc_get_order') && function_exists('intersoccer_campaign_link_joining_user')) {
        $linked = wc_get_order($order_id);
        if ($linked) {
            intersoccer_campaign_link_joining_user($linked);
        }
    }
}

add_action('woocommerce_admin_order_data_after_billing_address', 'intersoccer_campaign_display_joining_admin', 10, 1);
function intersoccer_campaign_display_joining_admin($order) {
    if (!is_object($order) || !method_exists($order, 'get_meta')) {
        return;
    }

    $joining = $order->get_meta(intersoccer_campaign_joining_meta_key());
    if ($joining !== '' && $joining !== null) {
        $label = intersoccer_campaign_translate('Joining With', __('Joining With', 'intersoccer-product-variations'));
        echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($joining) . '</p>';
        echo '<p class="description"><code>' . esc_html(intersoccer_campaign_joining_meta_key()) . '</code></p>';
    }

    $email = $order->get_meta(intersoccer_campaign_joining_email_meta_key());
    if ($email !== '' && $email !== null) {
        $label = intersoccer_campaign_translate('Guardian email', __('Guardian email', 'intersoccer-product-variations'));
        echo '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($email) . '</p>';
        echo '<p class="description"><code>' . esc_html(intersoccer_campaign_joining_email_meta_key()) . '</code></p>';
    }
}
