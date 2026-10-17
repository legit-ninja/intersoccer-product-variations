<?php
/**
 * Order-received (thank-you) page must not be cached.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('is_order_received_page')) {
    function is_order_received_page() {
        return !empty($GLOBALS['intersoccer_test_is_order_received_page']);
    }
}

if (!function_exists('wc_nocache_headers')) {
    function wc_nocache_headers() {
        $GLOBALS['intersoccer_test_wc_nocache_headers_called'] = true;
        if (function_exists('wc_maybe_define_constant')) {
            wc_maybe_define_constant('DONOTCACHEPAGE', true);
        } elseif (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}

if (!function_exists('wc_maybe_define_constant')) {
    function wc_maybe_define_constant($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers() {
        $GLOBALS['intersoccer_test_nocache_headers_called'] = true;
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class OrderReceivedNoCacheTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        unset(
            $GLOBALS['intersoccer_test_is_order_received_page'],
            $GLOBALS['intersoccer_test_wc_nocache_headers_called'],
            $GLOBALS['intersoccer_test_nocache_headers_called']
        );
    }

    public function test_does_not_send_nocache_on_non_order_received_pages(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = false;
        intersoccer_send_order_received_nocache_headers();
        $this->assertEmpty($GLOBALS['intersoccer_test_wc_nocache_headers_called'] ?? null);
        $this->assertEmpty($GLOBALS['intersoccer_test_nocache_headers_called'] ?? null);
    }

    public function test_sends_nocache_on_order_received_page(): void {
        $GLOBALS['intersoccer_test_is_order_received_page'] = true;
        intersoccer_send_order_received_nocache_headers();
        $this->assertTrue(!empty($GLOBALS['intersoccer_test_wc_nocache_headers_called']));
        $this->assertTrue(defined('DONOTCACHEPAGE'));
        $this->assertTrue(DONOTCACHEPAGE);
        $this->assertTrue(!empty($GLOBALS['intersoccer_test_nocache_headers_called']));
    }
}
