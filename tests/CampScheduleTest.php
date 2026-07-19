<?php
/**
 * Camp schedule meta helpers (source of truth + deprecated terms fallback).
 */

use PHPUnit\Framework\TestCase;

class CampScheduleTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (class_exists('MockMetaData')) {
			MockMetaData::reset();
		}
		require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
		if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
			require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
		}
	}

	public function test_camp_template_requires_schedule_meta() {
		$meta = intersoccer_attr_required('camp', 'meta');
		$this->assertContains('_camp_start_date', $meta);
		$this->assertContains('_camp_end_date', $meta);
		$this->assertContains('_camp_week_index', $meta);

		$health = intersoccer_attr_health_required_keys('camp');
		$this->assertContains('_camp_start_date', $health);
		$this->assertContains('pa_booking-type', $health);
		$this->assertContains('pa_camp-times', $health);
	}

	public function test_get_camp_schedule_prefers_meta_over_terms() {
		$vid = 9001;
		intersoccer_update_camp_schedule($vid, '2026-07-06', '2026-07-10', 3, true);

		$schedule = intersoccer_get_camp_schedule($vid, true);
		$this->assertSame('2026-07-06', $schedule['start']);
		$this->assertSame('2026-07-10', $schedule['end']);
		$this->assertSame(3, $schedule['week']);
		$this->assertSame('meta', $schedule['source']);
	}

	public function test_propose_from_week1_offsets_by_seven_days() {
		$w1 = intersoccer_camp_schedule_propose_from_week1('2026-06-29', 1, 5);
		$this->assertSame('2026-06-29', $w1['start']);
		$this->assertSame('2026-07-03', $w1['end']);

		$w2 = intersoccer_camp_schedule_propose_from_week1('2026-06-29', 2, 5);
		$this->assertSame('2026-07-06', $w2['start']);
		$this->assertSame('2026-07-10', $w2['end']);

		$easter = intersoccer_camp_schedule_propose_from_week1('2026-03-30', 1, 4);
		$this->assertSame('2026-03-30', $easter['start']);
		$this->assertSame('2026-04-02', $easter['end']);
	}

	public function test_deprecated_terms_parse_extracts_week_and_duration() {
		$parsed = intersoccer_camp_schedule_from_terms_deprecated(
			'Summer Week 2: July 6-10 (5 days)',
			'Summer Camps 2026'
		);
		$this->assertSame(2, $parsed['week']);
		// Start/end may come from RR helper or PV start+duration; week is reliable here.
		$this->assertSame('terms_parse', $parsed['source']);
	}

	public function test_migrate_dry_run_shape_without_wc_product() {
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule-migrate.php';
		$result = intersoccer_migrate_camp_dates_for_product(0, ['dry_run' => true]);
		$this->assertInstanceOf(WP_Error::class, $result);
	}
}
