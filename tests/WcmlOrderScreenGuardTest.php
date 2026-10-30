<?php
/**
 * WCML admin order screen guard — block itemmeta rewrite on view.
 */

use PHPUnit\Framework\TestCase;

class WcmlOrderScreenGuardTest extends TestCase {
    /** @var object|null */
    private $saved_woocommerce_wpml;

    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        $this->saved_woocommerce_wpml = $GLOBALS['woocommerce_wpml'] ?? null;
        unset($GLOBALS['woocommerce_wpml']);
        $admin_init_callbacks = $GLOBALS['intersoccer_test_actions']['admin_init'] ?? null;
        $GLOBALS['intersoccer_test_removed_actions'] = [];
        $GLOBALS['intersoccer_test_actions'] = [];
        if ($admin_init_callbacks !== null) {
            $GLOBALS['intersoccer_test_actions']['admin_init'] = $admin_init_callbacks;
        }

        if (!function_exists('intersoccer_pv_block_wcml_order_itemmeta_rewrite')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/wcml-order-screen-guard.php';
        }
    }

    protected function tearDown(): void {
        if ($this->saved_woocommerce_wpml === null) {
            unset($GLOBALS['woocommerce_wpml']);
        } else {
            $GLOBALS['woocommerce_wpml'] = $this->saved_woocommerce_wpml;
        }
        unset($GLOBALS['intersoccer_test_removed_actions']);
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
        parent::tearDown();
    }

    private function makeWcmlOrdersStub(): object {
        return new class {
            public function backend_before_order_itemmeta() {
            }

            public function backend_after_order_itemmeta() {
            }
        };
    }

    public function test_removes_wcml_itemmeta_callbacks_at_priority_100(): void {
        $orders = $this->makeWcmlOrdersStub();
        $GLOBALS['woocommerce_wpml'] = (object) ['orders' => $orders];

        add_action('woocommerce_before_order_itemmeta', [$orders, 'backend_before_order_itemmeta'], 100);
        add_action('woocommerce_after_order_itemmeta', [$orders, 'backend_after_order_itemmeta'], 100);

        intersoccer_pv_block_wcml_order_itemmeta_rewrite();

        $removed = $GLOBALS['intersoccer_test_removed_actions'];
        $this->assertCount(2, $removed);
        $this->assertSame('woocommerce_before_order_itemmeta', $removed[0]['hook']);
        $this->assertSame([$orders, 'backend_before_order_itemmeta'], $removed[0]['callback']);
        $this->assertSame(100, $removed[0]['priority']);
        $this->assertSame('woocommerce_after_order_itemmeta', $removed[1]['hook']);
        $this->assertSame([$orders, 'backend_after_order_itemmeta'], $removed[1]['callback']);
        $this->assertSame(100, $removed[1]['priority']);
        $this->assertFalse(has_action('woocommerce_before_order_itemmeta', [$orders, 'backend_before_order_itemmeta'], 100));
        $this->assertFalse(has_action('woocommerce_after_order_itemmeta', [$orders, 'backend_after_order_itemmeta'], 100));
    }

    public function test_no_op_when_woocommerce_wpml_missing(): void {
        unset($GLOBALS['woocommerce_wpml']);
        intersoccer_pv_block_wcml_order_itemmeta_rewrite();
        $this->assertSame([], $GLOBALS['intersoccer_test_removed_actions']);
    }

    public function test_no_op_when_orders_property_missing(): void {
        $GLOBALS['woocommerce_wpml'] = (object) [];
        intersoccer_pv_block_wcml_order_itemmeta_rewrite();
        $this->assertSame([], $GLOBALS['intersoccer_test_removed_actions']);
    }

    public function test_off_switch_skips_remove_action(): void {
        MockFilters::$filters['intersoccer_pv_block_wcml_order_itemmeta_rewrite'] = static function () {
            return false;
        };
        $orders = $this->makeWcmlOrdersStub();
        $GLOBALS['woocommerce_wpml'] = (object) ['orders' => $orders];
        add_action('woocommerce_before_order_itemmeta', [$orders, 'backend_before_order_itemmeta'], 100);

        intersoccer_pv_block_wcml_order_itemmeta_rewrite();

        $this->assertSame([], $GLOBALS['intersoccer_test_removed_actions']);
    }

    public function test_guard_registered_on_admin_init_priority_one(): void {
        $callbacks = $GLOBALS['intersoccer_test_actions']['admin_init'][1] ?? [];
        $this->assertContains('intersoccer_pv_block_wcml_order_itemmeta_rewrite', $callbacks);
    }
}
