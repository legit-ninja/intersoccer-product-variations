<?php
/**
 * Cross-order camp sibling discount: prior order in one WPML language, cart in another.
 * Mocks WPML order-query language filtering and get_term translation unless suppressed.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

if (!function_exists('did_action')) {
    function did_action($hook) { return 0; }
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('intersoccer_translate_string')) {
    function intersoccer_translate_string($text, $domain = null, $fallback = null) {
        return $fallback !== null ? $fallback : $text;
    }
}
if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}

class SiblingDiscountCrossOrderWpmlTest extends TestCase
{
    private const SEASON_TERM_EN = 88001;
    private const SEASON_TERM_FR = 88002;
    private const SEASON_TERM_DE = 88003;

    private const PROGRAMS = [
        'en' => [51723, 51724, 'Autumn Camps 2026', '2026', 'full-week'],
        'fr' => [51732, 51758, "Camps d'automne 2026", '2026', 'semaine-complete'],
        'de' => [54216, 54231, 'Herbst Camps 2026', '2026', 'ganze-woche'],
    ];

    private const BASE_PRICE = 525.0;
    private const SIBLING_RATE = 0.20;
    /** Second child in cart after a prior full-price sibling order. */
    private const EXPECTED_CART_LINE = 420.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWpmlFixtures();
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_options'] = [
            'intersoccer_enable_retroactive_sibling_discounts' => 'yes',
            'intersoccer_enable_retroactive_camp_discounts' => false,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset(
            $GLOBALS['intersoccer_test_disable_discount_order_suppress_filters'],
            $GLOBALS['intersoccer_test_disable_discount_term_default_lang_read']
        );
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_test_product_term_ids'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_order_lang'],
            $GLOBALS['intersoccer_test_wc_get_orders'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_wpml_simulate_term_filters'],
            $GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_wpml_suppress_term_filters'],
            $GLOBALS['intersoccer_test_wpml_term_id_for_current_lang'],
            $GLOBALS['intersoccer_test_disable_discount_order_suppress_filters'],
            $GLOBALS['intersoccer_test_disable_discount_term_default_lang_read']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        parent::tearDown();
    }

    private function bootstrapWpmlFixtures(): void
    {
        $terms = [];
        $term_ids = [];
        $parents = [];
        $season_term_by_lang = [
            'en' => self::SEASON_TERM_EN,
            'fr' => self::SEASON_TERM_FR,
            'de' => self::SEASON_TERM_DE,
        ];
        $GLOBALS['intersoccer_test_terms'] = [
            self::SEASON_TERM_EN => ['name' => 'Autumn Camps 2026', 'taxonomy' => 'pa_program-season'],
            self::SEASON_TERM_FR => ['name' => "Camps d'automne 2026", 'taxonomy' => 'pa_program-season'],
            self::SEASON_TERM_DE => ['name' => 'Herbst Camps 2026', 'taxonomy' => 'pa_program-season'],
        ];
        foreach (self::PROGRAMS as $lang => [$parent, $variation, $season, $year, $booking]) {
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $term_ids[$parent] = ['pa_program-season' => [$season_term_by_lang[$lang]]];
            $terms[$variation] = [];
            $parents[$variation] = $parent;
            MockMetaData::$data[$variation]['attribute_pa_booking-type'] = $booking;
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
        $GLOBALS['intersoccer_test_product_term_ids'] = $term_ids;
        $GLOBALS['intersoccer_test_wpml_simulate_term_filters'] = true;
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = false;
        $GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'] = true;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $GLOBALS['intersoccer_test_wpml_term_id_for_current_lang'] = static function ($term_id) {
            $term_id = (int) $term_id;
            $current = function_exists('apply_filters') ? (string) apply_filters('wpml_current_language', 'en') : 'en';
            $en = self::SEASON_TERM_EN;
            $fr = self::SEASON_TERM_FR;
            $de = self::SEASON_TERM_DE;
            $to_en = [$fr => $en, $de => $en];
            $canonical = $to_en[$term_id] ?? $term_id;
            if ($current === 'en') {
                return $canonical;
            }
            if ($current === 'fr' && $canonical === $en) {
                return $fr;
            }
            if ($current === 'de' && $canonical === $en) {
                return $de;
            }
            return $term_id;
        };
        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
        };
        MockFilters::$filters['wpml_current_language'] = static function () {
            return $GLOBALS['intersoccer_test_wpml_current_language'] ?? 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
            $map = [
                self::SEASON_TERM_FR => self::SEASON_TERM_EN,
                self::SEASON_TERM_DE => self::SEASON_TERM_EN,
            ];
            if ($lang === 'en' && isset($map[(int) $id])) {
                return $map[(int) $id];
            }
            return $id;
        };
        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) use ($parents) {
            $id = (int) $id;
            $parent = $parents[$id] ?? 0;
            return new class($id, $parent) {
                private $id;
                private $parent;
                public function __construct($id, $parent) { $this->id = $id; $this->parent = $parent; }
                public function get_id() { return $this->id; }
                public function get_parent_id() { return $this->parent; }
                public function get_price() { return 525.0; }
                public function get_attributes() { return []; }
                public function get_attribute($n) { return ''; }
                public function is_type($t) { return false; }
                public function get_name() { return 'Geneva Autumn Camps 2026'; }
            };
        };
        $GLOBALS['intersoccer_test_wc_get_orders'] = static function ($args = []) {
            if (empty($GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'])) {
                return array_keys($GLOBALS['intersoccer_test_orders'] ?? []);
            }
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
    }

    public static function priorCartLanguagePairs(): array
    {
        return [
            'DE prior, FR cart' => ['de', 'fr'],
            'FR prior, DE cart' => ['fr', 'de'],
            'EN prior, DE cart' => ['en', 'de'],
            'EN prior, FR cart' => ['en', 'fr'],
        ];
    }

    /**
     * @dataProvider priorCartLanguagePairs
     */
    public function testCrossOrderSiblingDiscountAcrossLanguages(string $prior_lang, string $cart_lang): void
    {
        $customer_id = 880000 + crc32($prior_lang . $cart_lang) % 1000;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = $cart_lang;

        [$prior_parent, $prior_variation, , , $prior_booking] = self::PROGRAMS[$prior_lang];
        $season_keys = [
            'en' => 'autumn camps 2026|2026',
            'fr' => "camps d'automne 2026|2026",
            'de' => 'herbst camps 2026|2026',
        ];
        $prior_item = new class($prior_parent, $prior_variation, $season_keys[$prior_lang], $prior_booking) {
            private $parent;
            private $variation;
            private $season_key;
            private $booking;
            public function __construct($parent, $variation, $season_key, $booking) {
                $this->parent = $parent;
                $this->variation = $variation;
                $this->season_key = $season_key;
                $this->booking = $booking;
            }
            public function get_product_id() { return $this->parent; }
            public function get_variation_id() { return $this->variation; }
            public function get_meta($key, $single = true) {
                $map = [
                    'assigned_player_id' => 'uuid-child-1',
                    'assigned_attendee' => 'QAKid One',
                    '_intersoccer_discount_season_key' => $this->season_key,
                ];
                return $map[$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };
        if ($prior_lang === 'de') {
            MockMetaData::$data[$prior_variation]['attribute_pa_booking-type'] = 'full-week';
        }
        $order_id = 91000 + $customer_id;
        $prior_order = new class($prior_item, $order_id) {
            private $item;
            private $id;
            public function __construct($item, $id) { $this->item = $item; $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_items() { return [501 => $this->item]; }
            public function get_date_created() {
                return new class {
                    public function format($f) { return '2026-09-01 10:00:00'; }
                };
            }
        };
        $GLOBALS['intersoccer_test_orders'] = [$order_id => $prior_order];
        $GLOBALS['intersoccer_test_order_lang'] = [$order_id => $prior_lang];
        $GLOBALS['intersoccer_test_order_customer'] = [$order_id => $customer_id];

        $cart = $this->makeSecondChildCart($cart_lang);

        intersoccer_apply_combo_discounts_to_items($cart);
        $this->assertCrossOrderCartDiscount($cart, "prior=$prior_lang cart=$cart_lang");
    }

    public static function crossOrderWpmlBreakPairs(): array
    {
        return [
            'DE prior, FR cart' => ['de', 'fr'],
            'FR prior, DE cart' => ['fr', 'de'],
        ];
    }

    /**
     * @dataProvider crossOrderWpmlBreakPairs
     */
    public function testCrossOrderFailsWithoutWpmlOrderSuppressFilters(string $prior_lang, string $cart_lang): void
    {
        $GLOBALS['intersoccer_test_disable_discount_order_suppress_filters'] = true;
        $customer_id = 890000 + crc32('nosuppress' . $prior_lang . $cart_lang) % 1000;
        $this->runCrossOrderScenario($prior_lang, $cart_lang, $customer_id, false);
    }

    /**
     * @dataProvider crossOrderWpmlBreakPairs
     */
    public function testCrossOrderFailsWithoutDefaultLanguageTermRead(string $prior_lang, string $cart_lang): void
    {
        $GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'] = true;
        $customer_id = 891000 + crc32('noterm' . $prior_lang . $cart_lang) % 1000;
        $this->runCrossOrderScenario($prior_lang, $cart_lang, $customer_id, false);
    }

    private function runCrossOrderScenario(string $prior_lang, string $cart_lang, int $customer_id, bool $expect_success): void
    {
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = $cart_lang;
        [$prior_parent, $prior_variation] = self::PROGRAMS[$prior_lang];
        $season_keys = [
            'en' => 'autumn camps 2026|2026',
            'fr' => "camps d'automne 2026|2026",
            'de' => 'herbst camps 2026|2026',
        ];
        $prior_item = new class($prior_parent, $prior_variation, $season_keys[$prior_lang]) {
            private $parent;
            private $variation;
            private $season_key;
            public function __construct($parent, $variation, $season_key) {
                $this->parent = $parent;
                $this->variation = $variation;
                $this->season_key = $season_key;
            }
            public function get_product_id() { return $this->parent; }
            public function get_variation_id() { return $this->variation; }
            public function get_meta($key, $single = true) {
                $map = [
                    'assigned_player_id' => 'uuid-child-1',
                    'assigned_attendee' => 'QAKid One',
                    '_intersoccer_discount_season_key' => $this->season_key,
                ];
                return $map[$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };
        if ($prior_lang === 'de') {
            MockMetaData::$data[$prior_variation]['attribute_pa_booking-type'] = 'full-week';
        }
        $order_id = 92000 + $customer_id;
        $prior_order = new class($prior_item, $order_id) {
            private $item;
            private $id;
            public function __construct($item, $id) { $this->item = $item; $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_items() { return [501 => $this->item]; }
            public function get_date_created() {
                return new class {
                    public function format($f) { return '2026-09-01 10:00:00'; }
                };
            }
        };
        $GLOBALS['intersoccer_test_orders'] = [$order_id => $prior_order];
        $GLOBALS['intersoccer_test_order_lang'] = [$order_id => $prior_lang];
        $GLOBALS['intersoccer_test_order_customer'] = [$order_id => $customer_id];

        $cart = $this->makeSecondChildCart($cart_lang);

        intersoccer_apply_combo_discounts_to_items($cart);
        if ($expect_success) {
            $this->assertCrossOrderCartDiscount($cart, "prior=$prior_lang cart=$cart_lang");
        } else {
            $total = 0.0;
            foreach ($cart->cart_contents as $line) {
                $total += $line['data']->get_price();
            }
            $this->assertNotEqualsWithDelta(
                self::EXPECTED_CART_LINE,
                $total,
                0.001,
                'Broken WPML handling must not apply the 20% sibling rate to the cart line'
            );
        }
    }

    private function makeSecondChildCart(string $cart_lang)
    {
        [$parent, $variation] = self::PROGRAMS[$cart_lang];
        $data = new class(self::BASE_PRICE) {
            private $p;
            public function __construct($p) { $this->p = (float) $p; }
            public function get_price() { return $this->p; }
            public function set_price($p) { $this->p = (float) $p; }
        };
        $line = [
            'product_id' => $parent,
            'variation_id' => $variation,
            'quantity' => 1,
            'assigned_player' => 1,
            'assigned_player_id' => 'uuid-child-2',
            'assigned_attendee' => 'QAKid Two',
            'base_price' => self::BASE_PRICE,
            'data' => $data,
        ];
        return new class(['line-child2-' . $cart_lang => $line]) {
            public $cart_contents;
            public function __construct($c) { $this->cart_contents = $c; }
            public function get_cart() { return $this->cart_contents; }
            public function get_applied_coupons() { return []; }
        };
    }

    private function assertCrossOrderCartDiscount($cart, string $context): void
    {
        $prices = [];
        $percents = [];
        foreach ($cart->cart_contents as $key => $line) {
            $percents[$key] = (float) ($line['intersoccer_sibling_percent'] ?? 0);
            $prices[$key] = $line['data']->get_price();
        }
        $discounted = array_filter($percents, static function ($p) {
            return abs($p - self::SIBLING_RATE) < 1e-9;
        });
        $this->assertCount(1, $discounted, "$context: expected 20% sibling on cart line. percents=" . json_encode($percents));
        $this->assertEqualsWithDelta(
            self::EXPECTED_CART_LINE,
            array_sum($prices),
            0.001,
            "$context: expected 420 (20% off 525) with prior sibling at 525"
        );
    }
}
