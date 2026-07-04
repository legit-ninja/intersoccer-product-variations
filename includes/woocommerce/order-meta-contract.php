<?php
/**
 * Registry-driven order line item meta contract for checkout and repair tools.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta keys that must not be written on new orders and may be stripped during repair.
 *
 * @return array<int,string>
 */
function intersoccer_order_meta_deprecated_keys() {
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = [
        'Variation ID',
        'Base Price',
        'Remaining Sessions',
        'Player Index',
        'intersoccer_player_index',
    ];

    return apply_filters('intersoccer_order_meta_deprecated_keys', $keys);
}

/**
 * Checkout / repair extras beyond registry attribute labels.
 *
 * @return array<int,string>
 */
function intersoccer_order_meta_checkout_extras() {
    return [
        'Assigned Attendee',
        'Attendee DOB',
        'Attendee Gender',
        'Medical Conditions',
        'Activity Type',
        'Season',
        'Days Selected',
        'Late Pickup Type',
        'Late Pickup Days',
        'Late Pickup Cost',
        'Start Date',
        'End Date',
        'Holidays',
        'Discount',
        'Discount Amount',
        'Girls Only',
        'assigned_player',
    ];
}

/**
 * Allowed visible meta keys for an InterSoccer product type.
 *
 * @param string $product_type camp|course|birthday|tournament
 * @return array<int,string>
 */
function intersoccer_order_meta_allowed_keys($product_type) {
    static $cache = [];
    $type = strtolower((string) $product_type);
    if (isset($cache[$type])) {
        return $cache[$type];
    }

    $keys = intersoccer_order_meta_checkout_extras();

    if (function_exists('intersoccer_attr_product_type_templates')) {
        $templates = intersoccer_attr_product_type_templates();
        if (isset($templates[$type])) {
            foreach (['parent', 'variation'] as $scope) {
                foreach ($templates[$type][$scope] as $slug) {
                    $label = intersoccer_attr_order_meta_label($slug);
                    if ($label !== '') {
                        $keys[] = $label;
                    }
                }
            }
        }
    }

    if (function_exists('intersoccer_attr_registry')) {
        foreach (intersoccer_attr_registry() as $slug => $def) {
            $keys[] = intersoccer_attr_taxonomy($slug);
            if (!empty($def['legacy_meta_keys'])) {
                foreach ($def['legacy_meta_keys'] as $legacy_key) {
                    $keys[] = $legacy_key;
                }
            }
        }
    }

    $cache[$type] = array_values(array_unique(array_filter($keys)));
    return $cache[$type];
}

/**
 * Localized composite suffix for girls-only programs.
 *
 * @param string $language en|fr|de
 * @return string
 */
function intersoccer_order_activity_type_girls_only_suffix($language = 'en') {
    $map = [
        'en' => 'Girls Only',
        'fr' => 'Filles uniquement',
        'de' => 'Nur Mädchen',
    ];
    $language = in_array($language, ['en', 'fr', 'de'], true) ? $language : 'en';
    $suffix = $map[$language];
    return function_exists('icl_t') ? icl_t('intersoccer-product-variations', $suffix, $suffix) : $suffix;
}

/**
 * Detect language hint from an existing Activity Type value.
 *
 * @param string $existing_activity_type
 * @return string en|fr|de
 */
function intersoccer_order_activity_type_detect_language($existing_activity_type = '') {
    if (function_exists('intersoccer_get_activity_type_in_language')) {
        $existing_lower = strtolower(trim((string) $existing_activity_type));
        if (strpos($existing_lower, 'cours') !== false || strpos($existing_lower, 'tournoi') !== false || strpos($existing_lower, 'filles') !== false) {
            return 'fr';
        }
        if (strpos($existing_lower, 'kurs') !== false || strpos($existing_lower, 'lager') !== false || strpos($existing_lower, 'turnier') !== false || strpos($existing_lower, 'madchen') !== false || strpos($existing_lower, 'mädchen') !== false) {
            return 'de';
        }
    }
    return 'en';
}

