<?php
/**
 * Read-only integration repro (sandbox DB, wp eval-file): retroactive camp sibling discount across
 * WPML languages, earlier-orders path.
 *
 * Scenario: a customer already has a completed full-week camp order placed from the DE site (one child).
 * A sibling is now booked on the SAME programme from the FR site. Expected: the sibling is ranked
 * after the child(ren) with prior same-season camps and gets the 2nd-child rate (3rd+ if 2+ prior kids).
 * Bug: prior order items carry _intersoccer_discount_season_key written in the checkout language
 * (e.g. "herbst camps 2026|2026"), while the FR cart computes "camps d'automne 2026|2026"; the strict
 * in_array() season filter in intersoccer_get_previous_sibling_child_totals() drops the DE orders.
 *
 * No IDs are hard-coded. Everything is discovered at runtime from the --user customer:
 *   - source orders: the customer's orders whose wpml_language = REPRO_SOURCE_LANG (default de)
 *   - programme: the first source-language full-week camp item with a stored season key
 *   - cart line: WPML translation of that variation into REPRO_CART_LANG (default fr)
 *   - cart child: the first player with no prior same-season camp in ANY language
 * Optional env: REPRO_SOURCE_LANG, REPRO_CART_LANG, REPRO_CART_PLAYER_INDEX, REPRO_SOURCE_ORDER.
 * Exit: 0 PASS, 1 FAIL, 2 setup error, 3 SKIP (no source-language order for this user).
 * Orders are listed by direct SQL; the orders the discount lookup actually gets are traced via the
 * woocommerce_order_query filter during the hook (WPML filters that WP_Query by current language).
 *
 * Tracing: the stored key is captured by hooking WC_Data::get_meta's view filter
 * (woocommerce_order_item_get__intersoccer_discount_season_key) during the real cart hook, i.e. the exact
 * reads at discounts.php extract_camp_items_from_order. The cart key is $context['camps_by_season_child']
 * from intersoccer_build_cart_context() on the same cart, which is the array discounts.php iterates
 * before calling intersoccer_get_previous_sibling_child_totals(..., [$season]). Where the plugin defines
 * intersoccer_discount_normalize_season_key() (PR #99), both sides are also shown normalized, as compared.
 *
 * Safety: every write query from this process is rewritten to a no-op SELECT and counted. No HTTP.
 *
 * Run (from sql-sandbox/):
 *   docker compose run --rm -v ../_wt/repro-sibling-xlang/tests/repro:/repro:ro wpcli \
 *     --user=<customer id> eval-file /repro/sibling-discount-earlier-orders.php
 */

global $wpdb;
$GLOBALS['repro_blocked_writes'] = [];
add_filter('query', static function ($q) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|RENAME)\b/i', $q)) {
        $GLOBALS['repro_blocked_writes'][] = substr(preg_replace('/\s+/', ' ', $q), 0, 200);
        return 'SELECT 0 /* repro: write blocked */';
    }
    return $q;
}, PHP_INT_MAX);

$P = $wpdb->prefix;
$src_lang  = getenv('REPRO_SOURCE_LANG') ?: 'de';
$cart_lang = getenv('REPRO_CART_LANG') ?: 'fr';
$uid = get_current_user_id();
$out = static function ($s = '') { echo $s, "\n"; };
$norm = static function ($k) {
    return function_exists('intersoccer_discount_normalize_season_key') ? (string) intersoccer_discount_normalize_season_key($k) : null;
};

$pv_main = WP_PLUGIN_DIR . '/intersoccer-product-variations/intersoccer-product-variations.php';
$out('== Environment');
$out(sprintf('PV version=%s user=%d source_lang=%s cart_lang=%s PHP=%s normalize_season_key=%s',
    preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents($pv_main), $m) ? $m[1] : '?',
    $uid, $src_lang, $cart_lang, PHP_VERSION, function_exists('intersoccer_discount_normalize_season_key') ? 'defined (#99)' : 'absent'));
