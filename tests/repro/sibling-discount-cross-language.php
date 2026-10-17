<?php
/**
 * Read-only integration repro (sandbox DB, wp eval-file): camp sibling discount across WPML languages.
 *
 * Same customer, two children, same programme (Geneva Autumn Camps 2026, Varembé, Autumn Week 4,
 * 5-13y full day, full week) booked as EN/FR/DE product lines. Builds two cart lines in memory
 * (stub cart, real WC_Product_Variation objects, set_price in memory only) and runs PV's real
 * woocommerce_before_calculate_totals handler intersoccer_apply_combo_discounts_to_items().
 * Expected: the 2nd child gets the 20% camp sibling rate in every combination.
 *
 * Safety: every write query (INSERT/UPDATE/DELETE/REPLACE/ALTER/DROP/CREATE/TRUNCATE) issued by
 * this process is rewritten to a no-op SELECT and counted; the run reports the count. Runs as
 * user 0 (no --user), so no prior-order lookups. No HTTP requests.
 *
 * Run (from sql-sandbox/):
 *   docker compose run --rm -v $WT/tests/repro:/repro:ro wpcli eval-file /repro/sibling-discount-cross-language.php
 */

global $wpdb;
$GLOBALS['repro_blocked_writes'] = [];
add_filter('query', static function ($q) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME)\b/i', $q)) {
        $GLOBALS['repro_blocked_writes'][] = substr(preg_replace('/\s+/', ' ', $q), 0, 160);
        return 'SELECT 0 /* repro: write blocked */';
    }
    return $q;
}, PHP_INT_MAX);

$P = $wpdb->prefix;
$programs = [
    'en' => [51723, 51724],
    'fr' => [51732, 51758],
    'de' => [54216, 54231],
];
$langs = ['en', 'fr', 'de'];

echo "== Environment\n";
printf("PV version=%s  prefix=%s  WPML=%s  current_user=%d  PHP=%s\n",
    (preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents(WP_PLUGIN_DIR . '/intersoccer-product-variations/intersoccer-product-variations.php'), $m) ? $m[1] : '?'),
    $P, defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : 'n/a', get_current_user_id(), PHP_VERSION);
foreach (['includes/woocommerce/discounts.php', 'includes/woocommerce/product-types.php', 'includes/helpers.php'] as $f) {
    printf("md5 %s %s\n", md5_file(WP_PLUGIN_DIR . '/intersoccer-product-variations/' . $f), $f);
}

echo "\n== WPML translation groups (icl_translations) and wpml_object_id\n";
foreach ($programs as $lang => [$pid, $vid]) {
    foreach (['post_product' => $pid, 'post_product_variation' => $vid] as $type => $id) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT trid, language_code, source_language_code FROM {$P}icl_translations WHERE element_type=%s AND element_id=%d", $type, $id), ARRAY_A);
        $map = [];
        foreach ($langs as $l) { $map[$l] = (int) apply_filters('wpml_object_id', $id, $type === 'post_product' ? 'product' : 'product_variation', false, $l); }
        printf("%s %-22s id=%d trid=%s lang=%s src=%s wpml_object_id=%s\n", $lang, $type, $id,
            $row['trid'] ?? '-', $row['language_code'] ?? '-', $row['source_language_code'] ?? '-', json_encode($map));
    }
}

echo "\n== Grouping inputs per line\n";
$termrow = static function ($pid, $tax) use ($wpdb, $P) {
    $out = [];
    foreach (wp_get_object_terms($pid, $tax) as $t) {
        $tr = $wpdb->get_row($wpdb->prepare(
            "SELECT trid, language_code FROM {$P}icl_translations WHERE element_type=%s AND element_id=%d", 'tax_' . $tax, $t->term_taxonomy_id), ARRAY_A);
        $out[] = sprintf('term_id=%d tt_id=%d slug=%s name="%s" trid=%s lang=%s', $t->term_id, $t->term_taxonomy_id, $t->slug, $t->name, $tr['trid'] ?? '-', $tr['language_code'] ?? '-');
    }
    return $out ? implode(' | ', $out) : '(none)';
};
foreach ($programs as $lang => [$pid, $vid]) {
    do_action('wpml_switch_language', $lang);
    wp_cache_flush();
    $bt = get_post_meta($vid, 'attribute_pa_booking-type', true);
    printf("[%s] parent=%d variation=%d type=%s\n", $lang, $pid, $vid, intersoccer_get_product_type($pid));
    printf("     pa_program-season: %s\n", $termrow($pid, 'pa_program-season'));
    printf("     pa_program-year:   %s\n", $termrow($pid, 'pa_program-year'));
    printf("     pa_booking-type:   %s\n", $termrow($pid, 'pa_booking-type'));
    printf("     variation attribute_pa_booking-type=%s counts_for_sibling=%s\n", json_encode($bt), var_export(intersoccer_discount_camp_booking_counts_for_sibling($bt), true));
    printf("     intersoccer_discount_season_key(variation)=%s\n", json_encode(intersoccer_discount_season_key($vid), JSON_UNESCAPED_UNICODE));
}

