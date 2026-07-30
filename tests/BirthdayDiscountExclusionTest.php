<?php
/**
 * Birthday products must never seed camp sibling baselines or receive InterSoccer sibling discounts.
 */

use PHPUnit\Framework\TestCase;

class BirthdayDiscountExclusionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if (!defined('ABSPATH')) {
			define('ABSPATH', dirname(__DIR__) . '/');
		}
		if (!function_exists('__')) {
			function __($text, $domain = null) {
				return $text;
			}
		}
		if (!function_exists('intersoccer_debug')) {
			function intersoccer_debug($message) {
			}
		}
		if (!function_exists('intersoccer_discount_exclude_product_from_camp_sibling_baseline')) {
			require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
		}
	}

	public function test_typed_birthday_excluded_from_camp_sibling_baseline(): void {
		$this->assertTrue(
			intersoccer_discount_exclude_product_from_camp_sibling_baseline(1001, 'birthday', false)
		);
	}

	public function test_camp_meta_with_birthday_signals_excluded(): void {
		$this->assertTrue(
			intersoccer_discount_exclude_product_from_camp_sibling_baseline(1002, 'camp', true),
			'Mis-typed camp + birthday signals must not seed sibling totals'
		);
	}

	public function test_real_camp_included_in_baseline(): void {
		$this->assertFalse(
			intersoccer_discount_exclude_product_from_camp_sibling_baseline(1003, 'camp', false)
		);
	}

	public function test_course_and_tournament_excluded_from_camp_baseline(): void {
		$this->assertTrue(intersoccer_discount_exclude_product_from_camp_sibling_baseline(1004, 'course', false));
		$this->assertTrue(intersoccer_discount_exclude_product_from_camp_sibling_baseline(1005, 'tournament', false));
	}

	public function test_admin_copy_does_not_claim_birthday_sibling_discounts(): void {
		$admin_ui = dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';
		$this->assertFileExists($admin_ui);
		$contents = file_get_contents($admin_ui);
		$this->assertStringNotContainsString(
			'Tournament and birthday sibling discounts',
			$contents,
			'Settings copy must not claim birthday sibling discounts exist'
		);
		$this->assertStringContainsString(
			'Birthday products are not eligible for InterSoccer sibling or progressive discounts',
			$contents
		);
	}

	public function test_helper_and_extract_guard_exist(): void {
		$discounts = dirname(__DIR__) . '/includes/woocommerce/discounts.php';
		$contents = file_get_contents($discounts);
		$this->assertStringContainsString('function intersoccer_discount_exclude_product_from_camp_sibling_baseline', $contents);
		$this->assertStringContainsString('intersoccer_discount_exclude_product_from_camp_sibling_baseline($resolve_id)', $contents);
	}
}