/**
 * Resolve Activity Type order meta (composite when girls-only).
 *
 * @param int    $product_id
 * @param int    $variation_id
 * @param string $product_type camp|course|birthday|tournament
 * @param string $existing_activity_type Language hint from existing order meta.
 * @return string
 */
function intersoccer_resolve_order_activity_type($product_id, $variation_id, $product_type, $existing_activity_type = '') {
    $product_type = strtolower(trim((string) $product_type));
    if ($product_type === '') {
        return 'Unknown';
    }

    $language = intersoccer_order_activity_type_detect_language($existing_activity_type);
    $base = function_exists('intersoccer_get_activity_type_in_language')
        ? intersoccer_get_activity_type_in_language($product_type, $existing_activity_type)
        : ucfirst($product_type);

    $forced_girls_only = apply_filters('intersoccer_order_activity_type_is_girls_only', null, $product_id, $variation_id, $product_type);
    if ($forced_girls_only !== null) {
        $is_girls_only = (bool) $forced_girls_only;
    } else {
        $is_girls_only = function_exists('intersoccer_line_is_girls_only_program')
            && intersoccer_line_is_girls_only_program((int) $product_id, (int) $variation_id);
    }

    if (!$is_girls_only) {
        return $base;
    }

    if (!in_array($product_type, ['camp', 'course'], true)) {
        return intersoccer_order_activity_type_girls_only_suffix($language);
    }

    $suffix = intersoccer_order_activity_type_girls_only_suffix($language);
    return $base . ', ' . $suffix;
}

/**
 * Whether a taxonomy is pa_activity-type (or legacy alias).
 *
 * @param string $taxonomy
 * @return bool
 */
function intersoccer_is_activity_type_taxonomy($taxonomy) {
    $tax = strtolower((string) $taxonomy);
    return in_array($tax, ['pa_activity-type', 'pa_activity_type'], true);
}

/**
 * Whether a taxonomy is a dedicated girls-only switch attribute.
 *
 * @param string $taxonomy
 * @return bool
 */
function intersoccer_is_girls_only_taxonomy($taxonomy) {
    if (function_exists('intersoccer_taxonomy_is_girls_only_switch_attribute')) {
        return intersoccer_taxonomy_is_girls_only_switch_attribute($taxonomy);
    }
    return (bool) preg_match('/^pa_girls?[\s_-]?only$/i', (string) $taxonomy);
}

/**
 * Format pa_girls-only term for order meta.
 *
 * @param string $slug
 * @param string $name
 * @return string
 */
function intersoccer_format_girls_only_meta_value($slug, $name) {
    if (function_exists('intersoccer_switch_term_indicates_girls_only_session')) {
        if (!intersoccer_switch_term_indicates_girls_only_session($slug, $name)) {
            return '';
        }
    }
    $display = trim((string) $name) !== '' ? trim((string) $name) : trim((string) $slug);
    return $display !== '' ? $display : 'Yes';
}

/**
 * Build order line meta for checkout or repair.
 *
 * @param array<string,mixed> $args {
 *   @type WC_Order|null           $order
 *   @type WC_Order_Item_Product|null $item
 *   @type int                     $product_id
 *   @type int                     $variation_id
 *   @type string                  $product_type
 *   @type array                   $cart_values Checkout cart line values when available.
 *   @type string                  $existing_activity_type
 *   @type bool                    $fix_activity_type_only
 * }
 * @return array{updates: array<string,mixed>, strip: array<int,string>}
 */
