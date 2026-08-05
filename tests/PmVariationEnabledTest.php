<?php
/**
 * Program Manager variation Enabled/Disabled helpers.
 */

use PHPUnit\Framework\TestCase;

class PmVariationEnabledTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__) . '/includes/helpers.php';
	}

	public function test_variation_is_enabled_only_for_publish() {
		$this->assertTrue(intersoccer_pm_variation_is_enabled('publish'));
		$this->assertFalse(intersoccer_pm_variation_is_enabled('private'));
		$this->assertFalse(intersoccer_pm_variation_is_enabled('draft'));
		$this->assertFalse(intersoccer_pm_variation_is_enabled(''));
	}

	public function test_variation_enabled_status_mapping() {
		$this->assertSame('publish', intersoccer_pm_variation_enabled_status(true));
		$this->assertSame('private', intersoccer_pm_variation_enabled_status(false));
	}

	public function test_camp_end_is_past() {
		$this->assertTrue(intersoccer_pm_camp_end_is_past('2026-07-01', '2026-08-05'));
		$this->assertFalse(intersoccer_pm_camp_end_is_past('2026-08-05', '2026-08-05'));
		$this->assertFalse(intersoccer_pm_camp_end_is_past('2026-08-10', '2026-08-05'));
		$this->assertFalse(intersoccer_pm_camp_end_is_past('', '2026-08-05'));
		$this->assertFalse(intersoccer_pm_camp_end_is_past('not-a-date', '2026-08-05'));
		$this->assertFalse(intersoccer_pm_camp_end_is_past('2026-07-01', 'bad'));
	}

	public function test_ended_needs_action_logic() {
		// Mirrors Program Manager row flag: past end AND still enabled (publish).
		$end = '2026-07-01';
		$today = '2026-08-05';
		$past = intersoccer_pm_camp_end_is_past($end, $today);
		$this->assertTrue($past && intersoccer_pm_variation_is_enabled('publish'));
		$this->assertFalse($past && intersoccer_pm_variation_is_enabled('private'));
	}

	public function test_camp_ended_fact_independent_of_enabled() {
		// data-camp-ended is based only on end date; enabled state only controls badge visibility.
		$end = '2026-07-01';
		$today = '2026-08-05';
		$camp_ended = intersoccer_pm_camp_end_is_past($end, $today);
		$this->assertTrue($camp_ended);

		$needs_action_when_enabled = $camp_ended && intersoccer_pm_variation_is_enabled('publish');
		$needs_action_when_disabled = $camp_ended && intersoccer_pm_variation_is_enabled('private');
		$this->assertTrue($needs_action_when_enabled);
		$this->assertFalse($needs_action_when_disabled);
		// Re-enable path: camp_ended stays true so the badge can be shown again.
		$this->assertTrue($camp_ended && intersoccer_pm_variation_is_enabled('publish'));
	}

	public function test_variation_ended_needs_action() {
		$today = '2026-08-05';
		$this->assertTrue(intersoccer_pm_variation_ended_needs_action('2026-07-01', 'publish', $today));
		$this->assertFalse(intersoccer_pm_variation_ended_needs_action('2026-07-01', 'private', $today));
		$this->assertFalse(intersoccer_pm_variation_ended_needs_action('2026-08-10', 'publish', $today));
		$this->assertFalse(intersoccer_pm_variation_ended_needs_action('', 'publish', $today));
		$this->assertFalse(intersoccer_pm_variation_ended_needs_action('2026-07-01', 'draft', $today));
	}
}