foreach (['includes/woocommerce/discounts.php', 'includes/woocommerce/product-types.php', 'includes/helpers.php', 'includes/woocommerce/order-meta-contract.php'] as $f) {
    $out(sprintf('md5 %s %s', md5_file(WP_PLUGIN_DIR . '/intersoccer-product-variations/' . $f), $f));
}
if ($uid <= 0) { $out('SETUP ERROR: run with --user=<customer id>'); WP_CLI::halt(2); }

$tax_trid = static function ($tt_id, $tax) use ($wpdb, $P) {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT trid FROM {$P}icl_translations WHERE element_type=%s AND element_id=%d", 'tax_' . $tax, $tt_id));
};
$season_sig = static function ($product_id) use ($tax_trid) {
    // Language-neutral programme season: WPML trid of pa_program-season + pa_program-year on the parent.
    $p = wc_get_product($product_id);
    $parent = ($p && $p->get_parent_id()) ? $p->get_parent_id() : (int) $product_id;
    $sig = [];
    foreach (['pa_program-season', 'pa_program-year'] as $tax) {
        $terms = wp_get_object_terms($parent, $tax);
        $sig[] = ($terms && !is_wp_error($terms)) ? $tax_trid($terms[0]->term_taxonomy_id, $tax) : 0;
    }
    return implode('/', $sig);
};
$is_full_week = static function ($bt) {
    if (function_exists('intersoccer_normalize_canonical_booking_type')) {
        $c = intersoccer_normalize_canonical_booking_type((string) $bt);
        if ($c !== '') { return $c === 'full-week'; }
    }
    return intersoccer_discount_camp_booking_counts_for_sibling($bt);
};

// ---- ALL of the customer's orders, read straight from the DB (no WPML query filtering), with WPML language.
// Do NOT call intersoccer_get_customer_previous_orders() here: it has a static cache, and the real hook
// must make the first call under the cart site language.
$lookback = intersoccer_discount_retroactive_lookback_months(true);
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT p.ID FROM {$P}posts p JOIN {$P}postmeta c ON c.post_id=p.ID AND c.meta_key='_customer_user' AND c.meta_value=%s
     WHERE p.post_type='shop_order' ORDER BY p.ID", (string) $uid));
$wpml_settings = get_option('icl_sitepress_settings', []);
$out(sprintf("\n== WPML settings: shop_order translation mode=%s (0=not translatable,1=translatable,2=display-as-translated); icl_translations rows for this user's orders=%d",
    var_export($wpml_settings['custom_posts_sync_option']['shop_order'] ?? null, true),
    $ids ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$P}icl_translations WHERE element_type='post_shop_order' AND element_id IN (" . implode(',', array_map('intval', $ids)) . ")") : 0));
$out(sprintf("\n== All orders of user %d (direct SQL): %d", $uid, count($ids)));
$valid = ['completed', 'processing', 'on-hold'];
$prior = [];
$order_lang = [];
foreach ($ids as $oid) {
    $o = wc_get_order((int) $oid);
    if (!$o) { continue; }
    $lang = (string) $o->get_meta('wpml_language');
    $icl = $wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$P}icl_translations WHERE element_type='post_shop_order' AND element_id=%d", $oid));
    $order_lang[(int) $oid] = $lang;
    $items = intersoccer_extract_camp_items_from_order($o);
    $out(sprintf('order %d wpml_language=%s icl_translations=%s status=%s camp_items=%d', $oid, $lang ?: '-', $icl ?: '-', $o->get_status(), count($items)));
    if (!in_array($o->get_status(), $valid, true)) { continue; }
    foreach ($items as $it) {
        $item = $o->get_item($it['item_id']);
        $it['order_lang'] = $lang;
        $it['stored_key'] = $item ? (string) $item->get_meta('_intersoccer_discount_season_key', true, 'edit') : '';
        $it['sig'] = $season_sig($it['variation_id'] ?: $it['product_id']);
        $it['player_key'] = intersoccer_discount_player_key($it);
        $it['full_week'] = $is_full_week($it['booking_type']);
        $prior[] = $it;
        $out(sprintf('    item %d product %d/%d player_key=%s booking=%s full_week=%s stored _intersoccer_discount_season_key=%s season_sig=%s total=%.2f',
            $it['item_id'], $it['product_id'], $it['variation_id'], $it['player_key'], $it['booking_type'],
            $it['full_week'] ? 'yes' : 'no', json_encode($it['stored_key'], JSON_UNESCAPED_UNICODE), $it['sig'], $it['line_total']));
    }
}

