<?php
/**
 * Order Meta Repair discovery helpers (risk ID collection + batch/scan alignment).
 */

use PHPUnit\Framework\TestCase;

if (!defined('INTERSOCCER_ORDER_META_REPAIR_TEST')) {
    define('INTERSOCCER_ORDER_META_REPAIR_TEST', true);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
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

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
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

class OrderMetaRepairDiscoveryTest extends TestCase {
    public function test_collect_order_ids_by_risk_filters_and_dedupes() {
        $rows = [
            ['order_id' => 101, 'risk_level' => 'low'],
            ['order_id' => 102, 'risk_level' => 'medium'],
            ['order_id' => 103, 'risk_level' => 'high'],
            ['order_id' => 104, 'risk_level' => 'medium'],
            ['order_id' => 102, 'risk_level' => 'medium'],
            ['order_id' => 0, 'risk_level' => 'low'],
            'not-an-array',
        ];

        $this->assertSame([101], intersoccer_collect_order_ids_by_risk($rows, 'low'));
        $this->assertSame([102, 104], intersoccer_collect_order_ids_by_risk($rows, 'medium'));
        $this->assertSame([103], intersoccer_collect_order_ids_by_risk($rows, 'high'));
        $this->assertSame([], intersoccer_collect_order_ids_by_risk($rows, 'unknown'));
    }

    public function test_batch_discovery_uses_shared_fillable_missing_helper() {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('function intersoccer_order_has_fillable_missing_meta', $src);
        $this->assertStringContainsString('function intersoccer_get_orders_needing_updates', $src);
        $this->assertStringContainsString('intersoccer_order_has_fillable_missing_meta($order)', $src);
        $this->assertStringNotContainsString(
            "\$essential_fields = ['Medical Conditions', 'Activity Type', 'Season', 'Attendee DOB', 'Attendee Gender'];",
            $src
        );
    }

    public function test_scan_ui_exposes_medium_and_high_select_all() {
        $src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('select-all-medium-risk', $src);
        $this->assertStringContainsString('select-all-high-risk', $src);
        $this->assertStringContainsString('intersoccer-select-by-risk', $src);
        $this->assertStringContainsString("\$per_page = 25;", $src);
    }
}
