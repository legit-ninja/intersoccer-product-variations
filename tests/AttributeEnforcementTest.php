<?php
/**
 * Attribute enforcement helper tests.
 */

use PHPUnit\Framework\TestCase;

if (!defined("ABSPATH")) {
    define("ABSPATH", __DIR__ . "/../");
}

if (!function_exists("add_filter")) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists("add_action")) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists("current_user_can")) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists("add_settings_error")) {
    function add_settings_error($setting, $code, $message, $type = "error") {
        return true;
    }
}

if (!function_exists("sanitize_title")) {
    function sanitize_title($title) {
        return strtolower(preg_replace("/[^a-z0-9-]+/", "-", (string) $title));
    }
}

if (!function_exists("get_current_screen")) {
    function get_current_screen() {
        return $GLOBALS["intersoccer_test_screen"] ?? null;
    }
}

require_once dirname(__DIR__) . "/includes/woocommerce/attribute-registry.php";

class AttributeEnforcementTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS["intersoccer_test_transients"] = [];
        $GLOBALS["intersoccer_test_user_id"] = 7;
        unset($GLOBALS["intersoccer_test_is_admin"]);
        $GLOBALS["intersoccer_test_screen"] = null;
        unset($_GET["post"]);
        require_once dirname(__DIR__) . "/includes/helpers.php";
        require_once dirname(__DIR__) . "/includes/woocommerce/attribute-enforcement.php";
    }

    protected function tearDown(): void {
        unset($GLOBALS["intersoccer_test_is_admin"], $GLOBALS["intersoccer_test_screen"], $_GET["post"]);
        parent::tearDown();
    }

    public function test_allowed_slug_matches_registry() {
        $this->assertTrue(intersoccer_attr_is_allowed_slug("activity-type"));
        $this->assertTrue(intersoccer_attr_is_allowed_slug("girls-only"));
        $this->assertFalse(intersoccer_attr_is_allowed_slug("random-custom-attr"));
    }

    public function test_camp_allowed_slugs_include_girls_only() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type("camp");
        $this->assertContains("girls-only", $slugs);
        $this->assertContains("camp-terms", $slugs);
        $this->assertNotContains("course-day", $slugs);
    }

    public function test_course_allowed_slugs_include_girls_only_not_camp_terms() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type("course");
        $this->assertContains("girls-only", $slugs);
        $this->assertContains("course-day", $slugs);
        $this->assertContains("course-times", $slugs);
        $this->assertNotContains("camp-terms", $slugs);
    }

    public function test_birthday_allowed_slugs_include_optional_exclude_season_year() {
        $slugs = intersoccer_attr_allowed_slugs_for_product_type("birthday");
        $this->assertContains("activity-type", $slugs);
        $this->assertContains("intersoccer-venues", $slugs);
        $this->assertContains("age-group", $slugs);
        $this->assertContains("canton-region", $slugs);
        $this->assertContains("city", $slugs);
        $this->assertNotContains("program-season", $slugs);
        $this->assertNotContains("program-year", $slugs);
    }

    public function test_pm_rejects_year_qualified_season_term() {
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_program-season", "Autumn 2026"));
    }

    public function test_pm_rejects_autumn_2027_slug_season() {
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_program-season", "Autumn", "autumn-2027"));
    }

    public function test_pm_accepts_evergreen_season() {
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_program-season", "Autumn", "autumn"));
    }

    public function test_pm_rejects_non_bare_program_year() {
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_program-year", "Year 2026"));
    }

    public function test_pm_accepts_bare_program_year() {
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_program-year", "2026", "2026"));
    }

    public function test_pm_rejects_activity_type_outside_registry() {
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_activity-type", "Soccer Club"));
    }

    public function test_pm_accepts_registry_activity_types_only() {
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_activity-type", "Camp", "camp"));
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_activity-type", "Course", "course"));
    }

    public function test_pm_rejects_girls_only_outside_registry() {
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_girls-only", "Maybe"));
    }

    public function test_pm_rejects_weekday_outside_registry() {
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_days-of-week", "Monday", "monday"));
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_course-day", "Friday"));
        $this->assertInstanceOf(WP_Error::class, intersoccer_attr_validate_new_term("pa_days-of-week", "Weekday"));
    }

    public function test_venues_stay_freeform_with_en_slug() {
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_intersoccer-venues", "Geneva Centre Sportif"));
        $this->assertSame("geneva-centre-sportif", intersoccer_attr_english_term_slug("Geneva Centre Sportif"));
        $this->assertTrue(intersoccer_attr_validate_new_term("pa_city", "Lausanne"));
    }

    public function test_required_parent_facets_block_publish_for_camp() {
        $missing = intersoccer_attr_parent_facets_block_publish(["pa_activity-type"], "camp");
        $this->assertContains("pa_program-year", $missing);
        $this->assertContains("pa_program-season", $missing);
        $this->assertContains("pa_girls-only", $missing);

        $complete = intersoccer_attr_required_parent_taxonomies("camp");
        $this->assertSame([], intersoccer_attr_parent_facets_block_publish($complete, "camp"));
    }

    public function test_birthday_publish_requires_activity_type_only() {
        $this->assertSame([], intersoccer_attr_parent_facets_block_publish(["pa_activity-type"], "birthday"));
        $this->assertSame(["pa_activity-type"], intersoccer_attr_parent_facets_block_publish([], "birthday"));
    }

    public function test_publish_block_notice_persists_for_user_and_product() {
        $product_id = 61625;
        $message = "Publish blocked. Term-shape violations (taxonomy standard): pa_program-season \"easter-2027\": Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.";

        intersoccer_attr_store_publish_block_notice($product_id, $message);

        $key = intersoccer_attr_publish_block_transient_key($product_id, 7);
        $this->assertSame($message, get_transient($key));

        $other_key = intersoccer_attr_publish_block_transient_key($product_id, 99);
        $this->assertFalse(get_transient($other_key));
    }

    public function test_publish_block_notice_is_consumed_once_for_matching_product() {
        $product_id = 61461;
        $message = "Publish blocked. Missing required parent attributes: pa_girls-only. Term-shape violations (taxonomy standard): pa_program-season \"easter-2027\": Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.";
        intersoccer_attr_store_publish_block_notice($product_id, $message);

        $this->assertSame($message, intersoccer_attr_consume_publish_block_notice($product_id));
        $this->assertSame("", intersoccer_attr_consume_publish_block_notice($product_id));
        $this->assertSame("", intersoccer_attr_consume_publish_block_notice(99999));
    }

    public function test_admin_notice_renders_for_product_edit_screen_and_clears() {
        $product_id = 61625;
        $message = "Publish blocked. Term-shape violations (taxonomy standard): pa_program-season \"easter-2027\": Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.";
        intersoccer_attr_store_publish_block_notice($product_id, $message);

        $GLOBALS["intersoccer_test_is_admin"] = true;
        $_GET["post"] = (string) $product_id;
        $GLOBALS["intersoccer_test_screen"] = (object) [
            "base" => "post",
            "post_type" => "product",
            "id" => "product",
        ];

        ob_start();
        intersoccer_attr_publish_block_admin_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString("notice notice-error", $html);
        $this->assertStringContainsString("easter-2027", $html);
        $this->assertStringContainsString("Publish blocked.", $html);
        $this->assertSame("", intersoccer_attr_consume_publish_block_notice($product_id));
    }

    public function test_apply_publish_block_forces_draft_and_stores_notice() {
        $product_id = 61461;
        $product = new class($product_id) {
            private $id;
            private $status = "publish";

            public function __construct($id) {
                $this->id = (int) $id;
            }

            public function get_id() {
                return $this->id;
            }

            public function get_status() {
                return $this->status;
            }

            public function set_status($status) {
                $this->status = $status;
            }
        };

        $shape = [
            "pa_program-season \"easter-2027\": Do not use year-qualified season terms (e.g. Autumn 2027). Use evergreen seasons and pa_program-year.",
        ];
        $blocked = intersoccer_attr_apply_publish_block($product, ["pa_girls-only"], $shape);

        $this->assertTrue($blocked);
        $this->assertSame("draft", $product->get_status());

        $stored = get_transient(intersoccer_attr_publish_block_transient_key($product_id, 7));
        $this->assertIsString($stored);
        $this->assertStringContainsString("Publish blocked.", $stored);
        $this->assertStringContainsString("pa_girls-only", $stored);
        $this->assertStringContainsString("easter-2027", $stored);
    }

    public function test_apply_publish_block_skips_when_already_draft() {
        $product = new class {
            public function get_id() {
                return 1;
            }
            public function get_status() {
                return "draft";
            }
            public function set_status($status) {
                throw new RuntimeException("set_status should not run for draft products");
            }
        };

        $this->assertFalse(intersoccer_attr_apply_publish_block($product, ["pa_girls-only"], []));
        $this->assertFalse(get_transient(intersoccer_attr_publish_block_transient_key(1, 7)));
    }

    public function test_season_shape_message_includes_term_slug() {
        $result = intersoccer_attr_validate_new_term("pa_program-season", "Easter 2027", "easter-2027");
        $this->assertInstanceOf(WP_Error::class, $result);

        $message = "";
        if (is_object($result) && !empty($result->errors) && is_array($result->errors)) {
            $first = reset($result->errors);
            $message = is_array($first) ? (string) reset($first) : (string) $first;
        }

        $formatted = sprintf("%s \"%s\": %s", "pa_program-season", "easter-2027", $message);
        $this->assertStringContainsString("easter-2027", $formatted);
        $this->assertStringContainsString("pa_program-season", $formatted);
        $this->assertStringContainsString("year-qualified", strtolower($formatted));
    }
}
