<?php
/**
 * Regression: #56 — product-page player stash must not write intersoccer_players names.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';

class PlayerNameRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_user_meta'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 901;
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset(
            $GLOBALS['intersoccer_test_user_meta'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_json_capture'],
            $GLOBALS['intersoccer_test_json']
        );
        parent::tearDown();
    }

    public function test_store_selected_player_does_not_mutate_intersoccer_players_meta()
    {
        $user_id = 901;
        $GLOBALS['intersoccer_test_user_meta'][$user_id]['intersoccer_players'] = [
            0 => [
                'player_id' => 'uuid-keep-names',
                'first_name' => 'Keep',
                'last_name' => 'Names',
                'dob' => '2015-01-01',
                'gender' => 'male',
            ],
        ];

        $_POST = [
            'nonce' => 'valid_nonce',
            'product_id' => 1001,
            'player_index' => '0',
            'player_id' => 'uuid-keep-names',
        ];

        try {
            intersoccer_store_selected_player_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }

        $stored = $GLOBALS['intersoccer_test_user_meta'][$user_id]['intersoccer_players'];
        $this->assertSame('Keep', $stored[0]['first_name']);
        $this->assertSame('Names', $stored[0]['last_name']);
    }
}
