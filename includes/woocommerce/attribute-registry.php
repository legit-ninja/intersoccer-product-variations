<?php
/**
 * InterSoccer canonical WooCommerce product attribute registry.
 *
 * Single source of truth for taxonomy slugs, WC admin labels, order-meta labels,
 * product-type templates, health requirements, and legacy alias fallbacks.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('INTERSOCCER_ATTRIBUTE_CONTRACT_VERSION')) {
    define('INTERSOCCER_ATTRIBUTE_CONTRACT_VERSION', 2);
}

/**
 * @return array<string,array<string,mixed>>
 */
function intersoccer_attr_registry() {
    static $registry = null;
    if ($registry !== null) {
        return $registry;
    }

    $weekdays = [
        ['name' => 'Monday', 'slug' => 'monday'],
        ['name' => 'Tuesday', 'slug' => 'tuesday'],
        ['name' => 'Wednesday', 'slug' => 'wednesday'],
        ['name' => 'Thursday', 'slug' => 'thursday'],
        ['name' => 'Friday', 'slug' => 'friday'],
        ['name' => 'Saturday', 'slug' => 'saturday'],
        ['name' => 'Sunday', 'slug' => 'sunday'],
    ];

    $registry = [
        'activity-type' => [
            'wc_label' => 'Activity Type',
            'order_meta_label' => 'Activity Type',
            'legacy_order_meta_labels' => [
                "Type d'activité",
                "Type d'activite",
                'Aktivitätstyp',
            ],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [
                ['name' => 'Camp', 'slug' => 'camp'],
                ['name' => 'Course', 'slug' => 'course'],
                ['name' => 'Birthday Party', 'slug' => 'birthday-party'],
                ['name' => 'Tournament', 'slug' => 'tournament'],
            ],
            'day_attribute' => false,
        ],
        'intersoccer-venues' => [
            'wc_label' => 'InterSoccer Venues',
            'order_meta_label' => 'Sites InterSoccer',
            'legacy_order_meta_labels' => [
                'InterSoccer Venues',
                'Lieux InterSoccer',
                'Lieu InterSoccer',
                'InterSoccer-Standorte',
            ],
            'legacy_taxonomy_aliases' => ['pa_intersoccer_venues'],
            'legacy_meta_keys' => ['attribute_pa_intersoccer_venues'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'program-season' => [
            'wc_label' => 'Program Season',
            'order_meta_label' => 'Season',
            'legacy_order_meta_labels' => [
                'Saison',
                'Saison (Programm)',
                'Jahreszeit',
            ],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [
                ['name' => 'Summer', 'slug' => 'summer'],
                ['name' => 'Autumn', 'slug' => 'autumn'],
                ['name' => 'Spring', 'slug' => 'spring'],
                ['name' => 'Winter', 'slug' => 'winter'],
            ],
            'day_attribute' => false,
        ],
        'program-year' => [
            'wc_label' => 'Program Year',
            'order_meta_label' => 'Year',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [
                ['name' => '2025', 'slug' => '2025'],
                ['name' => '2026', 'slug' => '2026'],
                ['name' => '2027', 'slug' => '2027'],
            ],
            'day_attribute' => false,
        ],
        'age-group' => [
            'wc_label' => 'Age Group',
            'order_meta_label' => 'Age Group',
            'legacy_order_meta_labels' => [
                "Groupe d'âge",
                'Groupe dage',
                'Altersgruppe',
            ],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [
                ['name' => '3-5y (Half-Day)', 'slug' => '3-5y-half-day'],
                ['name' => '5-13y (Full Day)', 'slug' => '5-13y-full-day'],
                ['name' => '3-12y', 'slug' => '3-12y'],
            ],
            'day_attribute' => false,
        ],
        'canton-region' => [
            'wc_label' => 'Canton / Region',
            'order_meta_label' => 'Canton / Region',
            'legacy_order_meta_labels' => [
                'Canton / Région',
                'Canton Region',
                'Kanton Region',
            ],
            'legacy_taxonomy_aliases' => ['pa_canton_region'],
            'legacy_meta_keys' => ['attribute_pa_canton_region'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'city' => [
            'wc_label' => 'City',
            'order_meta_label' => 'City',
            'legacy_order_meta_labels' => [
                'Ville',
                'Stadt',
            ],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'booking-type' => [
            'wc_label' => 'Booking Type',
            'order_meta_label' => 'Booking Type',
            'legacy_order_meta_labels' => [
                'Type de réservation',
                'Buchungstyp',
            ],
            'legacy_taxonomy_aliases' => ['pa_booking_type'],
            'legacy_meta_keys' => [
                'attribute_pa_booking_type',
                'attribute_booking-type',
                'attribute_booking_type',
            ],
            'default_terms' => [
                ['name' => 'Full Week', 'slug' => 'full-week'],
                ['name' => 'Single Day(s)', 'slug' => 'single-days'],
                ['name' => 'Full Term', 'slug' => 'full-term'],
            ],
            'day_attribute' => false,
        ],
        'days-of-week' => [
            'wc_label' => 'Days of Week',
            'order_meta_label' => 'Days of Week',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => ['pa_days_of_week'],
            'legacy_meta_keys' => ['attribute_pa_days_of_week'],
            'default_terms' => $weekdays,
            'day_attribute' => true,
        ],
        'camp-terms' => [
            'wc_label' => 'Camp Terms',
            'order_meta_label' => 'Camp Terms',
            'legacy_order_meta_labels' => [
                'Conditions du camp',
                'Conditions de camp',
                'Camp Begriffe',
            ],
            'legacy_taxonomy_aliases' => ['pa_camp_terms'],
            'legacy_meta_keys' => ['attribute_pa_camp_terms'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'course-day' => [
            'wc_label' => 'Course Day',
            'order_meta_label' => 'Course Day',
            'legacy_order_meta_labels' => [
                'Jour de cours',
                'Kurstag',
            ],
            'legacy_taxonomy_aliases' => ['pa_course_day'],
            'legacy_meta_keys' => ['attribute_pa_course_day'],
            'default_terms' => array_slice($weekdays, 0, 5),
            'day_attribute' => true,
        ],
        'course-times' => [
            'wc_label' => 'Course Times',
            'order_meta_label' => 'Course Times',
            'legacy_order_meta_labels' => [
                'Horaires du cours',
                'Kurszeiten',
            ],
            'legacy_taxonomy_aliases' => ['pa_course_times'],
            'legacy_meta_keys' => ['attribute_pa_course_times'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'camp-times' => [
            'wc_label' => 'Camp Times',
            'order_meta_label' => 'Camp Times',
            'legacy_order_meta_labels' => [
                'Horaires du camp',
                'Camp Zeiten',
            ],
            'legacy_taxonomy_aliases' => ['pa_camp_times'],
            'legacy_meta_keys' => ['attribute_pa_camp_times'],
            // Catalogue defaults: Full Day 10:00–17:00, Half Day Mini 10:00–12:30.
            'default_terms' => [
                ['name' => '1000-1700', 'slug' => '1000-1700'],
                ['name' => '1000-1230', 'slug' => '1000-1230'],
            ],
            'day_attribute' => false,
        ],
        'girls-only' => [
            'wc_label' => 'Girls Only',
            'order_meta_label' => 'Girls Only',
            'legacy_order_meta_labels' => [
                'Filles uniquement',
                'Nur Mädchen',
                'Nur Madchen',
            ],
            'legacy_taxonomy_aliases' => ['pa_girls_only', 'pa_girl-only', 'pa_girl_only'],
            'legacy_meta_keys' => [
                'attribute_pa_girls_only',
                'attribute_pa_girl-only',
                'attribute_pa_girl_only',
            ],
            'default_terms' => [
                ['name' => 'Yes', 'slug' => 'yes'],
                ['name' => 'No', 'slug' => 'no'],
                ['name' => "Girl's Only", 'slug' => 'girls-only'],
            ],
            'day_attribute' => false,
        ],
        'date' => [
            'wc_label' => 'Date',
            'order_meta_label' => 'Date',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'tournament-day' => [
            'wc_label' => 'Tournament Day',
            'order_meta_label' => 'Tournament Day',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => ['pa_tournament_day'],
            'legacy_meta_keys' => ['attribute_pa_tournament_day'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'tournament-time' => [
            'wc_label' => 'Tournament Time',
            'order_meta_label' => 'Tournament Time',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => ['pa_tournament_time'],
            'legacy_meta_keys' => ['attribute_pa_tournament_time'],
            'default_terms' => [],
            'day_attribute' => false,
        ],
        'note' => [
            'wc_label' => 'Note',
            'order_meta_label' => 'Note',
            'legacy_order_meta_labels' => [],
            'legacy_taxonomy_aliases' => [],
            'legacy_meta_keys' => [],
            'default_terms' => [],
            'day_attribute' => false,
        ],
    ];

    return $registry;
}

/**
 * Product-type attribute templates.
 *
 * @return array<string,array<string,array<int,string>>>
 */
function intersoccer_attr_product_type_templates() {
    static $templates = null;
    if ($templates !== null) {
        return $templates;
    }

    $core_parent = [
        'activity-type',
        'intersoccer-venues',
        'program-season',
        'program-year',
        'age-group',
        'canton-region',
        'city',
    ];

    $templates = [
        'camp' => [
            'parent' => array_merge($core_parent, ['girls-only', 'days-of-week', 'camp-terms', 'camp-times']),
            'variation' => ['booking-type', 'age-group', 'camp-times'],
            'meta' => ['_camp_start_date', '_camp_end_date', '_camp_week_index'],
        ],
        'course' => [
            'parent' => array_merge($core_parent, ['girls-only']),
            'variation' => ['course-day', 'course-times', 'age-group'],
            'meta' => ['_course_start_date', '_course_total_weeks', '_course_holiday_dates'],
        ],
        'birthday' => [
            'parent' => $core_parent,
            'variation' => ['age-group'],
            'meta' => [],
        ],
        'tournament' => [
            'parent' => array_merge($core_parent, ['date']),
            'variation' => ['tournament-day', 'tournament-time', 'age-group'],
            'meta' => [],
        ],
    ];

    return $templates;
}

/**
 * @param string $slug Attribute slug without pa_ prefix.
 * @return string|null
 */
function intersoccer_attr_definition($slug) {
    $registry = intersoccer_attr_registry();
    return $registry[$slug] ?? null;
}

/**
 * @param string $slug
 * @return string
 */
function intersoccer_attr_taxonomy($slug) {
    return 'pa_' . $slug;
}

/**
 * @param string $taxonomy_or_slug Taxonomy (pa_*) or bare slug.
 * @return string|null Bare slug.
 */
function intersoccer_attr_slug_from_taxonomy($taxonomy_or_slug) {
    $key = (string) $taxonomy_or_slug;
    if (strpos($key, 'pa_') === 0) {
        $key = substr($key, 3);
    }
    return intersoccer_attr_definition($key) ? $key : null;
}

/**
 * @param string $slug
 * @return string
 */
function intersoccer_attr_order_meta_label($slug) {
    $def = intersoccer_attr_definition($slug);
    return $def ? (string) $def['order_meta_label'] : '';
}

/**
 * @param string $taxonomy pa_* taxonomy.
 * @return string
 */
function intersoccer_attr_order_meta_label_for_taxonomy($taxonomy) {
    $slug = intersoccer_attr_slug_from_taxonomy($taxonomy);
    if (!$slug) {
        return '';
    }
    return intersoccer_attr_order_meta_label($slug);
}

/**
 * @return array<string,string> pa_* taxonomy => order meta label.
 */
function intersoccer_attr_order_meta_label_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (intersoccer_attr_registry() as $slug => $def) {
        $map[intersoccer_attr_taxonomy($slug)] = (string) $def['order_meta_label'];
    }
    return $map;
}

/**
 * @param string $slug
 * @return string
 */
function intersoccer_attr_wc_label($slug) {
    $def = intersoccer_attr_definition($slug);
    return $def ? (string) $def['wc_label'] : '';
}

/**
 * @return array<int,string>
 */
function intersoccer_attr_canonical_weekday_slugs() {
    return ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
}

/**
 * @return array<int,string> Taxonomies that use weekday ordering.
 */
function intersoccer_attr_day_order_taxonomies() {
    $taxonomies = [];
    foreach (intersoccer_attr_registry() as $slug => $def) {
        if (!empty($def['day_attribute'])) {
            $taxonomies[] = intersoccer_attr_taxonomy($slug);
        }
    }
    return $taxonomies;
}

/**
 * Required attributes for variation health checks.
 *
 * @param string $product_type camp|course|birthday|tournament
 * @param string $scope parent|variation|meta
 * @return array<int,string>
 */
function intersoccer_attr_required($product_type, $scope = 'variation') {
    $templates = intersoccer_attr_product_type_templates();
    $type = strtolower((string) $product_type);
    if (!isset($templates[$type])) {
        return [];
    }

    if ($scope === 'meta') {
        return $templates[$type]['meta'];
    }

    if ($scope === 'parent') {
        return array_map('intersoccer_attr_taxonomy', $templates[$type]['parent']);
    }

    return array_map('intersoccer_attr_taxonomy', $templates[$type]['variation']);
}

/**
 * Health-check required keys (variation taxonomies + course meta).
 *
 * @param string $product_type
 * @return array<int,string>
 */
function intersoccer_attr_health_required_keys($product_type) {
    $type = strtolower((string) $product_type);
    $keys = intersoccer_attr_required($type, 'variation');

    if ($type === 'course' || $type === 'camp') {
        $keys = array_merge($keys, intersoccer_attr_required($type, 'meta'));
    }

    return array_values(array_unique($keys));
}

/**
 * Defaults applied by Variation Health refresh action.
 *
 * @param string $product_type
 * @return array<string,string>
 */
function intersoccer_attr_refresh_defaults($product_type) {
    $type = strtolower((string) $product_type);
    $defaults = [
        'camp' => [
            'pa_booking-type' => 'full-week',
            'pa_age-group' => '5-13y-full-day',
            'pa_camp-times' => '1000-1700',
        ],
        'course' => [
            'pa_course-day' => 'monday',
            '_course_start_date' => gmdate('Y-m-d'),
            '_course_total_weeks' => '16',
            '_course_holiday_dates' => '',
        ],
    ];

    return $defaults[$type] ?? [];
}

/**
 * Parent-level defaults for camp refresh (individual weekday term slugs).
 *
 * @return array<string,array<int,string>>
 */
function intersoccer_attr_refresh_parent_defaults() {
    return [
        'camp' => [
            'pa_days-of-week' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ],
    ];
}

/**
 * @param string $slug
 * @return string
 */
function intersoccer_attr_resolve_meta_key($slug) {
    return 'attribute_' . intersoccer_attr_taxonomy($slug);
}

/**
 * All meta keys to try when reading a variation attribute (canonical first).
 *
 * @param string $slug
 * @return array<int,string>
 */
function intersoccer_attr_variation_meta_keys($slug) {
    $def = intersoccer_attr_definition($slug);
    if (!$def) {
        return [];
    }

    $keys = [intersoccer_attr_resolve_meta_key($slug)];
    $legacy = $def['legacy_meta_keys'] ?? [];
    foreach ($legacy as $key) {
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    $aliases = $def['legacy_taxonomy_aliases'] ?? [];
    foreach ($aliases as $alias_tax) {
        $alias_key = 'attribute_' . $alias_tax;
        if (!in_array($alias_key, $keys, true)) {
            $keys[] = $alias_key;
        }
    }

    return $keys;
}

/**
 * Read variation attribute value with legacy alias fallback.
 *
 * @param int    $variation_id
 * @param string $slug
 * @return string
 */
function intersoccer_attr_get_variation_value($variation_id, $slug) {
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0) {
        return '';
    }

    foreach (intersoccer_attr_variation_meta_keys($slug) as $meta_key) {
        $value = get_post_meta($variation_id, $meta_key, true);
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    if (function_exists('wc_get_product')) {
        $variation = wc_get_product($variation_id);
        if ($variation && method_exists($variation, 'get_attribute')) {
            $taxonomies = array_merge(
                [intersoccer_attr_taxonomy($slug)],
                intersoccer_attr_definition($slug)['legacy_taxonomy_aliases'] ?? []
            );
            foreach ($taxonomies as $taxonomy) {
                $attr = $variation->get_attribute($taxonomy);
                if (is_string($attr) && $attr !== '') {
                    return $attr;
                }
            }
        }
    }

    return '';
}

/**
 * Reverse lookup: legacy order-meta label => canonical order-meta label.
 *
 * @return array<string,string>
 */
function intersoccer_attr_legacy_order_meta_label_reverse_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (intersoccer_attr_registry() as $slug => $def) {
        $canonical = (string) $def['order_meta_label'];
        foreach ($def['legacy_order_meta_labels'] ?? [] as $legacy) {
            $map[(string) $legacy] = $canonical;
        }
    }
    return $map;
}

/**
 * Order-item shape detection keys (admin player assignment).
 *
 * @return array<int,string>
 */
function intersoccer_attr_order_shape_keys() {
    $keys = [];
    foreach (intersoccer_attr_registry() as $slug => $def) {
        $keys[] = intersoccer_attr_taxonomy($slug);
        $keys[] = (string) $def['order_meta_label'];
    }
    return array_values(array_unique($keys));
}

/**
 * WPML custom-field copy keys for attribute variation meta.
 *
 * @return array<int,string>
 */
function intersoccer_attr_wpml_copy_meta_keys() {
    $keys = [];
    foreach (intersoccer_attr_registry() as $slug => $def) {
        $keys[] = intersoccer_attr_resolve_meta_key($slug);
        foreach ($def['legacy_meta_keys'] ?? [] as $legacy_key) {
            $keys[] = $legacy_key;
        }
    }
    return array_values(array_unique($keys));
}

/**
 * @return array<int,string> All pa_* taxonomies in the registry.
 */
function intersoccer_attr_all_taxonomies() {
    return array_map('intersoccer_attr_taxonomy', array_keys(intersoccer_attr_registry()));
}
