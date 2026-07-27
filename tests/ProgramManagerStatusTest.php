<?php
/**
 * Program Manager product status allowlist (detail + quick edit).
 */

use PHPUnit\Framework\TestCase;

class ProgramManagerStatusTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__) . '/includes/helpers.php';
	}

	public function test_allowed_statuses() {
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('draft'));
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('publish'));
		$this->assertTrue(intersoccer_pm_is_allowed_product_status('private'));
	}

	public function test_disallowed_statuses() {
		$this->assertFalse(intersoccer_pm_is_allowed_product_status('pending'));
		$this->assertFalse(intersoccer_pm_is_allowed_product_status('trash'));
		$this->assertFalse(intersoccer_pm_is_allowed_product_status(''));
		$this->assertFalse(intersoccer_pm_is_allowed_product_status('Publish'));
	}
}
