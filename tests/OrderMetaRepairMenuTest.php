<?php
/**
 * Smoke tests for Order Meta Repair admin slug + legacy redirects.
 */

use PHPUnit\Framework\TestCase;

if (!defined('INTERSOCCER_ORDER_META_REPAIR_TEST')) {
    define('INTERSOCCER_ORDER_META_REPAIR_TEST', true);
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($GLOBALS['intersoccer_test_is_admin']);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        return !empty($GLOBALS['intersoccer_test_can_manage']);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null) {
        if (is_array($key)) {
            $args = $key;
            $url = $value;
        } else {
            $args = [$key => $value];
        }
        $url = $url ?: '';
        $sep = strpos($url, '?') === false ? '?' : '&';
        return $url . $sep . http_build_query($args);
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302) {
        $GLOBALS['intersoccer_test_redirect'] = $location;
        return true;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-repair-admin.php';

class OrderMetaRepairMenuTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['intersoccer_test_redirect'] = null;
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_can_manage'] = true;
        $_GET = [];
    }

    public function test_page_slug_is_unified_order_meta_repair() {
        $this->assertSame('intersoccer-order-meta-repair', intersoccer_order_meta_repair_page_slug());
    }

    public function test_admin_ui_registers_unified_submenu_not_legacy_slugs() {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString("intersoccer_order_meta_repair_page_slug()", $src);
        $this->assertStringContainsString("intersoccer_render_order_meta_repair_page", $src);
        // Old tools are not registered as submenu pages anymore.
        $this->assertDoesNotMatchRegularExpression(
            "/add_submenu_page\s*\([^;]*'intersoccer-update-orders'/s",
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            "/add_submenu_page\s*\([^;]*'intersoccer-automated-updates'/s",
            $src
        );
        $helper = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/order-meta-repair-admin.php');
        $this->assertStringContainsString("'intersoccer-update-orders'", $helper);
        $this->assertStringContainsString("'intersoccer-automated-updates'", $helper);
    }

    public function test_legacy_scan_slug_redirects_to_unified_scan_tab() {
        $_GET['page'] = 'intersoccer-update-orders';
        intersoccer_redirect_legacy_order_meta_repair_pages();
        $this->assertNotNull($GLOBALS['intersoccer_test_redirect']);
        $this->assertStringContainsString('page=intersoccer-order-meta-repair', $GLOBALS['intersoccer_test_redirect']);
        $this->assertStringContainsString('tab=scan', $GLOBALS['intersoccer_test_redirect']);
    }

    public function test_legacy_batch_slug_redirects_to_unified_batch_tab() {
        $_GET['page'] = 'intersoccer-automated-updates';
        intersoccer_redirect_legacy_order_meta_repair_pages();
        $this->assertNotNull($GLOBALS['intersoccer_test_redirect']);
        $this->assertStringContainsString('page=intersoccer-order-meta-repair', $GLOBALS['intersoccer_test_redirect']);
        $this->assertStringContainsString('tab=batch', $GLOBALS['intersoccer_test_redirect']);
    }
}
