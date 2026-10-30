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

if (!function_exists('get_post_status')) {
    function get_post_status($post_id) {
        $post_id = (int) $post_id;
        if (isset($GLOBALS['intersoccer_test_post_status'][$post_id])) {
            return $GLOBALS['intersoccer_test_post_status'][$post_id];
        }

        return 'draft';
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        return $GLOBALS['intersoccer_test_current_screen'] ?? null;
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id() {
            return 0;
        }

        public function is_type($type) {
            return false;
        }

        public function get_parent_id() {
            return 0;
        }

        public function get_children() {
            return [];
        }

        public function get_price() {
            return 0;
        }
    }
}

class AttributeEnforcementMockAttributeWithTerms {
    public function get_options() {
        return [1];
    }
}

class AttributeEnforcementSaveMockProduct extends WC_Product {
    /** @var array<int,string> */
    public $set_status_calls = [];

    /** @var array<string,mixed> */
    private $attributes;

    public function __construct(int $id, string $status, array $attributes) {
        $this->id = $id;
        $this->status = $status;
        $this->attributes = $attributes;
    }

    private $id;

    private $status;

    public function get_id() {
        return $this->id;
    }

    public function get_status() {
        return $this->status;
    }

    public function set_status($status) {
        $this->set_status_calls[] = (string) $status;
        $this->status = (string) $status;
    }

