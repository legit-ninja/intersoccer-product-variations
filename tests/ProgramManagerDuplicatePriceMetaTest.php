<?php
/**
 * Program Manager duplicate_program: price + camp/course variation meta.
 */
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ProgramManagerDuplicatePriceMetaTest extends TestCase {
	/** @var int */
	public static int $next_product_id = 910000;

	/** @var array<int,object> */
	public static array $wc_products = [];

	/** @var array<int,string> */
	public static array $product_types = [];

	/** @var array<int,array<int>> */
	public static array $children_by_parent = [];

	/** @var array<int,array<string,mixed>> */
	public static array $variation_price_state = [];

	/** @var array<int,array<string,mixed>> */
	public static array $saved_variation_meta = [];

	/** @var array<int,array<string,mixed>> */
	public static array $saved_variation_props = [];

	/** @var int */
	public static int $course_sync_calls = 0;

	/** @var array<int,mixed> */
	public static array $course_sync_args = [];

	/** @var int */
	public static int $order_api_calls = 0;

	/** @var bool */
	private bool $course_sync_spy_installed = false;

	protected function setUp(): void {
		parent::setUp();

		if (class_exists('MockMetaData')) {
			MockMetaData::reset();
		}
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}

		self::$next_product_id      = 200000;
		self::$wc_products          = [];
		self::$product_types        = [];
		self::$children_by_parent   = [];
		self::$variation_price_state = [];
		self::$saved_variation_meta = [];
		self::$saved_variation_props = [];
		self::$course_sync_calls    = 0;
		self::$course_sync_args     = [];
		self::$order_api_calls      = 0;
		$this->course_sync_spy_installed = false;

		$GLOBALS['intersoccer_test_order_api_calls'] = &self::$order_api_calls;

		require_once dirname(__DIR__) . '/includes/helpers.php';

		$this->installWordPressShims();
		$this->installWooCommerceMocks();

		require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';

		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}

		if (!function_exists('intersoccer_sync_course_metadata_to_translations')) {
			$this->course_sync_spy_installed = true;
			eval(<<<'PHP'
function intersoccer_sync_course_metadata_to_translations(
	$variation_id,
	$start,
	$weeks,
	$holidays,
	$discount,
	$end
) {
	ProgramManagerDuplicatePriceMetaTest::$course_sync_calls++;
	ProgramManagerDuplicatePriceMetaTest::$course_sync_args[] = func_get_args();
}
PHP
			);
		}

		MockFilters::$filters['intersoccer_pm_bulk_get_product'] = static function ($pre, $product_id) {
			$id = (int) $product_id;
			if (isset(ProgramManagerDuplicatePriceMetaTest::$wc_products[$id])) {
				return ProgramManagerDuplicatePriceMetaTest::$wc_products[$id];
			}
			return $pre;
		};

		$GLOBALS['intersoccer_wc_get_product_callback'] = static function ($product_id) {
			$id = (int) $product_id;
			return ProgramManagerDuplicatePriceMetaTest::$wc_products[$id] ?? null;
		};
	}

	protected function tearDown(): void {
		unset($GLOBALS['intersoccer_wc_get_product_callback'], $GLOBALS['intersoccer_test_order_api_calls']);
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
		parent::tearDown();
	}

	private function installWordPressShims(): void {
		if (!defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
		if (!defined('DAY_IN_SECONDS')) {
			define('DAY_IN_SECONDS', 86400);
		}
		if (!defined('WEEK_IN_SECONDS')) {
			define('WEEK_IN_SECONDS', 604800);
		}

		if (!function_exists('wp_parse_args')) {
			function wp_parse_args($args, $defaults = []) {
				if (is_object($args)) {
					$args = get_object_vars($args);
				}
				if (is_array($args)) {
					return array_merge($defaults, $args);
				}
				return $defaults;
			}
		}

		if (!function_exists('wp_insert_term')) {
			function wp_insert_term($name, $taxonomy, $args = []) {
				$id = count($GLOBALS['intersoccer_test_terms'] ?? []) + 7000;
				$slug = $args['slug'] ?? sanitize_title($name);
				$GLOBALS['intersoccer_test_terms'][$id] = [
					'name'     => $name,
					'slug'     => $slug,
					'taxonomy' => $taxonomy,
				];
				return ['term_id' => $id];
			}
		}

		if (!function_exists('wp_set_object_terms')) {
			function wp_set_object_terms($object_id, $terms, $taxonomy) {
				$object_id = (int) $object_id;
				$names = [];
				foreach ((array) $terms as $term) {
					if (is_numeric($term) && !empty($GLOBALS['intersoccer_test_terms'][(int) $term])) {
						$names[] = $GLOBALS['intersoccer_test_terms'][(int) $term]['name'];
					}
				}
				$GLOBALS['intersoccer_test_product_terms'][$object_id][$taxonomy] = $names;
				return true;
			}
		}

		if (!function_exists('admin_url')) {
			function admin_url($path = '') {
				return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
			}
		}

		if (!function_exists('add_query_arg')) {
			function add_query_arg($key, $value = null, $url = null) {
				if (is_array($key)) {
					$args = $key;
					$url  = $value;
				} else {
					$args = [$key => $value];
				}
				$base = (string) $url;
				return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($args);
			}
		}

		if (!function_exists('wc_update_order_item_meta')) {
			function wc_update_order_item_meta($item_id, $key, $value, $prev_value = '') {
				ProgramManagerDuplicatePriceMetaTest::$order_api_calls++;
				return true;
			}
		}

		if (!function_exists('wc_delete_product_transients')) {
			function wc_delete_product_transients($product_id = 0) {
				return true;
			}
		}

		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
	}

	private function installWooCommerceMocks(): void {
		if (!class_exists('InterSoccer_Product_Types', false)) {
			eval(<<<'PHP'
class InterSoccer_Product_Types {
	public static function get_product_type($product_id) {
		return ProgramManagerDuplicatePriceMetaTest::$product_types[(int) $product_id] ?? '';
	}
}
PHP
			);
		}

		if (!class_exists('WC_Product_Attribute', false)) {
			eval(<<<'PHP'
class WC_Product_Attribute {
	private $name;
	private $options = [];
	public function get_name() { return $this->name; }
	public function set_name($name) { $this->name = $name; return $this; }
	public function get_options() { return $this->options; }
	public function set_options($options) { $this->options = $options; return $this; }
}
PHP
			);
		}

		if (!class_exists('WC_Product_Variable', false)) {
			eval(<<<'PHP'
class WC_Product_Variable {
	public int $id = 0;
	public string $name = '';
	public string $status = 'draft';
	public array $attributes = [];
	public array $children = [];

	public function is_type($type) { return $type === 'variable'; }
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_attributes() { return $this->attributes; }
	public function get_children() { return $this->children; }
	public function set_name($name) { $this->name = (string) $name; }
	public function set_status($status) { $this->status = (string) $status; }
	public function set_catalog_visibility($v) {}
	public function set_attributes($attributes) { $this->attributes = $attributes; }
	public function save() {
		if ($this->id <= 0) {
			$this->id = ProgramManagerDuplicatePriceMetaTest::$next_product_id++;
		}
		ProgramManagerDuplicatePriceMetaTest::$wc_products[$this->id] = $this;
		ProgramManagerDuplicatePriceMetaTest::$children_by_parent[$this->id] = $this->children;
		return $this->id;
	}
}
PHP
			);
		}

		if (!class_exists('WC_Product_Variation', false)) {
			eval(<<<'PHP'
class WC_DateTime {
	private int $timestamp;
	public function __construct(int $timestamp) {
		$this->timestamp = $timestamp;
	}
	public function getTimestamp(): int {
		return $this->timestamp;
	}
}
class WC_Product_Variation {
	public int $id = 0;
	public int $parent_id = 0;
	public string $status = 'publish';
	public array $attributes = [];
	public $regular_price = '';
	public $sale_price = '';
	public $price = '';
	public $stock_quantity = null;
	public $date_on_sale_from = null;
	public $date_on_sale_to = null;

	public function is_type($type) { return $type === 'variation'; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
	public function get_attributes() { return $this->attributes; }
	public function get_status() { return $this->status; }
	public function set_parent_id($id) { $this->parent_id = (int) $id; }
	public function set_attributes($attrs) { $this->attributes = (array) $attrs; }
	public function set_status($status) { $this->status = (string) $status; }
	public function set_regular_price($p) { $this->regular_price = $p; }
	public function set_sale_price($p) { $this->sale_price = $p; }
	public function set_price($p) { $this->price = $p; }
	public function set_stock_quantity($q) { $this->stock_quantity = $q; }
	public function set_date_on_sale_from($d) { $this->date_on_sale_from = $d; }
	public function set_date_on_sale_to($d) { $this->date_on_sale_to = $d; }
	public function get_regular_price($context = 'view') { return $this->regular_price; }
	public function get_sale_price($context = 'view') { return $this->sale_price; }
	public function get_price($context = 'view') { return $this->price; }
	public function get_stock_quantity($context = 'view') { return $this->stock_quantity; }
	public function get_date_on_sale_from($context = 'view') { return $this->date_on_sale_from; }
	public function get_date_on_sale_to($context = 'view') { return $this->date_on_sale_to; }
	public function save() {
		if ($this->id <= 0) {
			$this->id = ProgramManagerDuplicatePriceMetaTest::$next_product_id++;
		}
		ProgramManagerDuplicatePriceMetaTest::$wc_products[$this->id] = $this;
		ProgramManagerDuplicatePriceMetaTest::$saved_variation_props[$this->id] = [
			'regular_price' => $this->regular_price,
			'sale_price'    => $this->sale_price,
			'price'         => $this->price,
			'stock_quantity'=> $this->stock_quantity,
			'attributes'    => $this->attributes,
		];
		if ($this->parent_id > 0) {
			if (!isset(ProgramManagerDuplicatePriceMetaTest::$children_by_parent[$this->parent_id])) {
				ProgramManagerDuplicatePriceMetaTest::$children_by_parent[$this->parent_id] = [];
			}
			ProgramManagerDuplicatePriceMetaTest::$children_by_parent[$this->parent_id][] = $this->id;
			$parent = ProgramManagerDuplicatePriceMetaTest::$wc_products[$this->parent_id] ?? null;
			if ($parent instanceof WC_Product_Variable) {
				$parent->children = ProgramManagerDuplicatePriceMetaTest::$children_by_parent[$this->parent_id];
			}
		}
		if ($this->regular_price !== '' && $this->regular_price !== null) {
			update_post_meta($this->id, '_regular_price', $this->regular_price);
		}
		if ($this->sale_price !== '' && $this->sale_price !== null) {
			update_post_meta($this->id, '_sale_price', $this->sale_price);
		}
		if ($this->price !== '' && $this->price !== null) {
			update_post_meta($this->id, '_price', $this->price);
		}
		return $this->id;
	}
}
PHP
			);
		}

		$GLOBALS['intersoccer_wc_get_product_callback'] = static function ($product_id) {
			$id = (int) $product_id;
			return ProgramManagerDuplicatePriceMetaTest::$wc_products[$id] ?? null;
		};
	}

	/**
	 * @param array<string,mixed> $meta
	 * @param array<string,string> $attributes
	 */
	private function seedSourceCampVariation(int $parent_id, int $var_id, array $meta, array $attributes, array $prices): void {
		$source_var = new WC_Product_Variation();
		$source_var->id = $var_id;
		$source_var->parent_id = $parent_id;
		$source_var->attributes = $attributes;
		$source_var->regular_price = $prices['regular'] ?? '';
		$source_var->sale_price = $prices['sale'] ?? '';
		$source_var->price = $prices['active'] ?? ($prices['regular'] ?? '');
		$source_var->stock_quantity = 12;
		self::$wc_products[$var_id] = $source_var;

		foreach ($meta as $key => $value) {
			MockMetaData::$data[$var_id][$key] = $value;
		}

		$parent = new WC_Product_Variable();
		$parent->id = $parent_id;
		$parent->name = 'Source Camp Program';
		$parent->children = [$var_id];
		self::$wc_products[$parent_id] = $parent;
		self::$children_by_parent[$parent_id] = [$var_id];
		self::$product_types[$parent_id] = 'camp';
		update_post_meta($parent_id, '_intersoccer_product_type', 'camp');
	}

	public function test_camp_duplicate_copies_prices_meta_and_not_stock(): void {
		$parent_id = 910001;
		$var_id    = 910101;
		$attrs     = ['pa_age-group' => '5-13y-full-day', 'pa_booking-type' => 'full-week'];
		$meta      = [
			'_intersoccer_enable_late_pickup'           => 'yes',
			'_intersoccer_camp_days_available'          => ['monday', 'tuesday'],
			'_intersoccer_late_pickup_days_available'   => ['friday'],
			'_camp_start_date'                          => '2026-07-06',
			'_camp_end_date'                            => '2026-07-10',
			'_camp_week_index'                          => 2,
		];

		$this->seedSourceCampVariation($parent_id, $var_id, $meta, $attrs, [
			'regular' => '450',
			'sale'    => '399',
			'active'  => '399',
		]);

		$new_id = InterSoccer_Program_Manager::duplicate_program($parent_id, 'Camp Copy', []);
		$this->assertIsInt($new_id);
		$this->assertNotSame($parent_id, $new_id);

		$new_parent = self::$wc_products[$new_id];
		$this->assertInstanceOf(WC_Product_Variable::class, $new_parent);
		$this->assertCount(1, $new_parent->get_children());

		$new_var_id = (int) $new_parent->get_children()[0];
		$new_var    = self::$wc_products[$new_var_id];
		$this->assertSame($attrs, $new_var->get_attributes());
		$this->assertSame('450', $new_var->get_regular_price());
		$this->assertSame('399', $new_var->get_sale_price());
		$this->assertSame('399', $new_var->get_price());
		$this->assertNull($new_var->get_stock_quantity());

		foreach (intersoccer_pm_duplicate_variation_meta_keys('camp') as $key) {
			$this->assertSame(
				MockMetaData::$data[$var_id][$key],
				MockMetaData::$data[$new_var_id][$key] ?? null,
				'Camp meta key ' . $key
			);
		}

		$this->assertSame(0, self::$order_api_calls);
		$this->assertSame(0, self::$course_sync_calls);
	}

	public function test_course_duplicate_copies_meta_and_syncs_translations(): void {
		$parent_id = 910002;
		$var_id    = 910102;
		$attrs     = ['pa_course-day' => 'monday', 'pa_age-group' => '5-13y'];
		$meta      = [
			'_course_start_date'       => '2026-09-01',
			'_course_total_weeks'      => '16',
			'_course_holiday_dates'    => ['2026-12-25', '2027-01-01'],
			'_course_weekly_discount'  => '5',
			'_end_date'                => '2027-01-15',
			'_intersoccer_enable_late_pickup' => 'no',
		];

		$this->seedSourceCampVariation($parent_id, $var_id, $meta, $attrs, [
			'regular' => '320',
			'sale'    => '',
			'active'  => '320',
		]);
		self::$product_types[$parent_id] = 'course';
		update_post_meta($parent_id, '_intersoccer_product_type', 'course');

		$new_id = InterSoccer_Program_Manager::duplicate_program($parent_id, 'Course Copy', []);
		$new_var_id = (int) self::$wc_products[$new_id]->get_children()[0];

		foreach (intersoccer_pm_duplicate_variation_meta_keys('course') as $key) {
			if (!array_key_exists($key, $meta)) {
				continue;
			}
			$this->assertSame(
				MockMetaData::$data[$var_id][$key],
				MockMetaData::$data[$new_var_id][$key] ?? null,
				'Course meta key ' . $key
			);
		}

		$this->assertSame('', self::$wc_products[$new_var_id]->get_sale_price());
		if ($this->course_sync_spy_installed) {
			$this->assertSame(1, self::$course_sync_calls);
			$this->assertSame($new_var_id, self::$course_sync_args[0][0]);
		} else {
			$this->markTestSkipped('Course translation sync helper already defined in this PHPUnit run.');
		}
	}

	public function test_duplicate_to_year_message_and_unchanged_dates(): void {
		$parent_id = 910003;
		$var_id    = 910103;
		$meta      = [
			'_course_start_date'  => '2026-09-01',
			'_course_total_weeks' => '12',
			'_end_date'           => '2026-12-15',
		];
		$this->seedSourceCampVariation($parent_id, $var_id, $meta, ['pa_course-day' => 'tuesday'], [
			'regular' => '280',
			'active'  => '280',
		]);
		self::$product_types[$parent_id] = 'course';

		$year_attr = new WC_Product_Attribute();
		$year_attr->set_name('pa_program-year');
		$year_attr->set_options([9204]);
		$GLOBALS['intersoccer_test_terms'][9204] = [
			'name'     => '2026',
			'slug'     => '2026',
			'taxonomy' => 'pa_program-year',
		];
		self::$wc_products[$parent_id]->attributes = ['pa_program-year' => $year_attr];

		$result = InterSoccer_Program_Manager::process_bulk_item('duplicate_to_year', $parent_id, [
			'year' => '2027',
		]);

		$this->assertStringContainsString('review camp/course dates', strtolower($result['message']));
		$this->assertStringContainsString('sync all languages', strtolower($result['message']));
		$this->assertStringContainsString('2027', $result['message']);

		$this->assertSame('2027', $GLOBALS['intersoccer_test_product_terms'][$result['new_product_id']]['pa_program-year'][0] ?? '');

		$new_var_id = (int) self::$wc_products[$result['new_product_id']]->get_children()[0];
		$this->assertSame('2026-09-01', MockMetaData::$data[$new_var_id]['_course_start_date']);
		$this->assertSame('280', self::$wc_products[$new_var_id]->get_regular_price());
	}

	public function test_absent_meta_and_sale_price_not_written(): void {
		$parent_id = 910004;
		$var_id    = 910104;
		$this->seedSourceCampVariation($parent_id, $var_id, [
			'_camp_start_date' => '2026-08-01',
			'_camp_end_date'   => '2026-08-05',
		], ['pa_booking-type' => 'full-week'], [
			'regular' => '100',
			'active'  => '100',
		]);

		$new_id = InterSoccer_Program_Manager::duplicate_program($parent_id, 'Sparse Camp', []);
		$new_var_id = (int) self::$wc_products[$new_id]->get_children()[0];

		$this->assertArrayNotHasKey('_intersoccer_enable_late_pickup', MockMetaData::$data[$new_var_id] ?? []);
		$this->assertArrayNotHasKey('_sale_price', MockMetaData::$data[$new_var_id] ?? []);
		$this->assertSame('', self::$wc_products[$new_var_id]->get_sale_price());
	}

	public function test_format_missing_key_label_for_schedule_start_year_before_program_year(): void {
		$method = new \ReflectionMethod(InterSoccer_Program_Manager::class, 'format_missing_key_label');
		$method->setAccessible(true);

		$label = $method->invoke(null, '_schedule_start_year_before_program_year');
		$this->assertSame(
			'Start date is before the program year (review dates)',
			$label
		);
	}

	public function test_completeness_flags_start_year_before_program_year(): void {
		$parent_id = 920001;
		$var_old   = 920101;
		$var_ok    = 920102;

		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-year'] = ['2027'];

		$variation_old = new WC_Product_Variation();
		$variation_old->id = $var_old;
		$variation_old->parent_id = $parent_id;
		$variation_old->regular_price = '200';
		self::$wc_products[$var_old] = $variation_old;

		$variation_ok = new WC_Product_Variation();
		$variation_ok->id = $var_ok;
		$variation_ok->parent_id = $parent_id;
		$variation_ok->regular_price = '200';
		self::$wc_products[$var_ok] = $variation_ok;

		MockMetaData::$data[$var_old]['_course_start_date'] = '2026-01-10';
		MockMetaData::$data[$var_ok]['_course_start_date']  = '2027-02-01';

		$old = InterSoccer_Program_Manager::get_variation_completeness($var_old, 'course');
		$this->assertContains('_schedule_start_year_before_program_year', $old['missing']);

		$ok = InterSoccer_Program_Manager::get_variation_completeness($var_ok, 'course');
		$this->assertNotContains('_schedule_start_year_before_program_year', $ok['missing']);
	}

	public function test_completeness_allows_cross_year_course_schedule_for_program_year(): void {
		$parent_id = 920010;
		$var_id    = 920110;

		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-year'] = ['2026'];
		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-season'] = ['Autumn'];

		$variation = new WC_Product_Variation();
		$variation->id = $var_id;
		$variation->parent_id = $parent_id;
		$variation->regular_price = '200';
		self::$wc_products[$var_id] = $variation;

		MockMetaData::$data[$var_id]['_course_start_date'] = '2026-09-01';
		MockMetaData::$data[$var_id]['_end_date']          = '2027-01-15';

		$result = InterSoccer_Program_Manager::get_variation_completeness($var_id, 'course');
		$this->assertNotContains('_schedule_start_year_before_program_year', $result['missing']);
	}

	public function test_completeness_allows_course_start_in_next_calendar_year_for_program_year(): void {
		$parent_id = 920011;
		$var_id    = 920111;

		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-year'] = ['2026'];
		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-season'] = ['Autumn'];

		$variation = new WC_Product_Variation();
		$variation->id = $var_id;
		$variation->parent_id = $parent_id;
		$variation->regular_price = '200';
		self::$wc_products[$var_id] = $variation;

		MockMetaData::$data[$var_id]['_course_start_date'] = '2027-01-08';
		MockMetaData::$data[$var_id]['_end_date']          = '2027-04-01';

		$result = InterSoccer_Program_Manager::get_variation_completeness($var_id, 'course');
		$this->assertNotContains('_schedule_start_year_before_program_year', $result['missing']);
	}

	public function test_completeness_flags_schedule_wholly_before_program_year(): void {
		$parent_id = 920012;
		$var_id    = 920112;

		$GLOBALS['intersoccer_test_product_terms'][$parent_id]['pa_program-year'] = ['2027'];

		$variation = new WC_Product_Variation();
		$variation->id = $var_id;
		$variation->parent_id = $parent_id;
		$variation->regular_price = '200';
		self::$wc_products[$var_id] = $variation;

		MockMetaData::$data[$var_id]['_course_start_date'] = '2026-09-01';
		MockMetaData::$data[$var_id]['_end_date']          = '2026-12-15';

		$result = InterSoccer_Program_Manager::get_variation_completeness($var_id, 'course');
		$this->assertContains('_schedule_start_year_before_program_year', $result['missing']);
	}

	public function test_duplicate_program_resolves_wpml_default_language_source(): void {
		$en_parent = 930001;
		$fr_parent = 930002;
		$en_var    = 930101;
		$fr_var    = 930102;

		$this->seedSourceCampVariation($en_parent, $en_var, [
			'_camp_start_date' => '2026-07-01',
			'_camp_end_date'   => '2026-07-05',
		], ['pa_booking-type' => 'full-week'], [
			'regular' => '888',
			'active'  => '888',
		]);

		$fr_var_obj = clone self::$wc_products[$en_var];
		$fr_var_obj->id = $fr_var;
		$fr_var_obj->parent_id = $fr_parent;
		$fr_var_obj->regular_price = '111';
		$fr_var_obj->price = '111';
		self::$wc_products[$fr_var] = $fr_var_obj;

		$fr_parent_obj = clone self::$wc_products[$en_parent];
		$fr_parent_obj->id = $fr_parent;
		$fr_parent_obj->children = [$fr_var];
		self::$wc_products[$fr_parent] = $fr_parent_obj;
		self::$children_by_parent[$fr_parent] = [$fr_var];
		self::$product_types[$fr_parent] = 'camp';
		self::$product_types[$en_parent] = 'camp';

		MockFilters::$filters['wpml_default_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en_parent, $fr_parent) {
			if ($type === 'product' && (int) $id === $fr_parent && $lang === 'en') {
				return $en_parent;
			}
			return $id;
		};

		$new_id = InterSoccer_Program_Manager::duplicate_program($fr_parent, 'FR clone', []);
		$new_var_id = (int) self::$wc_products[$new_id]->get_children()[0];
		$this->assertSame('888', self::$wc_products[$new_var_id]->get_regular_price());
	}

	public function test_copy_variation_prices_skips_expired_sale_window(): void {
		$method = new \ReflectionMethod(InterSoccer_Program_Manager::class, 'copy_variation_prices_from_source');
		$method->setAccessible(true);

		$source = new WC_Product_Variation();
		$source->regular_price = '300';
		$source->sale_price = '250';
		$source->price = '250';
		$source->date_on_sale_to = new WC_DateTime(time() - DAY_IN_SECONDS);

		$target = new WC_Product_Variation();
		$method->invoke(null, $target, $source);

		$this->assertSame('300', $target->get_regular_price());
		$this->assertSame('', $target->get_sale_price());
		$this->assertSame('300', $target->get_price());
		$this->assertNull($target->get_date_on_sale_to());
	}

	public function test_copy_variation_prices_copies_future_sale_window(): void {
		$method = new \ReflectionMethod(InterSoccer_Program_Manager::class, 'copy_variation_prices_from_source');
		$method->setAccessible(true);

		$future = new WC_DateTime(time() + WEEK_IN_SECONDS);
		$source = new WC_Product_Variation();
		$source->regular_price = '300';
		$source->sale_price = '250';
		$source->price = '250';
		$source->date_on_sale_to = $future;

		$target = new WC_Product_Variation();
		$method->invoke(null, $target, $source);

		$this->assertSame('250', $target->get_sale_price());
		$this->assertSame('250', $target->get_price());
		$this->assertSame($future, $target->get_date_on_sale_to());
	}

	public function test_copy_variation_prices_copies_sale_without_end_date(): void {
		$method = new \ReflectionMethod(InterSoccer_Program_Manager::class, 'copy_variation_prices_from_source');
		$method->setAccessible(true);

		$source = new WC_Product_Variation();
		$source->regular_price = '300';
		$source->sale_price = '275';
		$source->price = '275';

		$target = new WC_Product_Variation();
		$method->invoke(null, $target, $source);

		$this->assertSame('275', $target->get_sale_price());
		$this->assertSame('275', $target->get_price());
	}

	public function test_shared_meta_key_lists_match_expectations(): void {
		$this->assertSame(
			[
				'_intersoccer_enable_late_pickup',
				'_intersoccer_camp_days_available',
				'_intersoccer_late_pickup_days_available',
				'_camp_start_date',
				'_camp_end_date',
				'_camp_week_index',
			],
			intersoccer_pm_duplicate_variation_meta_keys('camp')
		);
		$this->assertContains('_course_weekly_discount', intersoccer_pm_duplicate_variation_meta_keys('course'));
		$this->assertContains('_end_date', intersoccer_pm_duplicate_variation_meta_keys('course'));
	}
}
