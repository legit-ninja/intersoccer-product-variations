<?php
/**
 * Order meta contract tests.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!class_exists('MockFilters')) {
    class MockFilters {
        public static $filters = [];

        public static function reset() {
            self::$filters = [];
        }
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        $hook = array_shift($args);
        $value = array_shift($args);

        if (isset(MockFilters::$filters[$hook]) && is_callable(MockFilters::$filters[$hook])) {
            array_unshift($args, $value);
            return call_user_func_array(MockFilters::$filters[$hook], $args);
        }

        return $value;
    }
}

if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy) {
        return false;
    }
}

if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms($product_id, $taxonomy, $args = []) {
        return [];
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/girls-only-verification.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class OrderMetaContractTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockFilters')) {
            MockFilters::reset();
        }
    }

    public function test_deprecated_keys_include_player_index_and_variation_id() {
        $keys = intersoccer_order_meta_deprecated_keys();
        $this->assertContains('Player Index', $keys);
        $this->assertContains('Variation ID', $keys);
        $this->assertContains('Base Price', $keys);
        $this->assertContains('Remaining Sessions', $keys);
    }

    public function test_allowed_keys_include_girls_only_for_camp() {
        $keys = intersoccer_order_meta_allowed_keys('camp');
        $this->assertContains('Girls Only', $keys);
        $this->assertContains('assigned_player', $keys);
        $this->assertNotContains('Player Index', $keys);
    }

    public function test_resolve_order_activity_type_composite_when_filtered_girls_only() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return true;
        };

        $value = intersoccer_resolve_order_activity_type(100, 200, 'camp', '');
        $this->assertStringContainsString('Camp', $value);
        $this->assertStringContainsString('Girls Only', $value);
    }

    public function test_resolve_order_activity_type_plain_when_not_girls_only() {
        MockFilters::$filters['intersoccer_order_activity_type_is_girls_only'] = static function () {
            return false;
        };

        $this->assertSame('Camp', intersoccer_resolve_order_activity_type(100, 200, 'camp', ''));
    }

    public function test_activity_type_girls_only_suffix_localized() {
        $this->assertSame('Girls Only', intersoccer_order_activity_type_girls_only_suffix('en'));
        $this->assertSame('Filles uniquement', intersoccer_order_activity_type_girls_only_suffix('fr'));
    }
}