// ---- Pick the source (DE) item: first full-week camp item with a stored key in the source language.
$want_order = (int) (getenv('REPRO_SOURCE_ORDER') ?: 0);
$source = null;
foreach ($prior as $it) {
    if ($it['order_lang'] === $src_lang && $it['full_week'] && $it['stored_key'] !== '' && (!$want_order || $it['order_id'] === $want_order)) {
        $source = $it; break;
    }
}
if (!$source) { $out("SKIP: no $src_lang full-week camp order item with a stored season key for user $uid (direction not covered)"); WP_CLI::halt(3); }
$out(sprintf("\n== Source %s item: order %d item %d variation %d player_key=%s stored key=%s",
    strtoupper($src_lang), $source['order_id'], $source['item_id'], $source['variation_id'], $source['player_key'], json_encode($source['stored_key'], JSON_UNESCAPED_UNICODE)));

// ---- Cart programme = WPML translation of the source variation into the cart language.
$cart_var = (int) apply_filters('wpml_object_id', $source['variation_id'], 'product_variation', false, $cart_lang);
$cart_par = (int) apply_filters('wpml_object_id', $source['parent_product_id'], 'product', false, $cart_lang);
if ($cart_var <= 0 || $cart_par <= 0) { $out('SETUP ERROR: no WPML translation for the source programme'); WP_CLI::halt(2); }
$cart_bt = get_post_meta($cart_var, 'attribute_pa_booking-type', true);
$target_sig = $source['sig'];
$out(sprintf('Cart programme (%s): parent %d variation %d attribute_pa_booking-type=%s season_sig=%s (source %s)',
    $cart_lang, $cart_par, $cart_var, json_encode($cart_bt), $season_sig($cart_var), $target_sig));

// ---- Pick the cart child: first player with no prior same-season (language-neutral) camp.
$players = get_user_meta($uid, 'intersoccer_players', true);
$players = is_array($players) ? array_values($players) : [];
$prior_same = array_filter($prior, static function ($it) use ($target_sig) { return $it['full_week'] && $it['sig'] === $target_sig; });
$cart_idx = getenv('REPRO_CART_PLAYER_INDEX');
$cart_idx = ($cart_idx === false || $cart_idx === '') ? null : (int) $cart_idx;
$line_for = static function ($idx) use ($players) {
    $p = $players[$idx] ?? [];
    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    return [
        'assigned_player' => $idx,
        'assigned_attendee' => $name,
        'assigned_player_id' => $p['player_id'] ?? ($p['id'] ?? ($p['uuid'] ?? null)),
    ];
};
if ($cart_idx === null) {
    foreach (array_keys($players) as $idx) {
        $hit = false;
        foreach ($prior_same as $it) {
            if (intersoccer_discount_players_match($line_for($idx), $it)) { $hit = true; break; }
        }
        if (!$hit) { $cart_idx = $idx; break; }
    }
}
if ($cart_idx === null) {
    // No real player without a prior same-season camp: use an in-memory sibling (never saved).
    $cart_idx = count($players);
    $cart_player = ['assigned_player' => $cart_idx, 'assigned_attendee' => 'Repro Sibling', 'assigned_player_id' => 'repro-sibling-' . md5((string) $uid)];
    $out('Cart child: no real player without a prior same-season camp -> in-memory sibling (not saved)');
} else {
    $cart_player = $line_for($cart_idx);
}

