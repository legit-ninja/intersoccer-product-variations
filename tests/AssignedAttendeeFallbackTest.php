<?php
/**
 * Fallback: fill Assigned Attendee (and related snapshot meta) from assigned_player_id on save.
 */

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/cart-calculations.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/express-checkout-player-gate.php';

if (!class_exists('IntersoccerAssignedAttendeeFallbackTestItem')) {
    class IntersoccerAssignedAttendeeFallbackTestItem {
        private $product_id;
        private $meta;

        public function __construct(int $product_id, array $meta) {
            $this->product_id = $product_id;
            $this->meta = $meta;
        }

        public function get_product_id() {
            return $this->product_id;
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

class AssignedAttendeeFallbackTest extends TestCase
{
    private const CAMP_ID = 880401;
    private const CUSTOMER_ID = 42;
    private const PLAYER_UUID = 'uuid-fallback-wrapper-0001';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_user_id'] = self::CUSTOMER_ID;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_product_name'] = 'Autumn Camp';
        unset($GLOBALS['intersoccer_test_player_by_id']);
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

    private function pmPlayerWrapper(): array
    {
        return [
            'key' => 3,
            'player' => [
                'player_id' => self::PLAYER_UUID,
                'first_name' => 'Mia',
                'last_name' => 'Example',
                'dob' => '2016-08-15',
                'gender' => 'Female',
                'medical_conditions' => '',
            ],
        ];
    }

    private function item(array $meta): IntersoccerAssignedAttendeeFallbackTestItem
    {
        $item = new IntersoccerAssignedAttendeeFallbackTestItem(self::CAMP_ID, $meta);
        $item->order = new class(self::CUSTOMER_ID) {
            private $id;

            public function __construct($id) {
                $this->id = $id;
            }

            public function get_customer_id() {
                return $this->id;
            }
        };
        return $item;
    }

    private function stubPlayerById(?callable $fn = null): void
    {
        $GLOBALS['intersoccer_test_player_by_id'] = $fn ?? function ($uid, $pid) {
            if ($uid === self::CUSTOMER_ID && $pid === self::PLAYER_UUID) {
                return $this->pmPlayerWrapper();
            }
            return null;
        };
    }

    #[Test]
    public function fills_assigned_attendee_from_pm_wrapper_shape(): void
    {
        $this->stubPlayerById();
        $item = $this->item(['assigned_player_id' => self::PLAYER_UUID]);
        $this->assertTrue(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
        $this->assertSame('Mia Example', $item->get_meta('Assigned Attendee'));
    }

    #[Test]
    public function wrapper_shape_fills_index_dob_and_gender_when_missing(): void
    {
        $this->stubPlayerById();
        $item = $this->item(['assigned_player_id' => self::PLAYER_UUID]);
        intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID);
        $this->assertSame(3, $item->get_meta('assigned_player'));
        $this->assertSame(3, $item->get_meta('_assigned_player'));
        $this->assertSame('2016-08-15', $item->get_meta('Attendee DOB'));
        $this->assertSame('Female', $item->get_meta('Attendee Gender'));
    }

    #[Test]
    public function flat_player_row_still_works(): void
    {
        $GLOBALS['intersoccer_test_player_by_id'] = function ($uid, $pid) {
            return ($uid === self::CUSTOMER_ID && $pid === 'uuid-flat')
                ? ['first_name' => 'Flat', 'last_name' => 'Row', 'dob' => '2015-01-01', 'gender' => 'Male']
                : null;
        };
        $item = $this->item(['assigned_player_id' => 'uuid-flat']);
        $this->assertTrue(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
        $this->assertSame('Flat Row', $item->get_meta('Assigned Attendee'));
        $this->assertSame('2015-01-01', $item->get_meta('Attendee DOB'));
    }

    #[Test]
    public function does_not_overwrite_existing_assigned_attendee(): void
    {
        $this->stubPlayerById();
        $item = $this->item([
            'assigned_player_id' => self::PLAYER_UUID,
            'Assigned Attendee' => 'Already Set',
        ]);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
        $this->assertSame('Already Set', $item->get_meta('Assigned Attendee'));
    }

    #[Test]
    public function does_not_overwrite_existing_player_index_or_snapshot_fields(): void
    {
        $this->stubPlayerById();
        $item = $this->item([
            'assigned_player_id' => self::PLAYER_UUID,
            'assigned_player' => 9,
            '_assigned_player' => 9,
            'Attendee DOB' => '2000-01-01',
            'Attendee Gender' => 'Other',
        ]);
        $this->assertTrue(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
        $this->assertSame(9, $item->get_meta('assigned_player'));
        $this->assertSame(9, $item->get_meta('_assigned_player'));
        $this->assertSame('2000-01-01', $item->get_meta('Attendee DOB'));
        $this->assertSame('Other', $item->get_meta('Attendee Gender'));
    }

    #[Test]
    public function returns_false_when_player_belongs_to_another_customer(): void
    {
        $this->stubPlayerById();
        $item = $this->item(['assigned_player_id' => self::PLAYER_UUID]);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($item, 7));
    }

    #[Test]
    public function returns_false_without_assigned_player_id(): void
    {
        $this->stubPlayerById();
        $item = $this->item([]);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
    }

    #[Test]
    public function returns_false_when_wrapper_has_no_name(): void
    {
        $GLOBALS['intersoccer_test_player_by_id'] = function () {
            return ['key' => 0, 'player' => ['first_name' => '', 'last_name' => '']];
        };
        $item = $this->item(['assigned_player_id' => self::PLAYER_UUID]);
        $this->assertFalse(intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID));
    }

    #[Test]
    public function hook_fills_program_line_on_save(): void
    {
        $this->stubPlayerById();
        $item = $this->item(['assigned_player_id' => self::PLAYER_UUID]);
        intersoccer_assigned_attendee_fallback_on_item_save($item);
        $this->assertSame('Mia Example', $item->get_meta('Assigned Attendee'));
    }

    #[Test]
    public function uses_player_gender_alias_when_gender_empty(): void
    {
        $GLOBALS['intersoccer_test_player_by_id'] = function () {
            return [
                'key' => 1,
                'player' => [
                    'first_name' => 'G',
                    'last_name' => 'Child',
                    'dob' => '2014-02-02',
                    'player_gender' => 'Male',
                ],
            ];
        };
        $item = $this->item(['assigned_player_id' => 'uuid-gender-alias']);
        intersoccer_fill_assigned_attendee_from_player_id($item, self::CUSTOMER_ID);
        $this->assertSame('Male', $item->get_meta('Attendee Gender'));
    }
}
