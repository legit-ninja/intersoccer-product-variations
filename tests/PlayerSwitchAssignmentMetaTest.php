<?php
/**
 * Admin player switch: selected-child snapshot only.
 *
 * Synthetic order items. In-memory readback after save(), not a database or browser session.
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

require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';
require_once dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';

if (!class_exists('PlayerSwitchAssignmentMetaTestItem')) {
    class PlayerSwitchAssignmentMetaTestItem {
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

if (!class_exists('PlayerSwitchAssignmentMetaTestOrder')) {
    class PlayerSwitchAssignmentMetaTestOrder {
        /** @var array<int,PlayerSwitchAssignmentMetaTestItem> */
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

class PlayerSwitchAssignmentMetaTest extends TestCase
{
    private const ORDER_ID = 88015341;
    private const CUSTOMER_ID = 88015342;
    private const ITEM_ID = 88015343;
    private const SIBLING_ID = 88015344;
    private const OLD_UUID = '11111111-1111-4111-8111-111111111111';
    private const NEW_UUID = '22222222-2222-4222-8222-222222222222';
    private const SIBLING_UUID = '33333333-3333-4333-8333-333333333333';

    /** @var array<int|string,mixed> */
    private $savedGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotGlobals([
            'intersoccer_test_is_admin',
            'intersoccer_test_orders',
            'intersoccer_test_player_by_id',
            'pv152_admin_test_players',
            'pv152_admin_test_players_by_uuid',
        ]);
        $GLOBALS['intersoccer_test_is_admin'] = true;
        if (!isset($GLOBALS['intersoccer_test_orders']) || !is_array($GLOBALS['intersoccer_test_orders'])) {
            $GLOBALS['intersoccer_test_orders'] = [];
        }
        $players = $this->playerRoster();
        $by_uuid = [];
        foreach ($players as $index => $player) {
            if (!empty($player['player_id'])) {
                $by_uuid[$player['player_id']] = [
                    'key' => $index,
                    'player' => $player,
                ];
            }
        }
        $GLOBALS['pv152_admin_test_players'] = [
            self::CUSTOMER_ID => $players,
        ];
        $GLOBALS['pv152_admin_test_players_by_uuid'] = [
            self::CUSTOMER_ID => $by_uuid,
        ];
        $GLOBALS['intersoccer_test_player_by_id'] = static function ($user_id, $player_id) {
            $map = $GLOBALS['pv152_admin_test_players_by_uuid'][$user_id] ?? [];
            return is_array($map) ? ($map[$player_id] ?? null) : null;
        };
    }

    protected function tearDown(): void
    {
        unset($_POST);
        $this->restoreGlobals();
        parent::tearDown();
    }

    public function test_full_child_to_missing_profile_removes_stale_dob_gender_medical_and_ids(): void
    {
        $item = $this->storedFullChildItem();
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '2']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(1, $read->save_count);
        $this->assertSame('Gamma Child', $read->get_meta('Assigned Attendee'));
        $this->assertSame('Gamma Child', $read->get_meta('assigned_attendee'));
        $this->assertSame(2, $read->get_meta('assigned_player'));
        $this->assertSame(2, $read->get_meta('_assigned_player'));
        $this->assertSame(2, $read->get_meta('Player Index'));
        $this->assertSame(2, $read->get_meta('intersoccer_player_index'));
        $this->assertMetaAbsent($read, 'assigned_player_id');
        $this->assertMetaAbsent($read, '_assigned_player_id');
        $this->assertMetaAbsent($read, 'Attendee DOB');
        $this->assertMetaAbsent($read, 'Player DOB');
        $this->assertMetaAbsent($read, 'Date de naissance du participant');
        $this->assertMetaAbsent($read, 'Attendee Gender');
        $this->assertMetaAbsent($read, 'gender');
        $this->assertMetaAbsent($read, 'Player Gender');
        $this->assertMetaAbsent($read, 'player_gender');
        $this->assertSame('', $this->rosterGenderFallback($read));
        $this->assertSame('None', $read->get_meta('Medical Conditions'));
        $this->assertFalse(intersoccer_order_meta_medical_conditions_has_value($read->get_meta('Medical Conditions')));
        $this->assertMetaAbsent($read, 'Player Medical');
        $this->assertMetaAbsent($read, 'medical_conditions');
        $this->assertMetaAbsent($read, 'Medizinische Bedingungen');
        $this->assertMetaAbsent($read, '_player_dob');
        $this->assertUnrelatedMetaKept($read);
        $this->assertPreviousUuidCannotResolve($read, 2, 'Gamma Child');
    }

    public function test_uuidless_index_zero_target_replaces_values_and_readback_uses_that_child(): void
    {
        $item = $this->storedFullChildItem();
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '0']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(1, $read->save_count);
        $this->assertSame('Beta Child', $read->get_meta('Assigned Attendee'));
        $this->assertSame(0, $read->get_meta('assigned_player'));
        $this->assertSame(0, $read->get_meta('_assigned_player'));
        $this->assertSame(0, $read->get_meta('Player Index'));
        $this->assertMetaAbsent($read, 'assigned_player_id');
        $this->assertMetaAbsent($read, '_assigned_player_id');
        $this->assertSame('2015-05-05', $read->get_meta('Attendee DOB'));
        $this->assertSame('2015-05-05', $read->get_meta('Player DOB'));
        $this->assertSame('2015-05-05', $read->get_meta('Date de naissance du participant'));
        $this->assertSame('Male', $read->get_meta('Attendee Gender'));
        $this->assertSame('Male', $read->get_meta('gender'));
        $this->assertSame('Male', $read->get_meta('Player Gender'));
        $this->assertSame('Male', $read->get_meta('player_gender'));
        $this->assertSame('Male', $this->rosterGenderFallback($read));
        $this->assertNotSame('Female', $read->get_meta('Attendee Gender'));
        $this->assertSame('Hay fever', $read->get_meta('Medical Conditions'));
        $this->assertSame('Hay fever', $read->get_meta('Medizinische Bedingungen'));
        $this->assertSame('Hay fever', $read->get_meta('Player Medical'));
        $this->assertSame('Hay fever', $read->get_meta('medical_conditions'));
        $this->assertNotSame('Asthma', $read->get_meta('Medical Conditions'));
        $this->assertUnrelatedMetaKept($read);
        $this->assertPreviousUuidCannotResolve($read, 0, 'Beta Child');
        $this->assertSame('Beta Child', intersoccer_discount_player_key($read));
    }

    public function test_nonempty_uuid_child_replaces_dob_gender_medical_and_both_ids(): void
    {
        $item = $this->storedFullChildItem();
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => self::NEW_UUID]);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(1, $read->save_count);
        $this->assertSame('Delta Child', $read->get_meta('Assigned Attendee'));
        $this->assertSame(3, $read->get_meta('assigned_player'));
        $this->assertSame(3, $read->get_meta('_assigned_player'));
        $this->assertSame(self::NEW_UUID, $read->get_meta('assigned_player_id'));
        $this->assertSame(self::NEW_UUID, $read->get_meta('_assigned_player_id'));
        $this->assertNotSame(self::OLD_UUID, $read->get_meta('assigned_player_id'));
        $this->assertNotSame(self::OLD_UUID, $read->get_meta('_assigned_player_id'));
        $this->assertSame('2016-06-06', $read->get_meta('Attendee DOB'));
        $this->assertSame('2016-06-06', $read->get_meta('Player DOB'));
        $this->assertSame('Male', $read->get_meta('Attendee Gender'));
        $this->assertSame('Male', $read->get_meta('gender'));
        $this->assertSame('Male', $this->rosterGenderFallback($read));
        $this->assertNotSame('Female', $read->get_meta('Attendee Gender'));
        $this->assertSame('Nut allergy', $read->get_meta('Medical Conditions'));
        $this->assertSame('Nut allergy', $read->get_meta('Player Medical'));
        $this->assertNotSame('Asthma', $read->get_meta('Medical Conditions'));
        $this->assertSame(self::NEW_UUID, intersoccer_discount_player_key($read));

        $resolved = intersoccer_admin_resolve_item_player_assignment($read, $this->playerRoster());
        $this->assertSame(self::NEW_UUID, $resolved['option_value']);
        $this->assertSame(self::NEW_UUID, $resolved['player_uuid']);
        $this->assertSame(3, $resolved['player_index']);
        $this->assertSame('Delta Child', $resolved['attendee_name']);
    }

    public function test_selected_child_gender_alias_and_medical_are_not_inherited(): void
    {
        $item = $this->storedFullChildItem();
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '5']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame('Zeta Child', $read->get_meta('Assigned Attendee'));
        $this->assertSame('Male', $read->get_meta('Attendee Gender'));
        $this->assertSame('Male', $read->get_meta('gender'));
        $this->assertSame('Male', $read->get_meta('player_gender'));
        $this->assertSame('Male', $this->rosterGenderFallback($read));
        $this->assertNotSame('Female', $read->get_meta('Attendee Gender'));
        $this->assertSame('Eczema', $read->get_meta('Medical Conditions'));
        $this->assertSame('Eczema', $read->get_meta('Player Medical'));
        $this->assertNotSame('Asthma', $read->get_meta('Medical Conditions'));
        $this->assertSame('2018-08-08', $read->get_meta('Attendee DOB'));
        $this->assertMetaAbsent($read, 'assigned_player_id');
        $this->assertMetaAbsent($read, '_assigned_player_id');
        $this->assertSame(5, $read->get_meta('assigned_player'));
        $this->assertSame(5, $read->get_meta('_assigned_player'));
    }

    public function test_whitespace_only_dob_gender_and_medical_are_treated_as_absent(): void
    {
        $item = $this->storedFullChildItem();
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '4']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame('Epsilon Child', $read->get_meta('Assigned Attendee'));
        $this->assertMetaAbsent($read, 'Attendee DOB');
        $this->assertMetaAbsent($read, 'Player DOB');
        $this->assertMetaAbsent($read, 'Attendee Gender');
        $this->assertMetaAbsent($read, 'gender');
        $this->assertMetaAbsent($read, 'Player Gender');
        $this->assertMetaAbsent($read, 'player_gender');
        $this->assertSame('', $this->rosterGenderFallback($read));
        $this->assertSame('None', $read->get_meta('Medical Conditions'));
        $this->assertFalse(intersoccer_order_meta_medical_conditions_has_value($read->get_meta('Medical Conditions')));
        $this->assertMetaAbsent($read, 'Player Medical');
        $this->assertMetaAbsent($read, 'Medizinische Bedingungen');
        $this->assertMetaAbsent($read, 'assigned_player_id');
        $this->assertMetaAbsent($read, '_assigned_player_id');
        $this->assertSame(4, $read->get_meta('assigned_player'));
        $this->assertPreviousUuidCannotResolve($read, 4, 'Epsilon Child');
    }

    public function test_same_player_resave_does_not_erase_snapshot(): void
    {
        $item = $this->storedFullChildItem();
        $before = $item->meta;
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '1']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(0, $read->save_count);
        $this->assertSame([], $read->update_log);
        $this->assertSame([], $read->delete_log);
        $this->assertSame($before, $read->meta);
        $this->assertSame(self::OLD_UUID, $read->get_meta('assigned_player_id'));
        $this->assertSame(self::OLD_UUID, $read->get_meta('_assigned_player_id'));
        $this->assertSame('2012-02-02', $read->get_meta('Attendee DOB'));
        $this->assertSame('Female', $read->get_meta('Attendee Gender'));
        $this->assertSame('Female', $read->get_meta('gender'));
        $this->assertSame('Asthma', $read->get_meta('Medical Conditions'));
    }

    public function test_missing_gender_switch_clears_plain_gender_before_reader_fallback(): void
    {
        $item = $this->storedFullChildItem();
        $item->meta['gender'] = 'Female';
        $item->meta['Player Gender'] = 'Female';
        $item->meta['Genre du participant'] = 'Female';
        $item->meta['Geschlecht des Teilnehmers'] = 'Female';
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '2']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertMetaAbsent($read, 'Attendee Gender');
        $this->assertMetaAbsent($read, 'gender');
        $this->assertMetaAbsent($read, 'Player Gender');
        $this->assertMetaAbsent($read, 'Genre du participant');
        $this->assertMetaAbsent($read, 'Geschlecht des Teilnehmers');
        $this->assertSame('', $this->rosterGenderFallback($read));
        $this->assertSame('None', $read->get_meta('Medical Conditions'));
        $this->assertFalse(intersoccer_order_meta_medical_conditions_has_value('None'));
        $this->assertMetaAbsent($read, 'Player Medical');
        $this->assertMetaAbsent($read, 'Medizinische Bedingungen');
    }

    public function test_same_player_uuid_resave_does_not_erase_snapshot(): void
    {
        $item = $this->storedFullChildItem();
        $before = $item->meta;
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => self::OLD_UUID]);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(0, $read->save_count);
        $this->assertSame([], $read->update_log);
        $this->assertSame([], $read->delete_log);
        $this->assertSame($before, $read->meta);
    }

    public function test_empty_selection_does_not_wipe_existing_child(): void
    {
        $item = $this->storedFullChildItem();
        $before = $item->meta;
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(0, $read->save_count);
        $this->assertSame([], $read->update_log);
        $this->assertSame([], $read->delete_log);
        $this->assertSame($before, $read->meta);
        $this->assertSame('Alpha Child', $read->get_meta('Assigned Attendee'));
        $this->assertSame(self::OLD_UUID, $read->get_meta('_assigned_player_id'));
    }

    public function test_unknown_selection_does_not_wipe_existing_child(): void
    {
        $item = $this->storedFullChildItem();
        $before = $item->meta;
        $this->bindOrder($item);

        $this->savePosted([self::ITEM_ID => '99']);

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(0, $read->save_count);
        $this->assertSame([], $read->delete_log);
        $this->assertSame($before, $read->meta);
    }

    public function test_sibling_line_and_unrelated_line_meta_stay_unchanged(): void
    {
        $item = $this->storedFullChildItem();
        $sibling = new PlayerSwitchAssignmentMetaTestItem();
        $sibling->meta = [
            'Assigned Attendee' => 'Sibling Child',
            'assigned_player' => 7,
            '_assigned_player' => 7,
            'assigned_player_id' => self::SIBLING_UUID,
            '_assigned_player_id' => self::SIBLING_UUID,
            'Attendee DOB' => '2011-11-11',
            'Attendee Gender' => 'Female',
            'Medical Conditions' => 'Sibling Note',
            'Activity Type' => 'Course',
            'Season' => 'Winter',
        ];
        $sibling_before = $sibling->meta;
        $order = new PlayerSwitchAssignmentMetaTestOrder(self::CUSTOMER_ID, [
            self::ITEM_ID => $item,
            self::SIBLING_ID => $sibling,
        ]);
        $GLOBALS['intersoccer_test_orders'][self::ORDER_ID] = $order;

        $this->savePosted([
            self::ITEM_ID => '2',
            self::SIBLING_ID => '',
        ]);

        $this->assertSame(self::CUSTOMER_ID, $order->get_customer_id());
        $this->assertSame(0, $sibling->save_count);
        $this->assertSame([], $sibling->update_log);
        $this->assertSame([], $sibling->delete_log);
        $this->assertSame($sibling_before, $sibling->meta);
        $this->assertSame(self::SIBLING_UUID, $sibling->get_meta('assigned_player_id'));
        $this->assertSame('2011-11-11', $sibling->get_meta('Attendee DOB'));
        $this->assertSame('Sibling Note', $sibling->get_meta('Medical Conditions'));

        $read = $this->readBack(self::ITEM_ID);
        $this->assertSame(1, $read->save_count);
        $this->assertSame('Gamma Child', $read->get_meta('Assigned Attendee'));
        $this->assertMetaAbsent($read, 'assigned_player_id');
        $this->assertUnrelatedMetaKept($read);
        $this->assertSame('Camp', $read->get_meta('Activity Type'));
        $this->assertNotSame('Course', $read->get_meta('Activity Type'));
    }

    /**
     * @param list<string> $keys
     */
    private function snapshotGlobals(array $keys): void
    {
        foreach ($keys as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? $GLOBALS[$key] : null;
        }
    }

    private function restoreGlobals(): void
    {
        foreach ($this->savedGlobals as $key => $value) {
            if ($value === null && !array_key_exists($key, $GLOBALS)) {
                continue;
            }
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        $this->savedGlobals = [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function playerRoster(): array
    {
        return [
            0 => [
                'first_name' => 'Beta',
                'last_name' => 'Child',
                'dob' => '2015-05-05',
                'gender' => 'Male',
                'medical_conditions' => 'Hay fever',
            ],
            1 => [
                'player_id' => self::OLD_UUID,
                'first_name' => 'Alpha',
                'last_name' => 'Child',
                'dob' => '2012-02-02',
                'gender' => 'Female',
                'medical_conditions' => 'Asthma',
            ],
            2 => [
                'first_name' => 'Gamma',
                'last_name' => 'Child',
                'dob' => '',
                'gender' => '',
                'medical_conditions' => '',
            ],
            3 => [
                'player_id' => self::NEW_UUID,
                'first_name' => 'Delta',
                'last_name' => 'Child',
                'dob' => '2016-06-06',
                'gender' => 'Male',
                'medical_conditions' => 'Nut allergy',
            ],
            4 => [
                'first_name' => 'Epsilon',
                'last_name' => 'Child',
                'dob' => '   ',
                'gender' => '   ',
                'medical_conditions' => '   ',
            ],
            5 => [
                'first_name' => 'Zeta',
                'last_name' => 'Child',
                'dob' => '2018-08-08',
                'gender' => '',
                'player_gender' => 'Male',
                'medical_conditions' => 'Eczema',
            ],
        ];
    }

    private function storedFullChildItem(): PlayerSwitchAssignmentMetaTestItem
    {
        $item = new PlayerSwitchAssignmentMetaTestItem();
        $item->meta = [
            'Assigned Attendee' => 'Alpha Child',
            'assigned_attendee' => 'Alpha Child',
            'assigned_player' => 1,
            '_assigned_player' => 1,
            'assigned_player_id' => self::OLD_UUID,
            '_assigned_player_id' => self::OLD_UUID,
            'Player Index' => 1,
            'intersoccer_player_index' => 1,
            'Attendee DOB' => '2012-02-02',
            'Player DOB' => '2012-02-02',
            'Date de naissance du participant' => '2012-02-02',
            'Attendee Gender' => 'Female',
            'gender' => 'Female',
            'Player Gender' => 'Female',
            'player_gender' => 'Female',
            'Medical Conditions' => 'Asthma',
            'Player Medical' => 'Asthma',
            'medical_conditions' => 'Asthma',
            'Medizinische Bedingungen' => 'Asthma',
            'Activity Type' => 'Camp',
            'Season' => 'Summer',
            'Base Price' => '100',
            'Variation ID' => '555',
        ];
        return $item;
    }

    private function bindOrder(PlayerSwitchAssignmentMetaTestItem $item): void
    {
        $GLOBALS['intersoccer_test_orders'][self::ORDER_ID] = new PlayerSwitchAssignmentMetaTestOrder(
            self::CUSTOMER_ID,
            [self::ITEM_ID => $item]
        );
    }

    /**
     * @param array<int|string,string> $posted
     */
    private function savePosted(array $posted): void
    {
        $_POST['player_assignment'] = $posted;
        intersoccer_save_player_assignment_from_admin(self::ORDER_ID, []);
    }

    private function readBack(int $item_id): PlayerSwitchAssignmentMetaTestItem
    {
        $order = $GLOBALS['intersoccer_test_orders'][self::ORDER_ID];
        $item = $order->get_item($item_id);
        $this->assertInstanceOf(PlayerSwitchAssignmentMetaTestItem::class, $item);
        return $item;
    }

    /**
     * Same candidate order as the roster field reader: Attendee Gender, then gender, then Player Gender.
     */
    private function rosterGenderFallback(PlayerSwitchAssignmentMetaTestItem $item): string
    {
        foreach (['Attendee Gender', 'gender', 'Player Gender'] as $key) {
            if (!array_key_exists($key, $item->meta)) {
                continue;
            }
            $value = trim((string) $item->meta[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function assertMetaAbsent(PlayerSwitchAssignmentMetaTestItem $item, string $key): void
    {
        $this->assertArrayNotHasKey($key, $item->meta);
        $this->assertSame('', $item->get_meta($key));
    }

    private function assertUnrelatedMetaKept(PlayerSwitchAssignmentMetaTestItem $item): void
    {
        $this->assertSame('Camp', $item->get_meta('Activity Type'));
        $this->assertSame('Summer', $item->get_meta('Season'));
        $this->assertSame('100', $item->get_meta('Base Price'));
        $this->assertSame('555', $item->get_meta('Variation ID'));
    }

    private function assertPreviousUuidCannotResolve(PlayerSwitchAssignmentMetaTestItem $item, $index, string $name): void
    {
        $plain = (string) $item->get_meta('assigned_player_id');
        $hidden = (string) $item->get_meta('_assigned_player_id');
        $this->assertSame('', $plain);
        $this->assertSame('', $hidden);

        $resolved = intersoccer_admin_resolve_item_player_assignment($item, $this->playerRoster());
        $this->assertSame((string) $index, (string) $resolved['option_value']);
        $this->assertSame('', $resolved['player_uuid']);
        $this->assertSame($index, $resolved['player_index']);
        $this->assertSame($name, $resolved['attendee_name']);
        $this->assertNotSame(self::OLD_UUID, $resolved['option_value']);

        $tokens = intersoccer_discount_player_identity_tokens($item);
        $this->assertNotContains(self::OLD_UUID, $tokens);
        $this->assertNotContains('Alpha Child', $tokens);
        $this->assertNotSame(self::OLD_UUID, intersoccer_discount_player_key($item));
    }
}
