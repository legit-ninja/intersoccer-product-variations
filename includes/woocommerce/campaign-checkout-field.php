<?php
/**
 * Classic checkout “who is your child joining?” + guardian email for campaign offers.
 *
 * Server-side validation is authoritative. JS only shows/hides the fields.
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

add_filter('woocommerce_checkout_fields', 'intersoccer_campaign_checkout_fields');
function intersoccer_campaign_checkout_fields($fields) {
    $offers = intersoccer_get_campaign_offers();
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

    $fields['order']['intersoccer_campaign_joining'] = [
        'type' => 'text',
        'label' => $label,
        'placeholder' => $placeholder,
        'required' => false,
        'class' => ['form-row-wide', 'intersoccer-campaign-joining'],
        'priority' => 120,
        'custom_attributes' => [
            'autocomplete' => 'off',
        ],
    ];

    $fields['order']['intersoccer_campaign_joining_email'] = [
        'type' => 'email',
        'label' => $email_label,
        'placeholder' => $email_placeholder,
        'required' => false,
        'class' => ['form-row-wide', 'intersoccer-campaign-joining'],
        'priority' => 121,
        'custom_attributes' => [
            'autocomplete' => 'off',
        ],
    ];

    return $fields;
}

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
        $joining = sanitize_text_field(wp_unslash($_POST['intersoccer_campaign_joining']));
    }

    $email = '';
    if (isset($_POST['intersoccer_campaign_joining_email'])) {
        $raw = wp_unslash($_POST['intersoccer_campaign_joining_email']);
        $email = function_exists('sanitize_email') ? sanitize_email($raw) : trim((string) $raw);
    }

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
