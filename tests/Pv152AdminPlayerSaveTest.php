<?php
/**
 * PV #152: admin order save must not rewrite attendee meta when player selection is unchanged.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public function __construct($args = []) {}
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page(...$args) {
        return '';
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = null) {
        echo $text;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) {
        echo $text;
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = null) {
        echo $text;
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $echo = true) {
        $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        $result = ((bool) $checked === (bool) $current) ? ' checked="checked"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('intersoccer_get_user_players')) {
    function intersoccer_get_user_players($customer_id) {
        return $GLOBALS['pv152_admin_test_players'][$customer_id] ?? [];
    }
}

if (!function_exists('intersoccer_get_player_by_id')) {
    function intersoccer_get_player_by_id($customer_id, $player_id) {
        if (isset($GLOBALS['pv152_admin_test_players_by_uuid'][$customer_id][$player_id])) {
            return $GLOBALS['pv152_admin_test_players_by_uuid'][$customer_id][$player_id];
        }
        return null;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
require_once dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';

if (!class_exists('Pv152AdminPlayerSaveTestItem')) {
    class Pv152AdminPlayerSaveTestItem {
        public $meta = [];
        public $save_count = 0;
        public $delete_log = [];
        public $update_log = [];

        public function get_meta($key, $single = true) {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data($key, $value) {
            $this->meta[$key] = $value;
            $this->update_log[] = $key;
        }

        public function delete_meta_data($key) {
            unset($this->meta[$key]);
            $this->delete_log[] = $key;
        }

        public function save() {
            $this->save_count++;
        }
    }
}

if (!class_exists('Pv152AdminPlayerSaveTestOrder')) {
    class Pv152AdminPlayerSaveTestOrder {
        /** @var array<int,Pv152AdminPlayerSaveTestItem> */
        public $items;

        private $customer_id;

        public function __construct(int $customer_id, array $items) {
            $this->customer_id = $customer_id;
            $this->items = $items;
        }

        public function get_customer_id() {
            return $this->customer_id;
        }

        public function get_item($item_id) {
            return $this->items[(int) $item_id] ?? null;
        }
    }
}

class Pv152AdminPlayerSaveTest extends TestCase
{
    private const ORDER_ID = 88015221;
    private const CUSTOMER_ID = 88015222;
    private const ITEM_ID = 88015223;
    private const PLAYER_UUID = '88015224-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_current_user_can'] = ['edit_shop_orders' => true];
        $GLOBALS['pv152_admin_test_players'] = [
            self::CUSTOMER_ID => [
                1 => [
                    'player_id' => self::PLAYER_UUID,
                    'first_name' => 'Alex',
                    'last_name' => 'Player',
                    'dob' => '2014-01-01',
                    'gender' => 'Female',
                    'medical_conditions' => '',
                ],
            ],
        ];
        $GLOBALS['pv152_admin_test_players_by_uuid'] = [
            self::CUSTOMER_ID => [
                self::PLAYER_UUID => [
                    'key' => 1,
                    'player' => $GLOBALS['pv152_admin_test_players'][self::CUSTOMER_ID][1],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_current_user_can'],
            $GLOBALS['pv152_admin_test_players'],
            $GLOBALS['pv152_admin_test_players_by_uuid'],
            $GLOBALS['intersoccer_test_orders'],
            $_POST
        );
        parent::tearDown();
    }

    private function itemWithStoredAssignment(): Pv152AdminPlayerSaveTestItem
    {
        $item = new Pv152AdminPlayerSaveTestItem();
        $item->meta = [
            'Assigned Attendee' => 'Alex Player',
            'assigned_player' => 1,
            'assigned_player_id' => self::PLAYER_UUID,
            'Attendee DOB' => '2014-01-01',
            'Attendee Gender' => 'Female',
            'Medical Conditions' => 'None',
        ];
        return $item;
    }

    public function test_pv152_admin_save_unchanged_player_makes_no_writes(): void
    {
        $item = $this->itemWithStoredAssignment();
        $GLOBALS['intersoccer_test_orders'][self::ORDER_ID] = new Pv152AdminPlayerSaveTestOrder(
            self::CUSTOMER_ID,
            [self::ITEM_ID => $item]
        );

        $_POST['player_assignment'] = [self::ITEM_ID => '1'];

        intersoccer_save_player_assignment_from_admin(self::ORDER_ID, []);

        $this->assertSame(0, $item->save_count);
        $this->assertSame([], $item->delete_log);
        $this->assertSame([], $item->update_log);
    }

    public function test_pv152_admin_save_changed_player_updates_only_that_item(): void
    {
        $item = $this->itemWithStoredAssignment();
        $other = new Pv152AdminPlayerSaveTestItem();
        $other->meta = [
            'Assigned Attendee' => 'Other Child',
            'assigned_player' => 0,
            'assigned_player_id' => '88015225-0000-4000-8000-000000000003',
        ];

        $GLOBALS['intersoccer_test_orders'][self::ORDER_ID] = new Pv152AdminPlayerSaveTestOrder(
            self::CUSTOMER_ID,
            [
                self::ITEM_ID => $item,
                88015226 => $other,
            ]
        );

        $_POST['player_assignment'] = [
            self::ITEM_ID => self::PLAYER_UUID,
            88015226 => '88015225-0000-4000-8000-000000000003',
        ];

        intersoccer_save_player_assignment_from_admin(self::ORDER_ID, []);

        $this->assertSame(0, $item->save_count);
        $this->assertSame(0, $other->save_count);
        $this->assertSame([], $other->delete_log);
        $this->assertSame([], $other->update_log);

        $GLOBALS['pv152_admin_test_players'][self::CUSTOMER_ID][2] = [
            'player_id' => '88015227-0000-4000-8000-000000000004',
            'first_name' => 'New',
            'last_name' => 'Pick',
            'dob' => '2013-03-03',
            'gender' => 'Male',
            'medical_conditions' => 'Asthma',
        ];

        $_POST['player_assignment'] = [self::ITEM_ID => '2'];

        intersoccer_save_player_assignment_from_admin(self::ORDER_ID, []);

        $this->assertSame(1, $item->save_count);
        $this->assertSame('New Pick', $item->meta['Assigned Attendee']);
        $this->assertSame(2, (int) $item->meta['assigned_player']);
        $this->assertSame('88015227-0000-4000-8000-000000000004', $item->meta['assigned_player_id']);
    }
}