    public function get_attributes() {
        return $this->attributes;
    }
}

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

    public function test_camp_times_accepts_new_office_ranges() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '0830-1530'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '1200-1530'));
    }

    public function test_camp_times_accepts_registry_defaults() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '1000-1700'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '1000-1230'));
    }

    public function test_camp_times_accepts_wpml_suffixed_slugs() {
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '1000-1700', '1000-1700-fr'));
        $this->assertTrue(intersoccer_attr_validate_new_term('pa_camp-times', '1000-1700', '1000-1700-de'));
    }

    public function test_camp_times_rejects_malformed_ranges() {
        $bad = [
            '8:30-15:30x',
            '1530-0830',
            '2500-2600',
            'abc',
            '10:00-17:00',
            '10h00-17h00',
        ];
        foreach ($bad as $name) {
            $result = intersoccer_attr_validate_new_term('pa_camp-times', $name);
            $this->assertInstanceOf(WP_Error::class, $result, 'Expected rejection for: ' . $name);
            $this->assertSame('invalid_camp_time', array_key_first($result->errors));
        }
    }

    public function test_pre_insert_term_camp_times_filter() {
        $bad = intersoccer_attr_pre_insert_term('1530-0830', 'pa_camp-times');
        $this->assertInstanceOf(WP_Error::class, $bad);

        $good = intersoccer_attr_pre_insert_term('0830-1530', 'pa_camp-times');
        $this->assertSame('0830-1530', $good);
    }

    public function test_camp_times_not_on_locked_slug_list() {
        $this->assertNotContains('camp-times', intersoccer_attr_locked_term_attribute_slugs());
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

    public function test_live_publish_product_with_shape_violation_stays_publish_and_stores_notice() {
        $this->reset_product_save_test_state();
        $product_id = 51723;
        intersoccer_attr_set_product_pre_save_status($product_id, 'publish');
        $GLOBALS['intersoccer_test_post_status'][$product_id] = 'publish';
        $this->seed_year_qualified_season_on_product($product_id);
        $product = $this->make_camp_product_for_save($product_id, 'publish');

        intersoccer_attr_validate_product_on_save($product);

        $this->assertSame('publish', $product->get_status());
        $this->assertNotContains('draft', $product->set_status_calls);
        $stored = get_transient(intersoccer_attr_product_save_notice_transient_key($product_id));
        $this->assertIsArray($stored);
        $this->assertSame('warning', $stored['type']);
        $this->assertStringContainsString('Saved and kept live', $stored['message']);
    }

    public function test_live_private_product_with_shape_violation_is_not_demoted_to_draft() {
        $this->reset_product_save_test_state();
        $product_id = 51818;
        intersoccer_attr_set_product_pre_save_status($product_id, 'private');
        $GLOBALS['intersoccer_test_post_status'][$product_id] = 'private';
        $this->seed_year_qualified_season_on_product($product_id);
        $product = $this->make_camp_product_for_save($product_id, 'publish');

        intersoccer_attr_validate_product_on_save($product);

        $this->assertNotContains('draft', $product->set_status_calls);
        $this->assertNotFalse(get_transient(intersoccer_attr_product_save_notice_transient_key($product_id)));
    }

    public function test_draft_product_with_shape_violation_blocked_from_publish() {
        $this->reset_product_save_test_state();
        $product_id = 41078;
        intersoccer_attr_set_product_pre_save_status($product_id, 'draft');
        $GLOBALS['intersoccer_test_post_status'][$product_id] = 'draft';
        $this->seed_year_qualified_season_on_product($product_id);
        $product = $this->make_camp_product_for_save($product_id, 'publish');

        intersoccer_attr_validate_product_on_save($product);

        $this->assertSame('draft', $product->get_status());
        $this->assertContains('draft', $product->set_status_calls);
        $stored = get_transient(intersoccer_attr_product_save_notice_transient_key($product_id));
        $this->assertIsArray($stored);
        $this->assertSame('error', $stored['type']);
        $this->assertStringContainsString('Publish blocked', $stored['message']);
    }

    public function test_draft_blocked_when_post_status_already_publish_before_live_check() {
        $this->reset_product_save_test_state();
        $product_id = 41078;
        intersoccer_attr_set_product_pre_save_status($product_id, 'draft');
        $GLOBALS['intersoccer_test_post_status'][$product_id] = 'publish';
        $this->seed_year_qualified_season_on_product($product_id);
        $product = $this->make_camp_product_for_save($product_id, 'publish');

        intersoccer_attr_validate_product_on_save($product);

        $this->assertSame('draft', $product->get_status());
        $this->assertContains('draft', $product->set_status_calls);
        $stored = get_transient(intersoccer_attr_product_save_notice_transient_key($product_id));
        $this->assertIsArray($stored);
        $this->assertStringContainsString('Publish blocked', $stored['message']);
    }

    public function test_product_save_notice_shown_once_on_edit_screen() {
        $this->reset_product_save_test_state();
        $product_id = 51723;
        intersoccer_attr_store_product_save_notice($product_id, 'Test persistent notice', 'warning');
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_current_screen'] = (object) ['id' => 'product'];
        $_GET['post'] = (string) $product_id;

        ob_start();
        intersoccer_attr_admin_product_save_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('Test persistent notice', $html);
        $this->assertFalse(get_transient(intersoccer_attr_product_save_notice_transient_key($product_id)));
    }

    public function test_product_save_notice_cleared_when_save_has_no_violations() {
        $this->reset_product_save_test_state();
        $product_id = 51723;
        intersoccer_attr_store_product_save_notice($product_id, 'Stale notice', 'error');
        intersoccer_attr_set_product_pre_save_status($product_id, 'publish');
        $GLOBALS['intersoccer_test_post_status'][$product_id] = 'publish';
        $GLOBALS['intersoccer_test_product_terms'][$product_id]['pa_program-season'] = [
            (object) ['name' => 'Autumn', 'slug' => 'autumn'],
        ];
        $product = $this->make_camp_product_for_save($product_id, 'publish');

        intersoccer_attr_validate_product_on_save($product);

        $this->assertFalse(get_transient(intersoccer_attr_product_save_notice_transient_key($product_id)));
    }

    private function reset_product_save_test_state(): void {
        $GLOBALS['intersoccer_test_transients'] = [];
        $GLOBALS['intersoccer_test_product_terms'] = [];
        $GLOBALS['intersoccer_test_post_status'] = [];
        $GLOBALS['intersoccer_attr_product_pre_save_status'] = [];
        $GLOBALS['intersoccer_test_product_type'] = 'camp';
        $GLOBALS['intersoccer_test_user_id'] = 42;
        $GLOBALS['intersoccer_test_is_admin'] = false;
        unset($GLOBALS['intersoccer_test_current_screen']);
        unset($_GET['post']);
    }

    private function seed_year_qualified_season_on_product(int $product_id): void {
        $GLOBALS['intersoccer_test_product_terms'][$product_id]['pa_program-season'] = [
            (object) [
                'name' => 'Autumn Camps 2026',
                'slug' => 'autumn-camps-2026',
            ],
        ];
    }

    private function make_camp_product_for_save(int $product_id, string $status): AttributeEnforcementSaveMockProduct {
        $attributes = [];
        foreach (intersoccer_attr_required_parent_taxonomies('camp') as $taxonomy) {
            $attributes[$taxonomy] = new AttributeEnforcementMockAttributeWithTerms();
        }

        return new AttributeEnforcementSaveMockProduct($product_id, $status, $attributes);
    }
}
