<?php
// Real-WooCommerce repro (run with: wp eval-file - race|sequential coupon|points|none < this file). Creates one @example.test user per run; take a DB snapshot first and restore it afterwards.
// Deterministic repro: PV's product-page stash AJAX (intersoccer_store_selected_player) races the 2nd add-to-cart.
// WC_Session_Handler loads the whole session (incl. 'cart') at request start and writes the whole row back at
// shutdown when dirty. A stash request that started before the add-to-cart and ends after it writes back the
// stale 1-line cart. Usage: wp eval-file race.php <scenario: race|sequential> <extra: coupon|points|none>
[$scenario, $extra] = array_pad($args, 2, 'race');
$extra = $extra ?: 'none';
const PID = 51723; const VID = 51724;
$ATTS = ['attribute_pa_intersoccer-venues' => 'geneva-stade-de-varembe-nations', 'attribute_pa_age-group' => '5-13y-full-day', 'attribute_pa_camp-terms' => 'autumn-week-4-october-19-23-5-days', 'attribute_pa_camp-times' => '1000-1700', 'attribute_pa_booking-type' => 'full-week'];
$email = "repro-sibdrop-race-$scenario-$extra@example.test";
$u = get_user_by('email', $email);
$uid = $u ? $u->ID : wp_insert_user(['user_login' => "repro-sibdrop-race-$scenario-$extra", 'user_email' => $email, 'user_pass' => wp_generate_password(24), 'role' => 'customer']);
$mk = fn($fn, $dob) => ['player_id' => intersoccer_generate_player_id(), 'first_name' => $fn, 'last_name' => 'Synthetic', 'dob' => $dob, 'gender' => 'male', 'avs_number' => '756.0000.0000.00', 'medical_conditions' => '', 'creation_timestamp' => time()];
intersoccer_persist_user_players($uid, [$mk('Kid A', '2017-03-01'), $mk('Kid B', '2018-05-01')]);
if ($extra === 'coupon' && !wc_get_coupon_id_by_code('repro-sibdrop10')) { $c = new WC_Coupon(); $c->set_code('repro-sibdrop10'); $c->set_discount_type('percent'); $c->set_amount(10); $c->save(); }
wp_set_current_user($uid);
function fresh_request() { wp_cache_flush(); WC()->session = null; WC()->cart = null; wc_load_cart(); WC()->cart->get_cart_from_session(); }
function end_request() { WC()->cart->calculate_totals(); WC()->session->save_data(); }
function lines($label) { $l = []; foreach (WC()->cart->get_cart() as $it) $l[] = $it['assigned_attendee'] ?? '?'; echo str_pad($label, 34), ' lines=', count($l), ' [', implode(', ', $l), '] coupons=', implode(',', WC()->cart->get_applied_coupons()), ' pts=', (int) WC()->session->get('intersoccer_points_to_redeem'), "\n"; }
function add_child($idx, $atts) { $_POST = $_REQUEST = array_merge($atts, ['add-to-cart' => PID, 'variation_id' => VID, 'quantity' => 1, 'player_assignment' => (string) $idx]); $ok = apply_filters('woocommerce_add_to_cart_validation', true, PID, 1, VID, $atts) && WC()->cart->add_to_cart(PID, 1, VID, $atts); $_POST = $_REQUEST = []; return (bool) $ok; }
function ajax_stash_player($idx) { return intersoccer_stash_selected_player(PID, ['player_index' => (string) $idx, 'player_id' => '']); }
function db_cart_lines($uid) { global $wpdb; wp_cache_flush(); $v = $wpdb->get_var($wpdb->prepare("SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=%s", (string) $uid)); $d = maybe_unserialize($v); return is_array($d) ? count((array) maybe_unserialize($d['cart'] ?? '')) : -1; }

// Request 0: clean cart.
fresh_request(); WC()->cart->empty_cart(true); WC()->session->set('intersoccer_points_to_redeem', 0); end_request();
// Request 1: parent picks Kid A (stash AJAX), then adds to cart.
fresh_request(); ajax_stash_player(0); end_request();
fresh_request(); echo "add Kid A: ", var_export(add_child(0, $ATTS), true), "\n"; end_request();
// Optional discount applied before the 2nd child (any session write would do).
if ($extra === 'coupon') { fresh_request(); WC()->cart->apply_coupon('repro-sibdrop10'); end_request(); }
if ($extra === 'points') { fresh_request(); WC()->session->set('intersoccer_points_to_redeem', 20); end_request(); }
fresh_request(); lines('after Kid A');

// Request R (AJAX, player select change -> Kid B) starts: session + cart loaded now.
if ($scenario === 'race') {
    fresh_request(); $ajax_session = WC()->session; $ajax_cart = WC()->cart;
}
if ($scenario === 'sequential') { fresh_request(); ajax_stash_player(1); end_request(); }
// Request P (form POST add-to-cart Kid B) runs and finishes while R is still in flight.
fresh_request(); echo "add Kid B: ", var_export(add_child(1, $ATTS), true), "\n"; end_request();
echo "DB session cart lines after add-to-cart Kid B committed: ", db_cart_lines($uid), "\n";
// Request R finishes: stash write marks the session dirty; shutdown save_data writes the stale cart back.
if ($scenario === 'race') { WC()->session = $ajax_session; WC()->cart = $ajax_cart; ajax_stash_player(1); WC()->session->save_data(); }
echo "DB session cart lines after stash AJAX finished: ", db_cart_lines($uid), "\n";
// Next page view (/cart/ or /checkout/).
fresh_request(); WC()->cart->calculate_totals(); lines("RESULT $scenario/$extra");
$n = count(WC()->cart->get_cart()); $notices = wc_notice_count('error') + wc_notice_count('notice');
echo "RESULT $scenario/$extra: ", ($n === 2 ? 'KEPT both children' : "DROPPED (lines=$n)"), " customer_notices=$notices uid=$uid\n";
