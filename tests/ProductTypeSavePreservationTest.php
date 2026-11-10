<?php
/**
 * Later product saves must keep _intersoccer_product_type when redetection finds nothing.
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ProductTypeSavePreservationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (!defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
		if (class_exists('MockMetaData')) {
			MockMetaData::reset();
		}
		$GLOBALS['intersoccer_test_transients'] = [];
		$GLOBALS['intersoccer_test_product_terms'] = [];
		$GLOBALS['intersoccer_test_warnings'] = [];
		require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
	}

	public function test_later_save_preserves_product_type_when_redetect_fails(): void {
		$product_id = 940001;
		update_post_meta($product_id, '_intersoccer_product_type', 'camp');

		do_action('woocommerce_update_product', $product_id);

		$this->assertSame('camp', get_post_meta($product_id, '_intersoccer_product_type', true));
	}

	public function test_later_save_still_updates_type_when_activity_term_is_present(): void {
		$product_id = 940002;
		update_post_meta($product_id, '_intersoccer_product_type', 'camp');
		$GLOBALS['intersoccer_test_product_terms'][$product_id]['pa_activity-type'] = [
			(object) [
				'term_id' => 77,
				'slug'    => 'course',
				'name'    => 'Course',
			],
		];

		do_action('woocommerce_process_product_meta', $product_id);

		$this->assertSame('course', get_post_meta($product_id, '_intersoccer_product_type', true));
	}

	public function test_later_save_does_not_invent_a_type_when_none_is_stored(): void {
		$product_id = 940003;

		do_action('woocommerce_update_product', $product_id);

		$this->assertArrayNotHasKey($product_id, MockMetaData::$data);
		$this->assertSame('', get_post_meta($product_id, '_intersoccer_product_type', true));
	}
}
