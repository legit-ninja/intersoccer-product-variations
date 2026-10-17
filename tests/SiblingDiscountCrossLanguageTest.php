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

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
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
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        // Deterministic globals regardless of suite order (other tests set these).
        $GLOBALS['intersoccer_test_options'] = [];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset($GLOBALS['intersoccer_test_user_id'], $GLOBALS['intersoccer_test_products']);

        $terms = [];
        $parents = [];
        foreach (self::PROGRAMS as [$parent, $variation, $season, $year, $booking]) {
            // Season/year are parent-only taxonomies (not copied to variations).
            $terms[$parent] = ['pa_program-season' => [$season], 'pa_program-year' => [$year]];
            $terms[$variation] = [];
            $parents[$variation] = $parent;
            MockMetaData::$data[$variation]['attribute_pa_booking-type'] = $booking;
        }
        $GLOBALS['intersoccer_test_product_terms'] = $terms;
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
            $GLOBALS['intersoccer_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_is_admin']
        );
        MockMetaData::$data = [];
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
     *
     * @dataProvider languagePairs
     */
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
     *
     * @dataProvider languagePairs
     */
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
}
