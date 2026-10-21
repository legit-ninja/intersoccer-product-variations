<?php
// Repro setup (sandbox only): 2 synthetic parents @example.test, 1 synthetic child each, 20 CRS points each.
$out = [];
foreach (['pts', 'ctl'] as $tag) {
    $email = "repro-ppo-$tag@example.test";
    $pw = 'Rp-' . substr(hash('sha256', $tag . 'ppo-local-only'), 0, 20);
    if ($u = get_user_by('email', $email)) { $uid = $u->ID; wp_set_password($pw, $uid); }
    else { $uid = wp_insert_user(['user_login' => "repro-ppo-$tag", 'user_email' => $email, 'user_pass' => $pw, 'role' => 'customer', 'first_name' => 'Repro', 'last_name' => 'Parent']); }
    if (is_wp_error($uid)) { echo "ERR ", $uid->get_error_message(), "\n"; exit(1); }
    foreach (['billing_first_name'=>'Repro','billing_last_name'=>'Parent','billing_address_1'=>'1 Test St','billing_city'=>'Geneva','billing_postcode'=>'1200','billing_country'=>'CH','billing_email'=>$email,'billing_phone'=>'+41790000000'] as $k=>$v) update_user_meta($uid,$k,$v);
    $mk = fn($fn, $dob) => ['player_id' => intersoccer_generate_player_id(), 'first_name' => $fn, 'last_name' => 'Synthetic', 'dob' => $dob, 'gender' => 'male', 'avs_number' => '756.0000.0000.00', 'medical_conditions' => '', 'creation_timestamp' => current_time('timestamp')];
    if (empty(get_user_meta($uid, 'intersoccer_players', true))) intersoccer_persist_user_players($uid, [$mk('Kid A', '2017-03-01')]);
    $out[$tag] = $uid;
    file_put_contents('/o/pw-'.$tag.'.txt', "repro-ppo-$tag $pw\n");
}
if (class_exists('InterSoccer_Points_Manager')) {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']); wp_set_current_user((int) $admins[0]);
    $pm = new InterSoccer_Points_Manager();
    foreach (['pts','ctl'] as $tag) {
        if ((float) $pm->get_points_balance($out[$tag]) < 20) { $pm->add_points_transaction($out[$tag], 'admin_adjustment', 20, null, 'Repro points place-order test grant', ['adjustment_reason' => 'bonus']); }
        $out[$tag.'_balance'] = $pm->get_points_balance($out[$tag]);
        $out[$tag.'_meta_balance'] = get_user_meta($out[$tag], 'intersoccer_points_balance', true);
    }
}
echo json_encode($out), "\n";
