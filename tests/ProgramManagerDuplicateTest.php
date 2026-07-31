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
}
