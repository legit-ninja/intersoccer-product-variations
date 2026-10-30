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
        'Booking details',
        'Campaign Code',
        'Campaign Discount %',
        'Girls Only',
        'assigned_player',
        'assigned_player_id',
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
 * Normalize a player row into order-meta detail fields.
 *
 * @param array<string,mixed> $player Player row from intersoccer_players.
 * @return array{first_name:string,last_name:string,name:string,dob:string,gender:string,medical_conditions:string,player_id:string}
 */
function intersoccer_format_player_details_row(array $player) {
    $gender_val = isset($player['gender']) ? trim((string) $player['gender']) : '';
    if ($gender_val === '' && isset($player['player_gender'])) {
        $gender_val = trim((string) $player['player_gender']);
    }

    return [
        'first_name' => (string) ($player['first_name'] ?? ''),
        'last_name' => (string) ($player['last_name'] ?? ''),
        'name' => trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
        'dob' => (string) ($player['dob'] ?? ''),
        'gender' => $gender_val,
        'medical_conditions' => (string) ($player['medical_conditions'] ?? ''),
        'player_id' => (string) ($player['player_id'] ?? ''),
    ];
}

/**
 * Resolve assigned player index, UUID, and display fields for order meta.
 *
 * @param int                      $user_id     Customer user ID.
 * @param array<string,mixed>      $cart_values Cart/checkout context.
 * @param WC_Order_Item_Product|null $item      Existing order line when repairing.
 * @return array{index:int|string|null,player_id:string,details:array<string,string>|null}
 */
function intersoccer_resolve_order_assigned_player($user_id, array $cart_values, $item = null) {
    $result = [
        'index' => null,
        'player_id' => '',
        'details' => null,
    ];

    if (!empty($cart_values['assigned_player_id'])) {
        $result['player_id'] = sanitize_text_field((string) $cart_values['assigned_player_id']);
    } elseif ($item instanceof WC_Order_Item_Product) {
        $result['player_id'] = sanitize_text_field((string) $item->get_meta('assigned_player_id', true));
    }

    if (isset($cart_values['assigned_player']) && $cart_values['assigned_player'] !== null && $cart_values['assigned_player'] !== '') {
        $result['index'] = absint($cart_values['assigned_player']);
    } elseif ($item instanceof WC_Order_Item_Product) {
        $index_meta = $item->get_meta('assigned_player', true);
        if ($index_meta !== '' && $index_meta !== null) {
            $result['index'] = absint($index_meta);
        }
    }

    if ($user_id <= 0) {
        return $result;
    }

    if ($result['player_id'] !== '' && function_exists('intersoccer_get_player_by_id')) {
        $by_id = intersoccer_get_player_by_id($user_id, $result['player_id']);
        if ($by_id !== null && is_array($by_id['player'])) {
            $result['index'] = $by_id['key'];
            $result['details'] = intersoccer_format_player_details_row($by_id['player']);
            if ($result['player_id'] === '' && !empty($by_id['player']['player_id'])) {
                $result['player_id'] = (string) $by_id['player']['player_id'];
            }
            return $result;
        }
    }

    if ($result['index'] !== null && function_exists('intersoccer_get_player_details')) {
        $details = intersoccer_get_player_details($user_id, $result['index']);
        if (!empty($details['name']) && $details['name'] !== 'Unknown Player') {
            $result['details'] = $details;
            if ($result['player_id'] === '' && !empty($details['player_id'])) {
                $result['player_id'] = (string) $details['player_id'];
            } elseif ($result['player_id'] === '' && function_exists('intersoccer_get_player_by_index')) {
                $row = intersoccer_get_player_by_index($user_id, $result['index']);
                if (is_array($row) && !empty($row['player_id'])) {
                    $result['player_id'] = (string) $row['player_id'];
                }
            }
        }
    }

    return $result;
}

/**
 * Apply attendee snapshot fields to order meta updates.
 *
 * Dual-writes underscore-prefixed keys (`_assigned_player`, `_assigned_player_id`)
 * alongside bare keys for new orders. RR readers can migrate to underscore keys
 * while bare keys remain for backward compatibility.
 *
 * @param array<string,mixed> $updates     Meta updates (by reference).
 * @param array<string,mixed> $cart_values Cart values.
 * @param array<string,mixed> $resolved    From intersoccer_resolve_order_assigned_player().
 */
