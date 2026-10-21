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
}

/** Mirrors WC_Session_Handler: read whole row at request start, write whole row at shutdown if dirty. */
final class SiblingDropRequestSession {
    private $customer; private $data; private $dirty = false;
    public function __construct($customer) {
        $this->customer = (string) $customer;
        $this->data = SiblingDropSessionStore::$rows[$this->customer] ?? [];
    }
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { if (($this->data[$key] ?? null) !== $value) { $this->data[$key] = $value; $this->dirty = true; } }
    public function __unset($key) { if (array_key_exists($key, $this->data)) { unset($this->data[$key]); $this->dirty = true; } }
    public function get_session_data() { return $this->data; }
    public function shutdown_save() { if ($this->dirty) { SiblingDropSessionStore::$rows[$this->customer] = $this->data; $this->dirty = false; } }
}

class SiblingDropSessionRaceReproTest extends TestCase
{
    private const CAMP_ID = 51723;
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
    }

    protected function tearDown(): void
    {
        $_POST = [];
        WC()->session = null;
        unset($GLOBALS['intersoccer_test_player_by_index'], $GLOBALS['intersoccer_test_json_capture'], $GLOBALS['intersoccer_test_json']);
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
        WC()->session = null;
        $s->shutdown_save();
    }

    private function cartLinesNow(): int
    {
        return count($this->startRequest()->get('cart', []));
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
