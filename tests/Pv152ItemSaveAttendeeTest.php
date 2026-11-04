<?php
/**
 * PV #152: assigned-attendee fallback only on new order items (creation), not existing saves.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';

if (!class_exists('Pv152ItemSaveAttendeeTestItem')) {
    class Pv152ItemSaveAttendeeTestItem {
        private $product_id;
        private $item_id;
        private $meta;

        public function __construct(int $product_id, int $item_id, array $meta) {
            $this->product_id = $product_id;
            $this->item_id = $item_id;
            $this->meta = $meta;
        }

        public function get_product_id() {
            return $this->product_id;
        }

        public function get_id() {
            return $this->item_id;
        }

        public function get_meta($key, $single = true) {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
        }

        /** @var object|null */
        public $order = null;

        public function get_order() {
            return $this->order;
        }
    }
}

class Pv152ItemSaveAttendeeTest extends TestCase
{
    private const CAMP_ID = 88015211;
    private const CUSTOMER_ID = 88015212;
    private const PLAYER_UUID = '88015213-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_user_id'] = self::CUSTOMER_ID;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_name'] = 'Fixture Camp';
        $GLOBALS['intersoccer_test_player_by_id'] = function ($uid, $pid) {
            if ($uid === self::CUSTOMER_ID && $pid === self::PLAYER_UUID) {
                return [
                    'key' => 1,
                    'player' => [
                        'player_id' => self::PLAYER_UUID,
                        'first_name' => 'Sam',
                        'last_name' => 'Fixture',
                        'dob' => '2015-05-05',
                        'gender' => 'Male',
                    ],
                ];
            }
            return null;
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_player_by_id'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_product_type'],
            $GLOBALS['intersoccer_test_product_name']
        );
        parent::tearDown();
    }

    private function orderStub(): object
    {
        return new class(self::CUSTOMER_ID) {
            private $id;

            public function __construct($id) {
                $this->id = $id;
            }

            public function get_customer_id() {
                return $this->id;
            }
        };
    }

    public function test_pv152_item_save_on_existing_item_does_not_fill_attendee(): void
    {
        $existing = new Pv152ItemSaveAttendeeTestItem(self::CAMP_ID, 88015299, ['assigned_player_id' => self::PLAYER_UUID]);
        $existing->order = $this->orderStub();
        intersoccer_assigned_attendee_fallback_on_item_save($existing);
        $this->assertSame('', $existing->get_meta('Assigned Attendee'));

        $new = new Pv152ItemSaveAttendeeTestItem(self::CAMP_ID, 0, ['assigned_player_id' => self::PLAYER_UUID]);
        $new->order = $this->orderStub();
        intersoccer_assigned_attendee_fallback_on_item_save($new);
        $this->assertSame('Sam Fixture', $new->get_meta('Assigned Attendee'));
        $this->assertSame('2015-05-05', $new->get_meta('Attendee DOB'));
        $this->assertSame('Male', $new->get_meta('Attendee Gender'));
    }
}