function intersoccer_apply_assigned_player_order_meta(array &$updates, array $cart_values, array $resolved) {
    $details = $resolved['details'] ?? null;
    if (!is_array($details) || empty($details['name'])) {
        if (!empty($cart_values['assigned_attendee'])) {
            $updates['Assigned Attendee'] = sanitize_text_field($cart_values['assigned_attendee']);
        }
        if ($resolved['index'] !== null) {
            $updates['assigned_player'] = $resolved['index'];
            $updates['_assigned_player'] = $resolved['index'];
        }
        if ($resolved['player_id'] !== '') {
            $updates['assigned_player_id'] = $resolved['player_id'];
            $updates['_assigned_player_id'] = $resolved['player_id'];
        }
        return;
    }

    if (!empty($cart_values['assigned_attendee'])) {
        $updates['Assigned Attendee'] = sanitize_text_field($cart_values['assigned_attendee']);
    } else {
        $updates['Assigned Attendee'] = $details['name'];
    }

    if ($resolved['index'] !== null) {
        $updates['assigned_player'] = $resolved['index'];
        $updates['_assigned_player'] = $resolved['index'];
    }
    if ($resolved['player_id'] !== '') {
        $updates['assigned_player_id'] = $resolved['player_id'];
        $updates['_assigned_player_id'] = $resolved['player_id'];
    }

    $updates['Attendee DOB'] = $details['dob'] !== '' ? $details['dob'] : null;
    $updates['Attendee Gender'] = $details['gender'] !== '' ? $details['gender'] : null;
    $updates['Medical Conditions'] = !empty($details['medical_conditions'])
        ? $details['medical_conditions']
        : 'None';
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
        $existing_activity_type = (string) intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Activity Type');
    }

    $activity_type = intersoccer_resolve_order_activity_type($product_id, $variation_id, $product_type, $existing_activity_type);
    $updates['Activity Type'] = $activity_type;

    $forced_girls_only = apply_filters('intersoccer_order_activity_type_is_girls_only', null, $product_id, $variation_id, $product_type);
    if ($forced_girls_only !== null) {
        $is_girls_only = (bool) $forced_girls_only;
    } else {
        $is_girls_only = function_exists('intersoccer_line_is_girls_only_program')
            && intersoccer_line_is_girls_only_program((int) $product_id, (int) $variation_id);
    }

    $canonical = intersoccer_build_canonical_order_line_meta(
        intersoccer_collect_canonical_order_facets($product_id, $variation_id, $product_type, $is_girls_only)
    );
    $updates = array_merge($updates, $canonical);

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

    $item_for_player = ($item instanceof WC_Order_Item_Product) ? $item : null;
    $resolved_player = intersoccer_resolve_order_assigned_player($user_id, $cart_values, $item_for_player);
    if ($resolved_player['details'] !== null || $resolved_player['index'] !== null || $resolved_player['player_id'] !== '') {
        intersoccer_apply_assigned_player_order_meta($updates, $cart_values, $resolved_player);
    } elseif (isset($cart_values['assigned_attendee']) && $cart_values['assigned_attendee'] !== '') {
        $updates['Assigned Attendee'] = sanitize_text_field($cart_values['assigned_attendee']);
    }

    if ($product_type === 'camp') {
        if (!empty($cart_values['camp_days']) && is_array($cart_values['camp_days'])) {
            $days_selected = $cart_values['camp_days'];
            if (function_exists('intersoccer_normalize_posted_camp_days')) {
                $days_selected = intersoccer_normalize_posted_camp_days($days_selected, $variation_id);
            }
            if (!empty($days_selected)) {
                $updates['Days Selected'] = implode(', ', array_map('sanitize_text_field', $days_selected));
            }
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
        // Stamp structured camp schedule as canonical English keys (variation SoT stays _camp_*).
        $vid = $variation_id ?: $product_id;
        if (function_exists('intersoccer_get_camp_schedule_meta')) {
            $schedule = intersoccer_get_camp_schedule_meta($vid);
            $labels = intersoccer_camp_schedule_order_meta_labels_en();
            if ($schedule['start'] !== '') {
                $updates[$labels['start']] = $schedule['start'];
            }
            if ($schedule['end'] !== '') {
                $updates[$labels['end']] = $schedule['end'];
            }
            if ($schedule['week'] !== null) {
                $updates[$labels['week']] = (string) $schedule['week'];
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
    }

    if (!empty($cart_values['discount_note'])) {
        $updates['Discount'] = sanitize_text_field($cart_values['discount_note']);
    }

    if (!empty($cart_values['intersoccer_info_note'])) {
        $updates['Booking details'] = sanitize_text_field((string) $cart_values['intersoccer_info_note']);
    }

    if (isset($cart_values['discount_amount']) && floatval($cart_values['discount_amount']) > 0) {
        // Store the raw number only — never wc_price()/HTML. Reports parse "Discount Amount"
        // with a digit regex; entity-encoded CHF (&#67;…) was read as 67 instead of 105.
        $discount_amount = round(floatval($cart_values['discount_amount']), 2);
        $updates['Discount Amount'] = $discount_amount;
        $updates['_intersoccer_total_item_discount'] = $discount_amount;
        $discount_name = !empty($cart_values['discount_note'])
            ? (string) $cart_values['discount_note']
            : 'Line Item Discount';
        $discount_type = function_exists('intersoccer_determine_discount_type')
            ? intersoccer_determine_discount_type($discount_name)
            : 'other';
        $updates['_intersoccer_item_discounts'] = [
            [
                'name' => $discount_name,
                'type' => $discount_type,
                'amount' => $discount_amount,
                'allocation_method' => 'cart_line',
            ],
        ];
    }

    $season_product_id = $variation_id ?: $product_id;
    $season = function_exists('intersoccer_get_product_season') ? intersoccer_get_product_season($season_product_id) : '';
    if ($season === '' && function_exists('intersoccer_get_product_season')) {
        $season = intersoccer_get_product_season($product_id);
    }
    if ($season) {
        $updates['Season'] = $season;
    }
    if (function_exists('intersoccer_discount_season_key')) {
        $discount_season = intersoccer_discount_season_key($season_product_id);
        if ($discount_season !== '') {
            $updates['_intersoccer_discount_season_key'] = $discount_season;
        }
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
 * Meta keys that repair may update when missing or empty (not add-only).
 *
 * @return array<int,string>
 */
function intersoccer_order_meta_correctable_keys() {
    return array_merge(
        [
            'Activity Type',
            'Attendee DOB',
            'Attendee Gender',
            'Medical Conditions',
            'assigned_player_id',
        ],
        intersoccer_canonical_order_meta_keys()
    );
}

/**
 * Language-neutral order-line keys from the taxonomy / order-meta contract.
 *
 * @return array<int,string>
 */
function intersoccer_canonical_order_meta_keys() {
    return [
        '_intersoccer_canonical_activity_type',
        '_intersoccer_canonical_girls_only',
        '_intersoccer_canonical_booking_type',
        '_intersoccer_canonical_venue',
        '_intersoccer_canonical_canton',
        '_intersoccer_canonical_age_group',
        '_intersoccer_canonical_camp_terms',
    ];
}

/**
 * @param string $product_type camp|course|birthday|tournament|…
 * @return string camp|course|tournament|birthday|event|other
 */
function intersoccer_normalize_canonical_activity_type($product_type) {
    $type = strtolower(trim((string) $product_type));
    $map = [
        'camp' => 'camp',
        'course' => 'course',
        'tournament' => 'tournament',
        'birthday' => 'birthday',
        'birthday-party' => 'birthday',
        'event' => 'event',
    ];
    return $map[$type] ?? 'other';
}

/**
 * @param mixed $raw Slug or display label.
 * @return string full-week|single-days|full-term|buyclub|other|''
 */
function intersoccer_normalize_canonical_booking_type($raw) {
    $raw = html_entity_decode(trim((string) $raw), ENT_QUOTES, 'UTF-8');
    if (function_exists('remove_accents')) {
        $raw = remove_accents($raw);
    } else {
        $raw = strtr($raw, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ç' => 'c',
        ]);
    }
    $raw = strtolower($raw);
    if ($raw === '') {
        return '';
    }
    $compact = preg_replace('/[\s_]+/', '-', $raw);
    $compact = str_replace(['(', ')'], '', (string) $compact);
    $aliases = [
        'full-week' => 'full-week',
        'fullweek' => 'full-week',
        'ganze-woche' => 'full-week',
        'semaine-complete' => 'full-week',
        'single-days' => 'single-days',
        'single-day' => 'single-days',
        'einzelner-tage' => 'single-days',
        'a-la-journee' => 'single-days',
        'full-term' => 'full-term',
        'fullterm' => 'full-term',
        'volle-laufzeit' => 'full-term',
        'trimestre-complet' => 'full-term',
        'buyclub' => 'buyclub',
        'buy-club' => 'buyclub',
        'other' => 'other',
    ];
    if (isset($aliases[$compact])) {
        return $aliases[$compact];
    }
    if (strpos($raw, 'full') !== false && strpos($raw, 'week') !== false) {
        return 'full-week';
    }
    if (strpos($raw, 'ganze') !== false && strpos($raw, 'woche') !== false) {
        return 'full-week';
    }
    if (strpos($raw, 'semaine') !== false && strpos($raw, 'complet') !== false) {
        return 'full-week';
    }
    if (strpos($raw, 'single') !== false) {
        return 'single-days';
    }
    if (strpos($raw, 'einzeln') !== false && strpos($raw, 'tag') !== false) {
        return 'single-days';
    }
    if (strpos($raw, 'journee') !== false || strpos($raw, 'journée') !== false) {
        return 'single-days';
    }
    if (strpos($raw, 'term') !== false) {
        return 'full-term';
    }
    if (strpos($raw, 'laufzeit') !== false) {
        return 'full-term';
    }
    if (strpos($raw, 'trimestre') !== false && strpos($raw, 'complet') !== false) {
        return 'full-term';
    }
    if (strpos($raw, 'buy') !== false && strpos($raw, 'club') !== false) {
        return 'buyclub';
    }
    return 'other';
}

/**
 * @param bool $is_girls_only
 * @return string 0|1
 */
function intersoccer_normalize_canonical_girls_only_flag($is_girls_only) {
    return $is_girls_only ? '1' : '0';
}

/**
 * Build language-neutral order-line keys from resolved facets.
 *
 * Values are EN slugs / fixed enums — never translated display labels.
 * Girls Only is 0/1 and is never folded into activity_type.
 * Camp terms are omitted when a camp week index is present.
 *
 * @param array<string,mixed> $facets {
 *   @type string     $product_type
 *   @type bool       $girls_only
 *   @type string     $booking_type
 *   @type string     $venue
 *   @type string     $canton
 *   @type string     $age_group
 *   @type string     $camp_terms
 *   @type int|string $camp_week_index
 * }
 * @return array<string,string>
 */
function intersoccer_build_canonical_order_line_meta(array $facets) {
    $meta = [
        '_intersoccer_canonical_activity_type' => intersoccer_normalize_canonical_activity_type($facets['product_type'] ?? ''),
        '_intersoccer_canonical_girls_only' => intersoccer_normalize_canonical_girls_only_flag(!empty($facets['girls_only'])),
    ];

    $booking = intersoccer_normalize_canonical_booking_type($facets['booking_type'] ?? '');
    if ($booking !== '') {
        $meta['_intersoccer_canonical_booking_type'] = $booking;
    }

    foreach ([
        'venue' => '_intersoccer_canonical_venue',
        'canton' => '_intersoccer_canonical_canton',
        'age_group' => '_intersoccer_canonical_age_group',
    ] as $facet => $key) {
        $slug = intersoccer_canonical_term_slug_value($facets[$facet] ?? '');
        if ($slug !== '') {
            $meta[$key] = $slug;
        }
    }

    $has_week = isset($facets['camp_week_index'])
        && $facets['camp_week_index'] !== null
        && $facets['camp_week_index'] !== '';
    if (!$has_week) {
        $camp_terms = intersoccer_canonical_term_slug_value($facets['camp_terms'] ?? '');
        if ($camp_terms !== '') {
            $meta['_intersoccer_canonical_camp_terms'] = $camp_terms;
        }
    }

    return $meta;
}

/**
 * Persistable EN slug: never HTML entities or display labels with spaces/commas.
 *
 * @param mixed $raw
 * @return string
 */
function intersoccer_canonical_term_slug_value($raw) {
    $raw = html_entity_decode(trim((string) $raw), ENT_QUOTES, 'UTF-8');
    if ($raw === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $raw = remove_accents($raw);
    }
    return sanitize_title($raw);
}

/**
 * Resolve a line's term slug from variation meta, then parent terms.
 *
 * @param int    $product_id
 * @param int    $variation_id
 * @param string $attr_slug Bare registry slug.
 * @return string
 */
function intersoccer_resolve_line_term_slug($product_id, $variation_id, $attr_slug) {
    $attr_slug = (string) $attr_slug;
    $variation_id = (int) $variation_id;
    $product_id = (int) $product_id;

    if ($variation_id > 0 && function_exists('intersoccer_attr_get_variation_value')) {
        $from_var = intersoccer_attr_get_variation_value($variation_id, $attr_slug);
        if (is_string($from_var) && $from_var !== '') {
            return intersoccer_canonical_term_slug_value($from_var);
        }
    }

    $taxonomy = function_exists('intersoccer_attr_taxonomy')
        ? intersoccer_attr_taxonomy($attr_slug)
        : ('pa_' . $attr_slug);

    foreach ([$variation_id, $product_id] as $id) {
        if ($id <= 0 || !function_exists('wc_get_product_terms')) {
            continue;
        }
        $terms = wc_get_product_terms($id, $taxonomy, ['fields' => 'slugs']);
        if (!empty($terms) && !is_wp_error($terms)) {
            $first = reset($terms);
            if (is_string($first) && $first !== '') {
                return intersoccer_canonical_term_slug_value($first);
            }
        }
    }

    return '';
}

/**
 * Collect canonical facets for a product line (catalog slugs, not display labels).
 *
 * @param int    $product_id
 * @param int    $variation_id
 * @param string $product_type
 * @param bool   $girls_only
 * @return array<string,mixed>
 */
function intersoccer_collect_canonical_order_facets($product_id, $variation_id, $product_type, $girls_only = false) {
    $facets = [
        'product_type' => $product_type,
        'girls_only' => (bool) $girls_only,
        'booking_type' => intersoccer_resolve_line_term_slug($product_id, $variation_id, 'booking-type'),
        'venue' => intersoccer_resolve_line_term_slug($product_id, $variation_id, 'intersoccer-venues'),
        'canton' => intersoccer_resolve_line_term_slug($product_id, $variation_id, 'canton-region'),
        'age_group' => intersoccer_resolve_line_term_slug($product_id, $variation_id, 'age-group'),
        'camp_terms' => intersoccer_resolve_line_term_slug($product_id, $variation_id, 'camp-terms'),
        'camp_week_index' => null,
    ];

    $vid = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
    if ($vid > 0 && function_exists('intersoccer_get_camp_schedule_meta')) {
        $schedule = intersoccer_get_camp_schedule_meta($vid);
        if (isset($schedule['week']) && $schedule['week'] !== null && $schedule['week'] !== '') {
            $facets['camp_week_index'] = $schedule['week'];
        }
    }

    return $facets;
}

/**
 * Collect attribute_pa_* keys from a variation for order line meta.
 *
 * Does not write raw pa_* keys — those duplicate attribute_pa_* and the human
 * order_meta_label (e.g. Age Group) in WooCommerce admin formatted meta.
 *
 * @param int $variation_id
 * @return array<string,string>
 */
function intersoccer_collect_variation_taxonomy_meta($variation_id) {
    $meta = [];
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0 || !function_exists('wc_get_product')) {
        return $meta;
    }

    $variation_product = wc_get_product($variation_id);
    if (!$variation_product || !method_exists($variation_product, 'get_variation_attributes')) {
        return $meta;
    }

    foreach ($variation_product->get_variation_attributes() as $vkey => $vval) {
        $taxonomy = str_replace('attribute_', '', (string) $vkey);
        if (strpos($taxonomy, 'pa_') !== 0 || $vval === '') {
            continue;
        }
        if (function_exists('intersoccer_attr_slug_from_taxonomy')) {
            $attr_slug = intersoccer_attr_slug_from_taxonomy($taxonomy);
            if ($attr_slug && function_exists('intersoccer_attr_resolve_meta_key')) {
                $meta[intersoccer_attr_resolve_meta_key($attr_slug)] = sanitize_text_field($vval);
            }
        }
    }

    return $meta;
}

/**
 * Sanitize a contract meta value for persistence on an order line item.
 *
 * @param mixed $value
 * @return mixed
 */
function intersoccer_sanitize_order_line_meta_value($value) {
    if (is_string($value)) {
        return sanitize_text_field($value);
    }
    return $value;
}

/**
 * Write registry-driven order line meta (checkout and repair).
 *
 * @param WC_Order_Item_Product $item
 * @param array<string,mixed>   $context {
 *   @type WC_Order|null $order
 *   @type int           $product_id
 *   @type int           $variation_id
 *   @type string        $product_type
 *   @type array         $cart_values
 *   @type bool          $fix_activity_type_only
 *   @type string        $mode checkout|repair
 * }
 * @return bool Whether any change was made (repair) or meta was written (checkout).
 */
function intersoccer_write_order_line_meta($item, array $context) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    $mode = isset($context['mode']) ? (string) $context['mode'] : 'checkout';
    $product_id = (int) ($context['product_id'] ?? 0);
    $variation_id = (int) ($context['variation_id'] ?? 0);
    $product_type = (string) ($context['product_type'] ?? '');
    $cart_values = is_array($context['cart_values'] ?? null) ? $context['cart_values'] : [];
    $fix_activity_type_only = !empty($context['fix_activity_type_only']);
    $order = $context['order'] ?? null;

    if ($product_id <= 0) {
        $product_id = (int) $item->get_product_id();
    }
    if ($variation_id <= 0) {
        $variation_id = (int) $item->get_variation_id();
    }
    if ($product_type === '' && $product_id > 0 && function_exists('intersoccer_get_product_type')) {
        $product_type = strtolower((string) intersoccer_get_product_type($product_id));
    }
    if (!($order instanceof WC_Order)) {
        $order = $item->get_order();
    }

    $built = intersoccer_build_order_line_meta([
        'order' => $order instanceof WC_Order ? $order : null,
        'item' => $item,
        'product_id' => $product_id,
        'variation_id' => $variation_id,
        'product_type' => $product_type,
        'cart_values' => $cart_values,
        'existing_activity_type' => (string) intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Activity Type'),
        'fix_activity_type_only' => $fix_activity_type_only,
    ]);

    $variation_tax_meta = intersoccer_collect_variation_taxonomy_meta($variation_id);
    $updates = array_merge($built['updates'], $variation_tax_meta);

    // Dual-write existing _camp_* keys alongside human Camp Start/End/Week labels.
    if (!$fix_activity_type_only && strtolower($product_type) === 'camp' && function_exists('intersoccer_get_camp_schedule_meta')) {
        $vid = $variation_id ?: $product_id;
        $schedule = intersoccer_get_camp_schedule_meta($vid);
        if (!empty($schedule['start'])) {
            $updates['_camp_start_date'] = $schedule['start'];
        }
        if (!empty($schedule['end'])) {
            $updates['_camp_end_date'] = $schedule['end'];
        }
        if (isset($schedule['week']) && $schedule['week'] !== null && $schedule['week'] !== '') {
            $updates['_camp_week_index'] = (string) $schedule['week'];
        }
    }

    // Prefer human order_meta_label over attribute_pa_* when either is already present
    // or about to be written — avoids repair re-adding attribute twins that prune removes.
    if (function_exists('intersoccer_attr_order_meta_label') && function_exists('intersoccer_attr_slug_from_taxonomy')) {
        foreach (array_keys($updates) as $key) {
            if (strpos((string) $key, 'attribute_pa_') !== 0) {
                continue;
            }
            $taxonomy = substr((string) $key, strlen('attribute_'));
            $slug = intersoccer_attr_slug_from_taxonomy($taxonomy);
            if (!$slug) {
                continue;
            }
            $label = intersoccer_attr_order_meta_label($slug);
            if ($label === '') {
                continue;
            }
            $has_label_in_updates = array_key_exists($label, $updates)
                && $updates[$label] !== null
                && $updates[$label] !== '';
            $has_label_on_item = (string) $item->get_meta($label, true) !== '';
            if ($has_label_in_updates || $has_label_on_item) {
                unset($updates[$key]);
            }
        }
    }

    if ($mode === 'checkout') {
        $existing_keys = [];
        foreach ($item->get_meta_data() as $meta_row) {
            $existing_keys[(string) $meta_row->key] = true;
        }

        $canonical_to_legacy = [];
        if (function_exists('intersoccer_attr_legacy_order_meta_label_reverse_map')) {
            foreach (intersoccer_attr_legacy_order_meta_label_reverse_map() as $legacy => $canonical) {
                $canonical_to_legacy[$canonical][] = $legacy;
            }
        }

        foreach ($updates as $key => $value) {
            if ($value === null || ($value === '' && $key !== 'Medical Conditions')) {
                continue;
            }

            if (!empty($canonical_to_legacy[$key])) {
                foreach ($canonical_to_legacy[$key] as $legacy) {
                    if (isset($existing_keys[$legacy])) {
                        $item->delete_meta_data($legacy);
                        unset($existing_keys[$legacy]);
                    }
                }
            }

            $sanitized = intersoccer_sanitize_order_line_meta_value($value);
            if (isset($existing_keys[$key])) {
                $item->update_meta_data($key, $sanitized);
                continue;
            }

            $item->add_meta_data($key, $sanitized, true);
            $existing_keys[$key] = true;
        }
        return true;
    }

    return intersoccer_apply_order_line_meta_updates($item, $updates, $fix_activity_type_only);
}

/**
 * Collapse multiple meta rows that share the same key into a single unique row.
 * Keeps the last non-empty value (or last value if all empty).
 *
 * @param WC_Order_Item_Product $item
 * @return bool Whether any change was made.
 */
function intersoccer_collapse_duplicate_order_meta_keys($item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    $by_key = [];
    foreach ($item->get_meta_data() as $meta) {
        $key = (string) $meta->key;
        if (!isset($by_key[$key])) {
            $by_key[$key] = [];
        }
        $by_key[$key][] = $meta->value;
    }

    $changed = false;
    foreach ($by_key as $key => $values) {
        if (count($values) < 2) {
            continue;
        }
        $kept = '';
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                $kept = $value;
            }
        }
        if ($kept === '' && $values !== []) {
            $kept = $values[count($values) - 1];
        }
        $item->delete_meta_data($key);
        $item->add_meta_data($key, $kept, true);
        $changed = true;
    }

    return $changed;
}

