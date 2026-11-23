<?php
/**
 * Order-item Season label repair tool.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/../');
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct($code = '', $message = '', $data = '') {
			$this->code = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing) {
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('__')) {
	function __($text, $domain = null) {
		return $text;
	}
}

if (!function_exists('do_action')) {
	function do_action($hook, ...$args) {
		$GLOBALS['intersoccer_test_do_action'][] = ['hook' => $hook, 'args' => $args];
	}
}

if (!function_exists('wp_mkdir_p')) {
	function wp_mkdir_p($dir) {
		return is_dir($dir) || mkdir($dir, 0755, true);
	}
}

if (!function_exists('wc_update_order_item_meta')) {
	function wc_update_order_item_meta($item_id, $key, $value, $prev_value = '') {
		$item_id = (int) $item_id;
		if (!isset($GLOBALS['intersoccer_test_order_item_meta'][$item_id])) {
			$GLOBALS['intersoccer_test_order_item_meta'][$item_id] = [];
		}
		$GLOBALS['intersoccer_test_order_item_meta'][$item_id][$key] = $value;
		if (isset($GLOBALS['intersoccer_test_order_items'][$item_id])) {
			$GLOBALS['intersoccer_test_order_items'][$item_id]->meta[$key] = $value;
		}
		$GLOBALS['intersoccer_test_wc_update_order_item_meta_calls'][] = [
			'item_id' => $item_id,
			'key'     => $key,
			'value'   => $value,
		];
		return true;
	}
}

if (!function_exists('wc_get_order_item_meta')) {
	function wc_get_order_item_meta($item_id, $key, $single = true) {
		$item_id = (int) $item_id;
		if (isset($GLOBALS['intersoccer_test_order_items'][$item_id])) {
			return $GLOBALS['intersoccer_test_order_items'][$item_id]->get_meta($key, $single);
		}
		return $GLOBALS['intersoccer_test_order_item_meta'][$item_id][$key] ?? '';
	}
}

require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
require_once dirname(__DIR__) . '/includes/woocommerce/order-season-label-repair.php';

class OrderSeasonLabelRepairTest extends TestCase {

	/** @var string */
	private $backup_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->backup_dir = sys_get_temp_dir() . '/pv-season-label-' . uniqid('', true);
		mkdir($this->backup_dir, 0755, true);
		$GLOBALS['intersoccer_test_season_label_backup_dir'] = $this->backup_dir;
		$GLOBALS['intersoccer_test_terms'] = [];
		$GLOBALS['intersoccer_test_product_term_ids'] = [];
		$GLOBALS['intersoccer_test_product_terms'] = [];
		$GLOBALS['intersoccer_test_orders'] = [];
		$GLOBALS['intersoccer_test_order_items'] = [];
		$GLOBALS['intersoccer_test_order_item_meta'] = [];
		$GLOBALS['intersoccer_test_wc_update_order_item_meta_calls'] = [];
		$GLOBALS['intersoccer_test_do_action'] = [];
		$GLOBALS['intersoccer_test_wpml_object_ids'] = [];

		if (class_exists('MockFilters')) {
			MockFilters::reset();
			MockFilters::$filters['wpml_default_language'] = static function () {
				return 'en';
			};
			MockFilters::$filters['wpml_object_id'] = static function ($id, $type, $return_original = false, $lang = null) {
				$key = (int) $id . '|' . (string) $type . '|' . (string) $lang;
				if (isset($GLOBALS['intersoccer_test_wpml_object_ids'][$key])) {
					return $GLOBALS['intersoccer_test_wpml_object_ids'][$key];
				}
				return $return_original ? (int) $id : 0;
			};
		}
	}

	protected function tearDown(): void {
		$this->removeTree($this->backup_dir);
		unset($GLOBALS['intersoccer_test_season_label_backup_dir']);
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
		parent::tearDown();
	}

	private function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (is_dir($path)) {
				$this->removeTree($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

	private function seedWinterAndSpringTerms(): void {
		$GLOBALS['intersoccer_test_terms'] = [
			101 => [
				'name' => 'Winter Courses',
				'slug' => 'winter-courses',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'en',
			],
			102 => [
				'name' => "Cours d'hiver",
				'slug' => 'cours-dhiver',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'fr',
			],
			103 => [
				'name' => 'Winterkurse',
				'slug' => 'winterkurse',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'de',
			],
			201 => [
				'name' => 'Spring/Summer Courses',
				'slug' => 'spring-summer-courses',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'en',
			],
			202 => [
				'name' => 'Cours printemps-été',
				'slug' => 'cours-printemps-ete',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'fr',
			],
			203 => [
				'name' => 'Frühlings-/Sommerkurse',
				'slug' => 'fruehlings-sommerkurse',
				'taxonomy' => 'pa_program-season',
				'wpml_lang' => 'de',
			],
		];

		$GLOBALS['intersoccer_test_wpml_object_ids'] = [
			'101|pa_program-season|fr' => 102,
			'101|pa_program-season|de' => 103,
			'201|pa_program-season|fr' => 202,
			'201|pa_program-season|de' => 203,
			'102|pa_program-season|en' => 101,
			'103|pa_program-season|en' => 101,
			'202|pa_program-season|en' => 201,
			'203|pa_program-season|en' => 201,
		];
	}

	private function makeItem(int $item_id, int $product_id, array $meta, int $variation_id = 0): object {
		$item = new class($item_id, $product_id, $meta, $variation_id) {
			public int $id;
			public int $product_id;
			public int $variation_id;
			public array $meta;
			public function __construct($id, $product_id, $meta, $variation_id) {
				$this->id = (int) $id;
				$this->product_id = (int) $product_id;
				$this->variation_id = (int) $variation_id;
				$this->meta = $meta;
			}
			public function get_id() { return $this->id; }
			public function get_product_id() { return $this->product_id; }
			public function get_variation_id() { return $this->variation_id; }
			public function get_meta($key, $single = true) {
				return $this->meta[$key] ?? '';
			}
			public function update_meta_data($key, $value) {
				$this->meta[$key] = $value;
			}
			public function save() {
				return true;
			}
		};
		$GLOBALS['intersoccer_test_order_items'][$item_id] = $item;
		return $item;
	}

	private function makeOrder(int $order_id, string $status, string $lang, array $items): object {
		$order = new class($order_id, $status, $lang, $items) {
			public int $id;
			public string $status;
			public string $lang;
			public array $items;
			public function __construct($id, $status, $lang, $items) {
				$this->id = (int) $id;
				$this->status = (string) $status;
				$this->lang = (string) $lang;
				$this->items = $items;
			}
			public function get_id() { return $this->id; }
			public function get_status() { return $this->status; }
			public function get_meta($key, $single = true) {
				return $key === 'wpml_language' ? $this->lang : '';
			}
			public function get_items($type = '') { return $this->items; }
			public function get_item($item_id) {
				foreach ($this->items as $item) {
					if ((int) $item->get_id() === (int) $item_id) {
						return $item;
					}
				}
				return false;
			}
		};
		$GLOBALS['intersoccer_test_orders'][$order_id] = $order;
		return $order;
	}

	private function assignProductTerm(int $product_id, int $term_id, string $name): void {
		$GLOBALS['intersoccer_test_product_term_ids'][$product_id]['pa_program-season'] = [$term_id];
		$GLOBALS['intersoccer_test_product_terms'][$product_id]['pa_program-season'] = [$name];
	}

	public function test_normalize_label_is_case_and_whitespace_insensitive(): void {
		$this->assertSame(
			InterSoccer_Order_Season_Label_Repair::normalize_label('Winter  2027'),
			InterSoccer_Order_Season_Label_Repair::normalize_label('  winter 2027 ')
		);
	}

	#[DataProvider('oldLabelProvider')]
	public function test_mapping_matches_en_fr_de_old_labels(string $old, string $target_en): void {
		$this->seedWinterAndSpringTerms();
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();
		$this->assertSame($target_en, InterSoccer_Order_Season_Label_Repair::match_old_label($old, $runtime['by_old']));
	}

	public static function oldLabelProvider(): array {
		return [
			['Winter 2027', 'Winter Courses'],
			['hiver 2027', 'Winter Courses'],
			['Winterkurse 2027', 'Winter Courses'],
			['Spring/Summer 2027', 'Spring/Summer Courses'],
			['Printemps/Été 2027', 'Spring/Summer Courses'],
			['Frühling/Sommer 2027', 'Spring/Summer Courses'],
		];
	}

	public function test_discount_season_key_is_not_a_primary_or_conditional_meta_key(): void {
		$keys = array_merge(
			InterSoccer_Order_Season_Label_Repair::primary_meta_keys(),
			InterSoccer_Order_Season_Label_Repair::conditional_meta_keys()
		);
		$this->assertNotContains('_intersoccer_discount_season_key', $keys);
	}

	public function test_slug_pa_program_season_meta_is_not_rewritten(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(50, 101, 'Winter Courses');
		$item = $this->makeItem(1, 50, [
			'Season' => 'Winter Courses',
			'pa_program-season' => 'winter-courses',
		]);
		$order = $this->makeOrder(10, 'completed', 'en', [$item]);
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();
		$result = InterSoccer_Order_Season_Label_Repair::evaluate_item($item, $order, $runtime);
		$this->assertSame([], $result['changes']);
	}

	public function test_product_term_gate_skips_unrelated_product(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(60, 201, 'Spring/Summer Courses');
		$item = $this->makeItem(2, 60, ['Season' => 'Winter 2027']);
		$order = $this->makeOrder(11, 'processing', 'en', [$item]);
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();
		$result = InterSoccer_Order_Season_Label_Repair::evaluate_item($item, $order, $runtime);
		$this->assertSame([], $result['changes']);
		$this->assertNotEmpty($result['skipped']);
		$this->assertSame('product_missing_target_term', $result['skipped'][0]['reason']);
	}

	public function test_rewrites_to_order_language_labels(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(70, 101, 'Winter Courses');

		$en_item = $this->makeItem(3, 70, ['Season' => 'Winter 2027']);
		$fr_item = $this->makeItem(4, 70, ['Season' => 'Hiver 2027']);
		$de_item = $this->makeItem(5, 70, ['Season' => 'Winterkurse 2027']);

		$en_order = $this->makeOrder(20, 'completed', 'en', [$en_item]);
		$fr_order = $this->makeOrder(21, 'completed', 'fr', [$fr_item]);
		$de_order = $this->makeOrder(22, 'completed', 'de', [$de_item]);

		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();

		$en = InterSoccer_Order_Season_Label_Repair::evaluate_item($en_item, $en_order, $runtime);
		$fr = InterSoccer_Order_Season_Label_Repair::evaluate_item($fr_item, $fr_order, $runtime);
		$de = InterSoccer_Order_Season_Label_Repair::evaluate_item($de_item, $de_order, $runtime);

		$this->assertSame('Winter Courses', $en['changes'][0]['new']);
		$this->assertSame("Cours d'hiver", $fr['changes'][0]['new']);
		$this->assertSame('Winterkurse', $de['changes'][0]['new']);
	}

	public function test_dry_run_writes_nothing(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(80, 101, 'Winter Courses');
		$item = $this->makeItem(6, 80, ['Season' => 'Winter 2027']);
		$order = $this->makeOrder(30, 'completed', 'en', [$item]);

		$report = InterSoccer_Order_Season_Label_Repair::run([
			'apply' => false,
			'order_iterator' => static function () use ($order) {
				yield $order;
			},
		]);

		$this->assertSame('dry_run', $report['mode']);
		$this->assertSame(1, $report['lines']);
		$this->assertSame([], $GLOBALS['intersoccer_test_wc_update_order_item_meta_calls']);
		$this->assertSame('Winter 2027', $item->get_meta('Season'));
		$this->assertEmpty(InterSoccer_Order_Season_Label_Repair::list_backups());
	}

	public function test_apply_writes_backup_first_and_rewrites(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(90, 101, 'Winter Courses');
		$item = $this->makeItem(7, 90, ['Season' => 'Winter 2027']);
		$order = $this->makeOrder(40, 'on-hold', 'en', [$item]);

		$fired = [];
		add_action(
			InterSoccer_Order_Season_Label_Repair::ACTION_REPAIRED,
			static function ($order_ids) use (&$fired) {
				$fired[] = $order_ids;
			}
		);

		$report = InterSoccer_Order_Season_Label_Repair::run([
			'apply' => true,
			'order_iterator' => static function () use ($order) {
				yield $order;
			},
		]);

		$this->assertSame('apply', $report['mode']);
		$this->assertNotEmpty($report['backup_file']);
		$this->assertFileExists($report['backup_file']);
		$backup = json_decode((string) file_get_contents($report['backup_file']), true);
		$this->assertSame('Winter 2027', $backup['entries'][0]['old']);
		$this->assertSame('Winter Courses', $backup['entries'][0]['new']);
		$this->assertSame('Winter Courses', $item->get_meta('Season'));
		$this->assertSame(1, $report['updated']);
		$this->assertSame([[40]], $fired);
	}

	public function test_apply_is_idempotent_on_second_run(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(100, 101, 'Winter Courses');
		$item = $this->makeItem(8, 100, ['Season' => 'Winter 2027']);
		$order = $this->makeOrder(50, 'completed', 'en', [$item]);
		$iterator = static function () use ($order) {
			yield $order;
		};

		$first = InterSoccer_Order_Season_Label_Repair::run([
			'apply' => true,
			'order_iterator' => $iterator,
		]);
		$this->assertSame(1, $first['updated']);

		$GLOBALS['intersoccer_test_wc_update_order_item_meta_calls'] = [];
		$second = InterSoccer_Order_Season_Label_Repair::run([
			'apply' => true,
			'order_iterator' => $iterator,
		]);
		$this->assertSame(0, $second['lines']);
		$this->assertSame(0, $second['updated']);
		$this->assertSame([], $GLOBALS['intersoccer_test_wc_update_order_item_meta_calls']);
	}

	public function test_rollback_restores_and_reports_conflicts(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(110, 101, 'Winter Courses');
		$item_ok = $this->makeItem(9, 110, ['Season' => 'Winter 2027']);
		$item_conflict = $this->makeItem(10, 110, ['Season' => 'Winter 2027']);
		$order = $this->makeOrder(60, 'completed', 'en', [$item_ok, $item_conflict]);

		$report = InterSoccer_Order_Season_Label_Repair::run([
			'apply' => true,
			'order_iterator' => static function () use ($order) {
				yield $order;
			},
		]);
		$this->assertSame(2, $report['updated']);

		// Simulate a later manual edit on one line → conflict on rollback.
		$item_conflict->meta['Season'] = 'Manual Override';

		$result = InterSoccer_Order_Season_Label_Repair::rollback($report['backup_file']);
		$this->assertSame(1, $result['restored']);
		$this->assertCount(1, $result['conflicts']);
		$this->assertSame('Winter 2027', $item_ok->get_meta('Season'));
		$this->assertSame('Manual Override', $item_conflict->get_meta('Season'));
	}

	public function test_unmatched_values_report_for_affected_products(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(120, 101, 'Winter Courses');
		$item = $this->makeItem(11, 120, ['Season' => 'Mystery Season Label']);
		$order = $this->makeOrder(70, 'completed', 'en', [$item]);
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();
		$result = InterSoccer_Order_Season_Label_Repair::evaluate_item($item, $order, $runtime);
		$this->assertContains('Mystery Season Label', $result['unmatched']);
	}

	public function test_label_on_pa_program_season_is_rewritten_when_not_a_slug(): void {
		$this->seedWinterAndSpringTerms();
		$this->assignProductTerm(130, 101, 'Winter Courses');
		$item = $this->makeItem(12, 130, [
			'Season' => 'Winter Courses',
			'pa_program-season' => 'Winter 2027',
		]);
		$order = $this->makeOrder(80, 'completed', 'en', [$item]);
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping();
		$result = InterSoccer_Order_Season_Label_Repair::evaluate_item($item, $order, $runtime);
		$this->assertCount(1, $result['changes']);
		$this->assertSame('pa_program-season', $result['changes'][0]['meta_key']);
		$this->assertSame('Winter Courses', $result['changes'][0]['new']);
	}

	public function test_json_mapping_file_extends_old_labels(): void {
		$this->seedWinterAndSpringTerms();
		$path = $this->backup_dir . '/custom-map.json';
		file_put_contents($path, json_encode([
			'Weird Old Winter' => 'Winter Courses',
		]));
		$runtime = InterSoccer_Order_Season_Label_Repair::build_runtime_mapping(null, $path);
		$this->assertSame(
			'Winter Courses',
			InterSoccer_Order_Season_Label_Repair::match_old_label('Weird Old Winter', $runtime['by_old'])
		);
	}

	public function test_plugin_loads_season_label_repair_file(): void {
		$src = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
		$this->assertStringContainsString('order-season-label-repair.php', $src);
	}

	public function test_wp_cli_registers_intersoccer_namespace_command(): void {
		$src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/order-season-label-repair.php');
		$this->assertStringContainsString("WP_CLI::add_command('intersoccer season-label-repair'", $src);
	}
}
