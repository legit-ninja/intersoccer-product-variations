<?php
/**
 * Attribute registry contract tests.
 */

use PHPUnit\Framework\TestCase;

class AttributeRegistryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
    }

    public function test_contract_version_is_defined() {
        $this->assertSame(2, INTERSOCCER_ATTRIBUTE_CONTRACT_VERSION);
    }

    public function test_every_registry_entry_has_required_fields() {
        foreach (intersoccer_attr_registry() as $slug => $def) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug, 'Slug must be hyphenated: ' . $slug);
            $this->assertNotContains('_', $slug, 'Canonical slug must not contain underscores: ' . $slug);
            $this->assertArrayHasKey('wc_label', $def, $slug);
            $this->assertArrayHasKey('order_meta_label', $def, $slug);
            $this->assertNotSame('', $def['wc_label'], $slug);
            $this->assertNotSame('', $def['order_meta_label'], $slug);
            $this->assertSame('pa_' . $slug, intersoccer_attr_taxonomy($slug));
        }
    }

    public function test_venue_order_meta_label_is_sites_intersoccer() {
        $this->assertSame('Sites InterSoccer', intersoccer_attr_order_meta_label('intersoccer-venues'));
    }

    public function test_health_required_keys_are_subset_of_registry() {
        foreach (['camp', 'course', 'birthday', 'tournament'] as $type) {
            foreach (intersoccer_attr_health_required_keys($type) as $key) {
                if (strpos($key, 'pa_') === 0) {
                    $this->assertNotNull(
                        intersoccer_attr_slug_from_taxonomy($key),
                        'Health key must exist in registry: ' . $key
                    );
                }
            }
        }
    }

    public function test_refresh_defaults_use_valid_term_slugs() {
        foreach (['camp', 'course'] as $type) {
            foreach (intersoccer_attr_refresh_defaults($type) as $key => $default) {
                if (strpos($key, 'pa_') === 0) {
                    $slug = intersoccer_attr_slug_from_taxonomy($key);
                    $this->assertNotNull($slug);
                    $terms = intersoccer_attr_definition($slug)['default_terms'] ?? [];
                    if (!empty($terms)) {
                        $slugs = array_column($terms, 'slug');
                        $this->assertContains(
                            $default,
                            $slugs,
                            "Refresh default {$default} for {$key} must be a seeded term slug"
                        );
                    }
                }
            }
        }
    }

    public function test_booking_type_legacy_meta_keys_include_underscore_variant() {
        $keys = intersoccer_attr_variation_meta_keys('booking-type');
        $this->assertContains('attribute_pa_booking-type', $keys);
        $this->assertContains('attribute_pa_booking_type', $keys);
    }

    public function test_product_type_templates_reference_registry_slugs() {
        foreach (intersoccer_attr_product_type_templates() as $type => $template) {
            foreach (['parent', 'variation'] as $scope) {
                foreach ($template[$scope] as $slug) {
                    $this->assertArrayHasKey($slug, intersoccer_attr_registry(), "$type.$scope slug: $slug");
                }
            }
        }
    }

    public function test_order_meta_label_map_includes_all_taxonomies() {
        $map = intersoccer_attr_order_meta_label_map();
        $this->assertCount(count(intersoccer_attr_registry()), $map);
        $this->assertArrayHasKey('pa_intersoccer-venues', $map);
        $this->assertSame('Sites InterSoccer', $map['pa_intersoccer-venues']);
    }

    // Regression: AUDIT-007 — Variation health required keys expanded to full templates
    public function test_health_required_keys_match_product_type_expectations() {
        $this->assertEmpty(
            intersoccer_attr_health_required_keys('birthday'),
            'Birthday products should not require full variation template attributes in health checks'
        );
        $this->assertEmpty(
            intersoccer_attr_health_required_keys('tournament'),
            'Tournament products should not require previously optional attributes in health checks'
        );

        $camp_keys = intersoccer_attr_health_required_keys('camp');
        $this->assertContains('pa_booking-type', $camp_keys);
        $this->assertNotContains('pa_course-day', $camp_keys);
    }

    public function test_camp_and_course_templates_include_girls_only() {
        $templates = intersoccer_attr_product_type_templates();
        $this->assertContains('girls-only', $templates['camp']['parent']);
        $this->assertContains('girls-only', $templates['course']['parent']);
    }
}
