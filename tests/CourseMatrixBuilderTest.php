<?php
/**
 * Course Program Manager matrix builder tests.
 */

use PHPUnit\Framework\TestCase;

class CourseMatrixBuilderTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__) . '/includes/helpers.php';
	}

	public function test_empty_days_yields_empty_matrix() {
		$this->assertSame(
			[],
			intersoccer_pm_build_course_matrix_rows([], ['5-13y'], ['1700-1800'], ['venue-a'])
		);
	}

	public function test_wednesday_only_times_two_ages() {
		$rows = intersoccer_pm_build_course_matrix_rows(
			['wednesday'],
			['5-13y', '3-5y']
		);
		$this->assertCount(2, $rows);
		$this->assertSame('wednesday', $rows[0]['pa_course-day']);
		$this->assertSame('5-13y', $rows[0]['pa_age-group']);
		$this->assertSame('wednesday', $rows[1]['pa_course-day']);
		$this->assertSame('3-5y', $rows[1]['pa_age-group']);
		$this->assertArrayNotHasKey('pa_course-times', $rows[0]);
		$this->assertArrayNotHasKey('pa_intersoccer-venues', $rows[0]);
	}

	public function test_wed_and_sunday_one_age() {
		$rows = intersoccer_pm_build_course_matrix_rows(
			['wednesday', 'sunday'],
			['5-13y']
		);
		$this->assertCount(2, $rows);
		$days = array_column($rows, 'pa_course-day');
		$this->assertSame(['wednesday', 'sunday'], $days);
	}

	public function test_cartesian_includes_time_and_venue() {
		$rows = intersoccer_pm_build_course_matrix_rows(
			['sunday'],
			['5-13y'],
			['1000-1100'],
			['zurich-a']
		);
		$this->assertCount(1, $rows);
		$this->assertSame('sunday', $rows[0]['pa_course-day']);
		$this->assertSame('5-13y', $rows[0]['pa_age-group']);
		$this->assertSame('1000-1100', $rows[0]['pa_course-times']);
		$this->assertSame('zurich-a', $rows[0]['pa_intersoccer-venues']);
		$this->assertStringContainsString('sunday', $rows[0]['label']);
	}
}
