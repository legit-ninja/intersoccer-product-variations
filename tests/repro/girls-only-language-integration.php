<?php
/**
 * Read-only integration repro (sandbox DB): a boy (user 46231 player 0, male) must be allowed on
 * camps whose pa_girls-only term is "no" in EN/FR/DE. Runs each product under its own WPML language
 * with a cold term cache, exactly like the storefront request. Writes nothing.
 *
 * Run (sql-sandbox dir):
 *   docker compose run --rm -v $WT/tests/repro:/repro:ro wpcli eval-file /repro/girls-only-language-integration.php
 */
$cases = [
    ['en', 51723, 51724, 'no'],
    ['fr', 51732, 51758, 'non'],
    ['de', 54216, 54231, 'nein'],
];
$fail = 0;
foreach ($cases as [$lang, $pid, $vid, $expect_term]) {
    do_action('wpml_switch_language', $lang);
    wp_cache_flush();
    $pairs = intersoccer_collect_taxonomy_term_pairs_for_line($pid, $vid, 'pa_girls-only');
    $is_go = intersoccer_line_is_girls_only_program($pid, $vid);
    $res = intersoccer_validate_line_player_girls_only(46231, 0, $pid, $vid); // player 0 = male fake kid
    $ok = !$is_go && $res === true;
    if (!$ok) { $fail++; }
    printf("%s [%s] product %d/%d pa_girls-only=%s girls_only=%s boy_allowed=%s%s\n",
        $ok ? 'PASS' : 'FAIL', $lang, $pid, $vid, json_encode($pairs, JSON_UNESCAPED_UNICODE),
        var_export($is_go, true), var_export($res === true, true),
        is_wp_error($res) ? ' error="' . $res->get_error_message() . '"' : '');
}
echo $fail ? "FAILED: $fail of " . count($cases) . "\n" : "OK\n";
