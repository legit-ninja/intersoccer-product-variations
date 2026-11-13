<?php
/**
 * Allowlisted coupon codes read from a front-end URL parameter.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('wc_format_coupon_code')) {
    function wc_format_coupon_code($value) {
        $value = sanitize_text_field((string) $value);
        return strtolower($value);
    }
}

if (!function_exists('wc_get_notices')) {
    /**
     * Supports the flat test-bootstrap list and Woo's type-keyed list.
     *
     * @param string $notice_type
     * @return array
     */
    function wc_get_notices($notice_type = '') {
        $all = (isset($GLOBALS['wc_notices']) && is_array($GLOBALS['wc_notices'])) ? $GLOBALS['wc_notices'] : [];
        if ($notice_type === '' || $notice_type === null) {
            return $all;
        }
        if (isset($all[$notice_type]) && is_array($all[$notice_type])) {
            $bucket = $all[$notice_type];
            if ($bucket === [] || array_key_exists(0, $bucket)) {
                return $bucket;
            }
        }
        $matched = [];
        foreach ($all as $notice) {
            if (is_array($notice) && isset($notice['type']) && $notice['type'] === $notice_type) {
                $matched[] = $notice;
            }
        }
        return $matched;
    }
}

if (!function_exists('wc_set_notices')) {
    function wc_set_notices($notices) {
        $GLOBALS['wc_notices'] = $notices;
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

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302) {
        $GLOBALS['intersoccer_test_redirect'] = $location;
        throw new UnexpectedValueException('redirect:' . $location);
    }
}

class IntersoccerUrlCouponTestSession {
    public $data = [];
    public $cookie_set = false;

    public function get($key, $default = null) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set($key, $value) {
        $this->data[$key] = $value;
    }

    public function set_customer_session_cookie($set) {
        if ($set) {
            $this->cookie_set = true;
        }
    }
}

class IntersoccerUrlCouponTestCart {
    public $items = [];
    public $applied = [];
    public $apply_calls = [];
    public $fail = false;
    public $subtotal = 0;
    public $calculated = 0;

    public function get_cart() {
        return $this->items;
    }

    public function is_empty() {
        return $this->items === [];
    }

    public function get_applied_coupons() {
        return $this->applied;
    }

    public function has_discount($code) {
        $needle = strtolower((string) $code);
        foreach ($this->applied as $applied) {
            if (strtolower((string) $applied) === $needle) {
                return true;
            }
        }
        return false;
    }

    public function get_subtotal() {
        return $this->subtotal;
    }

    public function apply_coupon($code) {
        $this->apply_calls[] = $code;
        if ($this->fail) {
            wc_add_notice('This coupon is not valid.', 'error');
            return false;
        }
        if ($this->has_discount($code)) {
            wc_add_notice('Coupon code already applied.', 'error');
            return false;
        }
        wc_add_notice('Coupon applied.', 'success');
        $this->applied[] = $code;
        return true;
    }

    public function calculate_totals() {
        $this->calculated++;
    }
}

class UrlCouponApplyTest extends TestCase {
    /** @var IntersoccerUrlCouponTestSession */
    private $session;

    /** @var IntersoccerUrlCouponTestCart */
    private $cart;

