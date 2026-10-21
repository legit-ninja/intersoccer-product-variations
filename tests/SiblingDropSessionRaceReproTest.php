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

if (!function_exists('intersoccer_is_single_day_booking_type')) {
    function intersoccer_is_single_day_booking_type($booking_type) {
        return $booking_type === 'single-days' || $booking_type === 'single_days';
    }
}

if (!class_exists('InterSoccer_Camp')) {
    class InterSoccer_Camp {
        public static function calculate_price($product_id, $variation_id, $camp_days) {
            return 50.0;
        }
    }
}

if (!function_exists('wc_price')) {
    function wc_price($price) {
        return 'CHF ' . number_format((float) $price, 2);
    }
}

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

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
        return true;
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

/**
 * Mirrors WC_Session_Handler + WC_Abstract_Session: per-key maybe_serialize in _data,
 * get() = maybe_unserialize, get_session() returns the raw row (WC 10.5 class-wc-session-handler.php).
 */
final class SiblingDropRequestSession {
    private $customer;
    /** @var array<string, mixed> Serialized per-key values like WC_Abstract_Session::$_data. */
    private $data;
    private $dirty = false;

    public function __construct($customer) {
        $this->customer = (string) $customer;
        $this->data = SiblingDropSessionStore::$rows[$this->customer] ?? [];
        $this->primeSessionObjectCache();
    }

    private function primeSessionObjectCache(): void {
        wp_cache_add(
            SiblingDropSessionStore::wcSessionCacheKey($this->customer),
            $this->data,
            WC_SESSION_CACHE_GROUP,
            3600
        );
    }

    public function get($key, $default = null) {
        $key = is_string($key) ? $key : (string) $key;
        return array_key_exists($key, $this->data) ? maybe_unserialize($this->data[$key]) : $default;
    }

    public function set($key, $value) {
        $key = is_string($key) ? $key : (string) $key;
        if ($value === null) {
            $this->__unset($key);
            return;
        }
        $serialized = maybe_serialize($value);
        if (($this->data[$key] ?? null) === $serialized) {
            return;
        }
        $this->data[$key] = $serialized;
        $this->dirty = true;
    }

