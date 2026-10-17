<?php
/**
 * Progressive camp week-2 discount must be language-invariant (WPML variation identity).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
if (!function_exists('intersoccer_translate_string')) {
    require_once dirname(__DIR__) . '/includes/language-helpers.php';
}
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';

if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}

class CampProgressiveWeekWpmlTest extends TestCase
{
    private const SEASON_TERM_EN = 88001;
    private const SEASON_TERM_FR = 88002;
    private const SEASON_TERM_DE = 88003;

    private const BASE_PRICE = 525.0;
    private const WEEK_2_PRICE = 472.5;
    /** Progressive week 3+ rate (20%) on base price — wrong tier when canonical exclude fails. */
    private const WEEK_3_PLUS_PRICE = 420.0;
    private const CHILD_UUID = 'uuid-progressive-child';

    /** lang => [parent, week1_var, week2_var, season label, year, booking-type] */
    private const WEEKS = [
        'en' => [71001, 71011, 71021, 'Autumn Camps 2026', '2026', 'full-week'],
        'fr' => [71002, 71012, 71022, "Camps d'automne 2026", '2026', 'semaine-complete'],
        'de' => [71003, 71013, 71023, 'Herbst Camps 2026', '2026', 'ganze-woche'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWpmlProductFixtures();
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_options'] = [
            'intersoccer_enable_retroactive_sibling_discounts' => false,
            'intersoccer_enable_retroactive_camp_discounts' => true,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;
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
            $GLOBALS['intersoccer_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_order_lang'],
            $GLOBALS['intersoccer_test_order_customer'],
            $GLOBALS['intersoccer_test_wc_get_orders'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_wpml_simulate_term_filters'],
            $GLOBALS['intersoccer_test_wpml_suppress_term_filters'],
            $GLOBALS['intersoccer_test_wpml_simulate_order_query_filter'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_wpml_term_id_for_current_lang'],
            $GLOBALS['intersoccer_test_wpml_switch_language_count']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        parent::tearDown();
    }

    private function bootstrapWpmlProductFixtures(): void
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
        foreach (self::WEEKS as $lang => [$parent, $week1, $week2, $season, $year, $booking]) {
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $term_ids[$parent] = ['pa_program-season' => [$season_term_by_lang[$lang]]];
            $terms[$week1] = [];
            $terms[$week2] = [];
            $parents[$week1] = $parent;
            $parents[$week2] = $parent;
            MockMetaData::$data[$week1]['attribute_pa_booking-type'] = $booking;
            MockMetaData::$data[$week2]['attribute_pa_booking-type'] = $booking;
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
            $id = (int) $id;
            $default_lang = 'en';
            if ($lang !== null && $lang !== $default_lang) {
                return $id;
            }
            $season_map = [
                self::SEASON_TERM_FR => self::SEASON_TERM_EN,
                self::SEASON_TERM_DE => self::SEASON_TERM_EN,
            ];
            if ($type === 'pa_program-season' || $type === 'term' || $type === null) {
                if (isset($season_map[$id])) {
                    return $season_map[$id];
                }
            }
            $product_map = [
                71002 => 71001,
                71003 => 71001,
            ];
            $variation_map = [
                71012 => 71011,
                71013 => 71011,
                71022 => 71021,
                71023 => 71021,
            ];
            if ($type === 'product') {
                return $product_map[$id] ?? $id;
            }
            if ($type === 'product_variation') {
                return $variation_map[$id] ?? $id;
            }
            return $season_map[$id] ?? $id;
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

    public static function priorAndCartLanguageMatrix(): array
    {
        $cases = [];
        foreach (['en', 'fr', 'de'] as $prior) {
            foreach (['en', 'fr', 'de'] as $cart) {
                $cases["prior $prior cart $cart"] = [$prior, $cart];
            }
        }
        return $cases;
    }

    #[DataProvider('priorAndCartLanguageMatrix')]
    public function testWeek2DiscountInvariantAcrossLanguages(string $prior_lang, string $cart_lang): void
    {
        $customer_id = 720000 + crc32($prior_lang . $cart_lang) % 10000;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = $cart_lang;
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;

        [$prior_parent, $prior_week1] = [self::WEEKS[$prior_lang][0], self::WEEKS[$prior_lang][1]];
        $season_keys = [
            'en' => 'autumn camps 2026|2026',
            'fr' => "camps d'automne 2026|2026",
            'de' => 'herbst camps 2026|2026',
        ];
        $this->seedPriorWeek1Order($customer_id, $prior_lang, $prior_parent, $prior_week1, $season_keys[$prior_lang]);

        $cart = $this->makeSingleChildWeek2Cart($cart_lang);
        intersoccer_apply_combo_discounts_to_items($cart);

        $price = $cart->cart_contents[array_key_first($cart->cart_contents)]['data']->get_price();
        $this->assertEqualsWithDelta(
            self::WEEK_2_PRICE,
            $price,
            0.001,
            "Week-2 progressive rate must match for prior=$prior_lang cart=$cart_lang"
        );
        $this->assertSame(
            0,
            (int) ($GLOBALS['intersoccer_test_wpml_switch_language_count'] ?? 0),
            'Discount lookup must not switch WPML language per order line'
        );
    }

    #[DataProvider('priorAndCartLanguageMatrix')]
    public function testWeek2CartPriceWhenPriorIncludesSameLogicalWeekInOtherLanguage(
        string $prior_lang,
        string $cart_lang
    ): void {
        $customer_id = 731000 + crc32('wk2dup' . $prior_lang . $cart_lang) % 10000;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = $cart_lang;

        $season_keys = [
            'en' => 'autumn camps 2026|2026',
            'fr' => "camps d'automne 2026|2026",
            'de' => 'herbst camps 2026|2026',
        ];
        [$prior_parent, $prior_week1] = [self::WEEKS[$prior_lang][0], self::WEEKS[$prior_lang][1]];
        $prior_week2_lang = self::priorWeek2StorageLangForCart($cart_lang);
        [, , $prior_week2] = self::WEEKS[$prior_week2_lang];

        $this->seedPriorWeek1Order($customer_id, $prior_lang, $prior_parent, $prior_week1, $season_keys[$prior_lang]);
        $this->seedPriorWeek2Order(
            $customer_id,
            $prior_week2_lang,
            self::WEEKS[$prior_week2_lang][0],
            $prior_week2,
            $season_keys[$prior_week2_lang],
            770000 + $customer_id
        );

        $cart = $this->makeSingleChildWeek2Cart($cart_lang);
        intersoccer_apply_combo_discounts_to_items($cart);

        $price = $cart->cart_contents[array_key_first($cart->cart_contents)]['data']->get_price();
        $this->assertEqualsWithDelta(
            self::WEEK_2_PRICE,
            $price,
            0.001,
            "Week-2 tier must apply when prior week-2 is stored in another locale (prior=$prior_lang cart=$cart_lang)"
        );
        $this->assertNotEqualsWithDelta(
            self::WEEK_3_PLUS_PRICE,
            $price,
            0.001,
            'Must not apply week-3+ tier when only one distinct prior week counts'
        );
    }

    public function testSameCartMixedLanguageTwoWeeksAppliesWeek2Discount(): void
    {
        $GLOBALS['intersoccer_test_user_id'] = 730001;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $cart = $this->makeTwoWeekCart('de', 'fr');
        intersoccer_apply_combo_discounts_to_items($cart);

        $this->assertEqualsWithDelta(
            self::BASE_PRICE,
            $cart->cart_contents['week1-de']['data']->get_price(),
            0.001,
            'First cart week stays full price'
        );
        $this->assertEqualsWithDelta(
            self::WEEK_2_PRICE,
            $cart->cart_contents['week2-fr']['data']->get_price(),
            0.001,
            'Second cart week gets 10% progressive (mixed DE+FR)'
        );
    }

    public function testSameCartMixedLanguageWeek2LinePriceWithPriorWeek2StoredInOtherLocale(): void
    {
        $customer_id = 730011;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $season_keys = [
            'fr' => "camps d'automne 2026|2026",
        ];
        $this->seedPriorWeek2Order(
            $customer_id,
            'fr',
            self::WEEKS['fr'][0],
            self::WEEKS['fr'][2],
            $season_keys['fr'],
            770011
        );

        $cart = $this->makeTwoWeekCart('de', 'fr');
        intersoccer_apply_combo_discounts_to_items($cart);

        $this->assertEqualsWithDelta(
            self::WEEK_2_PRICE,
            $cart->cart_contents['week2-fr']['data']->get_price(),
            0.001,
            'Second mixed-locale cart week must stay on week-2 progressive tier'
        );
        $this->assertNotEqualsWithDelta(
            self::WEEK_3_PLUS_PRICE,
            $cart->cart_contents['week2-fr']['data']->get_price(),
            0.001
        );
    }

    public function testPriorOrderStoredAsFrTranslationCartDeWeek2(): void
    {
        $customer_id = 730002;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $season_key = "camps d'automne 2026|2026";
        $this->seedPriorWeek1Order($customer_id, 'fr', self::WEEKS['fr'][0], self::WEEKS['fr'][1], $season_key);

        $cart = $this->makeSingleChildWeek2Cart('de');
        intersoccer_apply_combo_discounts_to_items($cart);
        $price = $cart->cart_contents[array_key_first($cart->cart_contents)]['data']->get_price();
        $this->assertEqualsWithDelta(self::WEEK_2_PRICE, $price, 0.001);
    }

    public function testNoWpmlFallsBackToRawVariationIdsForExclude(): void
    {
        MockFilters::$filters['wpml_default_language'] = static function () {
            return null;
        };
        unset(MockFilters::$filters['wpml_object_id']);

        $customer_id = 730003;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $this->seedPriorWeek1Order($customer_id, 'en', self::WEEKS['en'][0], self::WEEKS['en'][1], 'autumn camps 2026|2026');

        $cart = $this->makeSingleChildWeek2Cart('en');
        intersoccer_apply_combo_discounts_to_items($cart);
        $price = $cart->cart_contents[array_key_first($cart->cart_contents)]['data']->get_price();
        $this->assertEqualsWithDelta(self::WEEK_2_PRICE, $price, 0.001);
    }

    public function testManyPriorOrdersDoNotEmitWpmlLanguageSwitches(): void
    {
        $customer_id = 730004;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;

        $orders = [];
        $order_lang = [];
        $order_customer = [];
        for ($i = 0; $i < 55; $i++) {
            $lang = ['en', 'fr', 'de'][$i % 3];
            [$parent, $week1] = [self::WEEKS[$lang][0], self::WEEKS[$lang][1]];
            $season_keys = [
                'en' => 'autumn camps 2026|2026',
                'fr' => "camps d'automne 2026|2026",
                'de' => 'herbst camps 2026|2026',
            ];
            $order_id = 740000 + $i;
            $orders[$order_id] = $this->buildPriorOrder(
                $parent,
                $week1,
                $season_keys[$lang],
                $order_id,
                'uuid-other-child-' . $i,
                'Decoy Child ' . $i
            );
            $order_lang[$order_id] = $lang;
            $order_customer[$order_id] = $customer_id;
        }
        $GLOBALS['intersoccer_test_orders'] = $orders;
        $GLOBALS['intersoccer_test_order_lang'] = $order_lang;
        $GLOBALS['intersoccer_test_order_customer'] = $order_customer;
        $this->seedPriorWeek1Order($customer_id, 'en', self::WEEKS['en'][0], self::WEEKS['en'][1], 'autumn camps 2026|2026');

        $cart = $this->makeSingleChildWeek2Cart('fr');
        intersoccer_apply_combo_discounts_to_items($cart);

        $this->assertSame(
            0,
            (int) ($GLOBALS['intersoccer_test_wpml_switch_language_count'] ?? 0),
            'Large prior-order lookback must not call wpml_switch_language'
        );
    }

    public function testCanonicalCampBookingIdUsesProductIdWhenVariationAbsent(): void
    {
        $en_parent = self::WEEKS['en'][0];
        $fr_parent = self::WEEKS['fr'][0];
        $this->assertSame(
            intersoccer_discount_wpml_canonical_product_id($en_parent),
            intersoccer_discount_wpml_canonical_camp_booking_id(0, $fr_parent)
        );
        $this->assertSame(
            intersoccer_discount_wpml_canonical_variation_id(self::WEEKS['de'][2]),
            intersoccer_discount_wpml_canonical_camp_booking_id(self::WEEKS['fr'][2], self::WEEKS['fr'][0])
        );
    }

    public function testCanonicalVariationExcludeSkipsSameLogicalWeekAcrossLanguages(): void
    {
        $customer_id = 730005;
        $GLOBALS['intersoccer_test_user_id'] = $customer_id;
        $season_key = 'autumn camps 2026|2026';
        $this->seedPriorWeek1Order($customer_id, 'en', self::WEEKS['en'][0], self::WEEKS['en'][1], $season_key);
        $this->seedPriorWeek2Order($customer_id, 'fr', self::WEEKS['fr'][0], self::WEEKS['fr'][2], $season_key, 750001);

        $cart_item = [
            'assigned_player_id' => self::CHILD_UUID,
            'assigned_attendee' => 'Progressive Child',
            'variation_id' => self::WEEKS['de'][2],
        ];
        $season = intersoccer_discount_season_key(self::WEEKS['de'][2]);
        $previous = intersoccer_get_previous_camps_same_child_season(
            $customer_id,
            $cart_item,
            $season,
            24,
            self::WEEKS['de'][2]
        );
        $position = intersoccer_discount_same_child_week_position($previous, 0);
        $this->assertSame(2, $position, 'Prior week-1 only when week-2 prior matches exclude canonical id');
    }

    private function seedPriorWeek1Order(int $customer_id, string $lang, int $parent, int $variation, string $season_key): void
    {
        $order_id = 760000 + $customer_id + crc32($lang . $variation) % 100;
        $GLOBALS['intersoccer_test_orders'][$order_id] = $this->buildPriorOrder($parent, $variation, $season_key, $order_id);
        $GLOBALS['intersoccer_test_order_lang'][$order_id] = $lang;
        $GLOBALS['intersoccer_test_order_customer'][$order_id] = $customer_id;
    }

    private function seedPriorWeek2Order(int $customer_id, string $lang, int $parent, int $variation, string $season_key, int $order_id): void
    {
        $GLOBALS['intersoccer_test_orders'][$order_id] = $this->buildPriorOrder($parent, $variation, $season_key, $order_id);
        $GLOBALS['intersoccer_test_order_lang'][$order_id] = $lang;
        $GLOBALS['intersoccer_test_order_customer'][$order_id] = $customer_id;
    }

    private static function priorWeek2StorageLangForCart(string $cart_lang): string
    {
        $map = ['en' => 'fr', 'fr' => 'de', 'de' => 'fr'];
        return $map[$cart_lang] ?? 'fr';
    }

    private function buildPriorOrder(
        int $parent,
        int $variation,
        string $season_key,
        int $order_id,
        string $player_uuid = self::CHILD_UUID,
        string $attendee_name = 'Progressive Child'
    ) {
        $prior_item = new class($parent, $variation, $season_key, $player_uuid, $attendee_name) {
            private $parent;
            private $variation;
            private $season_key;
            private $player_uuid;
            private $attendee_name;
            public function __construct($parent, $variation, $season_key, $player_uuid, $attendee_name) {
                $this->parent = $parent;
                $this->variation = $variation;
                $this->season_key = $season_key;
                $this->player_uuid = $player_uuid;
                $this->attendee_name = $attendee_name;
            }
            public function get_product_id() { return $this->parent; }
            public function get_variation_id() { return $this->variation; }
            public function get_meta($key, $single = true) {
                $map = [
                    'assigned_player_id' => $this->player_uuid,
                    'assigned_attendee' => $this->attendee_name,
                    '_intersoccer_discount_season_key' => $this->season_key,
                ];
                return $map[$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };
        return new class($prior_item, $order_id) {
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
    }

    private function makeSingleChildWeek2Cart(string $cart_lang)
    {
        [, , $week2] = self::WEEKS[$cart_lang];
        [$parent] = [self::WEEKS[$cart_lang][0]];
        return $this->wrapCart([
            'week2-' . $cart_lang => $this->cartLine($parent, $week2),
        ]);
    }

    private function makeTwoWeekCart(string $lang_a, string $lang_b)
    {
        [$parent_a, $week1_a] = [self::WEEKS[$lang_a][0], self::WEEKS[$lang_a][1]];
        [$parent_b, , $week2_b] = self::WEEKS[$lang_b];
        return $this->wrapCart([
            'week1-' . $lang_a => $this->cartLine($parent_a, $week1_a),
            'week2-' . $lang_b => $this->cartLine($parent_b, $week2_b),
        ]);
    }

    private function cartLine(int $parent, int $variation): array
    {
        $data = new class(self::BASE_PRICE) {
            private $p;
            public function __construct($p) { $this->p = (float) $p; }
            public function get_price() { return $this->p; }
            public function set_price($p) { $this->p = (float) $p; }
        };
        return [
            'product_id' => $parent,
            'variation_id' => $variation,
            'quantity' => 1,
            'assigned_player' => 0,
            'assigned_player_id' => self::CHILD_UUID,
            'assigned_attendee' => 'Progressive Child',
            'base_price' => self::BASE_PRICE,
            'data' => $data,
        ];
    }

    private function wrapCart(array $contents)
    {
        return new class($contents) {
            public $cart_contents;
            public function __construct($c) { $this->cart_contents = $c; }
            public function get_cart() { return $this->cart_contents; }
            public function get_applied_coupons() { return []; }
        };
    }
}
