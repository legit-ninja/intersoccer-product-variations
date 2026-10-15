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
require_once dirname(__DIR__) . '/includes/woocommerce/late-pickup.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';


if (!function_exists('is_product')) {
    function is_product() {
        return !empty($GLOBALS['intersoccer_test_is_product']);
    }
}

if (!class_exists('IntersoccerDaysGateTestRequest')) {
    class IntersoccerDaysGateTestRequest {
        private $params;
        public function __construct(array $params) { $this->params = $params; }
        public function get_param($key) { return $this->params[$key] ?? null; }
    }
}

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
        $_GET = [];
        $GLOBALS['wc_notices'] = [];
        unset($GLOBALS['intersoccer_express_request'], $GLOBALS['intersoccer_test_is_product']);
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
        $_GET = [];
        unset($GLOBALS['intersoccer_express_request'], $GLOBALS['intersoccer_test_is_product'], $GLOBALS['wc_notices']);
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

    private function expressRequest()
    {
        $GLOBALS['intersoccer_express_request'] = true;
    }

    private function expressStoreApiRequest()
    {
        return new IntersoccerDaysGateTestRequest(['payment_data' => [['key' => 'express_payment_type', 'value' => 'apple_pay']]]);
    }

    public function testStashedDaysAreRestoredIntoPost()
    {
        $this->expressRequest();
        $this->assertTrue(intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Tuesday', 'Thursday']));
        $this->assertTrue(intersoccer_express_days_gate_add_to_cart_allowed(self::CAMP_ID, self::SINGLE_VAR));
        $this->assertSame(['Tuesday', 'Thursday'], $_POST['camp_days']);
    }

    public function testStashForAnotherVariationIsNotUsed()
    {
        $this->expressRequest();
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
            12 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::GERMAN_VAR, ['Days Selected' => 'Mittwoch']),
        ]);
        $this->assertSame([], intersoccer_days_gate_order_lines_missing_days($order));
        $thrown = null;
        try {
            intersoccer_days_gate_checkout_create_order($order, []);
        } catch (Exception $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown, 'An order whose single-day lines carry days must be created');
    }

    public function testStoreApiExpressCheckoutRefusesCartLineWithoutDays()
    {
        WC()->cart = $this->cartWith([
            'k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'assigned_player_id' => 'uuid-1'],
        ]);
        // Order lines are fine, so only the cart missing-days branch can refuse.
        $order = $this->order([11 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::WEEK_VAR, [])]);
        try {
            intersoccer_express_days_gate_store_api_checkout($order, $this->expressStoreApiRequest());
            $this->fail('Expected the express checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_express_gate_message(), $e->getMessage());
        }
    }

    public function testStoreApiOrderLineMetaCheckRefusesWithGenericMessage()
    {
        $order = $this->order([11 => new IntersoccerDaysGateTestItem(self::CAMP_ID, self::SINGLE_VAR, [])]);
        try {
            intersoccer_express_days_gate_store_api_checkout($order, null);
            $this->fail('Expected order creation to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_express_gate_message(), $e->getMessage());
        }
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
        $cart = [
            'ok' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'camp_days' => ['Monday'], 'assigned_player_id' => 'uuid-1', 'late_pickup_type' => 'full-week'],
            'bad' => ['product_id' => self::CAMP_ID, 'variation_id' => self::GERMAN_VAR, 'camp_days' => ['Monday'], 'assigned_player' => 0],
        ];
        $_POST = ['untouched' => '1'];
        $this->assertSame(['bad'], intersoccer_express_cart_lines_failing_validation($cart));
        $this->assertSame(['untouched' => '1'], $_POST, 'request $_POST is restored');
        $this->assertSame('uuid-1', $posted[0]['assigned_player_id']);
        $this->assertSame('full-week', $posted[0]['late_pickup_type']);
        $this->assertSame('0', $posted[1]['player_assignment']);
    }

    public function testStoreApiExpressCheckoutKeepsValidatorMessage()
    {
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed) {
            wc_add_notice('This camp is not available for the selected age group.', 'error');
            return false;
        };
        WC()->cart = $this->cartWith([
            'k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'camp_days' => ['Monday'], 'assigned_player_id' => 'uuid-1'],
        ]);
        try {
            intersoccer_express_days_gate_store_api_checkout($this->order([]), $this->expressStoreApiRequest());
            $this->fail('Expected the express checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame('This camp is not available for the selected age group.', $e->getMessage());
        }
    }

    public function testStoreApiExpressRecheckClearsValidatorNotices()
    {
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed) {
            wc_add_notice('This camp is not available for the selected age group.', 'error');
            return false;
        };
        WC()->cart = $this->cartWith([
            'k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'camp_days' => ['Monday'], 'assigned_player_id' => 'uuid-1'],
        ]);
        $message = null;
        try {
            intersoccer_express_days_gate_store_api_checkout($this->order([]), $this->expressStoreApiRequest());
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('This camp is not available for the selected age group.', $message);
        $this->assertSame([], intersoccer_express_error_notice_messages(), 'validator notices must not stay queued for the next page');
    }

    public function testStoreApiRecheckSkipsNonExpressCheckout()
    {
        $calls = 0;
        MockFilters::$filters['woocommerce_add_to_cart_validation'] = function ($passed) use (&$calls) {
            $calls++;
            return false;
        };
        WC()->cart = $this->cartWith([
            'k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::SINGLE_VAR, 'assigned_player_id' => 'uuid-1'],
        ]);
        $normal = new IntersoccerDaysGateTestRequest(['payment_data' => [['key' => 'wc-stripe-new-payment-method', 'value' => 'false']]]);
        $thrown = null;
        try {
            intersoccer_express_days_gate_store_api_checkout($this->order([]), $normal);
        } catch (Exception $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown, 'Normal block checkout is not rechecked here');
        $this->assertSame(0, $calls);
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

    public function testProductPageGetsHideClassOnlyWhenCartHasPrograms()
    {
        $GLOBALS['intersoccer_test_is_product'] = true;
        WC()->cart = $this->cartWith(['x' => ['product_id' => self::CAMP_ID, 'variation_id' => self::WEEK_VAR, 'assigned_player' => 0]]);
        $this->assertContains('intersoccer-express-cart-has-programs', intersoccer_express_gate_cart_programs_body_class(['a']));

        WC()->cart = $this->cartWith([]);
        $this->assertNotContains('intersoccer-express-cart-has-programs', intersoccer_express_gate_cart_programs_body_class(['a']));

        // A cart holding only a non-program product (no player needed) does not hide express.
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';
        $GLOBALS['intersoccer_test_product_name'] = 'Club Jersey';
        WC()->cart = $this->cartWith(['x' => ['product_id' => 880999]]);
        $this->assertNotContains('intersoccer-express-cart-has-programs', intersoccer_express_gate_cart_programs_body_class(['a']));
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_name'] = 'Autumn Camp';

        WC()->cart = $this->cartWith(['x' => ['product_id' => self::CAMP_ID, 'variation_id' => self::WEEK_VAR, 'assigned_player' => 0]]);
        $GLOBALS['intersoccer_test_is_product'] = false;
        $this->assertSame(['a'], intersoccer_express_gate_cart_programs_body_class(['a']), 'not on a product page');
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

    public function testStoreExpressFieldsAjaxStoresAndClears()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR,
                  'camp_days' => ['Monday'], 'late_pickup_type' => 'single-days', 'late_pickup_days' => ['Monday']];
        try { intersoccer_store_express_fields_callback(); } catch (Exception $e) { $this->assertSame('AJAX_EXIT', $e->getMessage()); }
        $this->assertFalse($GLOBALS['intersoccer_test_json']['data']['cleared']);
        $fields = intersoccer_get_stashed_express_fields(self::CAMP_ID, self::SINGLE_VAR);
        $this->assertSame(['Monday'], $fields['camp_days']);
        $this->assertSame('single-days', $fields['late_pickup_type']);

        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'variation_id' => (string) self::SINGLE_VAR];
        try { intersoccer_store_express_fields_callback(); } catch (Exception $e) { $this->assertSame('AJAX_EXIT', $e->getMessage()); }
        $this->assertTrue($GLOBALS['intersoccer_test_json']['data']['cleared']);
        $this->assertSame([], intersoccer_get_stashed_express_fields(self::CAMP_ID, self::SINGLE_VAR));
    }

    /* ---- Express-only restore on the normal add-to-cart hooks ---- */

    public function testClassicAddToCartIgnoresStaleStash()
    {
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Tuesday']); // left over from an earlier visit
        $_POST = ['assigned_player_id' => 'uuid-1'];
        $data = intersoccer_add_custom_cart_item_data([], self::CAMP_ID, self::SINGLE_VAR);
        $this->assertArrayNotHasKey('camp_days', $data, 'classic add-to-cart must not pick up stashed days');
        $this->assertArrayNotHasKey('camp_days', $_POST);
        $this->assertFalse(intersoccer_validate_cart_item(true, self::CAMP_ID, 1, self::SINGLE_VAR), 'classic add with no posted days is refused');
    }

    public function testExpressAddToCartRestoresStashOnNormalHooks()
    {
        intersoccer_stash_selected_days(self::CAMP_ID, self::SINGLE_VAR, ['Tuesday']);
        $_GET['wc-ajax'] = 'wc_stripe_add_to_cart';
        $_POST = ['assigned_player_id' => 'uuid-1'];
        $data = intersoccer_add_custom_cart_item_data([], self::CAMP_ID, self::SINGLE_VAR);
        $this->assertSame(['Tuesday'], $data['camp_days']);
    }

    public function testClassicExpressCheckoutRefusesLineWithoutDays()
    {
        $_POST = ['express_checkout_type' => 'apple_pay'];
        WC()->cart = $this->cartWith(['k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::GERMAN_VAR, 'assigned_player' => 0]]);
        $errors = $this->errors();
        intersoccer_express_days_gate_classic_checkout([], $errors);
        $this->assertSame([intersoccer_express_gate_message()], $errors->errors['intersoccer_payment_not_completed'] ?? null);

        WC()->cart = $this->cartWith(['k' => ['product_id' => self::CAMP_ID, 'variation_id' => self::GERMAN_VAR, 'assigned_player' => 0, 'camp_days' => ['Monday']]]);
        $ok = $this->errors();
        intersoccer_express_days_gate_classic_checkout([], $ok);
        $this->assertSame([], $ok->errors);
    }

    /* ---- Late pickup cost on the express path (recomputed, never posted) ---- */

    private function expressLatePickupLine(array $stash)
    {
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_per_day'] = 25.0;
        $GLOBALS['intersoccer_test_options']['intersoccer_late_pickup_full_week'] = 90.0;
        update_post_meta(self::WEEK_VAR, '_intersoccer_enable_late_pickup', 'yes');
        intersoccer_stash_express_fields(self::CAMP_ID, self::WEEK_VAR, $stash);
        $_GET['wc-ajax'] = 'wc_stripe_add_to_cart';
        $_POST = ['assigned_player_id' => 'uuid-1', 'late_pickup_cost' => '0.01'];
        intersoccer_restore_stashed_express_fields_into_post(self::CAMP_ID, self::WEEK_VAR);
        return intersoccer_add_custom_cart_item_data([], self::CAMP_ID, self::WEEK_VAR);
    }

    public function testExpressLatePickupFullWeekCost()
    {
        $line = $this->expressLatePickupLine(['late_pickup_type' => 'full-week']);
        $this->assertSame('full-week', $line['late_pickup_type']);
        $this->assertEquals(90.0, (float) $line['late_pickup_cost']);
    }

    public function testExpressLatePickupSingleDaysCost()
    {
        $line = $this->expressLatePickupLine(['late_pickup_type' => 'single-days', 'late_pickup_days' => ['Monday', 'Wednesday', 'Friday']]);
        $this->assertSame(['Monday', 'Wednesday', 'Friday'], $line['late_pickup_days']);
        $this->assertEquals(75.0, (float) $line['late_pickup_cost']);
    }

    public function testExpressLatePickupFiveSingleDaysUseWeekRate()
    {
        $line = $this->expressLatePickupLine(['late_pickup_type' => 'single-days', 'late_pickup_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']]);
        $this->assertEquals(90.0, (float) $line['late_pickup_cost']);
    }

    /* ---- Billing phone on Store API checkout ---- */

    private function phoneOrder($phone)
    {
        return new class($phone) {
            private $p;
            public function __construct($p) { $this->p = $p; }
            public function get_billing_phone() { return $this->p; }
        };
    }

    private function expressPhoneRequest(array $billing)
    {
        return new IntersoccerDaysGateTestRequest([
            'billing_address' => $billing,
            'payment_data' => [['key' => 'express_payment_type', 'value' => 'google_pay']],
        ]);
    }

    public function testNonExpressStoreApiCheckoutWithoutPhonePasses()
    {
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = 'required';
        $thrown = null;
        try {
            intersoccer_store_api_require_billing_phone(
                $this->phoneOrder(''),
                new IntersoccerDaysGateTestRequest(['billing_address' => ['phone' => ''], 'payment_data' => []])
            );
        } catch (Exception $e) {
            $thrown = $e;
        }
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        $this->assertNull($thrown, 'normal block checkout is left to its own phone validation');
    }

    public function testExpressCheckoutWithoutPhonePassesWhenPhoneIsOptional()
    {
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = 'optional';
        $thrown = null;
        try {
            intersoccer_store_api_require_billing_phone($this->phoneOrder(''), $this->expressPhoneRequest(['phone' => '']));
        } catch (Exception $e) {
            $thrown = $e;
        }
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        $this->assertNull($thrown, 'store marks the phone optional');
    }

    public function testStoreApiCheckoutWithoutPhoneIsRefusedWith400()
    {
        try {
            intersoccer_store_api_require_billing_phone($this->phoneOrder(''), $this->expressPhoneRequest(['phone' => '  ']));
            $this->fail('Expected the checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_billing_phone_required_message(), $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function testStoreApiCheckoutWithPhonePasses()
    {
        $thrown = null;
        try {
            intersoccer_store_api_require_billing_phone($this->phoneOrder('+41 00 000 00 00'), $this->expressPhoneRequest([]));
            intersoccer_store_api_require_billing_phone($this->phoneOrder(''), $this->expressPhoneRequest(['phone' => '+41 00 000 00 00']));
        } catch (Exception $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown);
    }

    public function testPhoneNotRequiredWhenStoreHidesPhoneField()
    {
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = 'hidden';
        $thrown = null;
        try {
            intersoccer_store_api_require_billing_phone($this->phoneOrder(''), $this->expressPhoneRequest([]));
        } catch (Exception $e) {
            $thrown = $e;
        }
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        $this->assertNull($thrown);
    }

    public function testStripeExpressParamsForcePayerPhoneWhenRequired()
    {
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = 'required';
        $params = intersoccer_express_force_payer_phone(['checkout' => ['needs_payer_phone' => false, 'currency_code' => 'chf']]);
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        $this->assertTrue($params['checkout']['needs_payer_phone']);
        $this->assertSame('chf', $params['checkout']['currency_code']);
    }

    /**
     * @dataProvider phoneNotRequiredSettings
     */
    public function testStripeExpressParamsLeftAloneWhenPhoneNotRequired($setting)
    {
        $GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field'] = $setting;
        $off = intersoccer_express_force_payer_phone(['checkout' => ['needs_payer_phone' => false]]);
        $on = intersoccer_express_force_payer_phone(['checkout' => ['needs_payer_phone' => true]]);
        unset($GLOBALS['intersoccer_test_options']['woocommerce_checkout_phone_field']);
        $this->assertFalse($off['checkout']['needs_payer_phone']);
        $this->assertTrue($on['checkout']['needs_payer_phone'], "Stripe's own value is kept");
    }

    public static function phoneNotRequiredSettings()
    {
        return [['optional'], ['hidden']];
    }
}