/**
 * Remove pa_* / attribute_pa_* twins when the human order_meta_label is present.
 *
 * WooCommerce formats attribute_pa_* and pa_* with the same display label as the
 * human key (e.g. all three show as "Age Group"), so keeping all three looks like
 * duplicate metadata in admin.
 *
 * @param WC_Order_Item_Product $item
 * @return bool Whether any change was made.
 */
function intersoccer_prune_taxonomy_attribute_twins($item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }
    if (!function_exists('intersoccer_attr_registry')) {
        return false;
    }

    $existing = [];
    foreach ($item->get_meta_data() as $meta) {
        $existing[(string) $meta->key] = $meta->value;
    }

    $changed = false;
    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (!is_array($def)) {
            continue;
        }
        $label = isset($def['order_meta_label']) ? (string) $def['order_meta_label'] : '';
        $taxonomy = function_exists('intersoccer_attr_taxonomy')
            ? (string) intersoccer_attr_taxonomy($slug)
            : '';
        if ($taxonomy === '' || strpos($taxonomy, 'pa_') !== 0) {
            continue;
        }
        $attr_key = function_exists('intersoccer_attr_resolve_meta_key')
            ? (string) intersoccer_attr_resolve_meta_key($slug)
            : ('attribute_' . $taxonomy);

        $has_label = $label !== ''
            && array_key_exists($label, $existing)
            && $existing[$label] !== null
            && $existing[$label] !== '';

        if ($has_label) {
            if (array_key_exists($taxonomy, $existing)) {
                $item->delete_meta_data($taxonomy);
                unset($existing[$taxonomy]);
                $changed = true;
            }
            if (array_key_exists($attr_key, $existing)) {
                $item->delete_meta_data($attr_key);
                unset($existing[$attr_key]);
                $changed = true;
            }
            continue;
        }

        // No human label: keep attribute_pa_*, drop redundant raw pa_*.
        if (array_key_exists($attr_key, $existing) && array_key_exists($taxonomy, $existing)) {
            $item->delete_meta_data($taxonomy);
            unset($existing[$taxonomy]);
            $changed = true;
        }
    }

    return $changed;
}

/**
 * English camp schedule order-item meta labels (canonical).
 *
 * @return array{start:string,end:string,week:string}
 */
function intersoccer_camp_schedule_order_meta_labels_en() {
    return [
        'start' => 'Camp Start Date',
        'end'   => 'Camp End Date',
        'week'  => 'Camp Week Index',
    ];
}

/**
 * Static FR/DE fallbacks for camp schedule order-item meta keys.
 *
 * @return array<string,array{start:string,end:string,week:string}>
 */
function intersoccer_camp_schedule_order_meta_labels_i18n_map() {
    return [
        'en' => intersoccer_camp_schedule_order_meta_labels_en(),
        'fr' => [
            'start' => 'Date de début du camp',
            'end'   => 'Date de fin du camp',
            'week'  => 'Index de semaine du camp',
        ],
        'de' => [
            'start' => 'Camp-Startdatum',
            'end'   => 'Camp-Enddatum',
            'week'  => 'Camp-Wochenindex',
        ],
    ];
}

/**
 * Localized camp schedule order-item meta labels for a language.
 *
 * Prefers icl_t() on the English string when available; otherwise static FR/DE map.
 *
 * @param string $language en|fr|de
 * @return array{start:string,end:string,week:string}
 */
function intersoccer_camp_schedule_order_meta_labels_for_language($language = 'en') {
    $language = in_array($language, ['en', 'fr', 'de'], true) ? $language : 'en';
    $en = intersoccer_camp_schedule_order_meta_labels_en();
    $map = intersoccer_camp_schedule_order_meta_labels_i18n_map();
    $fallback = $map[$language] ?? $en;

    $out = [];
    foreach (['start', 'end', 'week'] as $field) {
        $english = $en[$field];
        if (function_exists('icl_t')) {
            $translated = icl_t('intersoccer-product-variations', $english, $english);
            // When WPML has no translation, icl_t returns the English source — use static map for fr/de.
            if ($language !== 'en' && ($translated === '' || $translated === $english)) {
                $out[$field] = $fallback[$field];
            } else {
                $out[$field] = $translated !== '' ? $translated : $fallback[$field];
            }
        } else {
            $out[$field] = $fallback[$field];
        }
    }

    return $out;
}

/**
 * All known human label variants (EN/FR/DE) keyed by schedule field.
 *
 * @return array{start:array<int,string>,end:array<int,string>,week:array<int,string>}
 */
function intersoccer_camp_schedule_order_meta_label_variants() {
    $by_field = [
        'start' => [],
        'end'   => [],
        'week'  => [],
    ];
    foreach (intersoccer_camp_schedule_order_meta_labels_i18n_map() as $labels) {
        foreach (['start', 'end', 'week'] as $field) {
            $by_field[$field][] = $labels[$field];
        }
    }
    foreach ($by_field as $field => $labels) {
        $by_field[$field] = array_values(array_unique($labels));
    }
    return $by_field;
}

/**
 * Underscore keys still used on variations (and legacy order items).
 *
 * @return array{start:string,end:string,week:string}
 */
function intersoccer_camp_schedule_order_meta_underscore_keys() {
    return [
        'start' => '_camp_start_date',
        'end'   => '_camp_end_date',
        'week'  => '_camp_week_index',
    ];
}

/**
 * Whether a raw camp schedule meta value is usable for a field.
 *
 * @param string $field start|end|week
 * @param mixed  $value
 * @return bool
 */
function intersoccer_camp_schedule_order_meta_value_usable($field, $value) {
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') {
        return false;
    }
    if ($field === 'week') {
        return is_numeric($raw);
    }
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw);
}

/**
 * Keep human camp schedule labels; strip/migrate legacy `_camp_*` order-item keys.
 *
 * - If any EN/FR/DE label has a usable value → delete the matching `_camp_*` twin.
 * - If only `_camp_*` has a usable value → copy onto the order-language label, then delete underscore.
 *
 * @param WC_Order_Item_Product $item
 * @param string                $language en|fr|de hint when migrating underscore-only rows
 * @return bool Whether any change was made.
 */
function intersoccer_prune_camp_schedule_order_meta_twins($item, $language = '') {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    if ($language === '') {
        $activity = (string) intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Activity Type');
        $language = intersoccer_order_activity_type_detect_language($activity);
    }

    $existing = [];
    foreach ($item->get_meta_data() as $meta) {
        $existing[(string) $meta->key] = $meta->value;
    }

    $underscores = intersoccer_camp_schedule_order_meta_underscore_keys();
    $variants    = intersoccer_camp_schedule_order_meta_label_variants();
    $target      = intersoccer_camp_schedule_order_meta_labels_for_language($language);
    $changed     = false;

    foreach (['start', 'end', 'week'] as $field) {
        $underscore = $underscores[$field];
        $has_underscore = array_key_exists($underscore, $existing)
            && intersoccer_camp_schedule_order_meta_value_usable($field, $existing[$underscore]);

        $label_key = null;
        $label_value = null;
        foreach ($variants[$field] as $candidate) {
            if (!array_key_exists($candidate, $existing)) {
                continue;
            }
            if (!intersoccer_camp_schedule_order_meta_value_usable($field, $existing[$candidate])) {
                continue;
            }
            $label_key = $candidate;
            break;
        }

        if ($label_key !== null) {
            if ($has_underscore || array_key_exists($underscore, $existing)) {
                $item->delete_meta_data($underscore);
                unset($existing[$underscore]);
                $changed = true;
            }
            continue;
        }

        if ($has_underscore) {
            $raw = is_scalar($existing[$underscore]) ? trim((string) $existing[$underscore]) : '';
            $dest = $target[$field];
            $item->update_meta_data($dest, $raw);
            $existing[$dest] = $raw;
            $item->delete_meta_data($underscore);
            unset($existing[$underscore]);
            $changed = true;
        }
    }

    return $changed;
}

/**
 * Aggressively remove legacy label twins when the EN canonical key is present.
 *
 * Unlike intersoccer_normalize_legacy_order_meta_keys(), this deletes reverse-map
 * legacy keys even when the canonical key already has a value (A–C twins).
 * Also removes pa_* / attribute_pa_* when the human order_meta_label is present.
 * Migrates/prunes legacy `_camp_*` order-item keys onto human Camp Start/End/Week labels.
 *
 * @param WC_Order_Item_Product $item
 * @return bool Whether any change was made.
 */
function intersoccer_prune_legacy_order_meta_twins($item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    $changed = intersoccer_prune_camp_schedule_order_meta_twins($item);

    if (!function_exists('intersoccer_attr_legacy_order_meta_label_reverse_map')) {
        return $changed;
    }

    $reverse = intersoccer_attr_legacy_order_meta_label_reverse_map();
    if (empty($reverse)) {
        return $changed;
    }

    $existing = [];
    foreach ($item->get_meta_data() as $meta) {
        $existing[(string) $meta->key] = $meta->value;
    }

    $changed = intersoccer_normalize_legacy_order_meta_keys($item) || $changed;

    // Refresh after soft migrate.
    $existing = [];
    foreach ($item->get_meta_data() as $meta) {
        $existing[(string) $meta->key] = $meta->value;
    }

    foreach ($existing as $raw_key => $value) {
        if (!isset($reverse[$raw_key])) {
            continue;
        }
        $canonical = (string) $reverse[$raw_key];
        if ($canonical === $raw_key) {
            continue;
        }
        if (!array_key_exists($canonical, $existing)) {
            continue;
        }
        $item->delete_meta_data($raw_key);
        unset($existing[$raw_key]);
        $changed = true;
    }

    $changed = intersoccer_prune_taxonomy_attribute_twins($item) || $changed;
    $changed = intersoccer_collapse_duplicate_order_meta_keys($item) || $changed;

    return $changed;
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

    $changed = false;

    if (!$fix_activity_type_only && function_exists('intersoccer_attr_legacy_order_meta_label_reverse_map')) {
        $changed = intersoccer_normalize_legacy_order_meta_keys($item) || $changed;
    }

    $existing_keys = array_map(static function ($meta) {
        return $meta->key;
    }, $item->get_meta_data());

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
            continue;
        }

        if (in_array($key, intersoccer_order_meta_correctable_keys(), true)) {
            $existing_value = $item->get_meta($key, true);
            if ($existing_value === '' || $existing_value === null) {
                $item->update_meta_data($key, intersoccer_sanitize_order_line_meta_value($value));
                $changed = true;
            }
        }
    }

    return $changed;
}

/**
 * Rename non-English (FR/DE) order meta keys to their canonical English equivalents.
 *
 * Uses the attribute registry's legacy_order_meta_labels reverse map.
 * Skips keys where the canonical already exists with a non-empty value.
 *
 * @param WC_Order_Item_Product $item
 * @return bool Whether any key was renamed.
 */
function intersoccer_normalize_legacy_order_meta_keys($item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        return false;
    }

    $reverse = intersoccer_attr_legacy_order_meta_label_reverse_map();
    if (empty($reverse)) {
        return false;
    }

    $existing = [];
    foreach ($item->get_meta_data() as $meta) {
        $existing[$meta->key] = $meta->value;
    }

    $changed = false;
    foreach ($existing as $raw_key => $value) {
        if (!isset($reverse[$raw_key])) {
            continue;
        }

        $canonical = $reverse[$raw_key];
        if ($canonical === $raw_key) {
            continue;
        }

        $canonical_value = $existing[$canonical] ?? null;
        if ($canonical_value !== null && $canonical_value !== '') {
            continue;
        }

        $item->delete_meta_data($raw_key);
        $item->add_meta_data($canonical, $value, true);
        $changed = true;
    }

    return $changed;
}

