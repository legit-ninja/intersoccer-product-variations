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
        require_once dirname(__DIR__) . '/includes/helpers.php';
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
        $this->assertContains('course-day', $slugs);
        $this->assertContains('course-times', $slugs);
        $this->assertNotContains('camp-terms', $slugs);
    }

    public function test_birthday_allowed_slugs_include_optional_exclude_season_year() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type('birthday');
        $this->assertContains('activity-type', $slugs);
        $this->assertContains('intersoccer-venues', $slugs);
        $this->assertContains('age-group', $slugs);
        $this->assertContains('canton-region', $slugs);
        $this->assertContains('city', $slugs);
        $this->assertNotContains('program-season', $slugs);
        $this->assertNotContains('program-year', $slugs);
        $this->assertNotContains('camp-terms', $slugs);
    }

    public function test_pm_rejects_year_qualified_season_term() {
        $result = intersoccer_attr_validate_new_term('pa_program-season', 'Autumn 2027');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('year_qualified_season', $result->errors ? array_key_first($result->errors) : '');
    }

    public function test_pm_rejects_autumn_2027_slug_season() {
        $result = intersoccer_attr_validate_new_term('pa_program-season', 'autumn-2027');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_pm_accepts_evergreen_season() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Autumn', 'autumn'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-season', 'Summer'));
    }

    public function test_pm_rejects_non_bare_program_year() {
        $result = intersoccer_attr_validate_new_term('pa_program-year', 'Autumn 2027');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('non_bare_year', array_key_first($result->errors));
    }

    public function test_pm_accepts_bare_program_year() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-year', '2026'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_program-year', '2028'));
    }

    public function test_pm_rejects_activity_type_outside_registry() {
        $result = intersoccer_attr_validate_new_term('pa_activity-type', 'Camp, Girls Only');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('term_not_in_registry', array_key_first($result->errors));
    }

    public function test_pm_accepts_registry_activity_types_only() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_activity-type', 'Camp', 'camp'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_activity-type', 'Birthday Party', 'birthday-party'));
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term('pa_activity-type', 'Event'));
    }

    public function test_pm_rejects_girls_only_outside_registry() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_girls-only', 'Yes', 'yes'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_girls-only', "Girl's Only", 'girls-only'));
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term('pa_girls-only', 'Maybe'));
    }

    public function test_pm_rejects_weekday_outside_registry() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_days-of-week', 'Monday', 'monday'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_course-day', 'Friday'));
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term('pa_days-of-week', 'Weekday'));
    }

    public function test_venues_stay_freeform_with_en_slug() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_intersoccer-venues', 'Geneva Centre Sportif'));
        $this->assertSame('geneva-centre-sportif', intersoccer_attr_english_term_slug('Geneva Centre Sportif'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_city', 'Lausanne'));
    }

    public function test_required_parent_facets_block_publish_for_camp() {
        $missing = intersoccer_attr_parent_facets_block_publish(['pa_activity-type'], 'camp');
        $this->assertContains('pa_program-year', $missing);
        $this->assertContains('pa_program-season', $missing);
        $this->assertContains('pa_girls-only', $missing);

        $complete = intersoccer_attr_required_parent_taxonomies('camp');
        $this->assertSame([], intersoccer_attr_parent_facets_block_publish($complete, 'camp'));
    }

    public function test_birthday_publish_requires_activity_type_only() {
        $this->assertSame([], intersoccer_attr_parent_facets_block_publish(['pa_activity-type'], 'birthday'));
        $this->assertSame(['pa_activity-type'], intersoccer_attr_parent_facets_block_publish([], 'birthday'));
    }
}
