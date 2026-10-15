<?php
/**
 * Single-day camp days gate for Stripe express checkout (Apple Pay / Google Pay).
 *
 * Express add-to-cart and the Store API skip classic validation and do not post
 * camp_days[]. Days picked on the product page are stashed in the session and
 * restored; without days the express payment is refused with the generic message,
 * and order creation is refused when a single-day camp line has no Days Selected.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-camp.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';


if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
        return true;
    }
}

if (!class_exists('IntersoccerDaysGateTestSession')) {
    class IntersoccerDaysGateTestSession {
        public $data = [];
        public function get($key) { return $this->data[$key] ?? null; }
        public function set($key, $value) { $this->data[$key] = $value; }
        public function __unset($key) { unset($this->data[$key]); }
    }
}

if (!function_exists('WC')) {
    function WC() {
        if (!isset($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc'] = (object) ['session' => null, 'cart' => null];
        }
        return $GLOBALS['intersoccer_days_gate_wc'];
    }
}

if (!class_exists('IntersoccerDaysGateTestItem')) {
    class IntersoccerDaysGateTestItem {
        private $p; private $v; private $meta;
        public function __construct($p, $v, array $meta) { $this->p = $p; $this->v = $v; $this->meta = $meta; }
        public function get_product_id() { return $this->p; }
        public function get_variation_id() { return $this->v; }
        public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
        public $order = null;
        public function get_order() { return $this->order; }
    }
}

class ExpressCheckoutDaysGateTest extends TestCase
{
    private const CAMP_ID = 880301;
    private const SINGLE_VAR = 880302;
    private const GERMAN_VAR = 880303;
    private const WEEK_VAR = 880304;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_name'] = 'Autumn Camp';
        WC()->session = new IntersoccerDaysGateTestSession();
        MockMetaData::$data[self::SINGLE_VAR]['attribute_pa_booking-type'] = 'single-days';
        MockMetaData::$data[self::GERMAN_VAR]['attribute_pa_booking-type'] = 'einzelner-tage';
        MockMetaData::$data[self::WEEK_VAR]['attribute_pa_booking-type'] = 'full-week';
        unset($GLOBALS['intersoccer_test_json'], $GLOBALS['intersoccer_test_json_capture']);
        WC()->cart = null;
        $this->savedFilters = MockFilters::$filters;
        // Default: normal add-to-cart validation passes.
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed) { return $passed; };
    }

    private $savedFilters = [];

    protected function tearDown(): void
    {
        $_POST = [];
        WC()->session = null;
        WC()->cart = null;
        MockFilters::$filters = $this->savedFilters;
        unset($GLOBALS['intersoccer_test_player_by_id']);
        foreach ([self::SINGLE_VAR, self::GERMAN_VAR, self::WEEK_VAR] as $v) {
            unset(MockMetaData::$data[$v]);
        }
        unset(
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_name'],
            $GLOBALS['intersoccer_test_json'],
            $GLOBALS['intersoccer_test_json_capture']
        );
        parent::tearDown();
    }

    private function errors()
    {
        return new class {
            public $errors = [];
            public function add($code, $message) { $this->errors[$code][] = $message; }
        };
    }

    private function order(array $items)
    {
        return new class($items) {
            private $items;
            public function __construct($items) { $this->items = $items; }
            public function get_items() { return $this->items; }
        };
    }

    public function testEnglishAndGermanSingleDayVariantsAreDetected()
    {
        $this->assertTrue(intersoccer_days_gate_is_single_day_camp(self::CAMP_ID, self::SINGLE_VAR));
        $this->assertTrue(intersoccer_days_gate_is_single_day_camp(self::CAMP_ID, self::GERMAN_VAR));
        $this->assertTrue(intersoccer_days_gate_is_single_day_camp(self::CAMP_ID, 0, ['attribute_pa_booking-type' => 'Einzelner Tage']));
        $this->assertTrue(intersoccer_days_gate_is_single_day_camp(self::CAMP_ID, 0, ['attribute_pa_booking-type' => 'Single Day(s)']));
        $this->assertFalse(intersoccer_days_gate_is_single_day_camp(self::CAMP_ID, self::WEEK_VAR));
    }

    public function testNonCampProductsAreIgnored()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'course';
        $this->assertTrue(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
    }

    public function testExpressAddToCartRefusedWithoutDays()
    {
        $this->assertFalse(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
        $this->assertFalse(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::GERMAN_VAR));
    }

    public function testFullWeekNeedsNoDays()
    {
        $this->assertTrue(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::WEEK_VAR));
    }

    public function testPostedDaysAreAccepted()
    {
        $_POST['camp_days'] = ['Monday', 'Wednesday'];
        $this->assertTrue(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
    }

    public function testStashedDaysAreRestoredIntoPost()
    {
        $this->assertTrue(intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Tuesday', 'Thursday']));
        $this->assertTrue(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
        $this->assertSame(['Tuesday', 'Thursday'], $_POST['camp_days']);
    }

    public function testStashForAnotherVariationIsNotUsed()
    {
        intersoccer_stash_selected_days(self::CAMP_ID, self::GERMAN_VAR, ['Tuesday']);
        $this->assertFalse(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
        $this->assertArrayNotHasKey('camp_days', $_POST);
    }

    public function testStashFromAnotherUserOrExpiredIsDropped()
    {
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Monday']);
        $GLOBALS['intersoccer_test_user_id'] = 7;
        $this->assertSame([], intersoccer_get_stashed_selected_days(self::CAMP_ID, self::SINGLE_VAR));

        $GLOBALS['intersoccer_test_user_id'] = 42;
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Monday']);
        $key = intersoccer_express_fields_session_key(self::CAMP_ID);
        $data = WC()->session->get($key);
        $data['stashed_at'] = time() - 3 * 3600;
        WC()->session->set($key, $data);
        $this->assertSame([], intersoccer_get_stashed_selected_days(self::CAMP_ID, self::SINGLE_VAR));
    }

    public function testEmptyDaysClearTheStash()
    {
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Monday']);
        $this->assertFalse(intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, []));
        $this->assertNull(WC()->session->get(intersoccer_express_fields_session_key(self::CAMP_ID)));
    }

    public function testStripeExpressAddToCartRefusesSingleDayWithoutDays()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = [
            'security' => 'valid_nonce',
            'product_id' => (string) self::CAMP_ID,
            'variation_id' => (string) self::SINGLE_VAR,
            'assigned_player_id' => 'uuid-1',
        ];
        try {
            intersoccer_express_days_gate_stripe_add_to_cart();
            $this->fail('Expected a JSON error response');
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
        $this->assertSame(intersoccer_express_gate_message(), $GLOBALS['intersoccer_test_json']['data']['message']);
    }

    public function testStripeExpressAddToCartRefusesGermanVariantFromPostedAttribute()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = [
            'security' => 'valid_nonce',
            'product_id' => (string) self::CAMP_ID,
            'attribute_pa_booking-type' => 'einzelner-tage',
        ];
        try {
            intersoccer_express_days_gate_stripe_add_to_cart();
            $this->fail('Expected a JSON error response');
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
    }

    public function testStripeExpressAddToCartPassesWithStashedDays()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Friday']);
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR];
        $this->assertNull(intersoccer_express_days_gate_stripe_add_to_cart());
        $this->assertArrayNotHasKey('intersoccer_test_json', $GLOBALS);
        $this->assertSame(['Friday'], $_POST['camp_days']);
    }

    public function testStripeExpressAddToCartLeavesBadNonceToStripe()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['security' => 'nope', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR];
        $this->assertNull(intersoccer_express_days_gate_stripe_add_to_cart());
        $this->assertArrayNotHasKey('intersoccer_test_json', $GLOBALS);
    }

    public function testCartLinesWithoutDaysAreFlagged()
    {
        $cart = [
            'a' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR],
            'b' => ['product_id' => self::CAMP_ID, 'variation_id' => self::GERMAN_VAR, 'camp_days' => []],
            'c' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'camp_days' => ['Monday']],
            'd' => ['product_id' => self::CAMP_ID, 'variation_id' => self::WEEK_VAR],
        ];
        $this->assertSame(['a', 'b'], intersoccer_express_days_gate_missing_days_keys($cart));
    }

    public function testOrderCreationRefusedWhenSingleDayLineHasNoDaysSelected()
    {
        $order = $this->order([
            11 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::SINGLE_VAR, ['Days Selected' => 'Monday']),
            12 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::GERMAN_VAR, ['Days of Week' => 'Monday, Tuesday, Wednesday, Thursday, Friday']),
            13 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::WEEK_VAR, []),
        ]);
        $this->assertSame([12], intersoccer_days_gate_order_lines_missing_days($order));
        try {
            intersoccer_days_gate_checkout_create_order($order, []);
            $this->fail('Expected order creation to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_express_gate_message(), $e->getMessage());
        }
    }

    public function testOrderCreationPassesWhenDaysPresent()
    {
        $order = $this->order([
            11 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::SINGLE_VAR, ['Days Selected' => 'Monday, Friday']),
        ]);
        intersoccer_days_gate_assert_order_has_days($order);
        $this->assertTrue(true);
    }

    public function testStoreApiCheckoutRefusedFromOrderLineMeta()
    {
        $order = $this->order([
            11 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::SINGLE_VAR, []),
        ]);
        $this->expectException(Exception::class);
        intersoccer_express_days_gate_store_api_checkout($order, null);
    }

    public function testStoreSelectedDaysAjaxWritesAndClearsStash()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::GERMAN_VAR, 'camp_days' => ['Mittwoch']];
        try {
            intersoccer_store_selected_days_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertSame(['Wednesday'], intersoccer_get_stashed_selected_days(self::CAMP_ID, self::GERMAN_VAR));

        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::GERMAN_VAR, 'camp_days' => []];
        try {
            intersoccer_store_selected_days_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertSame([], intersoccer_get_stashed_selected_days(self::CAMP_ID, self::GERMAN_VAR));
    }

    public function testClassicExpressCheckoutIgnoresNormalCheckout()
    {
        $errors = $this->errors();
        intersoccer_express_days_gate_classic_checkout([], $errors);
        $this->assertSame([], $errors->errors);
    }

    private function cartWith(array $items)
    {
        return new class($items) {
            public $items; public $emptied = false;
            public function __construct($items) { $this->items = $items; }
            public function get_cart() { return $this->items; }
            public function empty_cart() { $this->emptied = true; $this->items = []; }
        };
    }

    private function callStripeAddToCart(callable $fn)
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        try {
            $r = $fn();
            return $r;
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
            return 'exit';
        }
    }

    public function testExpressPathRunsNormalAddToCartValidationAndRefusesOnFailure()
    {
        $seen = [];
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed, $pid, $qty, $vid) use (&$seen) {
            $seen[] = [$pid, $vid, $_POST['camp_days'] ?? null, $_POST['late_pickup_type'] ?? null];
            return false; // e.g. age group or girls-only failure
        };
        WC()->cart = $this->cartWith([]);
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR, 'camp_days' => ['Monday']];
        $this->assertSame('exit', $this->callStripeAddToCart('intersoccer_express_days_gate_stripe_add_to_cart'));
        $this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
        $this->assertSame(intersoccer_express_gate_message(), $GLOBALS['intersoccer_test_json']['data']['message']);
        $this->assertSame(self::CAMP_ID, $seen[0][0]);
        $this->assertSame(self::SINGLE_VAR, $seen[0][1]);
        $this->assertTrue(WC()->cart->emptied);
    }

    public function testExpressPathRestoresLatePickupBeforeValidation()
    {
        $seen = null;
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed) use (&$seen) {
            $seen = [$_POST['camp_days'] ?? null, $_POST['late_pickup_type'] ?? null, $_POST['late_pickup_days'] ?? null];
            return $passed;
        };
        $this->assertTrue(intersoccer_stash_express_fields(self::CAMP_ID, self::SINGLE_VAR, [
            'camp_days' => ['Monday', 'Tuesday'],
            'late_pickup_type' => 'single-days',
            'late_pickup_days' => ['Monday'],
            'late_pickup_cost' => '0.01', // never stashed or trusted
        ]));
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR];
        $this->assertNull($this->callStripeAddToCart('intersoccer_express_days_gate_stripe_add_to_cart'));
        $this->assertSame([['Monday', 'Tuesday'], 'single-days', ['Monday']], $seen);
        $this->assertArrayNotHasKey('late_pickup_cost', $_POST);
    }

    public function testFullWeekLatePickupStashDropsDays()
    {
        intersoccer_stash_express_fields(self::CAMP_ID, self::WEEK_VAR, ['late_pickup_type' => 'full-week', 'late_pickup_days' => ['Monday']]);
        $fields = intersoccer_get_stashed_express_fields(self::CAMP_ID, self::WEEK_VAR);
        $this->assertSame('full-week', $fields['late_pickup_type']);
        $this->assertSame([], $fields['late_pickup_days']);
        $_POST = [];
        $this->assertTrue(intersoccer_restore_stashed_express_fields_into_post(self::CAMP_ID, self::WEEK_VAR));
        $this->assertSame('full-week', $_POST['late_pickup_type']);
    }

    public function testInvalidLatePickupTypeIsNotStashed()
    {
        $this->assertFalse(intersoccer_stash_express_fields(self::CAMP_ID, self::WEEK_VAR, ['late_pickup_type' => '<script>']));
    }

    public function testStoreApiCheckoutReRunsValidationPerLine()
    {
        $posted = [];
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed, $pid, $qty, $vid) use (&$posted) {
            $posted[] = $_POST;
            return $vid !== self::GERMAN_VAR;
        };
        WC()->cart = $this->cartWith([
            'ok' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'camp_days' => ['Monday'], 'assigned_player_id' => 'uuid-1', 'late_pickup_type' => 'full-week'],
            'bad' => ['product_id' => self::CAMP_ID, 'variation_id' => self::GERMAN_VAR, 'camp_days' => ['Monday'], 'assigned_player' => 0],
        ]);
        $_POST = ['untouched' => '1'];
        $this->assertSame(['bad'], intersoccer_express_cart_lines_failing_validation(WC()->cart->get_cart()));
        $this->assertSame(['untouched' => '1'], $_POST, 'request $_POST is restored');
        $this->assertSame('uuid-1', $posted[0]['assigned_player_id']);
        $this->assertSame('full-week', $posted[0]['late_pickup_type']);
        $this->assertSame('0', $posted[1]['player_assignment']);

        $this->expectException(Exception::class);
        intersoccer_express_days_gate_store_api_checkout($this->order([]), null);
    }

    public function testExpressRefusedWhenCartAlreadyHoldsProgramLines()
    {
        WC()->cart = $this->cartWith(['x' => ['product_id' => self::CAMP_ID, 'variation_id' => self::WEEK_VAR, 'assigned_player' => 0]]);
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID];
        $this->assertSame('exit', $this->callStripeAddToCart('intersoccer_express_gate_refuse_when_cart_has_programs'));
        $this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
        $this->assertFalse(WC()->cart->emptied, 'the existing cart and its discounts are kept');

        unset($GLOBALS['intersoccer_test_json']);
        WC()->cart = $this->cartWith([]);
        $this->assertNull($this->callStripeAddToCart('intersoccer_express_gate_refuse_when_cart_has_programs'));
    }

    public function testCssAlsoHidesExpressWhenCartHasPrograms()
    {
        $css = intersoccer_express_gate_css();
        $this->assertStringContainsString('body.intersoccer-express-cart-has-programs #wc-stripe-express-checkout-element', $css);
        $this->assertStringContainsString('body.intersoccer-express-needs-player #wc-stripe-express-checkout-element', $css);
    }

    public function testAssignedAttendeeIsFilledFromPlayerIdOnSave()
    {
        $GLOBALS['intersoccer_test_player_by_id'] = function ($uid, $pid) {
            return ($uid === 42 && $pid === 'uuid-9') ? ['first_name' => 'Test', 'last_name' => 'Child'] : null;
        };
        $item = new IntersoccerDaysGateTestItem(self::CAMP_ID, self::WEEK_VAR, ['assigned_player_id' => 'uuid-9']);
        $item->order = new class { public function get_customer_id() { return 42; } };
        intersoccer_assigned_attendee_fallback_on_item_save($item);
        $this->assertSame('Test Child', $item->get_meta('Assigned Attendee'));

        $kept = new IntersoccerDaysGateTestItem(self::CAMP_ID, self::WEEK_VAR, ['assigned_player_id' => 'uuid-9', 'Assigned Attendee' => 'Already Set']);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($kept, 42));
        $this->assertSame('Already Set', $kept->get_meta('Assigned Attendee'));

        $other = new IntersoccerDaysGateTestItem(self::CAMP_ID, self::WEEK_VAR, ['assigned_player_id' => 'uuid-9']);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($other, 7), 'player must belong to the order customer');
    }

    public function testFrontEndStashesDaysAndPluginLoadsGate()
    {
        $root = dirname(__DIR__);
        $widgets = file_get_contents($root . '/includes/elementor-widgets.php');
        $this->assertStringContainsString("intersoccerStashSelectedDays(\$form, selectedDays);", $widgets);
        $this->assertStringContainsString("action: 'intersoccer_store_express_fields'", $widgets);
        $this->assertStringContainsString("intersoccerStashSelectedDays(null, null);", $widgets);
        $main = file_get_contents($root . '/intersoccer-product-variations.php');
        $this->assertStringContainsString("'includes/woocommerce/express-checkout-player-gate.php'", $main);
    }
}