/**
 * Meta keys that must be hidden from customer-facing order surfaces.
 *
 * These include internal player identifiers, taxonomy attribute keys,
 * and legacy PM keys that should only be visible to admin/reports.
 *
 * @return array<int,string>
 */
function intersoccer_order_meta_hidden_customer_keys() {
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = [
        'assigned_player',
        'assigned_player_id',
        'assigned_attendee',
        'Player Index',
        'intersoccer_player_index',
        'Camp Week Index',
        'Base Price',
        'Variation ID',
    ];

    return apply_filters('intersoccer_order_meta_hidden_customer_keys', $keys);
}

/**
 * Patterns for meta keys to hide from customer-facing display.
 *
 * Matches attribute_pa_* and optionally bare pa_* taxonomy keys.
 *
 * @return array<int,string>
 */
function intersoccer_order_meta_hidden_customer_patterns() {
    return [
        '/^attribute_pa_/',
        '/^pa_[a-z]/',
    ];
}

/**
 * Check if a meta key should be hidden from customer-facing order display.
 *
 * @param string $key Meta key to check.
 * @return bool
 */
function intersoccer_is_hidden_customer_order_meta_key($key) {
    $key = (string) $key;
    $canonical = function_exists('intersoccer_pv_canonical_stored_order_meta_key')
        ? intersoccer_pv_canonical_stored_order_meta_key($key)
        : $key;

    $hidden_keys = intersoccer_order_meta_hidden_customer_keys();
    if (in_array($key, $hidden_keys, true) || in_array($canonical, $hidden_keys, true)) {
        return true;
    }

    foreach (intersoccer_order_meta_hidden_customer_patterns() as $pattern) {
        if (preg_match($pattern, $key)) {
            return true;
        }
    }

    if (strpos($key, '_') === 0) {
        return true;
    }

    return false;
}

/**
 * Canonical English msgids for privacy redaction copy (also registered for WPML / .po).
 *
 * @return array<int,string>
 */
function intersoccer_privacy_redaction_string_msgids() {
    return [
        'Medical information: provided (view or update in My Account).',
        'Age at camp start: %d',
        'Birth year: %s',
    ];
}

/**
 * Static FR/DE fallbacks aligned with lang/intersoccer-product-variations-{fr,de}.po.
 *
 * @return array<string,array<string,string>>
 */
function intersoccer_privacy_redaction_string_i18n_map() {
    return [
        'Medical information: provided (view or update in My Account).' => [
            'fr' => 'Informations médicales : fournies (consulter ou mettre à jour dans Mon compte).',
            'de' => 'Medizinische Informationen: angegeben (im Mein Konto ansehen oder aktualisieren).',
        ],
        'Age at camp start: %d' => [
            'fr' => 'Âge au début du camp : %d',
            'de' => 'Alter bei Campbeginn: %d',
        ],
        'Birth year: %s' => [
            'fr' => 'Année de naissance : %s',
            'de' => 'Geburtsjahr: %s',
        ],
    ];
}

/**
 * Fingerprint of privacy redaction msgids and FR/DE seed map (gates WPML seeding).
 *
 * @return string
 */
function intersoccer_pv_privacy_redaction_strings_seed_fingerprint() {
    $payload = [
        'msgids' => intersoccer_privacy_redaction_string_msgids(),
        'map' => intersoccer_privacy_redaction_string_i18n_map(),
    ];
    $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

    return hash('sha256', (string) $json);
}

/**
 * Whether a WPML string translation row should be seeded from the static map.
 *
 * @param array<string,mixed> $lang_row        Existing translation row for one language.
 * @param int                 $complete_status ICL_TM_COMPLETE (or 10).
 * @return bool
 */
function intersoccer_pv_privacy_redaction_translation_needs_seed(array $lang_row, $complete_status) {
    if ($lang_row === []) {
        return true;
    }
    $value = isset($lang_row['value']) ? (string) $lang_row['value'] : '';
    if ($value === '' || $value === '10') {
        return true;
    }
    $status = isset($lang_row['status']) ? (int) $lang_row['status'] : 0;

    return $status !== (int) $complete_status;
}

/**
 * Register privacy redaction strings with WPML and seed FR/DE translations from the .po map.
 *
 * @return void
 */
function intersoccer_register_privacy_redaction_wpml_strings() {
    if (!function_exists('icl_register_string')) {
        return;
    }

    $fingerprint = intersoccer_pv_privacy_redaction_strings_seed_fingerprint();
    $stored = get_option('intersoccer_pv_privacy_strings_seed', '');
    if (is_string($stored) && $stored === $fingerprint) {
        return;
    }

    $map = intersoccer_privacy_redaction_string_i18n_map();
    $status = defined('ICL_TM_COMPLETE') ? (int) ICL_TM_COMPLETE : 10;
    $can_read_existing = function_exists('icl_get_string_translations_by_id');
    $can_seed_translations = function_exists('icl_get_string_id') && function_exists('icl_add_string_translation');
    $complete = $can_seed_translations;

    foreach (intersoccer_privacy_redaction_string_msgids() as $msgid) {
        icl_register_string('intersoccer-product-variations', $msgid, $msgid);
        if (!$can_seed_translations) {
            continue;
        }
        $string_id = icl_get_string_id($msgid, 'intersoccer-product-variations');
        if (!$string_id) {
            $complete = false;
            continue;
        }
        $existing_by_lang = [];
        if ($can_read_existing) {
            $raw = icl_get_string_translations_by_id($string_id);
            if (is_array($raw)) {
                foreach ($map[$msgid] ?? [] as $lang => $_translation) {
                    if (isset($raw[$lang]) && is_array($raw[$lang])) {
                        $existing_by_lang[$lang] = $raw[$lang];
                    } elseif (isset($raw['translations'][$lang]) && is_array($raw['translations'][$lang])) {
                        $existing_by_lang[$lang] = $raw['translations'][$lang];
                    }
                }
            }
        }
        foreach ($map[$msgid] ?? [] as $lang => $translation) {
            if ($can_read_existing) {
                $row = $existing_by_lang[$lang] ?? [];
                if (!intersoccer_pv_privacy_redaction_translation_needs_seed($row, $status)) {
                    continue;
                }
            }
            icl_add_string_translation($string_id, $lang, $translation, $status);
        }
    }

    if ($complete) {
        update_option('intersoccer_pv_privacy_strings_seed', $fingerprint, false);
    }
}

add_action('init', 'intersoccer_register_privacy_redaction_wpml_strings', 20);

/**
 * Translate a privacy redaction string at render time (gettext + WPML).
 *
 * @param string       $msgid        English msgid (may contain sprintf placeholders).
 * @param array<mixed> $sprintf_args Optional sprintf arguments.
 * @return string
 */
function intersoccer_pv_privacy_string($msgid, array $sprintf_args = []) {
    $text = (string) $msgid;
    $lang = apply_filters('wpml_current_language', null);
    if (is_string($lang) && $lang !== '' && $lang !== 'en') {
        $wpml = apply_filters('wpml_translate_single_string', $msgid, 'intersoccer-product-variations', $msgid, $lang);
        if (is_string($wpml) && $wpml !== '' && $wpml !== $msgid) {
            $text = $wpml;
        } elseif (function_exists('icl_t')) {
            $icl = icl_t('intersoccer-product-variations', $msgid, $msgid);
            if (is_string($icl) && $icl !== '' && $icl !== $msgid) {
                $text = $icl;
            }
        }
        if ($text === $msgid) {
            $fallback = intersoccer_privacy_redaction_string_i18n_map()[$msgid][$lang] ?? null;
            if (is_string($fallback) && $fallback !== '') {
                $text = $fallback;
            }
        }
    }
    if ($text === $msgid) {
        $text = __($msgid, 'intersoccer-product-variations');
    }
    if ($sprintf_args !== []) {
        return vsprintf($text, $sprintf_args);
    }
    return $text;
}

/**
 * Map WPML order language to a WordPress locale for gettext during emails.
 *
 * @param WC_Order|null $order
 * @return string|null Locale such as fr_FR, or null.
 */
function intersoccer_privacy_redaction_locale_for_order($order) {
    if (!is_object($order) || !method_exists($order, 'get_meta')) {
        return null;
    }
    $wpml = (string) $order->get_meta('wpml_language', true);
    if ($wpml === '') {
        return null;
    }
    $map = [
        'en' => 'en_US',
        'fr' => 'fr_FR',
        'de' => 'de_DE',
        'de_CH' => 'de_DE',
        'fr_CH' => 'fr_FR',
    ];
    if (isset($map[$wpml])) {
        return $map[$wpml];
    }
    return strpos($wpml, '_') !== false ? $wpml : null;
}

/**
 * Begin WooCommerce order email rendering (HTML and plain templates).
 *
 * Hooked on woocommerce_email_before_order_table, which runs for both plain and HTML emails.
 *
 * @param WC_Order|false $order
 * @param bool           $sent_to_admin
 * @param bool           $plain_text
 * @param WC_Email|null  $email
 * @return void
 */
function intersoccer_sensitive_order_meta_begin_email_context($order = false, $sent_to_admin = false, $plain_text = false, $email = null) {
    $depth = (int) ($GLOBALS['intersoccer_sensitive_order_meta_email_depth'] ?? 0);
    $GLOBALS['intersoccer_sensitive_order_meta_email_depth'] = $depth + 1;
    if ($depth > 0) {
        return;
    }
    $GLOBALS['intersoccer_sensitive_order_meta_email'] = true;

    $locale = intersoccer_privacy_redaction_locale_for_order($order);
    if ($locale && function_exists('switch_to_locale')) {
        $GLOBALS['intersoccer_sensitive_order_meta_prev_locale'] = function_exists('determine_locale')
            ? determine_locale()
            : get_locale();
        switch_to_locale($locale);
        if (function_exists('intersoccer_pv_load_textdomain_for_current_locale')) {
            intersoccer_pv_load_textdomain_for_current_locale($locale);
        }
    }

    if (is_object($order) && method_exists($order, 'get_meta')) {
        $wpml_lang = (string) $order->get_meta('wpml_language', true);
        if ($wpml_lang !== '' && has_action('wpml_switch_language')) {
            $GLOBALS['intersoccer_sensitive_order_meta_prev_wpml'] = apply_filters('wpml_current_language', null);
            do_action('wpml_switch_language', $wpml_lang);
        }
    }
}

/**
 * End WooCommerce order email rendering scope.
 *
 * @param WC_Order|false $order
 * @param bool           $sent_to_admin
 * @param bool           $plain_text
 * @param WC_Email|null  $email
 * @return void
 */
function intersoccer_sensitive_order_meta_end_email_context($order = false, $sent_to_admin = false, $plain_text = false, $email = null) {
    $depth = (int) ($GLOBALS['intersoccer_sensitive_order_meta_email_depth'] ?? 0);
    if ($depth <= 0) {
        return;
    }
    $GLOBALS['intersoccer_sensitive_order_meta_email_depth'] = $depth - 1;
    if ($depth > 1) {
        return;
    }

    unset($GLOBALS['intersoccer_sensitive_order_meta_email']);

    if (isset($GLOBALS['intersoccer_sensitive_order_meta_prev_wpml']) && has_action('wpml_switch_language')) {
        do_action('wpml_switch_language', $GLOBALS['intersoccer_sensitive_order_meta_prev_wpml']);
        unset($GLOBALS['intersoccer_sensitive_order_meta_prev_wpml']);
    }

    if (isset($GLOBALS['intersoccer_sensitive_order_meta_prev_locale']) && function_exists('restore_previous_locale')) {
        $prev_locale = (string) $GLOBALS['intersoccer_sensitive_order_meta_prev_locale'];
        restore_previous_locale();
        unset($GLOBALS['intersoccer_sensitive_order_meta_prev_locale']);
        if (function_exists('intersoccer_pv_load_textdomain_for_current_locale')) {
            intersoccer_pv_load_textdomain_for_current_locale($prev_locale);
        }
    }
}

/**
 * Whether formatted order meta is being built for a WooCommerce order email.
 *
 * @return bool
 */
function intersoccer_is_sensitive_order_meta_email_context() {
    return !empty($GLOBALS['intersoccer_sensitive_order_meta_email']);
}

/**
 * Whether attendee DOB / medical text should be redacted on this request.
 *
 * Thank-you page and all order emails (customer and admin notification). My Account keeps full values.
 *
 * @return bool
 */
function intersoccer_should_mask_sensitive_order_item_meta() {
    if (intersoccer_is_sensitive_order_meta_email_context()) {
        return true;
    }
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        return true;
    }
    return false;
}

