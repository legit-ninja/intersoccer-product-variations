<?php
/**
 * In-memory repro (sandbox DB, read-only): build the roster row for an order item the same way
 * RosterBuilder::processOrderItem does, then run the same validation RosterRepository::create runs
 * (Roster::validate()). Never calls create()/update(), so no roster row is written.
 *
 * Bug (b): order 62467 item 7887 (DE) must produce a valid row.
 * Bug (c): start_date must be the real camp start (2026-10-19), not empty/1970-01-01.
 * Controls: 62465/7885 (EN), 62466/7886 (FR), and EN/FR items re-validated with a genuine girls-only activity_type.
 *
 * Run (sql-sandbox dir):
 *   docker compose run --rm -v $WT/tests/repro:/repro:ro wpcli eval-file /repro/roster-from-order-item.php
 */
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

$builder = new RosterBuilder();
$rb = new ReflectionClass($builder);
$call = function ($name, ...$args) use ($rb, $builder) {
    $m = $rb->getMethod($name); $m->setAccessible(true); return $m->invoke($builder, ...$args);
};
$prop = function ($name) use ($rb, $builder) {
    $p = $rb->getProperty($name); $p->setAccessible(true); return $p->getValue($builder);
};

function repro_build_row($order_id, $item_id, $call, $prop, array $override = []) {
    $order = wc_get_order($order_id);
    $item = $order->get_item($item_id);
    $order_data = array_merge($call('extractOrderItemData', $order, $item_id, $item), $override);
    $players = $prop('player_repository')->getPlayersByCustomerId($order->get_customer_id());
    $assigned = $prop('player_matcher')->getAssignedPlayers($item, $players, ['skip_age_gender_validation' => true]);
    $customer = $call('extractCustomerData', $order);
    foreach ($assigned as $player) {
        $data = $call('buildRosterData', $order_data, $player, $customer);
        $prop('validator')->validateRosterData($data);
        $data = $call('ensureRosterProductIds', $data);
        return [$order_data, $data];
    }
    throw new RuntimeException("no assigned player for item $item_id");
}

$cases = [
    ['DE 62467/7887', 62467, 7887, []],
    ['EN 62465/7885', 62465, 7885, []],
    ['FR 62466/7886', 62466, 7886, []],
    ['EN 62465/7885 as genuine girls-only camp', 62465, 7885, ['activity_type' => 'Camp, Girls Only']],
    ['FR 62466/7886 as genuine girls-only camp (FR label)', 62466, 7886, ['activity_type' => 'Camp, Filles uniquement']],
];
$fail = 0;
foreach ($cases as [$label, $oid, $iid, $override]) {
    echo "== $label\n";
    try {
        [$order_data, $data] = repro_build_row($oid, $iid, $call, $prop, $override);
        printf("   activity_type=%s booking_type=%s girls_only=%s start_date=%s camp_start_date=%s\n",
            json_encode($data['activity_type'] ?? null, JSON_UNESCAPED_UNICODE), json_encode($data['booking_type'] ?? null),
            json_encode($data['girls_only'] ?? null), json_encode($data['start_date'] ?? null), json_encode($data['camp_start_date'] ?? null));
        // (b) row must validate
        $roster = new Roster($data);
        $b_ok = true;
        try { $roster->validate(); } catch (ValidationException $e) {
            $b_ok = false;
            echo "   (b) FAIL: " . $e->getMessage() . ' ' . json_encode($e->getValidationErrors(), JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ($b_ok) { echo "   (b) PASS: row validates\n"; }
        // (b) booking type for a full-week camp line
        $bt_ok = ($data['booking_type'] ?? '') === 'Full Week';
        echo '   (b) ' . ($bt_ok ? 'PASS' : 'FAIL') . ': booking_type expected "Full Week", got ' . json_encode($data['booking_type'] ?? null) . "\n";
        // (c) start date
        $row = $roster->toArray();
        $stored = $row['start_date'] ?? null;
        $c_ok = ($data['start_date'] ?? null) === '2026-10-19';
        echo '   (c) ' . ($c_ok ? 'PASS' : 'FAIL') . ': start_date expected "2026-10-19", got ' . json_encode($data['start_date'] ?? null)
            . ' (model toArray: ' . json_encode($stored) . '; DB column default is 1970-01-01)' . "\n";
        $fail += (int) !$b_ok + (int) !$bt_ok + (int) !$c_ok;
    } catch (Throwable $t) {
        $fail++;
        echo "   ERROR " . get_class($t) . ': ' . $t->getMessage() . "\n";
    }
}
echo "-- helper probes (DE/FR/EN normalisation)\n";
foreach (['full-week', 'semaine-complete', 'ganze-woche', 'Ganze Woche', 'other'] as $v) {
    printf("   RR intersoccer_normalize_booking_type_for_storage(%s)=%s | PV intersoccer_normalize_canonical_booking_type=%s\n",
        json_encode($v), json_encode(intersoccer_normalize_booking_type_for_storage($v)), json_encode(intersoccer_normalize_canonical_booking_type($v)));
}
echo $fail ? "FAILED assertions: $fail\n" : "OK\n";