function intersoccer_build_order_line_meta($args) {
    $order = $args['order'] ?? null;
    $item = $args['item'] ?? null;
    $product_id = (int) ($args['product_id'] ?? 0);
    $variation_id = (int) ($args['variation_id'] ?? 0);
    $product_type = strtolower((string) ($args['product_type'] ?? ''));
    $cart_values = is_array($args['cart_values'] ?? null) ? $args['cart_values'] : [];
    $existing_activity_type = (string) ($args['existing_activity_type'] ?? '');
    $fix_activity_type_only = !empty($args['fix_activity_type_only']);

    if ($item instanceof WC_Order_Item_Product) {
        if ($product_id <= 0) {
            $product_id = (int) $item->get_product_id();
        }
        if ($variation_id <= 0) {
            $variation_id = (int) $item->get_variation_id();
        }
    }

    if ($product_type === '' && $product_id > 0 && function_exists('intersoccer_get_product_type')) {
        $product_type = strtolower((string) intersoccer_get_product_type($product_id));
    }

    $updates = [];
    $strip = [];

    if (!in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
        return ['updates' => $updates, 'strip' => $strip];
    }

    if ($existing_activity_type === '' && $item instanceof WC_Order_Item_Product) {
        $existing_activity_type = (string) $item->get_meta('Activity Type', true);
    }

    $activity_type = intersoccer_resolve_order_activity_type($product_id, $variation_id, $product_type, $existing_activity_type);
    $activity_type_key = function_exists('icl_t')
        ? icl_t('intersoccer-product-variations', 'Activity Type', 'Activity Type')
        : 'Activity Type';
    $updates[$activity_type_key] = $activity_type;

    if ($fix_activity_type_only) {
        return ['updates' => $updates, 'strip' => $strip];
    }

    $user_id = 0;
    if ($order instanceof WC_Order) {
        $user_id = (int) $order->get_customer_id();
    } elseif ($item instanceof WC_Order_Item_Product) {
        $item_order = $item->get_order();
        if ($item_order instanceof WC_Order) {
            $user_id = (int) $item_order->get_customer_id();
        }
    }

    $assigned_player = null;
    if (isset($cart_values['assigned_player']) && $cart_values['assigned_player'] !== null) {
        $assigned_player = absint($cart_values['assigned_player']);
    } elseif ($item instanceof WC_Order_Item_Product) {
        $assigned_player = $item->get_meta('assigned_player', true);
        if ($assigned_player === '' || $assigned_player === null) {
            $assigned_player = null;
        } else {
            $assigned_player = absint($assigned_player);
        }
    }

    if (isset($cart_values['assigned_attendee']) && $cart_values['assigned_attendee'] !== '') {
        $updates['Assigned Attendee'] = sanitize_text_field($cart_values['assigned_attendee']);
        if ($assigned_player !== null) {
            $updates['assigned_player'] = $assigned_player;
        }
    } elseif ($user_id > 0 && $assigned_player !== null && function_exists('intersoccer_get_player_details')) {
        $player_details = intersoccer_get_player_details($user_id, $assigned_player);
        if (!empty($player_details['name'])) {
            $updates['Assigned Attendee'] = $player_details['name'];
            $updates['assigned_player'] = $assigned_player;
            $updates['Attendee DOB'] = $player_details['dob'] !== '' ? $player_details['dob'] : null;
            $updates['Attendee Gender'] = $player_details['gender'] !== '' ? $player_details['gender'] : null;
            $updates['Medical Conditions'] = !empty($player_details['medical_conditions'])
                ? $player_details['medical_conditions']
                : 'None';
        }
    }

    if ($product_type === 'camp') {
        if (!empty($cart_values['camp_days']) && is_array($cart_values['camp_days'])) {
            $updates['Days Selected'] = implode(', ', array_map('sanitize_text_field', $cart_values['camp_days']));
        }
        if (!empty($cart_values['late_pickup_type']) && $cart_values['late_pickup_type'] !== 'none') {
            $updates['Late Pickup Type'] = $cart_values['late_pickup_type'] === 'full-week' ? 'Full Week' : 'Single Day(s)';
            if ($cart_values['late_pickup_type'] === 'single-days' && !empty($cart_values['late_pickup_days']) && is_array($cart_values['late_pickup_days'])) {
                $updates['Late Pickup Days'] = implode(', ', array_map('sanitize_text_field', $cart_values['late_pickup_days']));
            }
            if (!empty($cart_values['late_pickup_cost']) && $cart_values['late_pickup_cost'] > 0) {
                $updates['Late Pickup Cost'] = wc_price($cart_values['late_pickup_cost']);
            }
        }
    } elseif ($product_type === 'course') {
        $vid = $variation_id ?: $product_id;
        if (function_exists('intersoccer_get_course_meta')) {
            $start_date = intersoccer_get_course_meta($vid, '_course_start_date', '');
            if ($start_date) {
                $updates['Start Date'] = date_i18n('F j, Y', strtotime($start_date));
            }
            $end_date = intersoccer_get_course_meta($vid, '_end_date', '');
            if ($end_date) {
                $updates['End Date'] = date_i18n('F j, Y', strtotime($end_date));
            }
            $holidays = intersoccer_get_course_meta($vid, '_course_holiday_dates', []);
            if (!empty($holidays) && is_array($holidays)) {
                $updates['Holidays'] = implode(', ', array_map(static function ($date) {
                    return date_i18n('F j, Y', strtotime($date));
                }, $holidays));
            }
        }
        if (!empty($cart_values['discount_note'])) {
            $updates['Discount'] = sanitize_text_field($cart_values['discount_note']);
        }
    }

    if (isset($cart_values['discount_amount']) && $cart_values['discount_amount'] > 0) {
        $updates['Discount Amount'] = wc_price($cart_values['discount_amount']);
    }

    $season = function_exists('intersoccer_get_product_season') ? intersoccer_get_product_season($product_id) : '';
    if ($season) {
        $season_key = function_exists('icl_t') ? icl_t('intersoccer-product-variations', 'Season', 'Season') : 'Season';
        $updates[$season_key] = $season;
    }

    if (function_exists('intersoccer_get_parent_product_attributes')) {
        $attributes = intersoccer_get_parent_product_attributes($product_id, $variation_id);
        foreach ($attributes as $label => $value) {
            if (in_array($label, ['Activity Type', 'Season'], true)) {
                continue;
            }
            $updates[$label] = $value;
        }
    }

    foreach ($updates as $key => $value) {
        if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
            unset($updates[$key]);
        }
    }

    return ['updates' => $updates, 'strip' => $strip];
}