/**
 * Order line item meta keys that store attendee date of birth (PV + PM naming, EN/FR/DE labels, storage variants).
 *
 * @return array<int,string>
 */
function intersoccer_sensitive_order_meta_dob_keys() {
    $keys = [
        'Attendee DOB',
        'Player DOB',
        'Date de naissance',
        'Geburtsdatum',
        'player_dob',
        '_attendee_dob',
        '_player_dob',
    ];

    /**
     * Filter order item meta keys treated as attendee DOB for privacy redaction.
     *
     * @param array<int,string> $keys
     */
    return apply_filters('intersoccer_sensitive_order_meta_dob_keys', $keys);
}

/**
 * Order line item meta keys that store medical / allergy text (PV + PM naming, EN/FR/DE labels, storage variants).
 *
 * @return array<int,string>
 */
function intersoccer_sensitive_order_meta_medical_keys() {
    $keys = [
        'Medical Conditions',
        'Player Medical',
        'Conditions médicales',
        'Medizinische Angaben',
        'medical_conditions',
        '_medical_conditions',
        'player_medical',
        '_player_medical',
    ];

    /**
     * Filter order item meta keys treated as medical conditions for privacy redaction.
     *
     * @param array<int,string> $keys
     */
    return apply_filters('intersoccer_sensitive_order_meta_medical_keys', $keys);
}

/**
 * Whether a formatted-meta key or display label matches a sensitive key list.
 *
 * @param string              $key         Stored meta key.
 * @param string              $display_key Human-facing label from WooCommerce.
 * @param array<int,string>   $candidates  Sensitive key list.
 * @return bool
 */
function intersoccer_sensitive_order_meta_key_matches_list($key, $display_key, array $candidates) {
    $key = (string) $key;
    $display_key = (string) $display_key;
    foreach ($candidates as $candidate) {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            continue;
        }
        if ($key === $candidate || $display_key === $candidate) {
            return true;
        }
        if (strcasecmp($key, $candidate) === 0 || strcasecmp($display_key, $candidate) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * @param string $key         Stored meta key.
 * @param string $display_key Display key from formatted meta.
 * @return bool
 */
function intersoccer_is_sensitive_order_meta_dob_key($key, $display_key = '') {
    return intersoccer_sensitive_order_meta_key_matches_list($key, $display_key, intersoccer_sensitive_order_meta_dob_keys());
}

/**
 * @param string $key         Stored meta key.
 * @param string $display_key Display key from formatted meta.
 * @return bool
 */
function intersoccer_is_sensitive_order_meta_medical_key($key, $display_key = '') {
    return intersoccer_sensitive_order_meta_key_matches_list($key, $display_key, intersoccer_sensitive_order_meta_medical_keys());
}

/**
 * Whether stored medical meta represents user-provided information (not empty / placeholder "None").
 *
 * @param mixed $value
 * @return bool
 */
function intersoccer_order_meta_medical_conditions_has_value($value) {
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') {
        return false;
    }
    return strcasecmp($raw, 'none') !== 0;
}

/**
 * Program start date (Y-m-d) for an order line item when available on the item or variation.
 *
 * Prefers stamped camp schedule meta on the line item, then course Start Date, then
 * {@see intersoccer_get_program_reference_date()} (camp `_camp_start_date`, course `_course_start_date`, etc.).
 *
 * @param WC_Order_Item $item
 * @return string|null Y-m-d or null.
 */
function intersoccer_get_order_item_program_start_ymd($item) {
    if (!is_object($item) || !method_exists($item, 'get_meta')) {
        return null;
    }

    if (function_exists('intersoccer_camp_schedule_order_meta_label_variants')) {
        $variants = intersoccer_camp_schedule_order_meta_label_variants();
        foreach ($variants['start'] as $label) {
            $raw = trim((string) $item->get_meta($label, true));
            if (function_exists('intersoccer_camp_schedule_order_meta_value_usable')
                && intersoccer_camp_schedule_order_meta_value_usable('start', $raw)) {
                return $raw;
            }
        }
        if (function_exists('intersoccer_camp_schedule_order_meta_underscore_keys')) {
            $underscore = intersoccer_camp_schedule_order_meta_underscore_keys()['start'];
            $raw = trim((string) $item->get_meta($underscore, true));
            if (function_exists('intersoccer_camp_schedule_order_meta_value_usable')
                && intersoccer_camp_schedule_order_meta_value_usable('start', $raw)) {
                return $raw;
            }
        }
    }

    $start_label = trim((string) $item->get_meta('Start Date', true));
    if ($start_label !== '' && function_exists('intersoccer_parse_loose_date_to_ymd')) {
        $parsed = intersoccer_parse_loose_date_to_ymd($start_label);
        if (is_string($parsed) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed)) {
            return $parsed;
        }
    }

    if ($item instanceof WC_Order_Item_Product && function_exists('intersoccer_resolve_order_item_product_context')) {
        $ctx = intersoccer_resolve_order_item_product_context($item);
        $product_id = (int) ($ctx['resolved_product_id'] ?? 0);
        $variation_id = (int) ($ctx['variation_id'] ?? 0);
        $product_type = (string) ($ctx['product_type'] ?? '');
        if (function_exists('intersoccer_get_program_reference_date')) {
            $ref = intersoccer_get_program_reference_date($product_id, $variation_id, $product_type !== '' ? $product_type : null);
            if (is_string($ref) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
                return $ref;
            }
        }
    }

    return null;
}

/**
 * Normalize attendee DOB stored on an order line to Y-m-d when possible.
 *
 * @param mixed $value
 * @return string|null
 */
function intersoccer_normalize_attendee_dob_order_meta_value($value) {
    $raw = is_scalar($value) ? trim((string) $value) : '';
    if ($raw === '') {
        return null;
    }

    $accept_dob_ymd = static function ($ymd) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $parts)) {
            return null;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $today = function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
        if ($ymd > $today) {
            return null;
        }
        return $ymd;
    };

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $accept_dob_ymd($raw);
    }
    if (function_exists('intersoccer_parse_loose_date_to_ymd')) {
        $parsed = intersoccer_parse_loose_date_to_ymd($raw);
        if (is_string($parsed)) {
            return $accept_dob_ymd($parsed);
        }
    }
    return null;
}

/**
 * Redacted attendee DOB string for emails and thank-you page.
 *
 * @param mixed           $value Raw DOB meta value.
 * @param WC_Order_Item   $item  Line item (for camp/course start lookup).
 * @return string
 */
function intersoccer_format_attendee_dob_for_redacted_surface($value, $item) {
    $dob_ymd = intersoccer_normalize_attendee_dob_order_meta_value($value);
    if ($dob_ymd === null) {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        $year = null;
        if ($raw !== '') {
            $current_year = (int) (function_exists('current_time') ? current_time('Y') : gmdate('Y'));
            if (preg_match('/^\d{4}$/', $raw)) {
                $year = (int) $raw;
            } elseif (preg_match('/^\d{1,2}[\/\.](\d{4})$/', $raw, $my_match)) {
                $year = (int) $my_match[1];
            } elseif (preg_match('/^(\d{4})-\d{1,2}$/', $raw, $ym_match)) {
                $year = (int) $ym_match[1];
            }
            if ($year !== null && ($year < 1900 || $year > $current_year)) {
                $year = null;
            }
        }
        if ($year !== null) {
            return intersoccer_pv_privacy_string('Birth year: %s', [(string) $year]);
        }
        return '';
    }

    $ref = intersoccer_get_order_item_program_start_ymd($item);
    if ($ref !== null && function_exists('intersoccer_age_on_date')) {
        $age = intersoccer_age_on_date($dob_ymd, $ref);
        if ($age !== null) {
            return intersoccer_pv_privacy_string('Age at camp start: %d', [(int) $age]);
        }
    }

    return intersoccer_pv_privacy_string('Birth year: %s', [substr($dob_ymd, 0, 4)]);
}

/**
 * Redacted medical conditions string for emails and thank-you page.
 *
 * @return string
 */
function intersoccer_format_medical_conditions_for_redacted_surface() {
    return intersoccer_pv_privacy_string('Medical information: provided (view or update in My Account).');
}

/**
 * Customer-facing order views (thank-you, My Account, customer emails).
 */
function intersoccer_is_customer_order_meta_display_context() {
    if (function_exists('intersoccer_is_sensitive_order_meta_email_context') && intersoccer_is_sensitive_order_meta_email_context()) {
        return true;
    }

    if (function_exists('is_admin') && is_admin() && (!function_exists('wp_doing_ajax') || !wp_doing_ajax())) {
        return false;
    }

    return true;
}

/**
 * English keys/values persisted on order lines — translate at display only.
 *
 * @return array<string,string> English key => English msgid for gettext.
 */
function intersoccer_stored_order_meta_display_key_map() {
    static $cached = null;
    if ($cached !== null) {
        return apply_filters('intersoccer_stored_order_meta_display_key_map', $cached);
    }

    $map = [];
    $skip_keys = ['assigned_player', 'assigned_player_id'];
    foreach (intersoccer_order_meta_checkout_extras() as $key) {
        if (in_array($key, $skip_keys, true) || strpos($key, '_') === 0) {
            continue;
        }
        $map[$key] = $key;
    }

    if (function_exists('intersoccer_camp_schedule_order_meta_labels_en')
        && function_exists('intersoccer_camp_schedule_order_meta_label_variants')) {
        $en_camp = intersoccer_camp_schedule_order_meta_labels_en();
        foreach (intersoccer_camp_schedule_order_meta_label_variants() as $field => $variants) {
            $msgid = $en_camp[$field] ?? '';
            if ($msgid === '') {
                continue;
            }
            $map[$msgid] = $msgid;
            foreach ($variants as $variant) {
                if ($variant !== '') {
                    $map[$variant] = $msgid;
                }
            }
        }
    }

    if (function_exists('intersoccer_attr_registry')) {
        foreach (intersoccer_attr_registry() as $def) {
            $label = isset($def['order_meta_label']) ? (string) $def['order_meta_label'] : '';
            if ($label === '') {
                continue;
            }
            $map[$label] = $label;
            if (!empty($def['legacy_order_meta_labels']) && is_array($def['legacy_order_meta_labels'])) {
                foreach ($def['legacy_order_meta_labels'] as $legacy) {
                    $legacy = (string) $legacy;
                    if ($legacy !== '') {
                        $map[$legacy] = $label;
                    }
                }
            }
        }
    }

    $map['Remaining Sessions'] = 'Remaining Sessions';
    $map['Sessions Remaining'] = 'Sessions Remaining';
    $map['InterSoccer Venues'] = 'Sites InterSoccer';
    $map['Player DOB'] = 'Player DOB';
    $map['Player Medical'] = 'Player Medical';

    foreach ([
        "Type d'activité" => 'Activity Type',
        'Type d’activité' => 'Activity Type',
        'Aktivitätstyp' => 'Activity Type',
        'Date de naissance du participant' => 'Attendee DOB',
        'Geburtsdatum des Teilnehmers' => 'Attendee DOB',
    ] as $legacy_key => $canonical_key) {
        $map[$legacy_key] = $canonical_key;
    }

    $cached = $map;

    return apply_filters('intersoccer_stored_order_meta_display_key_map', $cached);
}

/**
 * Resolve a stored order-meta key (EN/FR/DE legacy) to its canonical English msgid.
 *
 * @param string $meta_key Stored meta key.
 * @return string
 */
function intersoccer_pv_canonical_stored_order_meta_key($meta_key) {
    $meta_key = (string) $meta_key;
    if ($meta_key === '') {
        return '';
    }

    $map = intersoccer_stored_order_meta_display_key_map();
    if (isset($map[$meta_key])) {
        return $map[$meta_key];
    }

    if (function_exists('intersoccer_attr_legacy_order_meta_label_reverse_map')) {
        $reverse = intersoccer_attr_legacy_order_meta_label_reverse_map();
        if (isset($reverse[$meta_key])) {
            return (string) $reverse[$meta_key];
        }
    }

    return $meta_key;
}

/**
 * @param string $meta_key        Stored meta key.
 * @param string $canonical_english Canonical English key (e.g. Activity Type).
 * @return bool
 */
function intersoccer_pv_order_meta_key_is($meta_key, $canonical_english) {
    return intersoccer_pv_canonical_stored_order_meta_key($meta_key) === $canonical_english;
}

/**
 * Stored meta key aliases for a canonical English order-meta key (includes legacy FR/DE keys).
 *
 * @param string $canonical_english
 * @return array<int,string>
 */
function intersoccer_pv_order_item_meta_canonical_key_variants($canonical_english) {
    $canonical_english = (string) $canonical_english;
    if ($canonical_english === '') {
        return [];
    }

    $variants = [$canonical_english];
    $map = intersoccer_stored_order_meta_display_key_map();
    foreach ($map as $stored => $canonical) {
        if ($canonical === $canonical_english && $stored !== $canonical_english) {
            $variants[] = $stored;
        }
    }

    return array_values(array_unique($variants));
}

