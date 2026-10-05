<?php
/**
 * Stripe express checkout (Apple Pay / Google Pay) player gate.
 *
 * Express buttons stay hidden until a player is chosen, and the server refuses
 * an express payment for a camp/course line with no player. The refusal uses a
 * generic payment message, never a "select a player" notice (#79 / #71 rule).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';

class ExpressCheckoutPlayerGateTest extends TestCase
{
    private const CAMP_ID = 880101;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_name'] = 'Autumn Camp';
        unset($GLOBALS['intersoccer_test_json'], $GLOBALS['intersoccer_test_json_capture']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset(
            $GLOBALS['wc_notices'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_name'],
            $GLOBALS['intersoccer_test_json'],
            $GLOBALS['intersoccer_test_json_capture']
        );
        parent::tearDown();
    }

    /** WP_Error stub in bootstrap has no add(); use a minimal collector. */
    private function errors()
    {
        return new class {
            public $errors = [];
            public function add($code, $message)
            {
                $this->errors[$code][] = $message;
            }
        };
    }

    private function campLine(array $extra = []): array
    {
        return array_merge(['product_id' => self::CAMP_ID, 'variation_id' => self::CAMP_ID + 1, 'quantity' => 1], $extra);
    }

    public function testMissingPlayerLineIsFlagged()
    {
        $missing = intersoccer_express_gate_missing_player_keys(['abc' => $this->campLine()]);
        $this->assertSame(['abc'], $missing);
    }

    public function testPlayerIndexZeroCountsAsAssigned()
    {
        $cart = [
            'a' => $this->campLine(['assigned_player' => 0]),
            'b' => $this->campLine(['assigned_player_id' => 'uuid-1']),
            'c' => $this->campLine(['assigned_attendee' => 'Luis Example']),
        ];
        $this->assertSame([], intersoccer_express_gate_missing_player_keys($cart));
    }

    public function testProductsWithoutPlayerRequirementAreIgnored()
    {
        $GLOBALS['intersoccer_test_product_type'] = 'tournament';
        $GLOBALS['intersoccer_test_product_name'] = 'Club Jersey';
        $this->assertSame([], intersoccer_express_gate_missing_player_keys(['x' => ['product_id' => 880201]]));
        $this->assertTrue(intersoccer_express_gate_add_to_cart_allowed(880201));
    }

    public function testClassicCheckoutAddsGenericErrorOnlyWhenPlayerMissing()
    {
        $errors = $this->errors();
        $this->assertTrue(intersoccer_express_gate_add_checkout_error(['k' => $this->campLine()], $errors));
        $this->assertArrayHasKey('intersoccer_payment_not_completed', $errors->errors);
        $this->assertSame(intersoccer_express_gate_message(), $errors->errors['intersoccer_payment_not_completed'][0]);

        $ok = $this->errors();
        $this->assertFalse(intersoccer_express_gate_add_checkout_error(['k' => $this->campLine(['assigned_player' => 0])], $ok));
        $this->assertSame([], $ok->errors);
    }

    public function testClassicCheckoutHookIgnoresNonExpressRequests()
    {
        $this->assertFalse(intersoccer_express_gate_is_classic_express_request());
        $_POST['payment_request_type'] = 'apple_pay';
        $this->assertTrue(intersoccer_express_gate_is_classic_express_request());
        $_POST = ['express_checkout_type' => 'google_pay'];
        $this->assertTrue(intersoccer_express_gate_is_classic_express_request());
    }

    public function testStoreApiCheckoutThrowsGenericErrorWhenPlayerMissing()
    {
        try {
            intersoccer_express_gate_assert_cart_has_players(['k' => $this->campLine()]);
            $this->fail('Expected the checkout to be refused');
        } catch (Exception $e) {
            $this->assertSame(intersoccer_express_gate_message(), $e->getMessage());
        }
    }

    public function testStoreApiCheckoutPassesWhenEveryLineHasPlayer()
    {
        intersoccer_express_gate_assert_cart_has_players([
            'a' => $this->campLine(['assigned_player' => 0]),
            'b' => $this->campLine(['assigned_player_id' => 'uuid-2']),
        ]);
        $this->assertTrue(true);
    }

    public function testExpressAddToCartNeedsLoggedInUserAndPlayer()
    {
        $GLOBALS['intersoccer_test_user_id'] = 0;
        $this->assertFalse(intersoccer_express_gate_add_to_cart_allowed(self::CAMP_ID), 'Guests cannot express-buy a camp');

        $GLOBALS['intersoccer_test_user_id'] = 42;
        $_POST = [];
        $this->assertFalse(intersoccer_express_gate_add_to_cart_allowed(self::CAMP_ID), 'No posted or stashed player');

        $_POST = ['player_assignment' => '0'];
        $this->assertTrue(intersoccer_express_gate_add_to_cart_allowed(self::CAMP_ID), 'Index 0 is a valid player');
    }

    public function testStripeAddToCartHookRefusesWithoutPlayer()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID];
        try {
            intersoccer_express_gate_stripe_add_to_cart();
            $this->fail('Expected a JSON error response');
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
        $this->assertSame(intersoccer_express_gate_message(), $GLOBALS['intersoccer_test_json']['data']['message']);
    }

    public function testStripeAddToCartHookLetsPlayerThrough()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['security' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'assigned_player_id' => 'uuid-1'];
        $this->assertNull(intersoccer_express_gate_stripe_add_to_cart());
        $this->assertArrayNotHasKey('intersoccer_test_json', $GLOBALS);
    }

    public function testStripeAddToCartHookLeavesBadNonceToStripe()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['security' => 'nope', 'product_id' => (string) self::CAMP_ID];
        $this->assertNull(intersoccer_express_gate_stripe_add_to_cart());
        $this->assertArrayNotHasKey('intersoccer_test_json', $GLOBALS);
    }

    public function testClearingPlayerClearsStash()
    {
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID, 'player_index' => '', 'player_id' => ''];
        try {
            intersoccer_store_selected_player_callback();
            $this->fail('Expected a JSON response');
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $this->assertTrue($GLOBALS['intersoccer_test_json']['success']);
        $this->assertTrue($GLOBALS['intersoccer_test_json']['data']['cleared']);
    }

    public function testGenericMessageDoesNotAskToSelectAPlayer()
    {
        $msg = strtolower(intersoccer_express_gate_message());
        $this->assertStringNotContainsString('player', $msg);
        $this->assertStringNotContainsString('attendee', $msg);
        $this->assertStringNotContainsString('select', $msg);
    }

    public function testCssHidesWithoutDisplayNone()
    {
        $css = intersoccer_express_gate_css();
        $this->assertStringContainsString('body.intersoccer-express-needs-player #wc-stripe-express-checkout-element', $css);
        $this->assertStringContainsString('visibility: hidden', $css);
        $this->assertStringContainsString('height: 0', $css);
        $this->assertStringNotContainsString('display', $css, 'display:none can stop Stripe mounting its iframe');
    }

    public function testFrontEndTogglesGateFromPlayerChoice()
    {
        $root = dirname(__DIR__);
        $widgets = file_get_contents($root . '/includes/elementor-widgets.php');
        $this->assertStringContainsString("toggleClass(intersoccerPvExpressGateClass, !hasPlayer)", $widgets);
        $this->assertStringContainsString("intersoccerStashSelectedPlayer('');", $widgets);

        $enhancer = file_get_contents($root . '/js/product-enhancer.js');
        $this->assertStringContainsString("intersoccer-express-needs-player", $enhancer);
        $this->assertStringNotContainsString('$expressContainer.hide()', $enhancer);

        $main = file_get_contents($root . '/intersoccer-product-variations.php');
        $this->assertStringContainsString("'includes/woocommerce/express-checkout-player-gate.php'", $main);
    }
}
