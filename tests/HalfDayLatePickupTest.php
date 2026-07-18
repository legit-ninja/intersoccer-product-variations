<?php
/**
 * Half-day camp late pickup eligibility tests.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

$GLOBALS['intersoccer_test_post_meta'] = [];

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        global $intersoccer_test_post_meta;
        $post_id = (int) $post_id;
        if (!isset($intersoccer_test_post_meta[$post_id][$key])) {
            return '';
        }
        return $intersoccer_test_post_meta[$post_id][$key];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy = '') {
        return false;
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($id) {
        return new class($id) {
            public function get_parent_id() {
                return 100;
            }

            public function get_attribute($name) {
                global $intersoccer_test_post_meta;
                $id = (int) $this->id ?? 0;
                return $intersoccer_test_post_meta[$id]['attribute_' . $name] ?? '';
            }

            private $id;

            public function __construct($id) {
                $this->id = (int) $id;
            }
        };
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

class HalfDayLatePickupTest extends TestCase {
    protected function tearDown(): void {
        global $intersoccer_test_post_meta;
        $intersoccer_test_post_meta = [];
        parent::tearDown();
    }

    private function setVariationMeta($variation_id, $enabled, $age_slug) {
        $variation_id = (int) $variation_id;
        // Bootstrap get_post_meta reads MockMetaData (local $intersoccer_test_post_meta stub never loads).
        update_post_meta($variation_id, '_intersoccer_enable_late_pickup', $enabled ? 'yes' : 'no');
        update_post_meta($variation_id, 'attribute_pa_age-group', $age_slug);
        global $intersoccer_test_post_meta;
        $intersoccer_test_post_meta[$variation_id]['_intersoccer_enable_late_pickup'] = $enabled ? 'yes' : 'no';
        $intersoccer_test_post_meta[$variation_id]['attribute_pa_age-group'] = $age_slug;
    }

    public function test_is_half_day_age_group_detects_slug_and_label() {
        $this->assertTrue(intersoccer_is_half_day_age_group('3-5y (Half-Day)', '3-5y-half-day'));
        $this->assertFalse(intersoccer_is_half_day_age_group('5-13y (Full Day)', '5-13y-full-day'));
    }

    public function test_half_day_variation_disallows_late_pickup_even_when_enabled() {
        $this->setVariationMeta(201, true, '3-5y-half-day');
        $this->assertFalse(intersoccer_variation_allows_late_pickup(201));
    }

    public function test_full_day_variation_allows_late_pickup_when_enabled() {
        $this->setVariationMeta(202, true, '5-13y-full-day');
        $this->assertTrue(intersoccer_variation_allows_late_pickup(202));
    }
}