/**
 * Read order-item meta by canonical English key (tolerates legacy translated storage keys).
 *
 * @param WC_Order_Item_Product|object $item
 * @param string                       $canonical_english
 * @param bool                         $single
 * @return mixed
 */
function intersoccer_pv_order_item_get_meta_by_canonical_key($item, $canonical_english, $single = true) {
    if (!is_object($item) || !method_exists($item, 'get_meta')) {
        return $single ? '' : [];
    }

    foreach (intersoccer_pv_order_item_meta_canonical_key_variants($canonical_english) as $key) {
        $value = $item->get_meta($key, $single);
        if ($single) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
            if ($value !== '' && $value !== null && !is_string($value)) {
                return $value;
            }
        } elseif (!empty($value)) {
            return $value;
        }
    }

    return $single ? '' : [];
}

/**
 * Format a stored English order date label for customer display (values stay English in DB).
 *
 * @param string $text Stored date string.
 * @return string
 */
function intersoccer_pv_customer_display_date_format_msgid() {
    return __('j F Y', 'intersoccer-product-variations');
}

function intersoccer_pv_format_stored_order_date_for_display($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $ts = strtotime($text);
    if ($ts === false) {
        return $text;
    }

    return date_i18n(intersoccer_pv_customer_display_date_format_msgid(), $ts);
}

/**
 * Localize a stored comma-separated list of English date labels (e.g. Holidays).
 *
 * @param string $text Stored value.
 * @return string
 */
function intersoccer_pv_stored_english_order_date_token_pattern() {
    return '/(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}|\d{4}-\d{2}-\d{2}/';
}

/**
 * Extract whole stored English date tokens from a comma-containing Holidays value.
 *
 * @param string $text Stored Holidays meta value.
 * @return array<int,string>
 */
function intersoccer_pv_extract_stored_order_date_list_segments($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return [];
    }

    if (!preg_match_all(intersoccer_pv_stored_english_order_date_token_pattern(), $text, $matches)) {
        return [];
    }

    return $matches[0];
}

function intersoccer_pv_format_stored_order_date_list_for_display($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $segments = intersoccer_pv_extract_stored_order_date_list_segments($text);
    if ($segments === []) {
        if (strpos($text, ',') !== false) {
            return $text;
        }

        return intersoccer_pv_format_stored_order_date_for_display($text);
    }

    if (count($segments) === 1 && trim($segments[0]) === $text) {
        return intersoccer_pv_format_stored_order_date_for_display($segments[0]);
    }

    $scratch = $text;
    foreach ($segments as $segment) {
        $scratch = str_replace($segment, '', $scratch);
    }
    $scratch = preg_replace('/[\s,]+/', '', $scratch);
    if ($scratch !== '') {
        return $text;
    }

    $formatted = array_map('intersoccer_pv_format_stored_order_date_for_display', $segments);

    return implode(', ', $formatted);
}

/**
 * Repair common UTF-8 en-dash corruption stored as "?" between words (e.g. DE course Note).
 *
 * @param string $text Note body.
 * @return string
 */
function intersoccer_pv_is_german_display_locale() {
    $locale = '';
    if (function_exists('determine_locale')) {
        $locale = (string) determine_locale();
    } elseif (function_exists('get_locale')) {
        $locale = (string) get_locale();
    }

    return $locale !== '' && strpos(strtolower($locale), 'de') === 0;
}

/**
 * Repair DE course Note text where a stored en-dash was saved as "?" between words (DB content).
 *
 * @param string $text Note body.
 * @return string
 */
function intersoccer_pv_repair_utf8_dash_mojibake_in_note($text) {
    $text = (string) $text;
    if ($text === '' || strpos($text, '?') === false || !intersoccer_pv_is_german_display_locale()) {
        return $text;
    }

    if (preg_match('/\p{L}\s+\?\s+\p{L}/u', $text)) {
        return preg_replace('/\s+\?\s+/u', ' – ', $text);
    }

    return $text;
}

/**
 * Hide PM Player Medical when PV Medical Conditions is also on the line (duplicate).
 *
 * @param string            $key  Meta key.
 * @param WC_Order_Item     $item Order line item.
 * @return bool
 */
function intersoccer_pv_should_hide_duplicate_player_medical_meta_key($key, $item) {
    if (!intersoccer_pv_order_meta_key_is($key, 'Player Medical')) {
        return false;
    }

    if (!($item instanceof WC_Order_Item_Product) && !is_object($item)) {
        return false;
    }

    if (!method_exists($item, 'get_meta')) {
        return false;
    }

    $pv_medical = trim((string) $item->get_meta('Medical Conditions', true));

    return $pv_medical !== '';
}

/**
 * Hide PM Player DOB when PV Attendee DOB is also on the line (duplicate).
 *
 * @param string            $key  Meta key.
 * @param WC_Order_Item     $item Order line item.
 * @return bool
 */
function intersoccer_pv_should_hide_duplicate_player_dob_meta_key($key, $item) {
    if (!intersoccer_pv_order_meta_key_is($key, 'Player DOB')) {
        return false;
    }

    if (!($item instanceof WC_Order_Item_Product) && !is_object($item)) {
        return false;
    }

    if (!method_exists($item, 'get_meta')) {
        return false;
    }

    $attendee = trim((string) intersoccer_pv_order_item_get_meta_by_canonical_key($item, 'Attendee DOB'));

    return $attendee !== '';
}

/**
 * Weekday names persisted in English on order lines.
 *
 * @return array<int,string>
 */
function intersoccer_pv_order_meta_weekday_msgids() {
    return [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];
}

/**
 * @param string $label Single token (e.g. weekday).
 * @return string
 */
function intersoccer_pv_translate_order_meta_token_for_display($label) {
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }

    if (in_array($label, intersoccer_pv_order_meta_weekday_msgids(), true)) {
        return __($label, 'intersoccer-product-variations');
    }

    $activity_types = ['Camp', 'Course', 'Birthday Party', 'Tournament', 'Unknown'];
    if (in_array($label, $activity_types, true)) {
        return __($label, 'intersoccer-product-variations');
    }

    $seasons = ['Summer', 'Autumn', 'Spring', 'Winter'];
    if (in_array($label, $seasons, true)) {
        return __($label, 'intersoccer-product-variations');
    }

    if ($label === 'Full Week') {
        return __('Full Week', 'intersoccer-product-variations');
    }
    if ($label === 'Single Day(s)') {
        return __('Single Day(s)', 'intersoccer-product-variations');
    }
    if ($label === 'Full Term') {
        return __('Full Term', 'intersoccer-product-variations');
    }
    if ($label === 'Yes') {
        return __('Yes', 'intersoccer-product-variations');
    }
    if ($label === 'No') {
        return __('No', 'intersoccer-product-variations');
    }
    if ($label === 'Girls Only') {
        return __('Girls Only', 'intersoccer-product-variations');
    }

    return $label;
}

/**
 * Translate comma-separated stored order meta values (days, holidays).
 *
 * @param string $text Raw stored value.
 * @return string
 */
function intersoccer_pv_translate_comma_separated_order_meta_value($text) {
    $text = (string) $text;
    if ($text === '' || strpos($text, ',') === false) {
        return intersoccer_pv_translate_order_meta_token_for_display($text);
    }

    $parts = array_map('trim', explode(',', $text));
    $translated = array_map('intersoccer_pv_translate_order_meta_token_for_display', $parts);

    return implode(', ', $translated);
}

/**
 * @param string $text Stored Activity Type value.
 * @return string
 */
function intersoccer_pv_translate_activity_type_order_meta_value($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $suffix = ' - Girls Only';
    if (substr($text, -strlen($suffix)) === $suffix) {
        $base = substr($text, 0, -strlen($suffix));
        return intersoccer_pv_translate_order_meta_token_for_display($base) . ' - ' . __('Girls Only', 'intersoccer-product-variations');
    }

    return intersoccer_pv_translate_order_meta_token_for_display($text);
}

/**
 * Translate a PV textdomain msgid on customer-facing order surfaces (reload .mo, WPML strings).
 *
 * @param string $msgid English msgid.
 * @return string
 */
function intersoccer_pv_translate_textdomain_msgid_for_customer($msgid) {
    $msgid = (string) $msgid;
    if ($msgid === '') {
        return '';
    }

    if (function_exists('intersoccer_pv_load_textdomain_for_current_locale')
        && defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
        intersoccer_pv_load_textdomain_for_current_locale();
    }

    $text = __($msgid, 'intersoccer-product-variations');
    if ($text !== $msgid) {
        return $text;
    }

    $lang = apply_filters('wpml_current_language', null);
    if (is_string($lang) && $lang !== '' && $lang !== 'en') {
        $wpml = apply_filters('wpml_translate_single_string', $msgid, 'intersoccer-product-variations', $msgid, $lang);
        if (is_string($wpml) && $wpml !== '' && $wpml !== $msgid) {
            return $wpml;
        }
        if (function_exists('icl_t')) {
            $icl = icl_t('intersoccer-product-variations', $msgid, $msgid);
            if (is_string($icl) && $icl !== '' && $icl !== $msgid) {
                return $icl;
            }
        }
    }

    return $text;
}

/**
 * @param string $display_key Label shown to the customer.
 * @param string $meta_key    Raw meta key.
 * @return string
 */
function intersoccer_translate_stored_order_meta_display_key($display_key, $meta_key = '') {
    if (!intersoccer_is_customer_order_meta_display_context()) {
        return $display_key;
    }

    $lookup = $meta_key !== '' ? $meta_key : $display_key;
    $map = intersoccer_stored_order_meta_display_key_map();
    if (isset($map[$lookup])) {
        return intersoccer_pv_translate_textdomain_msgid_for_customer((string) $map[$lookup]);
    }

    return $display_key;
}

/**
 * Translate known English values stored on order lines (not keys).
 *
 * @param string $value     Stored meta value.
 * @param string $meta_key  Meta key.
 * @return string
 */
/**
 * Translate a stored discount label (cart/checkout/order display only).
 *
 * @param string $label English label from cart/order storage.
 * @return string
 */
function intersoccer_translate_stored_discount_label_for_display($label) {
    return intersoccer_translate_persisted_discount_label_for_display($label);
}

/**
 * Translate English discount labels stored on cart lines / order meta (display only).
 *
 * @param string $label Stored English label.
 * @return string
 */
