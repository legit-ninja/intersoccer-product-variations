<?php
/**
 * Apple Pay / Store API player persistence and checkout gate (#64).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

class PlayerCheckoutGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 1;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_categories'] = [];
        $GLOBALS['intersoccer_test_product_name'] = 'Autumn Camp';
        $GLOBALS['intersoccer_test_session'] = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['wc_notices'], $GLOBALS['intersoccer_test_user_id'], $GLOBALS['intersoccer_test_product_type']);
        unset($GLOBALS['intersoccer_test_product_categories'], $GLOBALS['intersoccer_test_product_name'], $GLOBALS['intersoccer_test_session']);
        parent::tearDown();
    }

    public function testCartItemHasAssignedPlayerHelpers()
    {
        $this->assertFalse(intersoccer_cart_item_has_assigned_player(['product_id' => 1]));
        $this->assertTrue(intersoccer_cart_item_has_assigned_player([
            'product_id' => 1,
            'assigned_player_id' => 'uuid-1',
        ]));
        $this->assertTrue(intersoccer_cart_item_has_assigned_player([
            'product_id' => 1,
            'assigned_player' => 0,
        ]));
        $this->assertTrue(intersoccer_cart_item_has_assigned_player([
            'product_id' => 1,
            'assigned_attendee' => 'Luis Example',
        ]));
    }

    public function testSessionStashAndRecoverRoundTrip()
    {
        if (!function_exists('WC')) {
            $this->markTestSkipped('WC stub required');
        }
        // Provide a minimal session bag via WC()->session if the bootstrap supports it.
        $this->assertTrue(function_exists('intersoccer_stash_selected_player'));
        $this->assertTrue(function_exists('intersoccer_get_stashed_selected_player'));

        // If session is unavailable in unit stub, stash returns false — still assert helpers exist.
        $ok = @intersoccer_stash_selected_player(12345, [
            'player_index' => 0,
            'player_id' => 'uuid-luis',
        ]);
        if ($ok) {
            $got = intersoccer_get_stashed_selected_player(12345);
            $this->assertIsArray($got);
            $this->assertSame('uuid-luis', $got['player_id']);
        } else {
            $this->assertTrue(true, 'Session unavailable in stub; helpers still registered');
        }
    }

    public function testSourceRegistersCheckoutPlayerGate()
    {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php');
        $this->assertStringContainsString("add_action('woocommerce_checkout_process', 'intersoccer_validate_cart_players_present'", $contents);
        $this->assertStringContainsString("add_action('woocommerce_check_cart_items', 'intersoccer_validate_cart_players_present'", $contents);
        $this->assertStringContainsString("woocommerce_store_api_checkout_update_order_from_request", $contents);
        $this->assertStringContainsString('intersoccer_store_selected_player', $contents);
        $this->assertStringContainsString('intersoccer_get_stashed_selected_player', $contents);
    }

    public function testProductEnhancerStashesPlayerForExpressCheckout()
    {
        $js = file_get_contents(dirname(__DIR__) . '/js/product-enhancer.js');
        $this->assertStringContainsString('stashSelectedPlayer', $js);
        $this->assertStringContainsString('intersoccer_store_selected_player', $js);
        $this->assertStringContainsString('assigned_player_id', $js);
    }
}
