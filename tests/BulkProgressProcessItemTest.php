<?php
/**
 * Program Manager list bulk process_bulk_item skip/fail paths.
 */

use PHPUnit\Framework\TestCase;

class BulkProgressProcessItemTest extends TestCase {
	/** @var array<int,object|false> */
	public static $products = [];

	/** @var array<int,string> */
	public static $product_types = [];

	protected function setUp(): void {
		parent::setUp();
		self::$products = [];
		self::$product_types = [];

		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}

		require_once dirname(__DIR__) . '/includes/helpers.php';

		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}

		if (!class_exists('InterSoccer_Product_Types', false)) {
			eval(<<<'PHP'
class InterSoccer_Product_Types {
	public static function get_product_type($product_id) {
		return BulkProgressProcessItemTest::$product_types[(int) $product_id] ?? '';
	}
}
PHP
			);
		}

		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}

		MockFilters::$filters['intersoccer_pm_bulk_get_product'] = static function ($pre, $product_id) {
			$id = (int) $product_id;
			if (array_key_exists($id, BulkProgressProcessItemTest::$products)) {
				return BulkProgressProcessItemTest::$products[$id];
			}
			return $pre;
		};
	}

	protected function tearDown(): void {
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
		parent::tearDown();
	}

	private function makeVariableProduct(int $id, string $name = 'Test Program', array $children = []): object {
		$product = new class($id, $name, $children) {
			public int $id;
			public string $name;
			public array $children;
			public function __construct($id, $name, $children) {
				$this->id = (int) $id;
				$this->name = (string) $name;
				$this->children = array_map('intval', $children);
			}
			public function get_id() { return $this->id; }
			public function get_name() { return $this->name; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_children() { return $this->children; }
			public function get_attributes() { return []; }
		};
		self::$products[$id] = $product;
		return $product;
	}

	public function test_unknown_action_fails(): void {
		$result = InterSoccer_Program_Manager::process_bulk_item('nope', 1);
		$this->assertFalse($result['ok']);
		$this->assertSame('failed', $result['outcome']);
	}

	public function test_missing_product_skipped(): void {
		self::$products[999001] = false;
		$result = InterSoccer_Program_Manager::process_bulk_item('refresh_attrs', 999001);
		$this->assertTrue($result['ok']);
		$this->assertSame('skipped', $result['outcome']);
		$this->assertStringContainsString('not a variable product', $result['message']);
	}

	public function test_duplicate_requires_year(): void {
		$this->makeVariableProduct(501, 'Camp Geneva');
		self::$product_types[501] = 'camp';

		$result = InterSoccer_Program_Manager::process_bulk_item('duplicate_to_year', 501, [
			'year' => '',
		]);
		$this->assertFalse($result['ok']);
		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('target program year', $result['message']);
	}

	public function test_duplicate_unknown_type_skipped(): void {
		$this->makeVariableProduct(502, 'Mystery Program');
		self::$product_types[502] = '';

		$result = InterSoccer_Program_Manager::process_bulk_item('duplicate_to_year', 502, [
			'year' => '2027',
		]);
		$this->assertTrue($result['ok']);
		$this->assertSame('skipped', $result['outcome']);
		$this->assertStringContainsString('unknown program type', $result['message']);
	}

	public function test_scaffold_skips_when_children_exist(): void {
		$this->makeVariableProduct(503, 'Already Scaffolded', [9001, 9002]);
		self::$product_types[503] = 'camp';

		$result = InterSoccer_Program_Manager::process_bulk_item('scaffold_variations', 503);
		$this->assertTrue($result['ok']);
		$this->assertSame('skipped', $result['outcome']);
		$this->assertStringContainsString('variations already exist', $result['message']);
	}

	public function test_wpml_sync_unavailable_fails(): void {
		$this->makeVariableProduct(504, 'Needs WPML');
		self::$product_types[504] = 'camp';

		if (function_exists('intersoccer_pm_sync_product_translations')) {
			$this->markTestSkipped('WPML sync helper already loaded by another test in this run.');
		}

		$result = InterSoccer_Program_Manager::process_bulk_item('sync_wpml_languages', 504);
		$this->assertFalse($result['ok']);
		$this->assertSame('failed', $result['outcome']);
		$this->assertStringContainsString('WPML sync is not available', $result['message']);
	}
}
