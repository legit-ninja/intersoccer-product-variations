<?php
/**
 * Unit tests for age-group parsing and program reference date helpers.
 *
 * Run: vendor/bin/phpunit tests/AgeGroupVerificationTest.php
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

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('intersoccer_warning')) {
    function intersoccer_warning($message, array $context = []) {
    }
}

if (!function_exists('intersoccer_debug')) {
    function intersoccer_debug($message, array $context = []) {
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';

class AgeGroupVerificationTest extends TestCase {

    public function test_parse_age_group_bounds_hyphen_years() {
        $b = intersoccer_parse_age_group_bounds('6-8 years');
        $this->assertNotNull($b);
        $this->assertSame(6, $b['min']);
        $this->assertSame(8, $b['max']);
    }

    public function test_parse_age_group_bounds_y_suffix() {
        $b = intersoccer_parse_age_group_bounds('5-8y');
        $this->assertNotNull($b);
        $this->assertSame(5, $b['min']);
        $this->assertSame(8, $b['max']);
    }

    public function test_parse_age_group_bounds_full_day_label() {
        $b = intersoccer_parse_age_group_bounds('5-13y (Full Day)');
        $this->assertNotNull($b);
        $this->assertSame(5, $b['min']);
        $this->assertSame(13, $b['max']);
    }

    public function test_parse_age_group_bounds_u_format() {
        $b = intersoccer_parse_age_group_bounds('U10');
        $this->assertNotNull($b);
        $this->assertNull($b['min']);
        $this->assertSame(9, $b['max']);
    }

    public function test_parse_age_group_bounds_minimum_plus() {
        $b = intersoccer_parse_age_group_bounds('12+');
        $this->assertNotNull($b);
        $this->assertSame(12, $b['min']);
        $this->assertNull($b['max']);
    }

    public function test_year_from_program_season() {
        $this->assertSame(2025, intersoccer_pv_year_from_program_season('Autumn camps 2025'));
        $this->assertSame(2024, intersoccer_pv_year_from_program_season('2024'));
    }

    public function test_parse_camp_start_date_from_terms() {
        $slug = 'autumn-week-4-october-20-october-24-5-days';
        $start = intersoccer_parse_camp_start_date_from_terms($slug, 'Autumn 2025');
        $this->assertSame('2025-10-20', $start);
    }

    public function test_parse_camp_start_same_month_variant() {
        $slug = 'summer-week-2-june-24-june-28-5-days';
        $start = intersoccer_parse_camp_start_date_from_terms($slug, '2025');
        $this->assertSame('2025-06-24', $start);
    }

    public function test_age_on_date() {
        $this->assertSame(10, intersoccer_age_on_date('2015-06-01', '2025-06-01'));
        $this->assertSame(9, intersoccer_age_on_date('2015-06-02', '2025-06-01'));
        $this->assertNull(intersoccer_age_on_date('2026-01-01', '2025-06-01'));
    }

    public function test_parse_loose_date_to_ymd() {
        $this->assertSame('2025-01-15', intersoccer_parse_loose_date_to_ymd('2025-01-15'));
        $this->assertNotNull(intersoccer_parse_loose_date_to_ymd('January 15, 2025'));
    }
}
