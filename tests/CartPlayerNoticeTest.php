<?php
/**
 * Cart/checkout must not nag customers to select a player when one is assigned.
 *
 * The #71 Apple Pay draft added a checkout/cart gate notice
 * ("Please remove this item from your cart and add it again with an attendee selected.").
 * That is not a fix: players are assigned at add-to-cart. Master must not ship it.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

class CartPlayerNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['wc_notices'], $GLOBALS['intersoccer_test_user_id'], $GLOBALS['intersoccer_test_product_type']);
        parent::tearDown();
    }

    /**
     * @group cart-player-notice
     * @group production-code
     */
    public function testSourceHasNoCheckoutPlayerSelectNag()
    {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php');
        $this->assertIsString($contents);
        $this->assertStringNotContainsString(
            'intersoccer_validate_cart_players_present',
            $contents,
            'Must not register the #71 cart/checkout player gate'
        );
        $this->assertStringNotContainsString(
            'intersoccer_missing_attendee_checkout_message',
            $contents
        );
        $this->assertStringNotContainsString(
            'remove this item from your cart and add it again with an attendee selected',
            $contents
        );
        $this->assertStringNotContainsString(
            'Please select an attendee before adding to cart.',
            $contents,
            'ATC must not emit a customer-facing select-attendee notice'
        );
        $this->assertStringNotContainsString(
            "woocommerce_check_cart_items', 'intersoccer_validate_cart_players_present",
            $contents
        );
        $this->assertStringNotContainsString(
            'woocommerce_store_api_checkout_update_order_from_request',
            $contents
        );
    }

    /**
     * @group cart-player-notice
     * @group production-code
     */
    public function testAssignedPlayerAtAddToCartProducesNoSelectNotice()
    {
        $_POST['player_assignment'] = '0';
        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);
        $this->assertTrue($passed);
        $this->assertEmpty($GLOBALS['wc_notices'], 'Assigned player must not produce a select-player notice');
        foreach ($GLOBALS['wc_notices'] as $notice) {
            $msg = strtolower((string) ($notice['message'] ?? ''));
            $this->assertStringNotContainsString('select an attendee', $msg);
            $this->assertStringNotContainsString('select a player', $msg);
            $this->assertStringNotContainsString('remove this item from your cart', $msg);
        }
    }

    /**
     * @group cart-player-notice
     * @group production-code
     */
    public function testMissingPlayerBlocksWithoutSelectNotice()
    {
        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);
        $this->assertFalse($passed, 'Still block ATC when no player is posted');
        $this->assertEmpty($GLOBALS['wc_notices'], 'Block must be silent (no select-attendee notice)');
    }

    /**
     * Cart line already carrying an assigned player must not look like a "select player" failure.
     *
     * @group cart-player-notice
     * @group production-code
     */
    public function testCartItemWithAssignedPlayerHasNoSelectNoticeFromValidation()
    {
        $cart_item = [
            'product_id' => 123,
            'variation_id' => 456,
            'assigned_player' => 0,
            'assigned_player_id' => 'uuid-luis',
            'assigned_attendee' => 'Luis Example',
        ];

        // Re-run ATC-style validation as if posting the same assignment again.
        $_POST['player_assignment'] = '0';
        $_POST['assigned_player_id'] = 'uuid-luis';
        $passed = intersoccer_validate_cart_item(true, (int) $cart_item['product_id'], 1, (int) $cart_item['variation_id'], null, $cart_item);
        $this->assertTrue($passed);
        $this->assertEmpty($GLOBALS['wc_notices']);

        $blob = strtolower(json_encode($GLOBALS['wc_notices']));
        $this->assertStringNotContainsString('select an attendee', $blob);
        $this->assertStringNotContainsString('select a player', $blob);
        $this->assertStringNotContainsString('remove this item from your cart', $blob);
    }
}
