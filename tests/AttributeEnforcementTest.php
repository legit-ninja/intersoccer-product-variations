<?php
/**
 * Attribute enforcement helper tests.
 */

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
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

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error') {
        return true;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(preg_replace('/[^a-z0-9-]+/', '-', (string) $title));
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';

class AttributeEnforcementTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/woocommerce/attribute-enforcement.php';
    }

    public function test_allowed_slug_matches_registry() {
        $this->assertTrue(intersoccer_attr_is_allowed_slug('activity-type'));
        $this->assertTrue(intersoccer_attr_is_allowed_slug('girls-only'));
        $this->assertFalse(intersoccer_attr_is_allowed_slug('random-custom-attr'));
    }

    public function test_camp_allowed_slugs_include_girls_only() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type('camp');
        $this->assertContains('girls-only', $slugs);
        $this->assertContains('camp-terms', $slugs);
        $this->assertNotContains('course-day', $slugs);
    }

    public function test_course_allowed_slugs_include_girls_only_not_camp_terms() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type('course');
        $this->assertContains('girls-only', $slugs);
        $this->assertNotContains('camp-terms', $slugs);
    }
}
