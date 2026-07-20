<?php
/**
 * Camp time ↔ age pairing helper tests.
 */

use PHPUnit\Framework\TestCase;

class CampTimeAgePairingTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__) . '/includes/helpers.php';
	}

	public function test_full_day_age_maps_to_1000_1700() {
		$this->assertSame(
			'1000-1700',
			intersoccer_pm_default_camp_time_slug_for_age('5-13y-full-day')
		);
	}

	public function test_half_day_age_maps_to_1000_1230() {
		$this->assertSame(
			'1000-1230',
			intersoccer_pm_default_camp_time_slug_for_age('3-5y-half-day')
		);
	}

	public function test_prefers_allowed_pool_match() {
		$this->assertSame(
			'1000-1500',
			intersoccer_pm_default_camp_time_slug_for_age('5-13y-full-day', ['1000-1500', '1000-1230'])
		);
		$this->assertSame(
			'1000-1230',
			intersoccer_pm_default_camp_time_slug_for_age('3-5y-half-day', ['1000-1700', '1000-1230'])
		);
	}

	public function test_empty_when_allowed_pool_has_no_safe_match() {
		$this->assertSame(
			'',
			intersoccer_pm_default_camp_time_slug_for_age('3-5y-half-day', ['0800-0900', '1400-1600'])
		);
	}

	public function test_heuristic_matches_end_hour_in_allowed() {
		$this->assertSame(
			'0930-1230',
			intersoccer_pm_default_camp_time_slug_for_age('3-5y-half-day', ['0930-1230', '0930-1700'])
		);
	}
}
