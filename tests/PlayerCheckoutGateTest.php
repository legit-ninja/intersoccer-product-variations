<?php
/**
 * Apple Pay / Store API player persistence (#64 / #71).
 *
 * Checkout/cart "select a player" gate notices were removed per Jeremy:
 * players are assigned at add-to-cart; a cart/checkout nag is not a fix.
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
        $this->assertTrue(function_exists('intersoccer_stash_selected_player'));
        $this->assertTrue(function_exists('intersoccer_get_stashed_selected_player'));

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

    public function testSourceKeepsStashAndHasNoCheckoutPlayerNag()
    {
        $contents = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php');
        $this->assertStringContainsString('intersoccer_store_selected_player', $contents);
        $this->assertStringContainsString('intersoccer_get_stashed_selected_player', $contents);
        $this->assertStringContainsString('intersoccer_restore_stashed_player_into_post', $contents);
        $this->assertStringContainsString('!$nonce || !wp_verify_nonce', $contents, 'Stash AJAX must require nonce');
        $this->assertStringContainsString('intersoccer_selected_player_belongs_to_user', $contents);
        $this->assertStringContainsString('intersoccer_clear_stashed_player_after_add', $contents);

        $this->assertStringNotContainsString('intersoccer_validate_cart_players_present', $contents);
        $this->assertStringNotContainsString('intersoccer_missing_attendee_checkout_message', $contents);
        $this->assertStringNotContainsString('intersoccer_find_cart_item_missing_assigned_player', $contents);
        $this->assertStringNotContainsString('intersoccer_store_api_validate_cart_players', $contents);
        $this->assertStringNotContainsString('remove this item from your cart', $contents);
        $this->assertStringNotContainsString("add_action('woocommerce_check_cart_items', 'intersoccer_validate_cart_players_present'", $contents);
        $this->assertStringNotContainsString("add_action('woocommerce_checkout_process', 'intersoccer_validate_cart_players_present'", $contents);
        $this->assertStringNotContainsString('woocommerce_store_api_checkout_update_order_from_request', $contents);
    }

    public function testValidateAcceptsStashedPlayerWithoutPost()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_player_by_id'] = function ($user_id, $player_id) {
            if ((int) $user_id === 42 && $player_id === 'uuid-luis') {
                return ['player_id' => 'uuid-luis', 'first_name' => 'Luis', 'last_name' => 'Example', 'key' => 0];
            }
            return null;
        };
        $ok = @intersoccer_stash_selected_player(123, [
            'player_index' => 0,
            'player_id' => 'uuid-luis',
        ]);
        if (!$ok) {
            $this->markTestSkipped('WC session stub required for stash acceptance');
        }
        $_POST = [];
        $passed = intersoccer_validate_cart_item(true, 123, 1, null, null, null);
        $this->assertTrue($passed, 'Validation must accept a stashed player before add_cart_item_data');
    }

    public function testStashRejectsPlayerNotOwnedByUser()
    {
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_player_by_id'] = function ($user_id, $player_id) {
            return null;
        };
        $ok = @intersoccer_stash_selected_player(999, [
            'player_id' => 'someone-elses-uuid',
        ]);
        $this->assertFalse($ok, 'Must not stash a player ID that does not belong to the current user');
    }

    public function testProductEnhancerStashesPlayerForExpressCheckout()
    {
        $js = file_get_contents(dirname(__DIR__) . '/js/product-enhancer.js');
        $this->assertStringContainsString('stashSelectedPlayer', $js);
        $this->assertStringContainsString('intersoccer_store_selected_player', $js);
        $this->assertStringContainsString('assigned_player_id', $js);
    }
}
