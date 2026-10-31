<?php
/**
 * Repro for two QA findings on PR #99 @ 12cbaee (fix/sibling-discount-xlang).
 *
 * A) Language cookie: each do_action('wpml_switch_language', $code) runs WPML's
 *    wpml_switch_language_action() -> SitePress::switch_lang($code, true), and with
 *    $cookie_lang = true WPML sends a wp-wpml_current_language Set-Cookie whenever the value changes
 *    (logged-in, non-AJAX request). 12cbaee reads every season term inside
 *    intersoccer_discount_wpml_execute_in_language('en', ...), which switches away and back, so one
 *    non-EN price calculation emits 2 Set-Cookie headers per term read and grows with order history.
 *    Each switch in that helper changes the language (it only switches when previous !== target), so the
 *    number of Set-Cookie headers equals the number of wpml_switch_language calls counted here.
 *    Expected: a price calculation never switches the visitor's language (0 calls; at most 1 tolerated).
 *
 * B) Same-week duplicate check: intersoccer_get_previous_camps_same_child_season() excludes a prior item
 *    only when its RAW variation_id equals the cart's. The same logical week booked earlier from FR is
 *    therefore excluded in the FR view, but counted as "week 1" in the EN and DE views, which then show a
 *    10% Camp Week 2 discount. Expected: the same price in EN, FR and DE views.
 *
 * Fixtures are synthetic (no real sandbox IDs). Cart shape mirrors the sandbox: variation
 * attribute_pa_booking-type 'full-week' (EN, FR) / 'ganze-woche' (DE), WPML-translated variation IDs,
 * parent-only pa_program-season / pa_program-year, default language EN, orders filtered by WPML unless
 * suppress_filters.
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

if (!function_exists('is_admin')) {
    function is_admin() { return !empty($GLOBALS['intersoccer_test_is_admin']); }
}
if (!function_exists('did_action')) {
    function did_action($hook) { return 0; }
}
if (!function_exists('intersoccer_translate_string')) {
    function intersoccer_translate_string($text, $domain = null, $fallback = null) {
        return $fallback !== null ? $fallback : $text;
    }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}
if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}

class Pv99CookieAndSameWeekReproTest extends TestCase
{
    private const TERM_EN = 98101;
    private const TERM_FR = 98102;
    private const TERM_DE = 98103;
    private const BASE = 525.0;
    private const WEEK2 = 472.5;
    private const CHILD = 'uuid-repro-pv99-child';

    /** lang => [parent, week4 variation, week3 variation, season label, booking-type meta] */
    private const P = [
        'en' => [91001, 91011, 91021, 'Autumn Camps 2026', 'full-week'],
        'fr' => [91002, 91012, 91022, "Camps d'automne 2026", 'full-week'],
        'de' => [91003, 91013, 91023, 'Herbst Camps 2026', 'ganze-woche'],
    ];
    private const STORED_KEY = [
        'en' => 'autumn camps 2026|2026',
        'fr' => "camps d'automne 2026|2026",
        'de' => 'herbst camps 2026|2026',
    ];

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_options'] = [
            'intersoccer_enable_retroactive_sibling_discounts' => false,
            'intersoccer_enable_retroactive_camp_discounts' => true,
        ];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_orders'] = [];
        $GLOBALS['intersoccer_test_order_lang'] = [];
        $GLOBALS['intersoccer_test_order_customer'] = [];
        unset(
            $GLOBALS['intersoccer_test_disable_discount_order_suppress_filters'],
            $GLOBALS['intersoccer_test_disable_discount_term_default_lang_read'],
            $GLOBALS['intersoccer_test_products']
        );
        $this->fixtures();
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;
    }

    protected function tearDown(): void
    {
        foreach ([
            'intersoccer_test_product_type', 'intersoccer_test_product_terms', 'intersoccer_test_product_term_ids',
            'intersoccer_test_terms', 'intersoccer_wc_get_product_callback', 'intersoccer_test_options',
            'intersoccer_test_orders', 'intersoccer_test_order_lang', 'intersoccer_test_order_customer',
            'intersoccer_test_wc_get_orders', 'intersoccer_test_user_id', 'intersoccer_test_wpml_simulate_term_filters',
            'intersoccer_test_wpml_suppress_term_filters', 'intersoccer_test_wpml_current_language',
            'intersoccer_test_wpml_term_id_for_current_lang', 'intersoccer_test_wpml_switch_language_count',
            'intersoccer_test_is_admin', 'wpdb',
            'intersoccer_test_transients', 'intersoccer_test_object_cache',
        ] as $g) {
            unset($GLOBALS[$g]);
        }
        MockMetaData::$data = [];
        MockFilters::reset();
        parent::tearDown();
    }

    private function fixtures(): void
    {
        intersoccer_pv_test_bootstrap_season_sql_wpdb(WpdbSeasonSqlMock::autumn_2026_wpml_fixture(
            self::TERM_EN,
            self::TERM_FR,
            self::TERM_DE
        ));
        $by_lang = ['en' => self::TERM_EN, 'fr' => self::TERM_FR, 'de' => self::TERM_DE];
        $GLOBALS['intersoccer_test_terms'] = [];
        $terms = $term_ids = $parents = [];
        foreach (self::P as $lang => [$parent, $w4, $w3, $season, $booking]) {
            $GLOBALS['intersoccer_test_terms'][$by_lang[$lang]] = ['name' => $season, 'taxonomy' => 'pa_program-season'];
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => ['2026']];
            $term_ids[$parent] = ['pa_program-season' => [$by_lang[$lang]]];
            foreach ([$w4, $w3] as $v) {
                $terms[$v] = [];
                $parents[$v] = $parent;
                MockMetaData::$data[$v]['attribute_pa_booking-type'] = $booking;
            }
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
        $GLOBALS['intersoccer_test_product_term_ids'] = $term_ids;
        $GLOBALS['intersoccer_test_wpml_simulate_term_filters'] = true;
        $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = false;
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        // WPML term filter: get_term() returns the term translated into the current language.
        $GLOBALS['intersoccer_test_wpml_term_id_for_current_lang'] = static function ($id) use ($by_lang) {
            $id = (int) $id;
            if (!in_array($id, $by_lang, true)) {
                return $id;
            }
            $cur = (string) apply_filters('wpml_current_language', 'en');
            return $by_lang[$cur] ?? $id;
        };

        $to_en = [
            'product' => [91002 => 91001, 91003 => 91001],
            'product_variation' => [91012 => 91011, 91013 => 91011, 91022 => 91021, 91023 => 91021],
            'pa_program-season' => [self::TERM_FR => self::TERM_EN, self::TERM_DE => self::TERM_EN],
        ];
        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () { return 'en'; };
        MockFilters::$filters['wpml_current_language'] = static function () {
            return $GLOBALS['intersoccer_test_wpml_current_language'] ?? 'en';
        };
        MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $orig = null, $lang = null) use ($to_en) {
            $id = (int) $id;
            if ($lang !== null && $lang !== 'en') {
                return $id;
            }
            return $to_en[$type][$id] ?? $id;
        };
        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) use ($parents) {
            $id = (int) $id;
            $parent = $parents[$id] ?? 0;
            return new class($id, $parent) {
                private $id; private $parent;
                public function __construct($id, $parent) { $this->id = $id; $this->parent = $parent; }
                public function get_id() { return $this->id; }
                public function get_parent_id() { return $this->parent; }
                public function get_price() { return 525.0; }
                public function get_attributes() { return []; }
                public function get_attribute($n) { return ''; }
                public function is_type($t) { return false; }
                public function get_name() { return 'Repro Autumn Camps 2026'; }
            };
        };
        // WPML order query filter: current language + EN fallback, unless suppress_filters.
        $GLOBALS['intersoccer_test_wc_get_orders'] = static function ($args = []) {
            $cid = (int) ($args['customer_id'] ?? 0);
            $ids = array_values(array_filter(array_keys($GLOBALS['intersoccer_test_orders'] ?? []), static function ($o) use ($cid) {
                return (int) ($GLOBALS['intersoccer_test_order_customer'][$o] ?? 0) === $cid;
            }));
            if (!empty($args['suppress_filters'])) {
                return $ids;
            }
            $cur = (string) apply_filters('wpml_current_language', 'en');
            $visible = $cur === 'en' ? ['en'] : ['en', $cur];
            return array_values(array_filter($ids, static function ($o) use ($visible) {
                return in_array((string) ($GLOBALS['intersoccer_test_order_lang'][$o] ?? 'en'), $visible, true);
            }));
        };
    }

    private function newCustomer(): int
    {
        return 990000 + (++self::$seq) * 10;
    }

    private function seed(int $cid, string $lang, int $variation, string $player = self::CHILD): void
    {
        $order_id = 880000 + (++self::$seq);
        $parent = self::P[$lang][0];
        $key = self::STORED_KEY[$lang];
        $item = new class($parent, $variation, $key, $player) {
            private $p; private $v; private $k; private $u;
            public function __construct($p, $v, $k, $u) { $this->p = $p; $this->v = $v; $this->k = $k; $this->u = $u; }
            public function get_product_id() { return $this->p; }
            public function get_variation_id() { return $this->v; }
            public function get_meta($key, $single = true) {
                return ['assigned_player_id' => $this->u, 'assigned_attendee' => 'Repro ' . $this->u,
                        '_intersoccer_discount_season_key' => $this->k][$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };
        $GLOBALS['intersoccer_test_orders'][$order_id] = new class($item, $order_id) {
            private $i; private $id;
            public function __construct($i, $id) { $this->i = $i; $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_items() { return [1 => $this->i]; }
            public function get_date_created() { return new class { public function format($f) { return '2026-09-01 10:00:00'; } }; }
        };
        $GLOBALS['intersoccer_test_order_lang'][$order_id] = $lang;
        $GLOBALS['intersoccer_test_order_customer'][$order_id] = $cid;
    }

    private function cart(string $lang, int $variation)
    {
        $data = new class(self::BASE) {
            private $p;
            public function __construct($p) { $this->p = (float) $p; }
            public function get_price() { return $this->p; }
            public function set_price($p) { $this->p = (float) $p; }
        };
        return new class(['line-' . $lang => [
            'product_id' => self::P[$lang][0], 'variation_id' => $variation, 'quantity' => 1,
            'assigned_player' => 0, 'assigned_player_id' => self::CHILD, 'assigned_attendee' => 'Repro ' . self::CHILD,
            'base_price' => self::BASE, 'data' => $data,
        ]]) {
            public $cart_contents;
            public function __construct($c) { $this->cart_contents = $c; }
            public function get_cart() { return $this->cart_contents; }
            public function get_applied_coupons() { return []; }
        };
    }

    /** Price-calculate a one-line cart in $view_lang and return [line price, wpml_switch_language calls]. */
    private function priceIn(string $view_lang, int $cid, int $variation): array
    {
        $GLOBALS['intersoccer_test_user_id'] = $cid;
        $GLOBALS['intersoccer_test_wpml_current_language'] = $view_lang;
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;
        $cart = $this->cart($view_lang, $variation);
        intersoccer_apply_combo_discounts_to_items($cart);
        $line = reset($cart->cart_contents);
        return [(float) $line['data']->get_price(), (int) $GLOBALS['intersoccer_test_wpml_switch_language_count'],
                (string) $GLOBALS['intersoccer_test_wpml_current_language']];
    }

    // ---------------------------------------------------------------- A: language cookie

    public function testHarnessCountsWpmlSwitchLanguage(): void
    {
        $GLOBALS['intersoccer_test_wpml_switch_language_count'] = 0;
        do_action('wpml_switch_language', 'fr');
        $this->assertSame(1, (int) $GLOBALS['intersoccer_test_wpml_switch_language_count'],
            'tests/bootstrap.php must count wpml_switch_language, otherwise case A cannot fail');
    }

    public static function cookieCases(): array
    {
        return [
            'FR view, 1 prior order'   => ['fr', 1],
            'FR view, 10 prior orders' => ['fr', 10],
            'FR view, 50 prior orders' => ['fr', 50],
            'DE view, 50 prior orders' => ['de', 50],
            'control EN view, 50 prior orders' => ['en', 50],
            'FR view, no order history (cart-only reads)' => ['fr', 0],
        ];
    }

    #[DataProvider('cookieCases')]
    public function testPriceCalculationDoesNotSwitchVisitorLanguage(string $view, int $orders): void
    {
        $cid = $this->newCustomer();
        for ($i = 0; $i < $orders; $i++) {
            $lang = ['en', 'fr', 'de'][$i % 3];
            $this->seed($cid, $lang, self::P[$lang][2], 'uuid-decoy-' . $i); // other children, week 3
        }
        [$price, $switches, $after] = $this->priceIn($view, $cid, self::P[$view][1]);
        $this->assertSame($view, $after, 'visitor language must be unchanged after the price calculation');
        $this->assertLessThanOrEqual(1, $switches, sprintf(
            '%s view with %d prior orders: %d wpml_switch_language calls = %d wp-wpml_current_language Set-Cookie headers '
            . '(WPML switch_lang($code, true)); expected at most 1, ideally 0', $view, $orders, $switches, $switches));
        $this->assertSame(0, $switches, 'a price calculation should not touch the language cookie at all');
    }

    // ---------------------------------------------------------------- B: same-week duplicate check

    public static function views(): array
    {
        return ['EN view' => ['en'], 'FR view' => ['fr'], 'DE view' => ['de']];
    }

    /**
     * Same child already booked week 4 from the FR site; the same week 4 is in the cart again
     * (variation shown in the view language). Must give one result in every view.
     */
    #[DataProvider('views')]
    public function testSameWeekBookedInFrIsLanguageInvariant(string $view): void
    {
        $results = [];
        foreach (['en', 'fr', 'de'] as $v) {
            $cid = $this->newCustomer();
            $this->seed($cid, 'fr', self::P['fr'][1]);           // prior: FR week 4
            [$price] = $this->priceIn($v, $cid, self::P[$v][1]); // cart: week 4 in view language
            $results[$v] = $price;
        }
        $this->assertEqualsWithDelta($results['fr'], $results[$view], 0.001,
            "same-week prior FR booking: $view price must equal FR view price; prices by view=" . json_encode($results));
        $this->assertEqualsWithDelta(self::BASE, $results[$view], 0.001,
            "the same logical week must not count as a prior week (no 10% Camp Week 2); prices by view=" . json_encode($results));
    }

    /** Control: prior week 3 (a different week) gives week-2 10% in every view. */
    #[DataProvider('views')]
    public function testControlDifferentPriorWeekGivesWeek2InEveryView(string $view): void
    {
        $cid = $this->newCustomer();
        $this->seed($cid, 'fr', self::P['fr'][2]); // prior: FR week 3
        [$price] = $this->priceIn($view, $cid, self::P[$view][1]);
        $this->assertEqualsWithDelta(self::WEEK2, $price, 0.001, "$view: different prior week must give 10% week-2 price");
    }

    /** Control: prior and cart in the same language (raw IDs equal) is excluded in every language. */
    #[DataProvider('views')]
    public function testControlSameWeekSameLanguageIsExcluded(string $view): void
    {
        $cid = $this->newCustomer();
        $this->seed($cid, $view, self::P[$view][1]);
        [$price] = $this->priceIn($view, $cid, self::P[$view][1]);
        $this->assertEqualsWithDelta(self::BASE, $price, 0.001, "$view: same-language same week must be excluded");
    }
}