function intersoccer_translate_persisted_discount_label_for_display($label) {
    if (!intersoccer_is_customer_order_meta_display_context()) {
        return $label;
    }

    $text = is_scalar($label) ? (string) $label : '';
    if ($text === '') {
        return $label;
    }

    $percent = '(\d+(?:\.\d+)?)';
    $rules = [
        '/^' . $percent . '\s*%?\s*Camp Sibling Discount$/' => static function ($m) {
            return sprintf(__('%s%% Camp Sibling Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Course Sibling Discount$/' => static function ($m) {
            return sprintf(__('%s%% Course Sibling Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Tournament Sibling Discount$/' => static function ($m) {
            return sprintf(__('%s%% Tournament Sibling Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Camp Week 2 Discount$/' => static function ($m) {
            return sprintf(__('%d%% Camp Week 2 Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Camp Week (\d+)\+ Discount$/' => static function ($m) {
            return sprintf(__('%d%% Camp Week %d+ Discount', 'intersoccer-product-variations'), $m[1], $m[2]);
        },
        '/^' . $percent . '\s*%?\s*Same Season Course Discount$/' => static function ($m) {
            return sprintf(__('%d%% Same Season Course Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Tournament Multiple Days Discount$/' => static function ($m) {
            return sprintf(__('%d%% Tournament Multiple Days Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Sibling Camp Discount$/' => static function ($m) {
            return sprintf(__('%d%% Sibling Camp Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Multi-Child Camp Discount$/' => static function ($m) {
            return sprintf(__('%d%% Multi-Child Camp Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Sibling Course Discount$/' => static function ($m) {
            return sprintf(__('%d%% Sibling Course Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Multi-Child Course Discount$/' => static function ($m) {
            return sprintf(__('%d%% Multi-Child Course Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Sibling Tournament Discount$/' => static function ($m) {
            return sprintf(__('%d%% Sibling Tournament Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Multi-Child Tournament Discount$/' => static function ($m) {
            return sprintf(__('%d%% Multi-Child Tournament Discount', 'intersoccer-product-variations'), $m[1]);
        },
        '/^' . $percent . '\s*%?\s*Sibling Discount Applied$/' => static function ($m) {
            return sprintf(__('%d%% Sibling Discount Applied', 'intersoccer-product-variations'), $m[1]);
        },
        '/^20\s*%?\s*Course Sibling Discount$/' => static function () {
            return __('20% Course Sibling Discount', 'intersoccer-product-variations');
        },
        '/^30\s*%?\s*Multi-Child Course Discount$/' => static function () {
            return __('30% Multi-Child Course Discount', 'intersoccer-product-variations');
        },
        '/^50\s*%?\s*Same-Season Course Discount$/' => static function () {
            return __('50% Same-Season Course Discount', 'intersoccer-product-variations');
        },
    ];

    foreach ($rules as $pattern => $callback) {
        if (preg_match($pattern, $text, $matches)) {
            return $callback($matches);
        }
    }

    return $label;
}

function intersoccer_translate_stored_order_meta_display_value($value, $meta_key = '') {
    $text = is_scalar($value) ? (string) $value : '';
    if ($text === '') {
        return $value;
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Attendee Gender')) {
        if (intersoccer_is_customer_order_meta_display_context()
            && function_exists('intersoccer_pv_format_stored_gender_for_display')) {
            return intersoccer_pv_format_stored_gender_for_display($text);
        }
    }

    if (!intersoccer_is_customer_order_meta_display_context()) {
        return $value;
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Discount') || $meta_key === '') {
        $translated = intersoccer_translate_persisted_discount_label_for_display($text);
        if ($translated !== $text) {
            return $translated;
        }
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Medical Conditions') && $text === 'None') {
        return __('None', 'intersoccer-product-variations');
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Note')) {
        return intersoccer_pv_repair_utf8_dash_mojibake_in_note($text);
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Activity Type')) {
        return intersoccer_pv_translate_activity_type_order_meta_value($text);
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Booking Type')
        || intersoccer_pv_order_meta_key_is($meta_key, 'Late Pickup Type')) {
        return intersoccer_pv_translate_order_meta_token_for_display($text);
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Girls Only')) {
        return intersoccer_pv_translate_order_meta_token_for_display($text);
    }

    if (intersoccer_pv_order_meta_key_is($meta_key, 'Season')) {
        return intersoccer_pv_translate_order_meta_token_for_display($text);
    }

    $date_keys = [
        'Start Date',
        'End Date',
        'Camp Start Date',
        'Camp End Date',
        'Holidays',
    ];
    foreach ($date_keys as $date_key) {
        if (intersoccer_pv_order_meta_key_is($meta_key, $date_key)) {
            if ($date_key === 'Holidays') {
                return intersoccer_pv_format_stored_order_date_list_for_display($text);
            }
            return intersoccer_pv_format_stored_order_date_for_display($text);
        }
    }

    $comma_keys = [
        'Days Selected',
        'Late Pickup Days',
        'Course Day',
        'Days of Week',
        'Tournament Day',
    ];
    foreach ($comma_keys as $comma_key) {
        if (intersoccer_pv_order_meta_key_is($meta_key, $comma_key)) {
            return intersoccer_pv_translate_comma_separated_order_meta_value($text);
        }
    }

    return $value;
}

/**
 * @param string          $display_key Display label.
 * @param object          $meta        Meta object.
 * @param WC_Order_Item   $item        Order item.
 * @return string
 */
function intersoccer_filter_order_item_display_meta_key($display_key, $meta, $item) {
    $meta_key = isset($meta->key) ? (string) $meta->key : '';
    return intersoccer_translate_stored_order_meta_display_key((string) $display_key, $meta_key);
}

/**
 * @param string          $display_value Display value.
 * @param object          $meta          Meta object.
 * @param WC_Order_Item   $item          Order item.
 * @return string
 */
function intersoccer_filter_order_item_display_meta_value($display_value, $meta, $item) {
    $meta_key = isset($meta->key) ? (string) $meta->key : '';
    return intersoccer_translate_stored_order_meta_display_value((string) $display_value, $meta_key);
}

add_filter('woocommerce_order_item_display_meta_key', 'intersoccer_filter_order_item_display_meta_key', 10, 3);
add_filter('woocommerce_order_item_display_meta_value', 'intersoccer_filter_order_item_display_meta_value', 10, 3);

/**
 * Apply display-time label translation on formatted meta objects.
 *
 * @param object $meta Formatted meta object.
 * @return object
 */
function intersoccer_translate_order_item_formatted_meta_object($meta) {
    if (!intersoccer_is_customer_order_meta_display_context()) {
        return $meta;
    }

    $meta_key = isset($meta->key) ? (string) $meta->key : '';
    if (isset($meta->display_key)) {
        $meta->display_key = intersoccer_translate_stored_order_meta_display_key((string) $meta->display_key, $meta_key);
    }
    $raw_value = isset($meta->value) ? $meta->value : '';
    $translated_value = intersoccer_translate_stored_order_meta_display_value($raw_value, $meta_key);
    if ($translated_value !== $raw_value) {
        $meta->value = $translated_value;
        if (isset($meta->display_value)) {
            $meta->display_value = $translated_value;
        }
    }

    return $meta;
}

/**
 * Apply DOB / medical redaction to formatted meta objects.
 *
 * @param array<int,object> $formatted_meta
 * @param WC_Order_Item     $item
 * @return array<int,object>
 */
function intersoccer_redact_sensitive_order_item_formatted_meta(array $formatted_meta, $item) {
    $out = [];
    foreach ($formatted_meta as $meta) {
        $key = isset($meta->key) ? (string) $meta->key : '';
        $display_key = isset($meta->display_key) ? (string) $meta->display_key : '';
        if ($key === '' && $display_key === '') {
            continue;
        }

        if (intersoccer_is_sensitive_order_meta_medical_key($key, $display_key)) {
            if (!intersoccer_order_meta_medical_conditions_has_value($meta->value ?? '')) {
                continue;
            }
            $redacted = intersoccer_format_medical_conditions_for_redacted_surface();
            $meta->value = $redacted;
            if (isset($meta->display_value)) {
                $meta->display_value = $redacted;
            }
            $out[] = $meta;
            continue;
        }

        if (intersoccer_is_sensitive_order_meta_dob_key($key, $display_key)) {
            $redacted = intersoccer_format_attendee_dob_for_redacted_surface($meta->value ?? '', $item);
            if ($redacted === '') {
                continue;
            }
            $meta->value = $redacted;
            if (isset($meta->display_value)) {
                $meta->display_value = $redacted;
            }
            $out[] = $meta;
            continue;
        }

        $out[] = $meta;
    }

    return $out;
}

/**
 * Meta keys that are raw taxonomy slugs (display twins of human order-meta labels).
 *
 * @return array<int,string>
 */
function intersoccer_pv_hidden_slug_attribute_meta_keys() {
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keys = ['girls_only'];
    if (!function_exists('intersoccer_attr_registry')) {
        return $keys;
    }

    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (!is_array($def)) {
            continue;
        }
        if (function_exists('intersoccer_attr_taxonomy')) {
            $keys[] = (string) intersoccer_attr_taxonomy($slug);
        }
        if (function_exists('intersoccer_attr_resolve_meta_key')) {
            $keys[] = (string) intersoccer_attr_resolve_meta_key($slug);
        }
        foreach ($def['legacy_taxonomy_aliases'] ?? [] as $alias) {
            $keys[] = (string) $alias;
        }
        foreach ($def['legacy_meta_keys'] ?? [] as $legacy_key) {
            $keys[] = (string) $legacy_key;
        }
    }

    $keys = array_values(array_unique(array_filter($keys, static function ($key) {
        return is_string($key) && $key !== '';
    })));

    return $keys;
}

/**
 * @param string $meta_key Stored order-item meta key.
 * @return bool
 */
function intersoccer_pv_is_hidden_slug_attribute_meta_key($meta_key) {
    $meta_key = (string) $meta_key;
    if ($meta_key === '') {
        return false;
    }

    return in_array($meta_key, intersoccer_pv_hidden_slug_attribute_meta_keys(), true);
}

/**
 * Prefer raw pa_* over attribute_pa_* when only slug keys share a display label.
 *
 * @param string $meta_key
 * @return int
 */
function intersoccer_pv_formatted_meta_slug_display_priority($meta_key) {
    $meta_key = (string) $meta_key;
    if (preg_match('/^attribute_pa_/i', $meta_key)) {
        return 40;
    }
    if (preg_match('/^pa_/i', $meta_key)) {
        return 30;
    }

    return 35;
}

/**
 * Stored meta_key strings that represent the human facet for a registry attribute slug.
 *
 * @param string $slug Registry slug (bare, not pa_*).
 * @return array<int,string>
 */
function intersoccer_pv_stored_human_meta_keys_for_attr_slug($slug) {
    $slug = (string) $slug;
    if ($slug === '' || !function_exists('intersoccer_attr_registry')) {
        return [];
    }

    $registry = intersoccer_attr_registry();
    if (!isset($registry[$slug]) || !is_array($registry[$slug])) {
        return [];
    }

    $def = $registry[$slug];
    $label = isset($def['order_meta_label']) ? (string) $def['order_meta_label'] : '';
    $keys = [];
    if ($label !== '') {
        $keys[] = $label;
        if (function_exists('intersoccer_pv_order_item_meta_canonical_key_variants')) {
            $keys = array_merge($keys, intersoccer_pv_order_item_meta_canonical_key_variants($label));
        }
    }
    foreach ($def['legacy_order_meta_labels'] ?? [] as $legacy) {
        $legacy = (string) $legacy;
        if ($legacy !== '') {
            $keys[] = $legacy;
        }
    }

    return array_values(array_unique(array_filter($keys, static function ($key) {
        return is_string($key) && $key !== '';
    })));
}

/**
 * Slug-side meta keys for one registry attribute (including legacy aliases).
 *
 * @param string $slug
 * @return array<int,string>
 */
function intersoccer_pv_slug_side_meta_keys_for_attr_slug($slug) {
    $slug = (string) $slug;
    if ($slug === '' || !function_exists('intersoccer_attr_registry')) {
        return [];
    }

    $registry = intersoccer_attr_registry();
    if (!isset($registry[$slug]) || !is_array($registry[$slug])) {
        return [];
    }

    $def = $registry[$slug];
    $keys = [];
    if (function_exists('intersoccer_attr_taxonomy')) {
        $keys[] = (string) intersoccer_attr_taxonomy($slug);
    }
    if (function_exists('intersoccer_attr_resolve_meta_key')) {
        $keys[] = (string) intersoccer_attr_resolve_meta_key($slug);
    }
    foreach ($def['legacy_taxonomy_aliases'] ?? [] as $alias) {
        $keys[] = (string) $alias;
    }
    foreach ($def['legacy_meta_keys'] ?? [] as $legacy_key) {
        $keys[] = (string) $legacy_key;
    }
    if ($slug === 'girls-only') {
        $keys[] = 'girls_only';
    }

    return array_values(array_unique(array_filter($keys, static function ($key) {
        return is_string($key) && $key !== '';
    })));
}

/**
 * @param array<string,true> $keys_present
 * @param string             $slug
 * @return bool
 */
function intersoccer_pv_formatted_meta_has_human_facet_key(array $keys_present, $slug) {
    foreach (intersoccer_pv_stored_human_meta_keys_for_attr_slug($slug) as $human_key) {
        if (isset($keys_present[$human_key])) {
            return true;
        }
    }

    return false;
}

/**
 * Hide pa_* / attribute_pa_* display rows when a readable stored label row remains (display only).
 *
 * @param array<int|mixed,object> $formatted_meta
 * @return array<int|mixed,object>
 */
function intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta(array $formatted_meta) {
    if ($formatted_meta === []) {
        return $formatted_meta;
    }

    $keys_present = [];
    foreach ($formatted_meta as $meta) {
        if (!is_object($meta)) {
            continue;
        }
        $keys_present[(string) ($meta->key ?? '')] = true;
    }

    if (function_exists('intersoccer_attr_registry')) {
        foreach (intersoccer_attr_registry() as $slug => $def) {
            if (!is_array($def)) {
                continue;
            }
            if (!intersoccer_pv_formatted_meta_has_human_facet_key($keys_present, (string) $slug)) {
                continue;
            }
            $hide_keys = array_flip(intersoccer_pv_slug_side_meta_keys_for_attr_slug((string) $slug));
            foreach ($formatted_meta as $meta_id => $meta) {
                if (!is_object($meta)) {
                    continue;
                }
                $key = (string) ($meta->key ?? '');
                if ($key !== '' && isset($hide_keys[$key])) {
                    unset($formatted_meta[$meta_id]);
                }
            }
        }
    }

    $by_label = [];
    foreach ($formatted_meta as $meta_id => $meta) {
        if (!is_object($meta)) {
            continue;
        }
        $display_key = isset($meta->display_key) ? (string) $meta->display_key : '';
        $label = strtolower(trim($display_key));
        if ($label === '') {
            continue;
        }
        $by_label[$label][] = $meta_id;
    }

    foreach ($by_label as $meta_ids) {
        if (count($meta_ids) <= 1) {
            continue;
        }

        $non_slug_ids = [];
        $slug_ids = [];
        foreach ($meta_ids as $meta_id) {
            $meta = $formatted_meta[$meta_id] ?? null;
            if (!is_object($meta)) {
                continue;
            }
            $key = isset($meta->key) ? (string) $meta->key : '';
            if (intersoccer_pv_is_hidden_slug_attribute_meta_key($key)) {
                $slug_ids[] = $meta_id;
            } else {
                $non_slug_ids[] = $meta_id;
            }
        }

        if ($non_slug_ids !== []) {
            foreach ($slug_ids as $slug_id) {
                unset($formatted_meta[$slug_id]);
            }
            continue;
        }

        if (count($slug_ids) <= 1) {
            continue;
        }

        $keep_id = null;
        $keep_priority = PHP_INT_MAX;
        foreach ($slug_ids as $slug_id) {
            $key = (string) ($formatted_meta[$slug_id]->key ?? '');
            $priority = intersoccer_pv_formatted_meta_slug_display_priority($key);
            if ($priority < $keep_priority) {
                $keep_priority = $priority;
                $keep_id = $slug_id;
            }
        }

        if ($keep_id === null) {
            continue;
        }

        foreach ($slug_ids as $slug_id) {
            if ($slug_id !== $keep_id) {
                unset($formatted_meta[$slug_id]);
            }
        }
    }

    return $formatted_meta;
}

/**
 * @param array<int|mixed,object> $formatted_meta
 * @param mixed                   $item
 * @return array<int|mixed,object>
 */
function intersoccer_pv_filter_dedupe_taxonomy_attribute_formatted_meta($formatted_meta, $item) {
    unset($item);
    if (!is_array($formatted_meta)) {
        return $formatted_meta;
    }

    return intersoccer_pv_dedupe_taxonomy_attribute_formatted_meta($formatted_meta);
}

/**
 * Resolve pa_* taxonomy from a slug-side order-item meta key.
 *
 * @param string $meta_key
 * @return string Taxonomy name (pa_*) or empty.
 */
function intersoccer_pv_taxonomy_from_slug_meta_key($meta_key) {
    $meta_key = (string) $meta_key;
    if ($meta_key === '') {
        return '';
    }

    if ($meta_key === 'girls_only' && function_exists('intersoccer_attr_taxonomy')) {
        return (string) intersoccer_attr_taxonomy('girls-only');
    }

    if (preg_match('/^attribute_(pa_.+)$/i', $meta_key, $matches)) {
        return (string) $matches[1];
    }

    if (preg_match('/^pa_/i', $meta_key)) {
        return $meta_key;
    }

    if (function_exists('intersoccer_attr_registry') && function_exists('intersoccer_attr_taxonomy')) {
        foreach (intersoccer_attr_registry() as $slug => $def) {
            if (!is_array($def)) {
                continue;
            }
            foreach ($def['legacy_taxonomy_aliases'] ?? [] as $alias) {
                if ((string) $alias === $meta_key) {
                    return (string) intersoccer_attr_taxonomy($slug);
                }
            }
            foreach ($def['legacy_meta_keys'] ?? [] as $legacy_key) {
                if ((string) $legacy_key !== $meta_key) {
                    continue;
                }
                if (preg_match('/^attribute_(pa_.+)$/i', (string) $legacy_key, $matches)) {
                    return (string) $matches[1];
                }
            }
        }
    }

    return '';
}

/**
 * WPML/current language code for term label resolution.
 *
 * @return string
 */
function intersoccer_pv_wpml_current_language_code() {
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        return (string) ICL_LANGUAGE_CODE;
    }

    $lang = apply_filters('wpml_current_language', null);

    return is_string($lang) ? trim($lang) : '';
}

/**
 * Readable term name for a taxonomy slug in the current language (falls back to stored slug).
 *
 * @param string $taxonomy pa_* taxonomy.
 * @param string $term_slug Term slug stored on the line item.
 * @return string
 */
function intersoccer_pv_readable_term_name_for_taxonomy_slug($taxonomy, $term_slug) {
    $taxonomy = (string) $taxonomy;
    $term_slug = trim((string) $term_slug);
    if ($taxonomy === '' || $term_slug === '') {
        return $term_slug;
    }

    if (!function_exists('get_term_by')) {
        return $term_slug;
    }

    $term = get_term_by('slug', $term_slug, $taxonomy);
    if (!$term || is_wp_error($term)) {
        return $term_slug;
    }

    $name = isset($term->name) ? (string) $term->name : $term_slug;
    $lang = intersoccer_pv_wpml_current_language_code();
    if ($lang !== '' && function_exists('icl_object_id')) {
        $translated_id = icl_object_id((int) $term->term_id, $taxonomy, false, $lang);
        if ($translated_id && (int) $translated_id !== (int) $term->term_id && function_exists('get_term')) {
            $translated = get_term((int) $translated_id, $taxonomy);
            if ($translated && !is_wp_error($translated) && !empty($translated->name)) {
                $name = (string) $translated->name;
            }
        }
    }

    return $name !== '' ? $name : $term_slug;
}

/**
 * @param array<string,true> $keys_present
 * @param string             $slug_meta_key
 * @return bool
 */
function intersoccer_pv_customer_should_synthesize_readable_slug_attribute_row(array $keys_present, $slug_meta_key) {
    if (!intersoccer_pv_is_hidden_slug_attribute_meta_key($slug_meta_key)) {
        return false;
    }

    $taxonomy = intersoccer_pv_taxonomy_from_slug_meta_key($slug_meta_key);
    if ($taxonomy === '' || !function_exists('intersoccer_attr_slug_from_taxonomy')) {
        return false;
    }

    $slug = intersoccer_attr_slug_from_taxonomy($taxonomy);
    if ($slug === null || $slug === '') {
        return false;
    }

    return !intersoccer_pv_formatted_meta_has_human_facet_key($keys_present, $slug);
}

/**
 * Build a customer-facing formatted-meta row for a slug-only attribute line.
 *
 * @param object              $meta Slug-side formatted meta object.
 * @param WC_Order_Item_Product $item Order line item.
 * @return object|null
 */
function intersoccer_pv_build_customer_readable_slug_attribute_formatted_meta($meta, $item) {
    unset($item);
    if (!is_object($meta)) {
        return null;
    }

    $meta_key = (string) ($meta->key ?? '');
    $taxonomy = intersoccer_pv_taxonomy_from_slug_meta_key($meta_key);
    if ($taxonomy === '') {
        return null;
    }

    $registry_label = function_exists('intersoccer_attr_order_meta_label_for_taxonomy')
        ? (string) intersoccer_attr_order_meta_label_for_taxonomy($taxonomy)
        : '';
    if ($registry_label === '' && function_exists('wc_attribute_label')) {
        $registry_label = (string) wc_attribute_label($taxonomy);
    }
    if ($registry_label === '') {
        return null;
    }

    $stored_slug = isset($meta->value) ? (string) $meta->value : '';
    $readable_value = intersoccer_pv_readable_term_name_for_taxonomy_slug($taxonomy, $stored_slug);
    $display_key = intersoccer_translate_stored_order_meta_display_key($registry_label, $registry_label);

    $out = clone $meta;
    $out->display_key = $display_key;
    $out->value = $readable_value;
    if (isset($out->display_value)) {
        $out->display_value = $readable_value;
    }

    return $out;
}

/**
 * Dedupe customer-facing formatted meta (e.g. legacy assigned_attendee + Assigned Attendee).
 *
 * @param array<int,object> $formatted_meta
 * @return array<int,object>
 */
function intersoccer_pv_dedupe_customer_order_formatted_meta(array $formatted_meta) {
    $seen_canonical = [];
    $out = [];
    foreach ($formatted_meta as $meta) {
        $key = isset($meta->key) ? (string) $meta->key : '';
        $canonical = function_exists('intersoccer_pv_canonical_stored_order_meta_key')
            ? intersoccer_pv_canonical_stored_order_meta_key($key)
            : $key;
        if ($canonical === 'Assigned Attendee') {
            if (isset($seen_canonical[$canonical])) {
                continue;
            }
            $seen_canonical[$canonical] = true;
        }
        $out[] = $meta;
    }

    return $out;
}

/**
 * Hide underscore-prefixed internal keys in admin order item meta (display only).
 *
 * @param array<int,object> $formatted_meta
 * @param WC_Order_Item     $item
 * @return array<int,object>
 */
function intersoccer_pv_filter_admin_order_item_formatted_meta($formatted_meta, $item) {
    if (!is_array($formatted_meta)) {
        return $formatted_meta;
    }
    if (!is_admin()
        || intersoccer_is_sensitive_order_meta_email_context()
        || (function_exists('intersoccer_is_customer_order_meta_display_context')
            && intersoccer_is_customer_order_meta_display_context())
        || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
        return $formatted_meta;
    }

    $filtered = [];
    foreach ($formatted_meta as $meta) {
        $key = isset($meta->key) ? (string) $meta->key : '';
        if ($key !== '' && $key[0] === '_') {
            continue;
        }
        $filtered[] = $meta;
    }

    return $filtered;
}

/**
 * Register internal order-line keys with WooCommerce admin hidden meta list.
 *
 * @param array<int,string> $hidden
 * @return array<int,string>
 */
function intersoccer_pv_admin_hidden_order_itemmeta($hidden) {
    $extra = function_exists('intersoccer_canonical_order_meta_keys')
        ? intersoccer_canonical_order_meta_keys()
        : [];
    $extra = array_merge($extra, [
        '_intersoccer_discount_season_key',
        '_intersoccer_total_item_discount',
        '_intersoccer_item_discounts',
        '_camp_start_date',
        '_camp_end_date',
        '_camp_week_index',
        '_assigned_player',
        '_assigned_player_id',
    ]);

    return array_values(array_unique(array_merge($hidden, $extra)));
}

function intersoccer_filter_customer_order_item_meta($formatted_meta, $item) {
    if (!is_array($formatted_meta)) {
        return $formatted_meta;
    }

    if (is_admin() && !wp_doing_ajax() && !intersoccer_is_sensitive_order_meta_email_context()) {
        return $formatted_meta;
    }

    $keys_present = [];
    foreach ($formatted_meta as $meta) {
        if (!is_object($meta)) {
            continue;
        }
        $present_key = (string) ($meta->key ?? '');
        if ($present_key !== '') {
            $keys_present[$present_key] = true;
        }
    }

    $filtered = [];
    foreach ($formatted_meta as $meta) {
        $key = isset($meta->key) ? (string) $meta->key : '';
        if ($key === '' || intersoccer_is_hidden_customer_order_meta_key($key)) {
            if ($key !== ''
                && intersoccer_pv_customer_should_synthesize_readable_slug_attribute_row($keys_present, $key)) {
                $readable = intersoccer_pv_build_customer_readable_slug_attribute_formatted_meta($meta, $item);
                if ($readable !== null) {
                    $filtered[] = $readable;
                }
            }
            continue;
        }
        if (intersoccer_pv_should_hide_duplicate_player_dob_meta_key($key, $item)) {
            continue;
        }
        $filtered[] = $meta;
    }

    $filtered = intersoccer_pv_dedupe_customer_order_formatted_meta($filtered);

    if (intersoccer_should_mask_sensitive_order_item_meta()) {
        $filtered = intersoccer_redact_sensitive_order_item_formatted_meta($filtered, $item);
    }

    $translated = [];
    foreach ($filtered as $meta) {
        $translated[] = intersoccer_translate_order_item_formatted_meta_object($meta);
    }

    return $translated;
}

add_filter('woocommerce_hidden_order_itemmeta', 'intersoccer_pv_admin_hidden_order_itemmeta', 20);
add_filter('woocommerce_order_item_get_formatted_meta_data', 'intersoccer_pv_filter_admin_order_item_formatted_meta', 5, 2);
add_filter('woocommerce_order_item_get_formatted_meta_data', 'intersoccer_pv_filter_dedupe_taxonomy_attribute_formatted_meta', 10, 2);
add_filter('woocommerce_order_item_get_formatted_meta_data', 'intersoccer_filter_customer_order_item_meta', 10, 2);
add_action('woocommerce_email_before_order_table', 'intersoccer_sensitive_order_meta_begin_email_context', 0, 4);
add_action('woocommerce_email_after_order_table', 'intersoccer_sensitive_order_meta_end_email_context', 9999, 4);

/**
 * Send no-cache headers on the order-received (thank-you) endpoint only.
 *
 * WooCommerce already treats the checkout page (including order-received) as non-cacheable via
 * WC_Cache_Helper::prevent_caching() and wc_nocache_headers(); PV does not override those.
 * This hook is an extra safeguard so thank-you output with redacted PII is not served from a page cache.
 *
 * @return void
 */
function intersoccer_send_order_received_nocache_headers() {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
        return;
    }

    if (function_exists('wc_nocache_headers')) {
        wc_nocache_headers();
        return;
    }

    if (function_exists('wc_maybe_define_constant')) {
        wc_maybe_define_constant('DONOTCACHEPAGE', true);
    } elseif (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }

    if (function_exists('nocache_headers')) {
        nocache_headers();
    }
}

add_action('template_redirect', 'intersoccer_send_order_received_nocache_headers', 0);
