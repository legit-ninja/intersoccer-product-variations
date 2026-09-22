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
        $existing_activity_type = (string) $item->get_meta('Activity Type', true);
    }

    $activity_type = intersoccer_resolve_order_activity_type($product_id, $variation_id, $product_type, $existing_activity_type);
    $activity_type_key = function_exists('icl_t')
        ? icl_t('intersoccer-product-variations', 'Activity Type', 'Activity Type')
        : 'Activity Type';
    $updates[$activity_type_key] = $activity_type;

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
        // Stamp structured camp schedule as localized human keys (variation SoT stays _camp_*).
        $vid = $variation_id ?: $product_id;
        if (function_exists('intersoccer_get_camp_schedule_meta')) {
            $schedule = intersoccer_get_camp_schedule_meta($vid);
            $lang = intersoccer_order_activity_type_detect_language($existing_activity_type);
            $labels = intersoccer_camp_schedule_order_meta_labels_for_language($lang);
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
    $raw = strtolower(trim((string) $raw));
    if ($raw === '') {
        return '';
    }
    $compact = preg_replace('/[\s_]+/', '-', $raw);
    $compact = str_replace(['(', ')'], '', (string) $compact);
    $aliases = [
        'full-week' => 'full-week',
        'fullweek' => 'full-week',
        'single-days' => 'single-days',
        'single-day' => 'single-days',
        'full-term' => 'full-term',
        'fullterm' => 'full-term',
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
    if (strpos($raw, 'single') !== false) {
        return 'single-days';
    }
    if (strpos($raw, 'term') !== false) {
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
        'existing_activity_type' => (string) $item->get_meta('Activity Type', true),
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
        $activity = (string) $item->get_meta('Activity Type', true);
        if ($activity === '') {
            // FR/DE Activity Type keys may be localized on the item.
            foreach (['Type d\'activité', 'Type d’activité', 'Aktivitätstyp'] as $alt) {
                $activity = (string) $item->get_meta($alt, true);
                if ($activity !== '') {
                    break;
                }
            }
        }
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
        'Player Index',
        'intersoccer_player_index',
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

    $hidden_keys = intersoccer_order_meta_hidden_customer_keys();
    if (in_array($key, $hidden_keys, true)) {
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
 * Filter order item formatted meta for customer-facing surfaces.
 *
 * Hides internal keys (assigned_player*, attribute_pa_*, underscore-prefixed)
 * from thank-you page, order emails, and My Account order views.
 *
 * Does NOT affect admin order views or reports-rosters raw meta reads.
 *
 * @param array<int,object> $formatted_meta Array of formatted meta objects.
 * @param WC_Order_Item     $item           Order item.
 * @return array<int,object>
 */
function intersoccer_filter_customer_order_item_meta($formatted_meta, $item) {
    if (!is_array($formatted_meta)) {
        return $formatted_meta;
    }

    if (is_admin() && !wp_doing_ajax()) {
        return $formatted_meta;
    }

    $filtered = [];
    foreach ($formatted_meta as $meta) {
        $key = isset($meta->key) ? (string) $meta->key : '';
        if ($key === '' || intersoccer_is_hidden_customer_order_meta_key($key)) {
            continue;
        }
        $filtered[] = $meta;
    }

    return $filtered;
}

add_filter('woocommerce_order_item_get_formatted_meta_data', 'intersoccer_filter_customer_order_item_meta', 10, 2);
