<?php
/**
 * Repro: second sibling's camp line silently disappears from the cart.
 *
 * Root cause: the product page fires intersoccer_store_selected_player (and
 * intersoccer_store_express_fields) as fire-and-forget AJAX on player / day change.
 * Each handler writes a stash key into the WooCommerce session. WC_Session_Handler
 * loads the whole session row (including 'cart') when a request starts and, when
 * anything was set, writes the whole row back at shutdown (WC 10.5.3
 * class-wc-session-handler.php save_data()). If the stash request starts before the
 * add-to-cart POST for the 2nd child and finishes after it, it writes back the cart
 * it loaded (1 line) over the 2-line cart. No notice is raised.
 *
 * The session double below mirrors that load-whole / write-whole behaviour; the
 * stash write is PV's real AJAX callback. Coupon vs points does not matter: both
 * fail when the requests overlap and both pass when they do not.
 *
 * Expected on PV 2.10.17 (063db80) and master 0380149 (#99): the two *Race* tests
 * FAIL (1 line left), the *Sequential* controls and the precondition PASS.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
// PV_SRC_ROOT lets the same test run against another read-only checkout (e.g. master 0380149).
$intersoccerPvSrcRoot = getenv('PV_SRC_ROOT') ?: dirname(__DIR__);
require_once $intersoccerPvSrcRoot . '/includes/woocommerce/cart-calculations.php';
require_once $intersoccerPvSrcRoot . '/includes/woocommerce/express-checkout-player-gate.php';

if (!defined('WC_SESSION_CACHE_GROUP')) {
    define('WC_SESSION_CACHE_GROUP', 'wc_session_id');
}

if (!class_exists('WC_Cache_Helper')) {
    class WC_Cache_Helper {
        public static function get_cache_prefix($group) {
            return 'wc_cache_test_' . $group . '_';
        }
    }
}

if (!function_exists('WC')) {
    function WC() {
        if (!isset($GLOBALS['intersoccer_days_gate_wc'])) {
            $GLOBALS['intersoccer_days_gate_wc'] = (object) ['session' => null, 'cart' => null];
        }
        return $GLOBALS['intersoccer_days_gate_wc'];
    }
}

/** The woocommerce_sessions table: one row per logged-in customer. */
final class SiblingDropSessionStore {
    public static $rows = [];

    public static function wcSessionCacheKey($customer): string {
        return WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP) . (string) $customer;
    }
}

/** Mirrors WC_Session_Handler: read whole row at request start, write whole row at shutdown if dirty. */
final class SiblingDropRequestSession {
    private $customer; private $data; private $dirty = false;
    public function __construct($customer) {
        $this->customer = (string) $customer;
        $this->data = SiblingDropSessionStore::$rows[$this->customer] ?? [];
        $this->primeSessionObjectCache();
    }

    /** Same as WC loading session_value into wp_cache_add at request start. */
    private function primeSessionObjectCache(): void {
        wp_cache_add(
            SiblingDropSessionStore::wcSessionCacheKey($this->customer),
            $this->data,
            WC_SESSION_CACHE_GROUP,
            3600
        );
    }
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { if (($this->data[$key] ?? null) !== $value) { $this->data[$key] = $value; $this->dirty = true; } }
    public function __unset($key) { if (array_key_exists($key, $this->data)) { unset($this->data[$key]); $this->dirty = true; } }
    public function get_session_data() { return $this->data; }
    public function get_customer_id() { return $this->customer; }
    /** Mirrors WC_Session_Handler::get_session() for merge-before-save in stash AJAX. */
    public function get_session($customer_id) {
        if ((string) $customer_id !== $this->customer) {
            return [];
        }
        $cache_key = SiblingDropSessionStore::wcSessionCacheKey($this->customer);
        $cached = wp_cache_get($cache_key, WC_SESSION_CACHE_GROUP);
        if (false !== $cached && is_array($cached)) {
            return $cached;
        }
        $fresh = SiblingDropSessionStore::$rows[$this->customer] ?? [];
        wp_cache_add($cache_key, $fresh, WC_SESSION_CACHE_GROUP, 3600);
        return $fresh;
    }
    public function shutdown_save() { if ($this->dirty) { SiblingDropSessionStore::$rows[$this->customer] = $this->data; $this->dirty = false; } }
}