    protected function setUp(): void {
        parent::setUp();
        if (!isset($GLOBALS['intersoccer_test_options']) || !is_array($GLOBALS['intersoccer_test_options'])) {
            $GLOBALS['intersoccer_test_options'] = [];
        }
        $GLOBALS['intersoccer_test_options']['intersoccer_url_coupon_allowlist'] = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        unset($GLOBALS['intersoccer_test_redirect']);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/programs/';

        $this->session = new IntersoccerUrlCouponTestSession();
        $this->cart = new IntersoccerUrlCouponTestCart();
        if (!isset($GLOBALS['intersoccer_days_gate_wc']) || !is_object($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc'] = (object) ['session' => null, 'cart' => null];
        }
        $GLOBALS['intersoccer_days_gate_wc']->session = $this->session;
        $GLOBALS['intersoccer_days_gate_wc']->cart = $this->cart;

        require_once dirname(__DIR__) . '/includes/woocommerce/url-coupon.php';
    }

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
        $GLOBALS['wc_notices'] = [];
        unset($GLOBALS['intersoccer_test_redirect']);
        if (isset($GLOBALS['intersoccer_test_options']) && is_array($GLOBALS['intersoccer_test_options'])) {
            unset(
                $GLOBALS['intersoccer_test_options']['intersoccer_url_coupon_allowlist'],
                $GLOBALS['intersoccer_test_options']['intersoccer_campaign_offers']
            );
        }
        if (isset($GLOBALS['intersoccer_days_gate_wc']) && is_object($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc']->session = null;
            $GLOBALS['intersoccer_days_gate_wc']->cart = null;
        }
        parent::tearDown();
    }

    public function test_empty_allowlist_stores_nothing_and_does_not_apply(): void {
        $this->frontGet(['coupon' => 'TESTCODE10']);
        $this->giveCartItem();

        $this->runRegistered('init', 'intersoccer_url_coupon_capture_query_arg');

        $this->assertArrayNotHasKey('intersoccer_url_coupon_code', $this->session->data);
        $this->assertFalse($this->session->cookie_set);
        $this->assertSame([], $this->cart->apply_calls);
        $this->assertSame([], $GLOBALS['wc_notices']);
    }

    public function test_code_not_on_allowlist_is_ignored_without_coupon_lookup(): void {
        $this->allow(['TESTCODE10']);
        $this->frontGet(['coupon' => 'TESTOTHER']);
        $this->giveCartItem();

        intersoccer_url_coupon_capture_query_arg();
        intersoccer_url_coupon_maybe_apply();

        $this->assertArrayNotHasKey('intersoccer_url_coupon_code', $this->session->data);
        $this->assertSame([], $this->cart->apply_calls);
        $this->assertSame([], $this->cart->applied);
        $this->assertSame([], $GLOBALS['wc_notices']);
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/url-coupon.php');
        $this->assertStringNotContainsString('new WC_Coupon', $src);
        $this->assertStringNotContainsString('intersoccer_get_campaign_offers', $src);
        $this->assertStringNotContainsString('intersoccer_campaign_offers', $src);
    }

    public function test_allowlisted_code_is_normalised_and_stored(): void {
        $this->allow(["  TestCode10 \n"]);
        $this->frontGet([
            'coupon' => ' TeStCode10 ',
            'utm_source' => 'newsletter',
            'fbclid' => 'abc123',
        ]);

        $this->runRegistered('init', 'intersoccer_url_coupon_capture_query_arg');

        $this->assertSame('testcode10', $this->session->get('intersoccer_url_coupon_code'));
        $this->assertTrue($this->session->cookie_set);
        $this->assertSame([], $this->cart->apply_calls);
    }

    public function test_stored_code_survives_to_a_later_cart_request(): void {
        $this->allow(['TESTCODE10']);
        $this->frontGet(['coupon' => 'TESTCODE10', 'utm_source' => 'newsletter']);
        intersoccer_url_coupon_capture_query_arg();
        $this->assertSame('testcode10', $this->session->get('intersoccer_url_coupon_code'));

        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/cart/';
        $this->giveCartItem();
        $this->runRegistered('woocommerce_cart_loaded_from_session', 'intersoccer_url_coupon_maybe_apply', [$this->cart]);

        $this->assertSame(['testcode10'], $this->cart->applied);
        $this->assertSame(['testcode10'], $this->cart->apply_calls);
        $this->assertSame('', $this->session->get('intersoccer_url_coupon_code'));
        $this->assertSame([], $GLOBALS['wc_notices']);
    }

    public function test_apply_on_add_to_cart_when_cart_gains_an_item(): void {
        $this->allow(['TESTCODE10']);
        $this->frontGet(['coupon' => 'TESTCODE10']);
        intersoccer_url_coupon_capture_query_arg();
        $this->assertSame([], $this->cart->apply_calls);

        $_GET = [];
        $this->giveCartItem();
        $GLOBALS['wc_notices'] = [
            ['message' => 'Cart updated.', 'type' => 'success', 'data' => []],
        ];
        $this->runRegistered('woocommerce_add_to_cart', 'intersoccer_url_coupon_maybe_apply');

        $this->assertSame(['testcode10'], $this->cart->applied);
        $this->assertSame(1, $this->cart->calculated);
        $this->assertCount(1, $GLOBALS['wc_notices']);
        $this->assertSame('Cart updated.', $GLOBALS['wc_notices'][0]['message']);
    }

    public function test_apply_on_cart_and_checkout_load(): void {
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');
        $this->giveCartItem();

        $this->runRegistered('woocommerce_before_cart', 'intersoccer_url_coupon_maybe_apply');
        $this->assertSame(['testcode10'], $this->cart->applied);
        $this->assertSame([], $GLOBALS['wc_notices']);

        $this->cart->applied = [];
        $this->cart->apply_calls = [];
        $this->cart->calculated = 0;
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');
        $this->runRegistered('woocommerce_before_checkout_form', 'intersoccer_url_coupon_maybe_apply');
        $this->assertSame(['testcode10'], $this->cart->applied);
        $this->assertSame(1, $this->cart->calculated);
    }

    public function test_invalid_allowlisted_code_fails_silently_and_is_not_retried_until_cart_changes(): void {
        $this->allow(['TESTEXPIRED']);
        $this->session->set('intersoccer_url_coupon_code', 'testexpired');
        $this->giveCartItem();
        $this->cart->fail = true;
        $kept = $this->cart->items;

        intersoccer_url_coupon_maybe_apply();

        $this->assertSame($kept, $this->cart->items);
        $this->assertSame([], $this->cart->applied);
        $this->assertSame([], $GLOBALS['wc_notices']);
        $this->assertSame(['testexpired'], $this->cart->apply_calls);
        $this->assertSame('testexpired', $this->session->get('intersoccer_url_coupon_code'));
        $this->assertNotSame('', $this->session->get('intersoccer_url_coupon_failed'));
        $this->assertSame(0, $this->cart->calculated);

        intersoccer_url_coupon_maybe_apply();
        $this->assertSame(['testexpired'], $this->cart->apply_calls);

        $this->cart->items['line-1']['quantity'] = 2;
        intersoccer_url_coupon_maybe_apply();
        $this->assertSame(['testexpired', 'testexpired'], $this->cart->apply_calls);
        $this->assertSame([], $GLOBALS['wc_notices']);
        $this->assertSame([], $this->cart->applied);
    }

    public function test_empty_cart_keeps_the_code_for_later(): void {
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');

        intersoccer_url_coupon_maybe_apply();

        $this->assertSame([], $this->cart->apply_calls);
        $this->assertSame('testcode10', $this->session->get('intersoccer_url_coupon_code'));
    }

    public function test_already_applied_code_is_not_applied_again(): void {
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');
        $this->giveCartItem();
        $this->cart->applied = ['testcode10'];

        intersoccer_url_coupon_maybe_apply();

        $this->assertSame([], $this->cart->apply_calls);
        $this->assertSame([], $GLOBALS['wc_notices']);
        $this->assertSame('', $this->session->get('intersoccer_url_coupon_code'));
        $this->assertSame(0, $this->cart->calculated);
    }

    public function test_input_sanitisation_length_case_and_junk(): void {
        $long = str_repeat('a', 32);
        $too_long = str_repeat('b', 33);
        $saved = intersoccer_url_coupon_sanitize_allowlist(" TestCode10 \nTEST-OTHER\nbad code\n" . $too_long . "\n" . $long . "\n!!!\n<script>alert(1)</script>");

        $this->assertSame(['testcode10', 'test-other', $long], $saved);
        $this->assertSame('', intersoccer_url_coupon_normalize(''));
        $this->assertSame('', intersoccer_url_coupon_normalize('   '));
        $this->assertSame('', intersoccer_url_coupon_normalize($too_long));
        $this->assertSame('', intersoccer_url_coupon_normalize('TEST CODE'));
        $this->assertSame('', intersoccer_url_coupon_normalize(['TESTCODE10']));
        $this->assertSame('testcode10', intersoccer_url_coupon_normalize('TeStCode10'));
        $this->assertSame(32, INTERSOCCER_URL_COUPON_MAX_LENGTH);
    }

    public function test_tracking_parameters_do_not_block_reading_or_stripping(): void {
        $this->allow(['TESTCODE10']);
        $_GET = [
            'coupon' => 'TeStCode10',
            'utm_source' => 'newsletter',
            'fbclid' => 'abc123',
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/programs/?coupon=TeStCode10&utm_source=newsletter&fbclid=abc123';

        intersoccer_url_coupon_capture_query_arg();
        $target = intersoccer_url_coupon_redirect_target_for_request();

        $this->assertSame('testcode10', $this->session->get('intersoccer_url_coupon_code'));
        $this->assertSame('/programs/?utm_source=newsletter&fbclid=abc123', $target);
        $this->assertStringNotContainsString('coupon=', $target);
    }

    public function test_post_admin_and_ajax_do_not_read_or_strip(): void {
        $this->allow(['TESTCODE10']);
        $_GET = ['coupon' => 'TESTCODE10'];
        $_SERVER['REQUEST_URI'] = '/programs/?coupon=TESTCODE10';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        intersoccer_url_coupon_capture_query_arg();
        intersoccer_url_coupon_maybe_redirect();
        $this->assertArrayNotHasKey('intersoccer_url_coupon_code', $this->session->data);
        $this->assertSame('', intersoccer_url_coupon_redirect_target_for_request());
        $this->assertArrayNotHasKey('intersoccer_test_redirect', $GLOBALS);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['intersoccer_test_is_admin'] = true;
        intersoccer_url_coupon_capture_query_arg();
        $this->assertSame('', intersoccer_url_coupon_redirect_target_for_request());

        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        intersoccer_url_coupon_capture_query_arg();
        $this->assertArrayNotHasKey('intersoccer_url_coupon_code', $this->session->data);
        $this->assertSame('', intersoccer_url_coupon_redirect_target_for_request());
    }

    public function test_ajax_add_to_cart_still_applies_a_stored_code(): void {
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');
        $this->giveCartItem();
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;

        intersoccer_url_coupon_maybe_apply();

        $this->assertSame(['testcode10'], $this->cart->applied);
        $this->assertSame([], $GLOBALS['wc_notices']);
    }

    public function test_unlisted_code_in_session_is_cleared_without_lookup(): void {
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testother');
        $this->giveCartItem();

        intersoccer_url_coupon_maybe_apply();

        $this->assertSame([], $this->cart->apply_calls);
        $this->assertSame('', $this->session->get('intersoccer_url_coupon_code'));
    }

    public function test_allowlisted_code_is_not_stripped_when_session_is_missing(): void {
        $this->allow(['TESTCODE10']);
        $GLOBALS['intersoccer_days_gate_wc']->session = null;
        $_GET = ['coupon' => 'TESTCODE10', 'utm_source' => 'newsletter'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/programs/?coupon=TESTCODE10&utm_source=newsletter';

        $this->assertSame('', intersoccer_url_coupon_redirect_target_for_request());
    }

    public function test_hooks_are_registered_for_capture_apply_and_redirect(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/url-coupon.php');
        $this->assertStringContainsString("add_action('init', 'intersoccer_url_coupon_capture_query_arg', 20);", $src);
        $this->assertStringContainsString("add_action('woocommerce_add_to_cart', 'intersoccer_url_coupon_maybe_apply', 15);", $src);
        $this->assertStringContainsString("add_action('woocommerce_before_cart', 'intersoccer_url_coupon_maybe_apply', 1);", $src);
        $this->assertStringContainsString("add_action('woocommerce_before_checkout_form', 'intersoccer_url_coupon_maybe_apply', 1);", $src);
        $this->assertStringContainsString("add_action('woocommerce_cart_loaded_from_session', 'intersoccer_url_coupon_maybe_apply', 20);", $src);
        $this->assertStringContainsString("add_action('template_redirect', 'intersoccer_url_coupon_maybe_redirect', 1);", $src);
        $this->assertStringContainsString('REST_REQUEST', $src);
        $this->assertStringContainsString('wp_doing_ajax', $src);
        $this->assertTrue($this->hookHas('init', 'intersoccer_url_coupon_capture_query_arg'));
        $this->assertTrue($this->hookHas('template_redirect', 'intersoccer_url_coupon_maybe_redirect'));
    }

    public function test_feature_does_not_write_orders(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/url-coupon.php');
        $this->assertStringNotContainsString('update_post_meta', $src);
        $this->assertStringNotContainsString('add_order_note', $src);
        $this->assertStringNotContainsString('woocommerce_checkout_create_order', $src);
        $main = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
        $this->assertStringContainsString("'includes/woocommerce/url-coupon.php'", $main);
        $admin = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
        $this->assertStringContainsString('intersoccer_url_coupon_allowlist', $admin);
        $this->assertStringContainsString('intersoccer_url_coupon_sanitize_allowlist', $admin);
        $this->assertStringContainsString('manage_woocommerce', $admin);
    }

    public function test_url_coupon_counts_as_non_campaign_for_the_existing_sibling_option(): void {
        require_once dirname(__DIR__) . '/includes/woocommerce/campaign-offers.php';
        update_option('intersoccer_campaign_offers', [
            [
                'id' => 'testcamp10',
                'enabled' => true,
                'code' => 'TESTCAMP10',
                'name' => 'Sample offer',
                'percent' => 10,
            ],
        ]);
        $this->allow(['TESTCODE10']);
        $this->session->set('intersoccer_url_coupon_code', 'testcode10');
        $this->giveCartItem();
        intersoccer_url_coupon_maybe_apply();

        $this->assertTrue(intersoccer_cart_has_non_campaign_coupons($this->cart));

        $campaign_only = new IntersoccerUrlCouponTestCart();
        $campaign_only->applied = ['testcamp10'];
        $this->assertFalse(intersoccer_cart_has_non_campaign_coupons($campaign_only));

        $discounts = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/discounts.php');
        $start = strpos($discounts, 'if (!$has_coupons || !$disable_with_coupons)');
        $end = strpos($discounts, 'End camp multi-child discount check');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);
        $camp_block = substr($discounts, $start, $end - $start);
        $this->assertStringContainsString('progressive_week_2', $camp_block);
        $this->assertStringContainsString('progressive_week_3_plus', $camp_block);
        $this->assertStringContainsString("get_option('intersoccer_disable_sibling_discount_with_coupons', false)", $discounts);
    }

    /**
     * @param array<string,string> $query
     */
    private function frontGet(array $query) {
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/programs/?' . http_build_query($query);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_doing_ajax'] = false;
    }

    /**
     * @param array<int,string> $codes
     */
    private function allow(array $codes) {
        update_option('intersoccer_url_coupon_allowlist', intersoccer_url_coupon_sanitize_allowlist($codes));
    }

    private function giveCartItem() {
        $this->cart->items = [
            'line-1' => [
                'product_id' => 501,
                'variation_id' => 0,
                'quantity' => 1,
            ],
        ];
        $this->cart->subtotal = 100;
    }

    /**
     * @param array<int,mixed> $args
     */
    private function runRegistered($hook, $callback, array $args = []) {
        $this->assertTrue($this->hookHas($hook, $callback), $hook . ' should register ' . $callback);
        call_user_func_array($callback, $args);
    }

    /**
     * @param string $hook
     * @param string $callback
     * @return bool
     */
    private function hookHas($hook, $callback) {
        $actions = isset($GLOBALS['intersoccer_test_actions'][$hook]) ? $GLOBALS['intersoccer_test_actions'][$hook] : [];
        foreach ($actions as $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered === $callback) {
                    return true;
                }
            }
        }
        return false;
    }
}
