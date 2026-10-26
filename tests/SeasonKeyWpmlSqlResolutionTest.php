<?php
/**
 * Legacy season keys resolve via SQL + WPML icl_translations (not get_terms scans).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/tests/support/SeasonSqlTestBootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

if (!function_exists('intersoccer_translate_string')) {
    require_once dirname(__DIR__) . '/includes/language-helpers.php';
}
if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}

class SeasonKeyWpmlSqlResolutionTest extends TestCase
{
    private const SEASON_EN = 88001;
    private const SEASON_FR = 88002;
    private const SEASON_DE = 88003;
    private const SEASON_SUMMER = 88004;

    private const BASE_PRICE = 525.0;
    private const SIBLING_RATE = 0.20;
    private const CHILD_UUID = 'uuid-sql-season-child';

    /** @var WpdbSeasonSqlMock */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = intersoccer_pv_test_bootstrap_season_sql_wpdb(WpdbSeasonSqlMock::autumn_2026_wpml_fixture(
            self::SEASON_EN,
            self::SEASON_FR,
            self::SEASON_DE,
            self::SEASON_SUMMER
        ));
        $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'] = true;

        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            $map = [
                self::SEASON_FR => self::SEASON_EN,
                self::SEASON_DE => self::SEASON_EN,
            ];
            if ($lang === 'en' && isset($map[(int) $id])) {
                return $map[(int) $id];
            }
            return $id;
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wpdb'],
            $GLOBALS['intersoccer_test_wpml_filter_get_term_by_to_current_language'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_transients'],
            $GLOBALS['intersoccer_test_object_cache'],
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_test_product_term_ids'],
            $GLOBALS['intersoccer_test_wc_get_orders'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_is_admin']
        );
        MockFilters::reset();
        intersoccer_pv_discount_season_term_id_clear_request_cache();
        parent::tearDown();
    }

    public static function legacyKeys(): array
    {
        return [
            'fr' => ["camps d'automne 2026|2026"],
            'de' => ['herbst camps 2026|2026'],
            'en' => ['autumn camps 2026|2026'],
            'ps' => ['ps:' . self::SEASON_EN . '|2026'],
        ];
    }

    #[DataProvider('legacyKeys')]
    public function test_legacy_and_ps_keys_normalize_same_under_de_and_fr_requests(string $legacy_key): void
    {
        $expected = 'ps:' . self::SEASON_EN . '|2026';
        foreach (['de', 'fr'] as $lang) {
            $GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
            intersoccer_pv_discount_season_term_id_clear_request_cache();
            $this->assertSame($expected, intersoccer_discount_normalize_season_key($legacy_key), "lang=$lang key=$legacy_key");
        }
    }

    public function test_curly_apostrophe_and_html_entity_fr_label_resolve(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $keys = [
            "camps d\u{2019}automne 2026|2026",
            "camps d&#039;automne 2026|2026",
        ];
        foreach ($keys as $key) {
            $this->assertSame(
                'ps:' . self::SEASON_EN . '|2026',
                intersoccer_discount_normalize_season_key($key)
            );
        }
    }

    public function test_unresolvable_key_stays_label_year(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $this->assertSame('unknown season label|2027', intersoccer_discount_normalize_season_key('unknown season label|2027'));
    }

    public function test_different_season_or_year_does_not_match_autumn(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $autumn = intersoccer_discount_normalize_season_key('autumn camps 2026|2026');
        $summer = intersoccer_discount_normalize_season_key('summer camps 2026|2026');
        $autumn_2027 = intersoccer_discount_normalize_season_key('autumn camps 2026|2027');
        $this->assertNotSame($autumn, $summer);
        $this->assertNotSame($autumn, $autumn_2027);
    }

    public function test_sql_lookup_runs_once_per_label_per_request_and_persistent_cache_on_next_request(): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $label = "camps d'automne 2026";
        $this->wpdb->season_lookup_query_count = 0;

        intersoccer_discount_season_term_id_from_label($label);
        intersoccer_discount_season_term_id_from_label($label);
        intersoccer_discount_normalize_season_key($label . '|2026');
        $this->assertSame(1, $this->wpdb->season_lookup_query_count, 'One SQL lookup per label per request');

        intersoccer_pv_discount_season_term_id_clear_request_cache();
        $before = $this->wpdb->season_lookup_query_count;
        $this->assertSame(self::SEASON_EN, intersoccer_discount_season_term_id_from_label($label));
        $this->assertSame($before, $this->wpdb->season_lookup_query_count, 'Second request uses persistent cache, not SQL');
    }

    public function test_legacy_prior_order_cross_language_sibling_discount(): void
    {
        $this->bootstrapDiscountPrograms();
        $GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'] = true;
        $customer_id = 503001;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $GLOBALS['intersoccer_test_options'] = [
            'intersoccer_enable_retroactive_sibling_discounts' => 'yes',
            'intersoccer_enable_retroactive_camp_discounts' => false,
        ];
        [$prior_parent, $prior_variation] = [51732, 51758];
        MockMetaData::$data[$prior_variation]['attribute_pa_booking-type'] = 'full-week';
        $prior_item = new class($prior_parent, $prior_variation) {
            private $parent;
            private $variation;
            public function __construct($parent, $variation)
            {
                $this->parent = $parent;
                $this->variation = $variation;
            }
            public function get_product_id()
            {
                return $this->parent;
            }
            public function get_variation_id()
            {
                return $this->variation;
            }
            public function get_meta($key, $single = true)
            {
                $map = [
                    'assigned_player_id' => 'uuid-child-1',
                    'assigned_attendee' => 'QAKid One',
                    '_intersoccer_discount_season_key' => "camps d'automne 2026|2026",
                ];
                return $map[$key] ?? '';
            }
            public function get_total()
            {
                return 525.0;
            }
            public function get_quantity()
            {
                return 1;
            }
        };
        $order_id = 920503001;
        $prior_order = new class($prior_item, $order_id) {
            private $item;
            private $id;
            public function __construct($item, $id)
            {
                $this->item = $item;
                $this->id = $id;
            }
            public function get_id()
            {
                return $this->id;
            }
            public function get_items()
            {
                return [501 => $this->item];
            }
            public function get_date_created()
            {
                return new class {
                    public function format($f)
                    {
                        return '2026-09-01 10:00:00';
                    }
                };
            }
        };
        $GLOBALS['intersoccer_test_orders'] = [$order_id => $prior_order];
        $GLOBALS['intersoccer_test_order_lang'] = [$order_id => 'fr'];
        $GLOBALS['intersoccer_test_order_customer'] = [$order_id => $customer_id];
        $GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'] = true;
        $GLOBALS['intersoccer_test_wc_get_orders'] = static function ($args = []) {
            $customer_id = isset($args['customer_id']) ? (int) $args['customer_id'] : 0;
            $all_ids = array_keys($GLOBALS['intersoccer_test_orders'] ?? []);
            if ($customer_id > 0) {
                $all_ids = array_values(array_filter($all_ids, static function ($order_id) use ($customer_id) {
                    return (int) ($GLOBALS['intersoccer_test_order_customer'][$order_id] ?? 0) === $customer_id;
                }));
            }
            if (!empty($args['suppress_filters'])) {
                return $all_ids;
            }
            $current = function_exists('apply_filters') ? (string) apply_filters('wpml_current_language', 'en') : 'en';
            $visible_by_lang = [
                'en' => ['en'],
                'fr' => ['en', 'fr'],
                'de' => ['en', 'de'],
            ];
            $visible = $visible_by_lang[$current] ?? ['en'];
            return array_values(array_filter($all_ids, static function ($order_id) use ($visible) {
                $lang = (string) ($GLOBALS['intersoccer_test_order_lang'][$order_id] ?? 'en');
                return in_array($lang, $visible, true);
            }));
        };

        $data = new class() {
            private $p = 525.0;
            public function get_price()
            {
                return $this->p;
            }
            public function set_price($p)
            {
                $this->p = (float) $p;
            }
        };
        $cart = new class($data) {
            public $cart_contents;
            public function __construct($data)
            {
                $this->cart_contents = [
                    'child2' => [
                        'product_id' => 54216,
                        'variation_id' => 54231,
                        'quantity' => 1,
                        'assigned_player' => 2,
                        'assigned_player_id' => 'uuid-child-2',
                        'assigned_attendee' => 'QAKid Two',
                        'base_price' => 525.0,
                        'data' => $data,
                    ],
                ];
            }
            public function get_cart()
            {
                return $this->cart_contents;
            }
            public function get_applied_coupons()
            {
                return [];
            }
        };
        intersoccer_apply_combo_discounts_to_items($cart);
        $this->assertEqualsWithDelta(420.0, $cart->cart_contents['child2']['data']->get_price(), 0.001);
    }

    private function bootstrapDiscountPrograms(): void
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $programs = [
            'en' => [51723, 51724, 'Autumn Camps 2026', '2026', 'full-week'],
            'fr' => [51732, 51758, "Camps d'automne 2026", '2026', 'semaine-complete'],
            'de' => [54216, 54231, 'Herbst Camps 2026', '2026', 'ganze-woche'],
        ];
        $terms = [];
        $term_ids = [];
        $parents = [];
        $by_lang = ['en' => self::SEASON_EN, 'fr' => self::SEASON_FR, 'de' => self::SEASON_DE];
        foreach ($programs as $lang => [$parent, $variation, $season, $year, $booking]) {
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $term_ids[$parent] = ['pa_program-season' => [$by_lang[$lang]]];
            $parents[$variation] = $parent;
            MockMetaData::$data[$variation]['attribute_pa_booking-type'] = $booking;
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
        $GLOBALS['intersoccer_test_product_term_ids'] = $term_ids;
        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) use ($parents) {
            $id = (int) $id;
            $parent = $parents[$id] ?? 0;
            return new class($id, $parent) {
                private $id;
                private $parent;
                public function __construct($id, $parent)
                {
                    $this->id = $id;
                    $this->parent = $parent;
                }
                public function get_id()
                {
                    return $this->id;
                }
                public function get_parent_id()
                {
                    return $this->parent;
                }
                public function get_price()
                {
                    return 525.0;
                }
                public function get_attributes()
                {
                    return [];
                }
                public function get_attribute($n)
                {
                    return '';
                }
                public function is_type($t)
                {
                    return false;
                }
            };
        };
    }
}