class SiblingDropSessionRaceReproTest extends TestCase
{
    private const CAMP_ID = 51723;
    private const VAR_ID = 51724;
    private const USER_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        SiblingDropSessionStore::$rows = [];
        $GLOBALS['intersoccer_test_user_id'] = self::USER_ID;
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_player_by_index'] = function ($uid, $idx) {
            return ['player_id' => 'player-uuid-' . $idx, 'first_name' => 'Kid ' . $idx];
        };
        $GLOBALS['intersoccer_test_json_capture'] = true;
        $GLOBALS['intersoccer_test_object_cache'] = [];
        $GLOBALS['intersoccer_test_actions'] = [];
        unset($GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        WC()->session = null;
        $GLOBALS['intersoccer_test_object_cache'] = [];
        unset($GLOBALS['intersoccer_test_actions'], $GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        unset(
            $GLOBALS['intersoccer_test_player_by_index'],
            $GLOBALS['intersoccer_test_json_capture'],
            $GLOBALS['intersoccer_test_json'],
            $GLOBALS['intersoccer_test_user_id'],
            $GLOBALS['intersoccer_test_product_type']
        );
        parent::tearDown();
    }

    private function startRequest(): SiblingDropRequestSession
    {
        return new SiblingDropRequestSession(self::USER_ID);
    }

    /** Add-to-cart POST: WC_Cart_Session::set_session() stores the new cart; shutdown saves it. */
    private function addToCartRequest(int $childIndex): void
    {
        $s = $this->startRequest();
        $cart = $s->get('cart', []);
        $cart['line_child_' . $childIndex] = ['product_id' => self::CAMP_ID, 'quantity' => 1, 'assigned_player' => $childIndex, 'unique_key' => 'player_player-uuid-' . $childIndex];
        $s->set('cart', $cart);
        $s->shutdown_save();
    }

    /** Coupon applied on /cart/ (applied_coupons in session) or points chosen (CRS session key). */
    private function discountRequest(string $kind): void
    {
        $s = $this->startRequest();
        if ($kind === 'coupon') {
            $s->set('applied_coupons', ['repro-sibdrop10']);
        } else {
            $s->set('intersoccer_points_to_redeem', 20);
        }
        $s->shutdown_save();
    }

    /** PV's real product-page AJAX handler, run inside the given request's session. */
    private function runStashAjax(SiblingDropRequestSession $s, int $childIndex): void
    {
        WC()->session = $s;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => self::CAMP_ID, 'player_index' => (string) $childIndex, 'player_id' => ''];
        try {
            intersoccer_store_selected_player_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        do_action('shutdown');
        unset($GLOBALS['intersoccer_test_actions']['shutdown'], $GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        WC()->session = null;
        $s->shutdown_save();
    }

    /** PV express-fields AJAX (camp days / late pickup), same session race as player stash. */
    /** Legacy camp-day session AJAX (intersoccer_store_selected_days), same race as other stash handlers. */
    private function runSelectedDaysStashAjax(SiblingDropRequestSession $s, array $campDays): void
    {
        WC()->session = $s;
        $_POST = [
            'nonce' => 'valid_nonce',
            'product_id' => self::CAMP_ID,
            'variation_id' => self::VAR_ID,
            'camp_days' => $campDays,
        ];
        try {
            intersoccer_store_selected_days_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        do_action('shutdown');
        unset($GLOBALS['intersoccer_test_actions']['shutdown'], $GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        WC()->session = null;
        $s->shutdown_save();
    }

    private function bookSecondChildSelectedDays(string $discount, bool $overlap): int
    {
        $this->runStashAjax($this->startRequest(), 0);
        $this->addToCartRequest(0);
        $this->discountRequest($discount);
        $daysChild2 = ['Tuesday'];
        if ($overlap) {
            $ajax = $this->startRequest();
            $this->addToCartRequest(1);
            $this->assertSame(2, $this->cartLinesNow(), 'add-to-cart for child 2 did commit 2 lines');
            $this->runSelectedDaysStashAjax($ajax, $daysChild2);
        } else {
            $this->runSelectedDaysStashAjax($this->startRequest(), $daysChild2);
            $this->addToCartRequest(1);
        }
        return $this->cartLinesNow();
    }

    private function runExpressFieldsStashAjax(SiblingDropRequestSession $s, array $campDays): void
    {
        WC()->session = $s;
        $_POST = [
            'nonce' => 'valid_nonce',
            'product_id' => self::CAMP_ID,
            'variation_id' => self::VAR_ID,
            'camp_days' => $campDays,
            'late_pickup_type' => '',
            'late_pickup_days' => [],
        ];
        try {
            intersoccer_store_express_fields_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        do_action('shutdown');
        unset($GLOBALS['intersoccer_test_actions']['shutdown'], $GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        WC()->session = null;
        $s->shutdown_save();
    }

    private function bookSecondChildExpressFields(string $discount, bool $overlap): int
    {
        $this->runStashAjax($this->startRequest(), 0);
        $this->addToCartRequest(0);
        $this->discountRequest($discount);
        $daysChild2 = ['Tuesday'];
        if ($overlap) {
            $ajax = $this->startRequest();
            $this->addToCartRequest(1);
            $this->assertSame(2, $this->cartLinesNow(), 'add-to-cart for child 2 did commit 2 lines');
            $this->runExpressFieldsStashAjax($ajax, $daysChild2);
        } else {
            $this->runExpressFieldsStashAjax($this->startRequest(), $daysChild2);
            $this->addToCartRequest(1);
        }
        return $this->cartLinesNow();
    }

    private function cartLinesNow(): int
    {
        return count($this->startRequest()->get('cart', []));
    }

    /** @return array<string, mixed> */
    private function seedStaleSessionObjectCache(SiblingDropRequestSession $ajax): array
    {
        $snapshot = unserialize(serialize($ajax->get_session_data()), ['allowed_classes' => false]);
        wp_cache_set(
            SiblingDropSessionStore::wcSessionCacheKey($ajax->get_customer_id()),
            $snapshot,
            WC_SESSION_CACHE_GROUP,
            3600
        );
        return $snapshot;
    }

    private function bookSecondChild(string $discount, bool $overlap): int
    {
        $this->runStashAjax($this->startRequest(), 0);   // pick child 1
        $this->addToCartRequest(0);                       // add child 1
        $this->discountRequest($discount);                // coupon or points
        if ($overlap) {
            $ajax = $this->startRequest();                // pick child 2: AJAX starts...
            $this->addToCartRequest(1);                   // ...user clicks Add to cart; POST completes first
            $this->assertSame(2, $this->cartLinesNow(), 'add-to-cart for child 2 did commit 2 lines');
            $this->runStashAjax($ajax, 1);                // ...AJAX finishes and saves its stale session
        } else {
            $this->runStashAjax($this->startRequest(), 1);
            $this->addToCartRequest(1);
        }
        return $this->cartLinesNow();
    }

    public function testPreconditionStashHandlerWritesSession(): void
    {
        $s = $this->startRequest();
        $this->runStashAjax($s, 1);
        $row = SiblingDropSessionStore::$rows[(string) self::USER_ID] ?? [];
        $this->assertNotEmpty($row[intersoccer_selected_player_session_key(self::CAMP_ID)] ?? null, 'stash AJAX marks the session dirty and saves it');
    }

    public function testOverlappingStashRequestObjectCacheStillHasStaleCart(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->assertCount(1, (array) $ajax->get('cart'), 'sanity: AJAX request starts with a 1-line cart');
        $cache_key = SiblingDropSessionStore::wcSessionCacheKey($ajax->get_customer_id());
        $this->seedStaleSessionObjectCache($ajax);
        $cached = wp_cache_get($cache_key, WC_SESSION_CACHE_GROUP);
        $this->assertIsArray($cached);
        $this->assertCount(1, (array) ($cached['cart'] ?? []), 'stale snapshot is in object cache');
        $this->addToCartRequest(1);
        $this->assertCount(1, (array) ($ajax->get_session(self::USER_ID)['cart'] ?? []), 'get_session still serves the cached 1-line cart');
        $this->assertSame(2, $this->cartLinesNow(), 'DB row already has both children');
    }

    public function testRefreshMergeWithoutCacheDeleteUsesStaleObjectCache(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->seedStaleSessionObjectCache($ajax);
        $this->addToCartRequest(1);

        MockFilters::$filters['intersoccer_skip_wc_session_cache_delete_for_refresh'] = static function () {
            return true;
        };
        WC()->session = $ajax;
        intersoccer_refresh_wc_session_volatile_from_store();
        $this->assertCount(1, (array) $ajax->get('cart'), 'merge without cache drop keeps the stale 1-line cart');
    }

    public function testRefreshMergeAfterCacheDeleteReadsCurrentDbCart(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->seedStaleSessionObjectCache($ajax);
        $this->addToCartRequest(1);

        WC()->session = $ajax;
        intersoccer_refresh_wc_session_volatile_from_store();
        $this->assertCount(2, (array) $ajax->get('cart'), 'cache drop + get_session reads the 2-line DB row');
    }

    public function testRaceExpressFieldsCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChildExpressFields('coupon', true), 'second sibling camp line dropped (coupon, overlapping express-fields stash AJAX)');
    }

    public function testRaceSelectedDaysCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChildSelectedDays('coupon', true), 'second sibling camp line dropped (coupon, overlapping store_selected_days AJAX)');
    }

    /** FAILS on 2.10.17 and master: the stale AJAX save drops child 2. */
    public function testRaceCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChild('coupon', true), 'second sibling camp line silently dropped (coupon, overlapping stash AJAX)');
    }

    /** FAILS too: points are no protection once the requests overlap. */
    public function testRacePointsSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChild('points', true), 'second sibling camp line silently dropped (points, overlapping stash AJAX)');
    }

    /** Control: same coupon cart, AJAX finishes before the add-to-cart. PASSES. */
    public function testSequentialCouponKeepsBothChildren(): void
    {
        $this->assertSame(2, $this->bookSecondChild('coupon', false));
    }

    /** Control (QA's "points" case): AJAX finishes before the add-to-cart. PASSES. */
    public function testSequentialPointsKeepsBothChildren(): void
    {
        $this->assertSame(2, $this->bookSecondChild('points', false));
    }
}