// Expected (language-neutral): distinct prior children with same-season full-week camps, excluding the cart child.
$prior_kids = [];
foreach ($prior_same as $it) {
    if (!intersoccer_discount_players_match($cart_player, $it) && $it['player_key'] !== null) {
        $prior_kids[$it['player_key']][] = sprintf('%d[%s]', $it['order_id'], $it['order_lang']);
    }
}
$rates = intersoccer_get_discount_rates();
$r2 = $rates['camp']['2nd_child'] ?? null; $r3 = $rates['camp']['3rd_plus_child'] ?? null;
$n = count($prior_kids);
$expected = $n === 0 ? 0.0 : (float) ($n === 1 ? $r2 : $r3);
$out(sprintf('Cart child: index %d attendee=%s player_id=%s', $cart_idx, json_encode($cart_player['assigned_attendee'], JSON_UNESCAPED_UNICODE), json_encode($cart_player['assigned_player_id'])));
$out(sprintf('Prior same-season children (any language): %s', json_encode($prior_kids)));
$out(sprintf('Rates: 2nd=%s 3rd+=%s -> expected cart child rate %.0f%% (position %d)', var_export($r2, true), var_export($r3, true), $expected * 100, $n + 1));

// ---- Run the real cart hook under the cart site language, tracing stored-key reads.
do_action('wpml_switch_language', $cart_lang);
wp_cache_flush();
$product = wc_get_product($cart_var);
$base = (float) $product->get_price();
$cart = new class(['line-sibling-' . $cart_lang => array_merge([
    'product_id' => $cart_par, 'variation_id' => $cart_var, 'quantity' => 1, 'base_price' => $base, 'data' => $product,
], $cart_player)]) {
    public $cart_contents;
    public function __construct($c) { $this->cart_contents = $c; }
    public function get_cart() { return $this->cart_contents; }
    public function get_applied_coupons() { return []; }
};

$GLOBALS['repro_order_queries'] = [];
$qtrace = static function ($results, $args) {
    $GLOBALS['repro_order_queries'][] = [$args, $results];
    return $results;
};
// intersoccer_build_cart_context() makes the first (statically cached) previous-orders lookup, so trace from here.
add_filter('woocommerce_order_query', $qtrace, PHP_INT_MAX, 2);
$ctx = intersoccer_build_cart_context($cart->get_cart());
$cart_keys = array_keys($ctx['camps_by_season_child']);

$GLOBALS['repro_meta_reads'] = [];
$trace = static function ($value, $item) {
    $GLOBALS['repro_meta_reads'][] = [$item->get_order_id(), $item->get_id(), (string) $value];
    return $value;
};
add_filter('woocommerce_order_item_get__intersoccer_discount_season_key', $trace, 10, 2);
intersoccer_apply_combo_discounts_to_items($cart);
remove_filter('woocommerce_order_item_get__intersoccer_discount_season_key', $trace, 10);
remove_filter('woocommerce_order_query', $qtrace, PHP_INT_MAX);

$line = reset($cart->cart_contents);
$actual = (float) ($line['intersoccer_sibling_percent'] ?? 0);
$price = (float) $line['data']->get_price();
$prior_totals = intersoccer_get_previous_sibling_child_totals($uid, 'camp', $lookback, $cart_keys ? [(string) $cart_keys[0]] : []);

