<?php
/**
 * Program Manager duplicate / year-roll helpers.
 */

use PHPUnit\Framework\TestCase;

class ProgramManagerDuplicateTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__) . '/includes/helpers.php';
	}

	public function test_normalize_program_year() {
		$this->assertSame('2027', intersoccer_pm_normalize_program_year('2027'));
		$this->assertSame('2026', intersoccer_pm_normalize_program_year(' 2026 '));
		$this->assertSame('2027', intersoccer_pm_normalize_program_year('year-2027'));
		$this->assertSame('', intersoccer_pm_normalize_program_year('Autumn'));
		// Digits in a season label still extract to a bare year for pa_program-year —
		// never use the label as a season term name.
		$this->assertSame('2027', intersoccer_pm_normalize_program_year('Autumn 2027'));
	}

	public function test_discount_compose_season_key_isolates_evergreen_years() {
		$key_2026 = intersoccer_discount_compose_season_key('Autumn', '2026');
		$key_2027 = intersoccer_discount_compose_season_key('Autumn', '2027');
		$this->assertSame('autumn|2026', $key_2026);
		$this->assertSame('autumn|2027', $key_2027);
		$this->assertNotSame($key_2026, $key_2027);
	}

	public function test_discount_compose_season_key_legacy_year_qualified() {
		// Without pa_program-year, legacy labels stay unique as-is.
		$this->assertSame('Autumn 2026', intersoccer_discount_compose_season_key('Autumn 2026', ''));
		$this->assertSame('Autumn 2027', intersoccer_discount_compose_season_key('Autumn 2027', ''));
		$this->assertNotSame(
			intersoccer_discount_compose_season_key('Autumn 2026', ''),
			intersoccer_discount_compose_season_key('Autumn 2027', '')
		);
	}

	public function test_discount_compose_season_key_with_year_on_legacy_label() {
		$this->assertSame(
			'autumn 2026|2026',
			intersoccer_discount_compose_season_key('Autumn 2026', '2026')
		);
	}

	public function test_rewrite_title_replaces_year() {
		$this->assertSame(
			'Camp Autumn Geneva 2027',
			intersoccer_pm_rewrite_program_title_for_year('Camp Autumn Geneva 2026', '2027')
		);
	}

	public function test_rewrite_title_strips_copy_and_appends_year() {
		$this->assertSame(
			'Football Camp Geneva 2027',
			intersoccer_pm_rewrite_program_title_for_year('Football Camp Geneva (Copy)', '2027')
		);
	}

	public function test_rewrite_title_appends_when_no_year() {
		$this->assertSame(
			'Football Camp 2027',
			intersoccer_pm_rewrite_program_title_for_year('Football Camp', '2027')
		);
	}

	public function test_rewrite_title_noop_without_valid_year() {
		$this->assertSame(
			'Camp Autumn 2026',
			intersoccer_pm_rewrite_program_title_for_year('Camp Autumn 2026', '')
		);
		$this->assertSame(
			'Camp Autumn 2026',
			intersoccer_pm_rewrite_program_title_for_year('Camp Autumn 2026', 'Autumn')
		);
	}

	public function test_year_normalize_does_not_invent_season_term_names() {
		$normalized = intersoccer_pm_normalize_program_year('Autumn 2027');
		$this->assertSame('2027', $normalized);
		$this->assertNotSame('Autumn 2027', $normalized);
		$this->assertStringNotContainsString('Autumn', $normalized);
	}

	public function test_year_qualified_season_label_detection() {
		$this->assertTrue(intersoccer_pm_is_year_qualified_season_label('Autumn 2027'));
		$this->assertTrue(intersoccer_pm_is_year_qualified_season_label('Summer 2026'));
		$this->assertTrue(intersoccer_pm_is_year_qualified_season_label('autumn-2027'));
		$this->assertFalse(intersoccer_pm_is_year_qualified_season_label('Autumn'));
		$this->assertFalse(intersoccer_pm_is_year_qualified_season_label('Winter'));
		$this->assertFalse(intersoccer_pm_is_year_qualified_season_label('2027'));
		$this->assertFalse(intersoccer_pm_is_year_qualified_season_label(''));
	}

	public function test_allowed_statuses_include_private_for_retired_catalogue() {
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('draft'));
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('publish'));
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('private'));
	}

	public function test_roll_ymd_one_year_shifts_calendar_dates(): void {
		$this->assertSame('2027-07-06', intersoccer_pm_roll_ymd_one_year('2026-07-06'));
		$this->assertSame('2027-01-31', intersoccer_pm_roll_ymd_one_year('2026-01-31'));
		$this->assertSame('2027-12-31', intersoccer_pm_roll_ymd_one_year('2026-12-31'));
		$this->assertSame('2024-02-28', intersoccer_pm_roll_ymd_one_year('2023-02-28'));
	}

	public function test_roll_ymd_one_year_clamps_leap_day(): void {
		$this->assertSame('2025-02-28', intersoccer_pm_roll_ymd_one_year('2024-02-29'));
		$this->assertSame('2021-02-28', intersoccer_pm_roll_ymd_one_year('2020-02-29'));
		$this->assertSame('2029-02-28', intersoccer_pm_roll_ymd_one_year('2028-02-29'));
		$this->assertSame('2025-02-28', intersoccer_pm_roll_ymd_one_year('2024-02-28'));
	}

	public function test_roll_ymd_one_year_leaves_invalid_values_unchanged(): void {
		$this->assertSame('2026-02-31', intersoccer_pm_roll_ymd_one_year('2026-02-31'));
		$this->assertSame('2026-13-01', intersoccer_pm_roll_ymd_one_year('2026-13-01'));
		$this->assertSame('06/07/2026', intersoccer_pm_roll_ymd_one_year('06/07/2026'));
		$this->assertSame('', intersoccer_pm_roll_ymd_one_year(''));
		$this->assertSame(2, intersoccer_pm_roll_ymd_one_year(2));
	}

	public function test_roll_variation_date_meta_shifts_schedule_dates_only(): void {
		$this->assertSame('2027-09-01', intersoccer_pm_roll_variation_date_meta('_course_start_date', '2026-09-01'));
		$this->assertSame('2028-01-15', intersoccer_pm_roll_variation_date_meta('_end_date', '2027-01-15'));
		$this->assertSame('2027-07-10', intersoccer_pm_roll_variation_date_meta('_camp_end_date', '2026-07-10'));
		$this->assertSame(
			['2027-12-25', '2028-01-01', '2026-02-31'],
			intersoccer_pm_roll_variation_date_meta('_course_holiday_dates', ['2026-12-25', '2027-01-01', '2026-02-31'])
		);
		$this->assertSame(
			"2027-12-25\n2028-01-01",
			intersoccer_pm_roll_variation_date_meta('_course_holiday_dates', "2026-12-25\n2027-01-01")
		);
		$this->assertSame(2, intersoccer_pm_roll_variation_date_meta('_camp_week_index', 2));
		$this->assertSame('16', intersoccer_pm_roll_variation_date_meta('_course_total_weeks', '16'));
	}
}