/**
 * Strip deprecated keys from an order line item when assigned_player is present.
 *
 * @param WC_Order_Item_Product $item
 * @return array<int,string> Keys removed.
 */
function intersoccer_strip_deprecated_order_line_meta($item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return [];
    }

    $removed = [];
    foreach (intersoccer_order_meta_deprecated_keys() as $key) {
        if ($key === 'Player Index' || $key === 'intersoccer_player_index') {
            $assigned = $item->get_meta('assigned_player', true);
            if ($assigned === '' || $assigned === null) {
                continue;
            }
        }
        if ($item->get_meta($key, true) !== '') {
            $item->delete_meta_data($key);
            $removed[] = $key;
        }
    }

    return $removed;
}

/**
 * Apply contract updates to an order line item.
 *
 * @param WC_Order_Item_Product $item
 * @param array<string,mixed>   $updates
 * @param bool                  $fix_activity_type_only
 * @return bool Whether any change was made.
 */
function intersoccer_apply_order_line_meta_updates($item, array $updates, $fix_activity_type_only = false) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    $existing_keys = array_map(static function ($meta) {
        return $meta->key;
    }, $item->get_meta_data());

    $changed = false;

    foreach ($updates as $key => $value) {
        if ($fix_activity_type_only && $key !== 'Activity Type') {
            continue;
        }
        if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
            continue;
        }

        if (!in_array($key, $existing_keys, true)) {
            if ($fix_activity_type_only) {
                continue;
            }
            $item->add_meta_data($key, $value);
            $changed = true;
            continue;
        }

        if ($key === 'Activity Type' && function_exists('intersoccer_normalize_activity_type')) {
            $existing_value = $item->get_meta($key, true);
            $normalized_existing = intersoccer_normalize_activity_type($existing_value);
            $normalized_expected = intersoccer_normalize_activity_type($value);
            if ($normalized_existing !== $normalized_expected) {
                $corrected = (strpos((string) $normalized_expected, 'girls') !== false)
                    ? $value
                    : (function_exists('intersoccer_get_activity_type_in_language')
                        ? intersoccer_get_activity_type_in_language($normalized_expected, $existing_value)
                        : $value);
                $item->update_meta_data($key, $corrected);
                $changed = true;
            }
        }
    }

    return $changed;
}
