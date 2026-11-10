<?php
/**
 * Girls-only add-to-cart when the storefront posts a player UUID.
 *
 * Numeric indexes still resolve. A posted player that cannot be resolved is rejected
 * with the existing girls-only notice.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';

class GirlsOnlyPostedPlayerUuidTest extends TestCase {

    private const BOY_UUID = '11111111-1111-4111-8111-111111111111';
    private const GIRL_UUID = '22222222-2222-4222-8222-222222222222';
    private const UNKNOWN_UUID = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void {
        parent::setUp();
        $_POST = [];
        $GLOBALS['wc_notices'] = [];
        $GLOBALS['intersoccer_test_user_id'] = 42;
        unset($GLOBALS['intersoccer_test_player_details'], $GLOBALS['intersoccer_test_player_by_id']);
        MockFilters::$filters['intersoccer_line_is_girls_only_program'] = static function () {
            return true;
        };
    }

    protected function tearDown(): void {
        $_POST = [];
        unset(
            $GLOBALS['wc_notices'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_player_details'],
            $GLOBALS['intersoccer_test_player_by_id']
        );
        unset(MockFilters::$filters['intersoccer_line_is_girls_only_program']);
        parent::tearDown();
    }

    public function test_uuid_boy_is_rejected_with_existing_wrong_gender_message(): void {
        $_POST['player_assignment'] = self::BOY_UUID;
        $this->stubPlayersById([
            0 => $this->player(self::BOY_UUID, 'male'),
        ]);

        $passed = $this->addToCart();

        $this->assertFalse($passed);
        $this->assertSame(
            'This session is for girls only. Please choose a female attendee or pick a different session.',
            $this->errorNotice()
        );
    }

    public function test_uuid_girl_is_allowed(): void {
        $_POST['player_assignment'] = self::GIRL_UUID;
        $this->stubPlayersById([
            0 => $this->player(self::GIRL_UUID, 'female'),
        ]);

        $passed = $this->addToCart();

        $this->assertTrue($passed);
        $this->assertSame([], $GLOBALS['wc_notices']);
    }

    public function test_numeric_index_boy_is_rejected(): void {
        $_POST['player_assignment'] = '0';
        $GLOBALS['intersoccer_test_player_details'] = [
            'first_name' => 'Test',
            'last_name' => 'Kid',
            'gender' => 'male',
        ];

        $passed = $this->addToCart();

        $this->assertFalse($passed);
        $this->assertSame(
            'This session is for girls only. Please choose a female attendee or pick a different session.',
            $this->errorNotice()
        );
    }

    public function test_numeric_index_girl_is_allowed(): void {
        $_POST['player_assignment'] = '1';
        $GLOBALS['intersoccer_test_player_details'] = [
            'first_name' => 'Test',
            'last_name' => 'Kid',
            'gender' => 'female',
        ];

        $passed = $this->addToCart();

        $this->assertTrue($passed);
        $this->assertSame([], $GLOBALS['wc_notices']);
    }

    public function test_unresolvable_posted_player_is_rejected_with_existing_message(): void {
        $_POST['player_assignment'] = self::UNKNOWN_UUID;

        $passed = $this->addToCart();

        $this->assertFalse($passed);
        $this->assertSame(intersoccer_pv_girls_only_gender_required_message(), $this->errorNotice());
    }

    /**
     * @param array<int|string, array<string, string>> $players
     */
    private function stubPlayersById(array $players): void {
        $GLOBALS['intersoccer_test_player_by_id'] = static function ($user_id, $player_id) use ($players) {
            $needle = strtolower((string) $player_id);
            foreach ($players as $key => $player) {
                if (!is_array($player)) {
                    continue;
                }
                $id = strtolower((string) ($player['player_id'] ?? ''));
                if ($id !== '' && $id === $needle) {
                    return ['key' => $key, 'player' => $player];
                }
            }
            return null;
        };
    }

    /**
     * @return array<string, string>
     */
    private function player(string $player_id, string $gender): array {
        return [
            'player_id' => $player_id,
            'first_name' => 'Test',
            'last_name' => 'Kid',
            'gender' => $gender,
        ];
    }

    /**
     * @return bool
     */
    private function addToCart() {
        return intersoccer_validate_player_girls_only_on_add_to_cart(true, 100, 1, 0, [], null);
    }

    private function errorNotice(): string {
        $this->assertCount(1, $GLOBALS['wc_notices']);
        $this->assertSame('error', $GLOBALS['wc_notices'][0]['type']);
        return (string) $GLOBALS['wc_notices'][0]['message'];
    }
}