$rules = get_option('intersoccer_discount_rules', []);
$would_update = intersoccer_merge_default_discount_rules($rules) !== $rules;
printf("\nintersoccer_get_discount_rates() would update_option(intersoccer_discount_rules): %s (blocked if so)\n", $would_update ? 'yes' : 'no');

$make_cart = static function ($langA, $langB) use ($programs) {
    $lines = [];
    foreach ([[$langA, 'qa-sib-uuid-1', 'QAKid One', 0], [$langB, 'qa-sib-uuid-2', 'QAKid Two', 1]] as [$l, $uuid, $name, $idx]) {
        [$pid, $vid] = $programs[$l];
        $product = wc_get_product($vid);
        $base = (float) $product->get_price();
        $lines["line-$idx-$l"] = [
            'product_id' => $pid, 'variation_id' => $vid, 'quantity' => 1,
            'assigned_player' => $idx, 'assigned_player_id' => $uuid, 'assigned_attendee' => $name,
            'base_price' => $base, 'data' => $product,
        ];
    }
    return new class($lines) {
        public $cart_contents;
        public function __construct($c) { $this->cart_contents = $c; }
        public function get_cart() { return $this->cart_contents; }
        public function get_applied_coupons() { return []; }
    };
};

$cases = [
    ['control', 'en', 'en'], ['control', 'de', 'de'], ['control', 'fr', 'fr'],
    ['cross', 'de', 'fr'], ['cross', 'en', 'de'], ['cross', 'fr', 'en'],
];
$fail = 0; $total = 0;
echo "\n== Cases (2 siblings, same customer; each case run with site language en/fr/de)\n";
foreach ($cases as [$kind, $a, $b]) {
    foreach ($langs as $site) {
        do_action('wpml_switch_language', $site);
        wp_cache_flush();
        $cart = $make_cart($a, $b);
        $ctx = intersoccer_build_cart_context($cart->get_cart());
        $buckets = array_map('array_keys', $ctx['camps_by_season_child']);
        intersoccer_apply_combo_discounts_to_items($cart);
        $pct = []; $sum = 0; $base = 0;
        foreach ($cart->cart_contents as $k => $line) {
            $pct[$k] = (float) ($line['intersoccer_sibling_percent'] ?? 0);
            $sum += (float) $line['data']->get_price();
            $base += (float) $line['base_price'];
        }
        $n20 = count(array_filter($pct, static function ($p) { return abs($p - 0.20) < 1e-9; }));
        $ok = ($n20 === 1) && abs($sum - ($base - 0.20 * min(array_column($cart->cart_contents, 'base_price')))) < 0.01;
        $total++;
        if (!$ok) { $fail++; }
        printf("%s %-7s %s+%s site=%s  sibling%%=%s  total=%.2f (base %.2f)  buckets=%s\n",
            $ok ? 'PASS' : 'FAIL', $kind, strtoupper($a), strtoupper($b), $site,
            json_encode($pct), $sum, $base, json_encode($buckets, JSON_UNESCAPED_UNICODE));
    }
}

printf("\nBlocked write queries from this process: %d\n", count($GLOBALS['repro_blocked_writes']));
foreach ($GLOBALS['repro_blocked_writes'] as $q) { echo "  blocked: $q\n"; }
echo $fail ? "FAILED: $fail of $total\n" : "OK: $total of $total\n";
if ($fail) { WP_CLI::halt(1); }