    public function __unset($key) {
        $key = is_string($key) ? $key : (string) $key;
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /** Raw row (serialized values), like WC_Abstract_Session::get_session_data(). */
    public function get_session_data() {
        return $this->data;
    }

    public function get_customer_id() {
        return $this->customer;
    }

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

    public function shutdown_save() {
        if ($this->dirty) {
            SiblingDropSessionStore::$rows[$this->customer] = $this->data;
            wp_cache_set(
                SiblingDropSessionStore::wcSessionCacheKey($this->customer),
                $this->data,
                WC_SESSION_CACHE_GROUP,
                3600
            );
            $this->dirty = false;
        }
    }

    /** @return array<string, mixed> */
    public function pv_get_data_bundle(): array {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function pv_set_data_bundle(array $data): void {
        $this->data = $data;
    }

    public function pv_is_dirty(): bool {
        return $this->dirty;
    }

    public function pv_set_dirty(bool $dirty): void {
        $this->dirty = $dirty;
    }

    public function pv_get_session_expiration(): int {
        return time() + 3600;
    }

    public function pv_fallback_save_data(): void {
        $this->shutdown_save();
    }
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
        unset($GLOBALS['intersoccer_pv_wc_session_cart_mutated'], $GLOBALS['intersoccer_pv_wc_session_cart_snapshot']);
        MockFilters::$filters['intersoccer_pv_wc_session_persisted_row_read'] = static function ($default, $customer_id) {
            $cid = (string) $customer_id;
            if (!isset(SiblingDropSessionStore::$rows[$cid])) {
                return null;
            }
            return [
                'session_value' => maybe_serialize(SiblingDropSessionStore::$rows[$cid]),
                'session_expiry' => time() + 3600,
            ];
        };
        MockFilters::$filters['intersoccer_pv_wc_session_persisted_row_cas_write'] = static function ($null, $customer_id, $old_blob, $new_blob, $expiry) {
            if (!empty($GLOBALS['intersoccer_pv_cas_always_fail_writes'])) {
                return false;
            }
            if (!empty($GLOBALS['intersoccer_pv_cas_simulate_write_conflict_once'])) {
                $GLOBALS['intersoccer_pv_cas_simulate_write_conflict_once'] = false;
                $GLOBALS['intersoccer_pv_cas_simulated_write_conflicts'] = (int) ($GLOBALS['intersoccer_pv_cas_simulated_write_conflicts'] ?? 0) + 1;
                return false;
            }
            $cid = (string) $customer_id;
            if ($old_blob !== '') {
                $current = isset(SiblingDropSessionStore::$rows[$cid])
                    ? maybe_serialize(SiblingDropSessionStore::$rows[$cid])
                    : '';
                if ($current !== $old_blob) {
                    return false;
                }
            }
            $bundle = maybe_unserialize($new_blob);
            if (!is_array($bundle)) {
                return false;
            }
            SiblingDropSessionStore::$rows[$cid] = $bundle;
            wp_cache_set(
                SiblingDropSessionStore::wcSessionCacheKey($cid),
                $bundle,
                WC_SESSION_CACHE_GROUP,
                3600
            );
            return true;
        };
        intersoccer_pv_wc_session_register_cart_mutation_hooks();
        add_action('shutdown', 'intersoccer_pv_wc_session_shutdown_save_router', 20);
        add_action('woocommerce_load_cart_from_session', 'intersoccer_pv_wc_session_snapshot_cart_on_load', 0);
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
        $session = new SiblingDropRequestSession(self::USER_ID);
        intersoccer_pv_wc_session_snapshot_cart_from_session($session);
        return $session;
    }

    private function finishHandlerShutdown(SiblingDropRequestSession $s): void
    {
        WC()->session = $s;
        do_action('shutdown');
        unset($GLOBALS['intersoccer_test_actions']['shutdown'], $GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        WC()->session = null;
    }

    /** Add-to-cart POST: WC_Cart_Session::set_session() stores the new cart; shutdown saves it. */
    private function addToCartRequest(int $childIndex, int $productId = self::CAMP_ID): void
    {
        unset($GLOBALS['intersoccer_pv_wc_session_cart_mutated']);
        $s = $this->startRequest();
        $cart = $s->get('cart', []);
        if (!is_array($cart)) {
            $cart = [];
        }
        $cart['line_child_' . $childIndex] = ['product_id' => $productId, 'quantity' => 1, 'assigned_player' => $childIndex, 'unique_key' => 'player_player-uuid-' . $childIndex];
        $s->set('cart', $cart);
        $s->shutdown_save();
        unset($GLOBALS['intersoccer_pv_wc_session_cart_mutated']);
    }

    /** Runs PV after-add clear hooks inside an add-to-cart request (must not schedule volatile refresh). */
    private function addToCartRequestWithAfterAddClear(int $childIndex, int $productId = self::CAMP_ID): void
    {
        $s = $this->startRequest();
        WC()->session = $s;
        $_REQUEST['add-to-cart'] = (string) $productId;
        $cart = $s->get('cart', []);
        if (!is_array($cart)) {
            $cart = [];
        }
        $cart['line_child_' . $childIndex] = ['product_id' => $productId, 'quantity' => 1, 'assigned_player' => $childIndex, 'unique_key' => 'player_player-uuid-' . $childIndex];
        $s->set('cart', $cart);
        intersoccer_clear_stashed_player_after_add('key', $productId, 1, self::VAR_ID, null, []);
        intersoccer_clear_stashed_express_fields_after_add('key', $productId, 1, self::VAR_ID, null, []);
        unset($GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        do_action('shutdown');
        unset($GLOBALS['intersoccer_test_actions']['shutdown']);
        $this->assertEmpty($GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled'] ?? null, 'add-to-cart must not schedule volatile refresh');
        $_REQUEST = [];
        $s->set('cart', $cart);
        $s->shutdown_save();
        WC()->session = null;
        unset($GLOBALS['intersoccer_pv_wc_session_cart_mutated']);
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
    private function runStashAjax(SiblingDropRequestSession $s, int $childIndex, int $productId = self::CAMP_ID): void
    {
        WC()->session = $s;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => $productId, 'player_index' => (string) $childIndex, 'player_id' => ''];
        try {
            intersoccer_store_selected_player_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        $this->finishHandlerShutdown($s);
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
        $this->finishHandlerShutdown($s);
    }

    private function runClearSessionDataAjax(SiblingDropRequestSession $s): void
    {
        WC()->session = $s;
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => (string) self::CAMP_ID];
        try {
            intersoccer_clear_session_data_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        $this->finishHandlerShutdown($s);
    }

    private function runCalculateCampPriceStashAjax(SiblingDropRequestSession $s, array $campDays): void
    {
        $GLOBALS['intersoccer_test_wp_get_post_parent_id_map'] = [self::VAR_ID => self::CAMP_ID];
        WC()->session = $s;
        $_POST = [
            'nonce' => 'valid_nonce',
            'variation_id' => self::VAR_ID,
            'camp_days' => $campDays,
        ];
        $GLOBALS['intersoccer_test_booking_type'] = 'single-days';
        try {
            intersoccer_calculate_camp_price_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        unset($GLOBALS['intersoccer_test_booking_type']);
        $_POST = [];
        $this->finishHandlerShutdown($s);
    }

    private function runFragmentLikeDirtySessionAjax(SiblingDropRequestSession $s): void
    {
        WC()->session = $s;
        $s->set('client_currency', 'CHF');
        $this->finishHandlerShutdown($s);
    }

    private function bookSecondChildClearSessionData(string $discount, bool $overlap): int
    {
        $this->runStashAjax($this->startRequest(), 0);
        $this->addToCartRequest(0);
        $this->discountRequest($discount);
        if ($overlap) {
            $ajax = $this->startRequest();
            $this->addToCartRequest(1);
            $this->assertSame(2, $this->cartLinesNow(), 'add-to-cart for child 2 did commit 2 lines');
            $this->runClearSessionDataAjax($ajax);
        } else {
            $this->runClearSessionDataAjax($this->startRequest());
            $this->addToCartRequest(1);
        }
        return $this->cartLinesNow();
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
        $this->finishHandlerShutdown($s);
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

    public function testSnapshotCartHookWithoutArgumentUsesWcSession(): void
    {
        $this->addToCartRequest(0);
        WC()->session = $this->startRequest();
        unset($GLOBALS['intersoccer_pv_wc_session_cart_snapshot']);
        do_action('woocommerce_load_cart_from_session');
        $this->assertSame(1, (int) ($GLOBALS['intersoccer_pv_wc_session_cart_snapshot']['lines'] ?? -1));
        WC()->session = null;
    }

    public function testPreconditionStashHandlerWritesSession(): void
    {
        $s = $this->startRequest();
        $this->runStashAjax($s, 1);
        $row = SiblingDropSessionStore::$rows[(string) self::USER_ID] ?? [];
        $stashKey = intersoccer_selected_player_session_key(self::CAMP_ID);
        $stashVal = isset($row[$stashKey]) ? maybe_unserialize($row[$stashKey]) : null;
        $this->assertNotEmpty($stashVal, 'stash AJAX marks the session dirty and saves it');
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
        $this->assertCount(1, (array) maybe_unserialize($cached['cart'] ?? ''), 'stale snapshot is in object cache');
        $this->addToCartRequest(1);
        $this->seedStaleSessionObjectCache($ajax);
        $staleRow = $ajax->get_session(self::USER_ID);
        $this->assertCount(1, (array) maybe_unserialize($staleRow['cart'] ?? ''), 'get_session still serves the cached 1-line cart');
        $this->assertSame(2, $this->cartLinesNow(), 'DB row already has both children');
    }

    public function testRefreshMergeWithoutCacheDeleteUsesStaleObjectCache(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->seedStaleSessionObjectCache($ajax);
        $this->addToCartRequest(1);
        $this->seedStaleSessionObjectCache($ajax);

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
        $this->seedStaleSessionObjectCache($ajax);

        WC()->session = $ajax;
        intersoccer_refresh_wc_session_volatile_from_store();
        $this->assertCount(2, (array) $ajax->get('cart'), 'cache drop + get_session reads the 2-line DB row');
        $this->assertIsArray($ajax->get('cart'), 'merged cart must be decoded to an array');
    }

    public function testSequentialAddsWithAfterAddClearAndOverlappingStashKeepBothLines(): void
    {
        $this->runStashAjax($this->startRequest(), 0);
        $this->addToCartRequestWithAfterAddClear(0);
        $this->discountRequest('coupon');
        $ajax = $this->startRequest();
        $this->addToCartRequestWithAfterAddClear(1);
        $this->assertSame(2, $this->cartLinesNow(), 'second sequential add committed 2 lines');
        $this->runStashAjax($ajax, 1);
        $this->assertSame(2, $this->cartLinesNow());
        $session = $this->startRequest();
        $this->assertIsArray($session->get('cart'));
        $this->assertSame(['repro-sibdrop10'], $session->get('applied_coupons'));
    }

    public function testAddToCartAfterAddClearDoesNotScheduleVolatileRefresh(): void
    {
        unset($GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled']);
        $this->addToCartRequestWithAfterAddClear(0);
        $this->assertEmpty($GLOBALS['intersoccer_wc_session_volatile_refresh_scheduled'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function siblingBookingLocaleMatrix(): array
    {
        $localeOffsets = ['en' => 0, 'fr' => 1000, 'de' => 2000];
        $scenarios = [
            'two_different_camps' => [51723, 51823],
            'course_and_camp' => [52700, 51723],
            'coupon_with_points' => [51723, 51723],
        ];
        $matrix = [];
        foreach ($localeOffsets as $locale => $offset) {
            foreach ($scenarios as $name => [$first, $second]) {
                $matrix[$name . '_' . $locale] = [$locale, $first + $offset, $second + $offset, $name];
            }
        }
        return $matrix;
    }

    /**
     * @dataProvider siblingBookingLocaleMatrix
     */
    public function testSequentialAndOverlappingStashKeepsLinesPerLocale(string $locale, int $firstProductId, int $secondProductId, string $scenario): void
    {
        $GLOBALS['intersoccer_test_wpml_current_language'] = $locale;
        $stashProductId = $secondProductId;
        $this->runStashAjax($this->startRequest(), 0, $firstProductId);
        $this->addToCartRequestWithAfterAddClear(0, $firstProductId);
        if ($scenario === 'coupon_with_points') {
            $s = $this->startRequest();
            $s->set('applied_coupons', ['repro-sibdrop10']);
            $s->set('intersoccer_points_to_redeem', 20);
            $s->shutdown_save();
        } else {
            $this->discountRequest('coupon');
        }
        $ajax = $this->startRequest();
        $this->addToCartRequestWithAfterAddClear(1, $secondProductId);
        $this->assertSame(2, $this->cartLinesNow());
        $this->runStashAjax($ajax, 1, $stashProductId);
        $this->assertSame(2, $this->cartLinesNow());
        $session = $this->startRequest();
        $this->assertIsArray($session->get('cart'));
        $this->assertSame(['repro-sibdrop10'], $session->get('applied_coupons'));
        if ($scenario === 'coupon_with_points') {
            $this->assertSame(20, (int) $session->get('intersoccer_points_to_redeem'));
        }
    }

    public function testRaceExpressFieldsCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChildExpressFields('coupon', true), 'second sibling camp line dropped (coupon, overlapping express-fields stash AJAX)');
    }

    public function testRaceSelectedDaysCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChildSelectedDays('coupon', true), 'second sibling camp line dropped (coupon, overlapping store_selected_days AJAX)');
    }

    public function testRaceClearSessionDataCouponSecondChildLineIsKept(): void
    {
        $this->assertSame(2, $this->bookSecondChildClearSessionData('coupon', true), 'second sibling camp line dropped (coupon, overlapping clear_session_data AJAX)');
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function slowServerHandlerMatrix(): array
    {
        return [
            'store_selected_player' => ['store_selected_player'],
            'store_express_fields' => ['store_express_fields'],
            'store_selected_days' => ['store_selected_days'],
            'calculate_camp_price' => ['calculate_camp_price'],
            'clear_session_data' => ['clear_session_data'],
            'fragment_like_dirty' => ['fragment_like_dirty'],
        ];
    }

    /**
     * Handler started before add, add commits fully, handler finishes after (slow-server interleaving).
     *
     * @dataProvider slowServerHandlerMatrix
     */
    public function testSlowServerHandlerStartedBeforeAddKeepsNewerLine(string $handler): void
    {
        MockMetaData::$data[self::VAR_ID]['attribute_pa_booking-type'] = 'single-days';
        $this->runStashAjax($this->startRequest(), 0);
        $this->addToCartRequest(0);
        $this->discountRequest('coupon');
        $ajax = $this->startRequest();
        $this->addToCartRequest(1);
        $this->assertSame(2, $this->cartLinesNow(), 'add committed before slow handler finished');

        switch ($handler) {
            case 'store_selected_player':
                $this->runStashAjax($ajax, 1);
                break;
            case 'store_express_fields':
                $this->runExpressFieldsStashAjax($ajax, ['Tuesday']);
                break;
            case 'store_selected_days':
                $this->runSelectedDaysStashAjax($ajax, ['Tuesday']);
                break;
            case 'calculate_camp_price':
                $this->runCalculateCampPriceStashAjax($ajax, ['Tuesday']);
                break;
            case 'clear_session_data':
                $this->runClearSessionDataAjax($ajax);
                break;
            case 'fragment_like_dirty':
                $this->runFragmentLikeDirtySessionAjax($ajax);
                break;
            default:
                $this->fail('Unknown handler');
        }

        $this->assertSame(2, $this->cartLinesNow(), 'CAS save must not drop the newer cart line');
        $session = $this->startRequest();
        $this->assertSame(['repro-sibdrop10'], $session->get('applied_coupons'));
        if ($handler === 'store_selected_player') {
            $stash = $session->get(intersoccer_selected_player_session_key(self::CAMP_ID));
            $this->assertIsArray($stash);
            $this->assertNotEmpty($stash['player_id'] ?? '');
        }
    }

    public function testCasSaveWarningOnlyWhenStaleWritePrevented(): void
    {
        $this->addToCartRequest(0);
        $GLOBALS['intersoccer_test_warnings'] = [];
        $this->runFragmentLikeDirtySessionAjax($this->startRequest());
        $this->assertSame([], $GLOBALS['intersoccer_test_warnings'], 'no log without a concurrent cart change');
        add_action('shutdown', 'intersoccer_pv_wc_session_shutdown_save_router', 20);
        $ajax = $this->startRequest();
        $this->addToCartRequest(1);
        WC()->session = $ajax;
        $ajax->set('client_currency', 'EUR');
        $this->finishHandlerShutdown($ajax);
        $this->assertSame(2, $this->cartLinesNow());
        $this->assertCount(1, $GLOBALS['intersoccer_test_warnings']);
        $this->assertMatchesRegularExpression('/^PV session CAS save kind=\S+ prevented_stale=1 attempts=1 fallback=0$/', $GLOBALS['intersoccer_test_warnings'][0]);
    }

    public function testAddCommitsAfterVolatileRefreshBeforeHandlerShutdownSave(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->seedStaleSessionObjectCache($ajax);
        WC()->session = $ajax;
        intersoccer_refresh_wc_session_volatile_from_store();
        $this->addToCartRequest(1);
        $_POST = ['nonce' => 'valid_nonce', 'product_id' => self::CAMP_ID, 'player_index' => '1', 'player_id' => ''];
        try {
            intersoccer_store_selected_player_callback();
        } catch (Exception $e) {
            $this->assertSame('AJAX_EXIT', $e->getMessage());
        }
        $_POST = [];
        $this->finishHandlerShutdown($ajax);
        $this->assertSame(2, $this->cartLinesNow());
    }

    public function testCasRetryKeepsNewerCartWhenRowChangesBetweenReads(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->addToCartRequest(1);
        WC()->session = $ajax;
        $ajax->set(intersoccer_selected_player_session_key(self::CAMP_ID), [
            'player_index' => 1,
            'player_id' => 'player-uuid-1',
            'user_id' => self::USER_ID,
            'stashed_at' => time(),
        ]);
        $GLOBALS['intersoccer_pv_cas_simulate_write_conflict_once'] = true;
        $GLOBALS['intersoccer_pv_cas_simulated_write_conflicts'] = 0;
        $this->finishHandlerShutdown($ajax);
        $this->assertSame(1, (int) ($GLOBALS['intersoccer_pv_cas_simulated_write_conflicts'] ?? 0));
        $this->assertSame(2, $this->cartLinesNow());
    }

    public function testCasExhaustionFallbackSaveKeepsNewerCartLine(): void
    {
        $this->addToCartRequest(0);
        $ajax = $this->startRequest();
        $this->addToCartRequest(1);
        WC()->session = $ajax;
        $ajax->set(intersoccer_selected_player_session_key(self::CAMP_ID), [
            'player_index' => 1,
            'player_id' => 'player-uuid-1',
            'user_id' => self::USER_ID,
            'stashed_at' => time(),
        ]);
        $GLOBALS['intersoccer_pv_cas_always_fail_writes'] = true;
        $this->finishHandlerShutdown($ajax);
        unset($GLOBALS['intersoccer_pv_cas_always_fail_writes']);
        $this->assertSame(2, $this->cartLinesNow());
    }

    public function testGuestNoPersistedRowUsesFallbackSave(): void
    {
        $guest = new SiblingDropRequestSession('t_guest123');
        intersoccer_pv_wc_session_snapshot_cart_from_session($guest);
        $guest->set('intersoccer_selected_player_' . self::CAMP_ID, ['player_id' => 'x', 'stashed_at' => time()]);
        WC()->session = $guest;
        $this->finishHandlerShutdown($guest);
        $this->assertArrayHasKey('t_guest123', SiblingDropSessionStore::$rows);
        $this->assertFalse($guest->pv_is_dirty());
    }
}
