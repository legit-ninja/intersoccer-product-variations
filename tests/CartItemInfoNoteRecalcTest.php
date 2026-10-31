<?php
/**
 * Regression: issue #119 — camp day-count / course weeks-remaining cart note survives discount recalculation.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-camp.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-course.php';
require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

if (!function_exists('did_action')) {
    function did_action($hook) {
        return 0;
    }
}
if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}
if (!function_exists('icl_t')) {
    function icl_t($context, $name, $string) {
        if (!empty($GLOBALS['intersoccer_test_icl_t']) && isset($GLOBALS['intersoccer_test_icl_t'][$string])) {
            return $GLOBALS['intersoccer_test_icl_t'][$string];
        }
        return $string;
    }
}
require_once dirname(__DIR__) . '/includes/language-helpers.php';
if (!function_exists('intersoccer_get_discount_message')) {
    function intersoccer_get_discount_message($key, $context = 'cart_message', $fallback = '') {
        return $fallback;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return esc_html(__($text, $domain));
    }
}

class CartItemInfoNoteRecalcTest extends TestCase
{
    private const CAMP_PARENT = 71001;
    private const CAMP_VARIATION = 71002;
    private const COURSE_PARENT = 72001;
    public const COURSE_VARIATION = 72002;
    public const COURSE_VARIATION_TUE = 72003;
    private const SEASON_TERM = 88001;
    private const BASE_PRICE = 525.0;
    private const COURSE_PRICE_LOW = 400.0;
    private const COURSE_PRICE_HIGH = 600.0;
    private const SIBLING_RATE = 0.20;
    private const SAME_SEASON_RATE = 0.50;
    private const MASTER_SIBLING_NOTE = '20% Camp Sibling Discount';
    private const SAME_SEASON_DISCOUNT_NOTE = '50% Same Season Course Discount';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_options'] = [];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset(
            $GLOBALS['intersoccer_test_icl_t'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_orders'],
            $GLOBALS['intersoccer_test_wc_get_orders']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        add_filter('woocommerce_get_cart_item_from_session', 'intersoccer_restore_cart_item_info_note_from_session', 10, 3);

        $GLOBALS['intersoccer_test_terms'] = [
            self::SEASON_TERM => [
                'name' => 'Autumn Camps 2026',
                'taxonomy' => 'pa_program-season',
            ],
        ];
        $GLOBALS['intersoccer_test_product_terms'] = [
            self::CAMP_PARENT => ['pa_program-season' => ['Autumn Camps 2026'], 'pa_program-year' => ['2026']],
            self::CAMP_VARIATION => [],
        ];
        $GLOBALS['intersoccer_test_product_term_ids'] = [
            self::CAMP_PARENT => ['pa_program-season' => [self::SEASON_TERM]],
        ];
        MockMetaData::$data[self::CAMP_VARIATION]['attribute_pa_booking-type'] = 'single-days';
        MockMetaData::$data[self::COURSE_VARIATION]['attribute_pa_course-day'] = 'monday';
        MockMetaData::$data[self::COURSE_VARIATION]['_course_start_date'] = date('Y-m-d', strtotime('+4 weeks'));
        MockMetaData::$data[self::COURSE_VARIATION]['_course_total_weeks'] = 10;
        MockMetaData::$data[self::COURSE_VARIATION]['_course_weekly_discount'] = 0;
        MockMetaData::$data[self::COURSE_VARIATION]['_course_holiday_dates'] = [];

        $GLOBALS['intersoccer_wc_get_product_callback'] = static function ($id) {
            return CartItemInfoNoteRecalcTest::fixtureProduct((int) $id);
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_options'],
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_terms'],
            $GLOBALS['intersoccer_test_product_terms'],
            $GLOBALS['intersoccer_test_product_term_ids'],
            $GLOBALS['intersoccer_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_icl_t']
        );
        MockMetaData::$data = [];
        MockFilters::reset();
        if (class_exists('InterSoccer_Course')) {
            InterSoccer_Course::reset_runtime_cache();
        }
        parent::tearDown();
    }

    private function makePriceData(float $base): object
    {
        return new class($base) {
            private $p;
            public function __construct($p)
            {
                $this->p = (float) $p;
            }
            public function get_price()
            {
                return $this->p;
            }
            public function set_price($p)
            {
                $this->p = (float) $p;
            }
        };
    }

    private function campLine(array $camp_days, string $key = 'line-camp'): array
    {
        return [
            'product_id' => self::CAMP_PARENT,
            'variation_id' => self::CAMP_VARIATION,
            'quantity' => 1,
            'camp_days' => $camp_days,
            'assigned_player_id' => 'uuid-' . $key,
            'assigned_attendee' => 'Camper',
            'base_price' => self::BASE_PRICE,
            'data' => $this->makePriceData(self::BASE_PRICE),
        ];
    }

    private function courseLine(string $key = 'line-course'): array
    {
        return $this->courseLineWithVariation(self::COURSE_VARIATION, self::BASE_PRICE, $key);
    }

    private function courseLineWithVariation(int $variation_id, float $base_price, string $key = 'line-course'): array
    {
        return [
            'product_id' => self::COURSE_PARENT,
            'variation_id' => $variation_id,
            'quantity' => 1,
            'assigned_player_id' => 'uuid-' . $key,
            'assigned_attendee' => 'Student',
            'base_price' => $base_price,
            'data' => $this->makePriceData($base_price),
        ];
    }

    private static function fixtureProduct(int $id): object
    {
        $parents = [
            self::CAMP_VARIATION => self::CAMP_PARENT,
            self::COURSE_VARIATION => self::COURSE_PARENT,
            self::COURSE_VARIATION_TUE => self::COURSE_PARENT,
        ];
        $prices = [
            self::COURSE_VARIATION => self::BASE_PRICE,
            self::COURSE_VARIATION_TUE => self::COURSE_PRICE_HIGH,
        ];
        $parent = $parents[$id] ?? 0;
        $base_price = $prices[$id] ?? self::BASE_PRICE;

        return new class($id, $parent, $base_price) {
            private $id;
            private $parent;
            private $base_price;

            public function __construct($id, $parent, $base_price)
            {
                $this->id = $id;
                $this->parent = $parent;
                $this->base_price = $base_price;
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
                return $this->base_price;
            }

            public function get_attributes()
            {
                return [];
            }

            public function get_attribute($n)
            {
                if ($n === 'pa_course-day' || $n === 'course-day') {
                    if ($this->id === CartItemInfoNoteRecalcTest::COURSE_VARIATION) {
                        return 'monday';
                    }
                    if ($this->id === CartItemInfoNoteRecalcTest::COURSE_VARIATION_TUE) {
                        return 'tuesday';
                    }
                }

                return '';
            }

            public function is_type($t)
            {
                return false;
            }

            public function get_name()
            {
                return 'Fixture product';
            }
        };
    }

    private function makeCart(array $lines): object
    {
        return new class($lines) {
            public $cart_contents;
            public function __construct($lines)
            {
                $this->cart_contents = $lines;
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
    }

    private function runRecalculations(object $cart, int $times = 2): void
    {
        for ($i = 0; $i < $times; $i++) {
            intersoccer_apply_combo_discounts_to_items($cart);
        }
    }

    private function runBeforeCalculateTotals(object $cart, int $times = 2): void
    {
        for ($i = 0; $i < $times; $i++) {
            do_action('woocommerce_before_calculate_totals', $cart);
        }
    }

    private function deriveItem(array $cart_item, string $type): array
    {
        $GLOBALS['intersoccer_test_product_type'] = $type;
        return intersoccer_add_derived_cart_item_data($cart_item);
    }

    private function displayValues(array $cart_item, string $type): array
    {
        $GLOBALS['intersoccer_test_product_type'] = $type;
        $rows = intersoccer_display_cart_item_metadata([], $cart_item);
        return array_map(static function ($row) {
            return wp_strip_all_tags($row['value'] ?? '');
        }, $rows);
    }

    public function testCampSingleDayInfoNoteSurvivesMultipleRecalculations(): void
    {
        $line = $this->deriveItem($this->campLine(['Monday']), 'camp');
        $cart = $this->makeCart(['line-camp' => $line]);
        $this->runRecalculations($cart, 3);

        $this->assertSame('1 Day(s) Selected', $cart->cart_contents['line-camp']['intersoccer_info_note'] ?? '');
        $this->assertSame('', $cart->cart_contents['line-camp']['discount_note'] ?? '');
        $this->assertContains('1 Day(s) Selected', $this->displayValues($cart->cart_contents['line-camp'], 'camp'));
    }

    public function testCampMultiDayInfoNoteSurvivesRecalculation(): void
    {
        $line = $this->deriveItem($this->campLine(['Monday', 'Wednesday', 'Friday']), 'camp');
        $cart = $this->makeCart(['line-camp' => $line]);
        $this->runRecalculations($cart, 2);

        $this->assertSame('3 Day(s) Selected', $cart->cart_contents['line-camp']['intersoccer_info_note'] ?? '');
        $this->assertContains('3 Day(s) Selected', $this->displayValues($cart->cart_contents['line-camp'], 'camp'));
    }

    public function testCourseWeeksRemainingInfoNoteSurvivesRecalculation(): void
    {
        $line = $this->deriveItem($this->courseLine(), 'course');
        $expected = InterSoccer_Course::calculate_discount_note(
            self::COURSE_VARIATION,
            (int) ($line['remaining_sessions'] ?? 0)
        );
        $this->assertNotSame('', $expected);

        $cart = $this->makeCart(['line-course' => $line]);
        $this->runRecalculations($cart, 2);

        $this->assertSame($expected, $cart->cart_contents['line-course']['intersoccer_info_note'] ?? '');
        $this->assertSame('', $cart->cart_contents['line-course']['discount_note'] ?? '');
        $this->assertContains($expected, $this->displayValues($cart->cart_contents['line-course'], 'course'));
    }

    public function testCampSiblingDiscountMatchesMasterAndInfoNoteRemains(): void
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        MockMetaData::$data[self::CAMP_VARIATION]['attribute_pa_booking-type'] = 'full-week';

        $line1 = $this->deriveItem($this->campLine([], 'child-1'), 'camp');
        $line2 = $this->deriveItem($this->campLine([], 'child-2'), 'camp');
        $cart = $this->makeCart([
            'line-child-1' => $line1,
            'line-child-2' => $line2,
        ]);
        $this->runRecalculations($cart, 2);

        $discounted_key = null;
        foreach ($cart->cart_contents as $key => $row) {
            if (!empty($row['intersoccer_sibling_percent']) && abs((float) $row['intersoccer_sibling_percent'] - self::SIBLING_RATE) < 1e-9) {
                $discounted_key = $key;
                break;
            }
        }
        $this->assertNotNull($discounted_key, 'One line must receive the 20% camp sibling rate');

        $discounted = $cart->cart_contents[$discounted_key];
        $this->assertEqualsWithDelta(self::BASE_PRICE * (1 - self::SIBLING_RATE), $discounted['data']->get_price(), 0.001);
        $this->assertEqualsWithDelta(self::BASE_PRICE * self::SIBLING_RATE, (float) $discounted['discount_amount'], 0.001);
        $this->assertSame(self::MASTER_SIBLING_NOTE, $discounted['discount_note']);
        $this->assertSame('', $discounted['intersoccer_info_note'] ?? '');
        $this->assertEqualsWithDelta(
            self::BASE_PRICE * 2 * (1 - self::SIBLING_RATE / 2),
            $cart->cart_contents['line-child-1']['data']->get_price()
            + $cart->cart_contents['line-child-2']['data']->get_price(),
            0.001
        );
    }

    public function testDiscountNoteClearsWhenSiblingRemovedButInfoNoteStays(): void
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $line1 = $this->deriveItem($this->campLine(['Tuesday'], 'solo'), 'camp');
        $line2 = $this->deriveItem($this->campLine(['Thursday'], 'sibling'), 'camp');
        MockMetaData::$data[self::CAMP_VARIATION]['attribute_pa_booking-type'] = 'full-week';

        $cart = $this->makeCart([
            'line-solo' => $line1,
            'line-sibling' => $line2,
        ]);
        $this->runRecalculations($cart, 1);
        $this->assertNotSame('', $cart->cart_contents['line-sibling']['discount_note'] ?? '');

        unset($cart->cart_contents['line-solo']);
        $this->runRecalculations($cart, 1);

        $this->assertSame('', $cart->cart_contents['line-sibling']['discount_note'] ?? '');
        $this->assertSame('1 Day(s) Selected', $cart->cart_contents['line-sibling']['intersoccer_info_note'] ?? '');
    }

    public function testSessionFilterBackfillsMissingInfoNoteWithoutTouchingPrices(): void
    {
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $line = $this->deriveItem($this->campLine(['Friday']), 'camp');
        unset($line['intersoccer_info_note']);

        $backfilled = apply_filters('woocommerce_get_cart_item_from_session', $line, $line, 'line-sess');
        $this->assertSame('1 Day(s) Selected', $backfilled['intersoccer_info_note'] ?? '');
        $this->assertEqualsWithDelta(self::BASE_PRICE, (float) $backfilled['base_price'], 0.001);
        $this->assertEqualsWithDelta(self::BASE_PRICE, $backfilled['data']->get_price(), 0.001);
        $this->assertSame('', $backfilled['discount_note'] ?? '');

        $existing = $line;
        $existing['intersoccer_info_note'] = 'Keep this note';
        $existing['discount_note'] = '20% Camp Sibling Discount';
        $existing['base_price'] = 111.0;
        $existing['data']->set_price(222.0);

        $preserved = apply_filters('woocommerce_get_cart_item_from_session', $existing, $existing, 'line-keep');
        $this->assertSame('Keep this note', $preserved['intersoccer_info_note']);
        $this->assertSame('20% Camp Sibling Discount', $preserved['discount_note']);
        $this->assertEqualsWithDelta(111.0, (float) $preserved['base_price'], 0.001);
        $this->assertEqualsWithDelta(222.0, $preserved['data']->get_price(), 0.001);
    }

    public function testCourseSameSeasonComboViaBeforeCalculateTotalsPreservesInfoNote(): void
    {
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        MockMetaData::$data[self::COURSE_VARIATION_TUE] = [
            'attribute_pa_course-day' => 'tuesday',
            '_course_start_date' => date('Y-m-d', strtotime('+4 weeks')),
            '_course_total_weeks' => 10,
            '_course_weekly_discount' => 0,
            '_course_holiday_dates' => [],
        ];

        $child = 'uuid-same-season';
        $line_mon = $this->deriveItem(
            $this->courseLineWithVariation(self::COURSE_VARIATION, self::COURSE_PRICE_LOW, $child),
            'course'
        );
        $line_tue = $this->deriveItem(
            $this->courseLineWithVariation(self::COURSE_VARIATION_TUE, self::COURSE_PRICE_HIGH, $child),
            'course'
        );
        $line_mon['assigned_attendee'] = 'Student';
        $line_tue['assigned_attendee'] = 'Student';

        $cart = $this->makeCart([
            'line-mon' => $line_mon,
            'line-tue' => $line_tue,
        ]);
        $this->runBeforeCalculateTotals($cart, 2);

        $mon = $cart->cart_contents['line-mon'];
        $tue = $cart->cart_contents['line-tue'];

        $this->assertSame('', $mon['discount_note'] ?? '');
        $this->assertEqualsWithDelta(self::COURSE_PRICE_LOW, $mon['data']->get_price(), 0.001);
        $this->assertNotSame('', $mon['intersoccer_info_note'] ?? '');

        $this->assertSame(self::SAME_SEASON_DISCOUNT_NOTE, $tue['discount_note']);
        $this->assertEqualsWithDelta(
            self::COURSE_PRICE_HIGH * (1 - self::SAME_SEASON_RATE),
            $tue['data']->get_price(),
            0.001
        );
        $this->assertEqualsWithDelta(
            self::COURSE_PRICE_HIGH * self::SAME_SEASON_RATE,
            (float) $tue['discount_amount'],
            0.001
        );
        $this->assertNotSame('', $tue['intersoccer_info_note'] ?? '');

        $frSameSeasonNote = $this->msgstrFromPo(
            dirname(__DIR__) . '/lang/intersoccer-product-variations-fr.po',
            '50% Same Season Course Discount'
        );
        $this->assertStringContainsString('même saison', $frSameSeasonNote);
        $tue['discount_note'] = $frSameSeasonNote;
        $this->assertContains($frSameSeasonNote, $this->displayValues($tue, 'course'));
    }

    public function testFrenchInfoAndSiblingDiscountNotes(): void
    {
        $GLOBALS['intersoccer_test_icl_t'] = [
            '%s%% Camp Sibling Discount' => '%s%% Réduction fratrie camp',
        ];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        MockMetaData::$data[self::CAMP_VARIATION]['attribute_pa_booking-type'] = 'full-week';

        $frDayNote = $this->frDeTranslate('%d Day(s) Selected', 1, 'fr');
        $this->assertStringContainsString('jour(s) sélectionné(s)', $frDayNote);

        $line = $this->deriveItem($this->campLine(['Lundi'], 'fr-camp'), 'camp');
        $line['intersoccer_info_note'] = $frDayNote;
        $this->assertContains($frDayNote, $this->displayValues($line, 'camp'));

        $cart = $this->makeCart([
            'line-a' => $this->deriveItem($this->campLine(['Lundi'], 'fr-a'), 'camp'),
            'line-b' => $this->deriveItem($this->campLine(['Mardi'], 'fr-b'), 'camp'),
        ]);
        foreach (['line-a', 'line-b'] as $key) {
            $cart->cart_contents[$key]['intersoccer_info_note'] = $frDayNote;
        }
        $this->runRecalculations($cart, 1);

        $notes = array_map(static function ($row) {
            return $row['discount_note'] ?? '';
        }, $cart->cart_contents);
        $this->assertContains('20% Réduction fratrie camp', $notes);
        $this->assertContains($frDayNote, $this->displayValues($cart->cart_contents['line-a'], 'camp'));
    }

    public function testGermanInfoAndSiblingDiscountNotes(): void
    {
        $GLOBALS['intersoccer_test_icl_t'] = [
            '%s%% Camp Sibling Discount' => '%s%% Geschwisterrabatt Camp',
        ];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        MockMetaData::$data[self::CAMP_VARIATION]['attribute_pa_booking-type'] = 'full-week';

        $deDayNote = $this->frDeTranslate('%d Day(s) Selected', 2, 'de');
        $this->assertStringContainsString('Tag(e) ausgewählt', $deDayNote);

        $line = $this->deriveItem($this->campLine(['Montag', 'Dienstag'], 'de-camp'), 'camp');
        $line['intersoccer_info_note'] = $deDayNote;
        $this->assertContains($deDayNote, $this->displayValues($line, 'camp'));

        $cart = $this->makeCart([
            'line-a' => $this->deriveItem($this->campLine(['Montag'], 'de-a'), 'camp'),
            'line-b' => $this->deriveItem($this->campLine(['Dienstag'], 'de-b'), 'camp'),
        ]);
        foreach (['line-a', 'line-b'] as $key) {
            $cart->cart_contents[$key]['intersoccer_info_note'] = $deDayNote;
        }
        $this->runRecalculations($cart, 1);

        $notes = array_map(static function ($row) {
            return $row['discount_note'] ?? '';
        }, $cart->cart_contents);
        $this->assertContains('20% Geschwisterrabatt Camp', $notes);
    }

    /**
     * Load msgstr from compiled .po (gettext extension unavailable in CI image).
     */
    private function frDeTranslate(string $msgid, int $count, string $locale = 'fr_FR'): string
    {
        $po = dirname(__DIR__) . '/lang/intersoccer-product-variations-' . $locale . '.po';
        $msgstr = $this->msgstrFromPo($po, $msgid);
        return sprintf($msgstr, $count);
    }

    private function msgstrFromPo(string $poFile, string $msgid): string
    {
        $contents = file_get_contents($poFile);
        $pattern = '/msgid "' . preg_quote($msgid, '/') . '"\s+msgstr "((?:[^"\\\\]|\\\\.)*)"/s';
        if (!preg_match($pattern, $contents, $m)) {
            return $msgid;
        }
        return stripcslashes($m[1]);
    }
}