$out(sprintf("\n== wc_get_orders() calls made by the discount code (intersoccer_get_customer_previous_orders, discounts.php wc_get_orders), site=%s: %d", $cart_lang, count($GLOBALS['repro_order_queries'])));
foreach ($GLOBALS['repro_order_queries'] as [$a, $r]) {
    $rids = array_map(static function ($x) { return is_object($x) ? $x->get_id() : (int) $x; }, (array) $r);
    $by = [];
    foreach ($rids as $rid) { $by[$order_lang[$rid] ?? '?'][] = $rid; }
    $src_all = array_keys(array_filter($order_lang, static function ($l) use ($src_lang) { return $l === $src_lang; }));
    $out(sprintf('  args customer_id=%s status=%s date_created=%s -> %d orders by lang %s; %s-language orders of this user NOT returned: %s',
        var_export($a['customer_id'] ?? null, true), json_encode($a['status'] ?? null), $a['date_created'] ?? '-', count($rids),
        json_encode($by), strtoupper($src_lang), json_encode(array_values(array_diff($src_all, $rids)))));
}
$out("\n== Values compared at discounts.php (season filter in intersoccer_get_previous_sibling_child_totals)");
$out(sprintf('FR cart season key (camps_by_season_child, site=%s): %s%s', $cart_lang, json_encode($cart_keys, JSON_UNESCAPED_UNICODE),
    $norm('x') !== null ? ' normalized=' . json_encode(array_map($norm, $cart_keys), JSON_UNESCAPED_UNICODE) : ''));
$seen = [];
foreach ($GLOBALS['repro_meta_reads'] as [$oid, $iid, $v]) {
    $k = "$oid/$iid";
    if (isset($seen[$k])) { continue; }
    $seen[$k] = true;
    $out(sprintf('stored key read by the hook: order %d item %d = %s%s', $oid, $iid, json_encode($v, JSON_UNESCAPED_UNICODE),
        $norm('x') !== null ? ' normalized=' . json_encode($norm($v), JSON_UNESCAPED_UNICODE) : ''));
}
$out(sprintf('meta reads traced during hook: %d%s', count($GLOBALS['repro_meta_reads']),
    in_array($source['order_id'], array_column($GLOBALS['repro_meta_reads'], 0), true) ? '' : sprintf(' (source %s order %d never reached the comparison)', strtoupper($src_lang), $source['order_id'])));
$out(sprintf('intersoccer_get_previous_sibling_child_totals(%d, camp, %d, [cart key]) = %s', $uid, $lookback, json_encode($prior_totals)));

$out("\n== Result");
$out(sprintf('base %.2f  expected rate %.0f%% -> expected line %.2f  |  actual rate %.0f%% -> actual line %.2f  discount_note=%s',
    $base, $expected * 100, $base * (1 - $expected), $actual * 100, $price, json_encode($line['discount_note'] ?? '', JSON_UNESCAPED_UNICODE)));
$ok = $expected > 0 && abs($actual - $expected) < 1e-9 && abs($price - $base * (1 - $expected)) < 0.01;
$src_cmp = $source['stored_key'];
$cart_cmp = (string) ($cart_keys[0] ?? '');
if ($norm('x') !== null) { $src_cmp = $norm($src_cmp); $cart_cmp = $norm($cart_cmp); }
$reached = in_array($source['order_id'], array_column($GLOBALS['repro_meta_reads'], 0), true);
$out(sprintf('%s earlier-orders %s order -> %s cart: source order %d %s; stored %s %s cart %s (as compared)',
    $ok ? 'PASS' : 'FAIL', strtoupper($src_lang), strtoupper($cart_lang), $source['order_id'],
    $reached ? 'reached the season comparison' : 'NOT returned by wc_get_orders (never compared)',
    json_encode($src_cmp, JSON_UNESCAPED_UNICODE), $src_cmp === $cart_cmp ? '==' : '!=', json_encode($cart_cmp, JSON_UNESCAPED_UNICODE)));
if ($ok && !$reached) {
    $out('NOTE: PASS here is incidental (other-language orders supplied the prior children); the source order itself was not counted.');
}
$out(sprintf("\nBlocked write queries from this process: %d", count($GLOBALS['repro_blocked_writes'])));
foreach ($GLOBALS['repro_blocked_writes'] as $q) { $out("  blocked: $q"); }
if (!$ok) { WP_CLI::halt(1); }
