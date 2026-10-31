<?php
/**
 * Repro (failing by design until fixed): camp sibling discount must combine across
 * WPML languages. Same customer, two children, the same programme (Geneva Autumn Camps
 * 2026, Varembé, Autumn Week 4, 5-13y full day, full week) booked from different site
 * languages.
 *
 * Fixture values come from Tess's sandbox evidence (qa-pv97-rr107-71ab56a-269d2ac: specs.json
 * variation attributes, SUMMARY.md season keys). Not yet re-verified by read-only lookup; run
 * tests/repro/sibling-discount-cross-language.php against the sandbox to confirm:
 *   EN parent 51723 / variation 51724  season "Autumn Camps 2026"       booking-type "full-week"
 *   FR parent 51732 / variation 51758  season "Camps d'automne 2026"    booking-type "semaine-complete"
 *   DE parent 54216 / variation 54231  season "Herbst Camps 2026"       booking-type "ganze-woche"
 *   pa_program-year "2026" in all three languages.
 *
 * Expected: the 2nd child gets the 20% camp sibling rate in every combination.
 * Controls EN+EN and DE+DE pass today. FR+FR, DE+FR, EN+DE, FR+EN fail.
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

class SiblingDiscountCrossLanguageTest extends TestCase
{
    private const SEASON_TERM_EN = 88001;
    private const SEASON_TERM_FR = 88002;
    private const SEASON_TERM_DE = 88003;

    /** lang => [parent, variation, season term name, program-year term name, booking-type slug] */
    private const PROGRAMS = [
        'en' => [51723, 51724, 'Autumn Camps 2026', '2026', 'full-week'],
        'fr' => [51732, 51758, "Camps d'automne 2026", '2026', 'semaine-complete'],
        'de' => [54216, 54231, 'Herbst Camps 2026', '2026', 'ganze-woche'],
    ];

    private const BASE_PRICE = 525.0;
    private const SIBLING_RATE = 0.20;

    protected function setUp(): void
    {
        parent::setUp();
        intersoccer_pv_test_bootstrap_season_sql_wpdb(WpdbSeasonSqlMock::autumn_2026_wpml_fixture(
            self::SEASON_TERM_EN,
            self::SEASON_TERM_FR,
            self::SEASON_TERM_DE
        ));
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        // Deterministic globals regardless of suite order (other tests set these).
        $GLOBALS['intersoccer_test_options'] = [];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset($GLOBALS['intersoccer_test_user_id'], $GLOBALS['intersoccer_test_products']);

        $terms = [];
        $term_ids = [];
        $parents = [];
        $season_term_by_lang = [
            'en' => self::SEASON_TERM_EN,
            'fr' => self::SEASON_TERM_FR,
            'de' => self::SEASON_TERM_DE,
        ];
        $GLOBALS['intersoccer_test_terms'] = [
            self::SEASON_TERM_EN => [
                'name' => 'Autumn Camps 2026',
                'taxonomy' => 'pa_program-season',
            ],
            self::SEASON_TERM_FR => [
                'name' => "Camps d'automne 2026",
                'taxonomy' => 'pa_program-season',
            ],
            self::SEASON_TERM_DE => [
                'name' => 'Herbst Camps 2026',
                'taxonomy' => 'pa_program-season',
            ],
        ];
        foreach (self::PROGRAMS as $lang => [$parent, $variation, $season, $year, $booking]) {
            // Season/year are parent-only taxonomies (not copied to variations).
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $term_ids[$parent] = ['pa_program-season' => [$season_term_by_lang[$lang]]];
            $terms[$variation] = [];
            $parents[$variation] = $parent;
            MockMetaData::$data[$variation]['attribute_pa_booking-type'] = $booking;
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
        $GLOBALS['intersoccer_test_product_term_ids'] = $term_ids;
        MockFilters::reset();
        MockFilters::$filters['wpml_default_language'] = static function () {
            return 'en';
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
                private $id; private $parent;
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
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_wc_get_orders'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['wpdb'],
            $GLOBALS['intersoccer_test_transients'],
            $GLOBALS['intersoccer_test_object_cache']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        parent::tearDown();
    }

    public static function languagePairs(): array
    {
        return [
            'control EN+EN' => ['en', 'en'],
            'control DE+DE' => ['de', 'de'],
            'control FR+FR' => ['fr', 'fr'],
            'cross DE+FR'   => ['de', 'fr'],
            'cross EN+DE'   => ['en', 'de'],
            'cross FR+EN'   => ['fr', 'en'],
        ];
    }

    private function makeCart(string $langA, string $langB)
    {
        $line = static function (string $lang, string $uuid, string $name, int $index) {
            [$parent, $variation] = self::PROGRAMS[$lang];
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
                'assigned_player' => $index,
                'assigned_player_id' => $uuid,
                'assigned_attendee' => $name,
                'base_price' => self::BASE_PRICE,
                'data' => $data,
            ];
        };
        return new class([
            'line-child1-' . $langA => $line($langA, 'uuid-child-1', 'QAKid One', 0),
            'line-child2-' . $langB => $line($langB, 'uuid-child-2', 'QAKid Two', 1),
        ]) {
            public $cart_contents;
            public function __construct($c) { $this->cart_contents = $c; }
            public function get_cart() { return $this->cart_contents; }
            public function get_applied_coupons() { return []; }
        };
    }

    private function diag(string $langA, string $langB): string
    {
        $out = [];
        foreach (array_unique([$langA, $langB]) as $lang) {
            [$parent, $variation, , , $booking] = self::PROGRAMS[$lang];
            $out[] = sprintf(
                '%s: season_key=%s booking_type=%s counts_for_sibling=%s',
                $lang,
                json_encode(intersoccer_discount_season_key($variation), JSON_UNESCAPED_UNICODE),
                $booking,
                var_export(intersoccer_discount_camp_booking_counts_for_sibling($booking), true)
            );
        }
        return implode('; ', $out);
    }

    /**
     * Grouping layer: both children must land in one camps_by_season_child bucket.
     */
    #[DataProvider('languagePairs')]
    public function testBothSiblingsShareOneSeasonBucket(string $langA, string $langB): void
    {
        $cart = $this->makeCart($langA, $langB);
        $ctx = intersoccer_build_cart_context($cart->get_cart());
        $buckets = array_map('array_keys', $ctx['camps_by_season_child']);
        $max = 0;
        foreach ($buckets as $children) {
            $max = max($max, count($children));
        }
        $this->assertSame(
            2,
            $max,
            "Two siblings ($langA+$langB, same programme) must share one season bucket. buckets="
            . json_encode($buckets, JSON_UNESCAPED_UNICODE) . ' | ' . $this->diag($langA, $langB)
        );
    }

    /**
     * End-to-end cart hook: 2nd child gets the 20% camp sibling rate.
     */
    #[DataProvider('languagePairs')]
    public function testSecondChildGetsCampSiblingDiscount(string $langA, string $langB): void
    {
        $cart = $this->makeCart($langA, $langB);
        intersoccer_apply_combo_discounts_to_items($cart);

        $percents = [];
        $prices = [];
        foreach ($cart->cart_contents as $key => $line) {
            $percents[$key] = (float) ($line['intersoccer_sibling_percent'] ?? 0);
            $prices[$key] = $line['data']->get_price();
        }
        $discounted = array_filter($percents, static function ($p) { return abs($p - self::SIBLING_RATE) < 1e-9; });

        $this->assertCount(
            1,
            $discounted,
            "Expected exactly one line at 20% sibling rate for $langA+$langB. percents=" . json_encode($percents)
            . ' prices=' . json_encode($prices) . ' | ' . $this->diag($langA, $langB)
        );
        $this->assertEqualsWithDelta(
            self::BASE_PRICE * 2 * (1 - self::SIBLING_RATE / 2),
            array_sum($prices),
            0.001,
            "Cart total for $langA+$langB must be 525 + 420 = 945"
        );
    }

    public static function frenchFullWeekBookingVariants(): array
    {
        return [
            'slug semaine-complete' => ['semaine-complete'],
            'label semaine complete' => ['semaine complete'],
            'label Semaine Complète' => ['Semaine Complète'],
        ];
    }

    #[DataProvider('frenchFullWeekBookingVariants')]
    public function testFrenchFullWeekBookingTypeCountsForSibling(string $booking_type): void
    {
        $this->assertTrue(
            intersoccer_discount_camp_booking_counts_for_sibling($booking_type),
            'FR full-week booking types must qualify for camp sibling discounts'
        );
    }

    public function testPriorOrderTranslatedSeasonKeyMatchesEnglishCartSiblingDiscount(): void
    {
        $GLOBALS['intersoccer_test_options']['intersoccer_enable_retroactive_sibling_discounts'] = 'yes';
        $GLOBALS['intersoccer_test_options']['intersoccer_enable_retroactive_camp_discounts'] = false;
        $GLOBALS['intersoccer_test_user_id'] = 46231;

        $prior_item = new class {
            public function get_product_id() { return 54216; }
            public function get_variation_id() { return 54231; }
            public function get_meta($key, $single = true) {
                $map = [
                    'assigned_player_id' => 'uuid-child-1',
                    'assigned_attendee' => 'QAKid One',
                    '_intersoccer_discount_season_key' => 'herbst camps 2026|2026',
                ];
                return $map[$key] ?? '';
            }
            public function get_total() { return 525.0; }
            public function get_quantity() { return 1; }
        };
        $prior_order = new class($prior_item) {
            private $item;
            public function __construct($item) { $this->item = $item; }
            public function get_id() { return 90001; }
            public function get_items() { return [501 => $this->item]; }
            public function get_date_created() {
                return new class {
                    public function format($f) { return '2026-09-01 10:00:00'; }
                };
            }
        };
        $GLOBALS['intersoccer_test_orders'] = [90001 => $prior_order];
        $GLOBALS['intersoccer_test_wc_get_orders'] = static function () {
            return [90001];
        };

        $cart = $this->makeCart('en', 'fr');
        intersoccer_apply_combo_discounts_to_items($cart);

        $prices = [];
        foreach ($cart->cart_contents as $line) {
            $prices[] = $line['data']->get_price();
        }
        $this->assertEqualsWithDelta(
            self::BASE_PRICE * 2 * (1 - self::SIBLING_RATE / 2),
            array_sum($prices),
            0.001,
            'Prior DE season meta + EN/FR cart must still produce 525 + 420'
        );
    }

    public function testNormalizeSeasonKeyMapsTranslatedStoredMetaToCanonical(): void
    {
        $en = intersoccer_discount_normalize_season_key('autumn camps 2026|2026');
        $de = intersoccer_discount_normalize_season_key('herbst camps 2026|2026');
        $fr = intersoccer_discount_normalize_season_key("camps d'automne 2026|2026");
        $this->assertSame($en, $de);
        $this->assertSame($en, $fr);
        $this->assertStringStartsWith('ps:', $en);
        $this->assertStringContainsString('|2026', $en);
    }
}
