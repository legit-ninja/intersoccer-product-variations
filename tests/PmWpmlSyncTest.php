<?php
/**
 * Program Manager Sync all languages (WPML) orchestration tests.
 */

use PHPUnit\Framework\TestCase;

class PmWpmlSyncTest extends TestCase {
	private int $en_parent = 8001;
	private int $fr_parent = 8002;
	private int $de_parent = 8003;
	private int $en_var = 8101;
	private int $fr_var = 8102;
	private int $de_var = 8103;

	/** @var array<int,object> */
	private static array $products = [];

	/** @var array<int,array<string,int>> */
	private static array $product_lang_map = [];

	/** @var array<int,array<string,int>> */
	private static array $variation_lang_map = [];

	/** @var array<string,int> */
	private static array $make_duplicate_calls = [];

	/** @var int */
	private static int $wcml_sync_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		if (class_exists('MockMetaData')) {
			MockMetaData::reset();
		}
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}

		self::$products = [];
		self::$product_lang_map = [];
		self::$variation_lang_map = [];
		self::$make_duplicate_calls = [];
		self::$wcml_sync_calls = 0;

		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.6-test');
		}

		require_once dirname(__DIR__) . '/includes/language-helpers.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/pm-wpml-sync.php';

		$this->installWcMocks();
		$this->installWpmlFilters();
	}

	private function installWcMocks(): void {
		$en_parent = $this->en_parent;
		$en_var = $this->en_var;

		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation {}');
		}
		if (!class_exists('PmWpmlMockVariableProduct', false)) {
			eval(<<<'PHP'
class PmWpmlMockVariableProduct {
	public $id;
	public $children;
	public $type = 'variable';
	public function __construct($id, $children = []) {
		$this->id = (int) $id;
		$this->children = array_map('intval', $children);
	}
	public function get_id() { return $this->id; }
	public function is_type($type) { return $type === $this->type; }
	public function get_children() { return $this->children; }
}
class PmWpmlMockVariationProduct extends WC_Product_Variation {
	public $id;
	public $attrs = [];
	public $regular_price = '';
	public $price = '';
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function get_regular_price() { return $this->regular_price; }
	public function get_price() { return $this->price; }
	public function set_attributes($attrs) { $this->attrs = $attrs; }
	public function set_regular_price($p) { $this->regular_price = $p; }
	public function set_price($p) { $this->price = $p; }
	public function save() { return $this->id; }
	public function get_parent_id() { return 0; }
}
PHP
			);
		}

		self::$products[$en_parent] = new PmWpmlMockVariableProduct($en_parent, [$en_var]);
		self::$products[$en_var] = new PmWpmlMockVariationProduct($en_var);
		self::$products[$en_var]->attrs = [
			'pa_intersoccer-venues' => 'geneve-stade',
			'pa_age-group' => '5-13y-full-day',
			'pa_camp-terms' => 'week-1',
		];
		self::$products[$en_var]->regular_price = '250';
		self::$products[$en_var]->price = '250';

		MockFilters::$filters['intersoccer_pm_wpml_get_product'] = static function ($pre, $id) {
			return PmWpmlSyncTest::getProduct((int) $id);
		};

		if (!function_exists('wc_delete_product_transients')) {
			function wc_delete_product_transients($id) {
				return true;
			}
		}
		if (!function_exists('wp_set_object_terms')) {
			function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) {
				return true;
			}
		}
		if (!function_exists('get_post')) {
			function get_post($id) {
				return (object) ['ID' => (int) $id, 'post_type' => 'product'];
			}
		}
	}

	public static function getProduct(int $id) {
		return self::$products[$id] ?? false;
	}

	private function installWpmlFilters(): void {
		$en_parent = $this->en_parent;
		$fr_parent = $this->fr_parent;
		$de_parent = $this->de_parent;
		$en_var = $this->en_var;
		$fr_var = $this->fr_var;
		$de_var = $this->de_var;

		// Start with no FR/DE parents — create path.
		self::$product_lang_map = [
			$en_parent => ['en' => $en_parent],
		];
		self::$variation_lang_map = [
			$en_var => ['en' => $en_var],
		];

		MockFilters::$filters['wpml_active_languages'] = static function () {
			return [
				'en' => ['code' => 'en'],
				'fr' => ['code' => 'fr'],
				'de' => ['code' => 'de'],
			];
		};
		MockFilters::$filters['wpml_default_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($value, $args = null) use ($en_parent, $en_var) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			if ($id === $en_parent || $id === $en_var) {
				return 'en';
			}
			return $value;
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			$id = (int) $id;
			$lang = $lang ?: 'en';
			$map = ($type === 'product_variation')
				? PmWpmlSyncTest::$variation_lang_map
				: PmWpmlSyncTest::$product_lang_map;
			if (!isset($map[$id])) {
				// Look up by any known id in values.
				foreach ($map as $row) {
					if (in_array($id, $row, true)) {
						return $row[$lang] ?? ($return_original ? ($row['en'] ?? $id) : 0);
					}
				}
				return $return_original ? $id : 0;
			}
			if (!isset($map[$id][$lang])) {
				return $return_original ? ($map[$id]['en'] ?? $id) : 0;
			}
			return $map[$id][$lang];
		};

		MockFilters::$filters['intersoccer_pm_wpml_make_duplicate'] = static function ($pre, $master_id, $lang) use ($en_parent, $fr_parent, $de_parent, $en_var, $fr_var, $de_var) {
			$master_id = (int) $master_id;
			$lang = (string) $lang;
			PmWpmlSyncTest::$make_duplicate_calls[$lang] = $master_id;
			$new_id = $lang === 'fr' ? $fr_parent : ($lang === 'de' ? $de_parent : 0);
			if ($new_id <= 0) {
				return false;
			}
			PmWpmlSyncTest::$product_lang_map[$en_parent][$lang] = $new_id;
			PmWpmlSyncTest::$product_lang_map[$new_id] = PmWpmlSyncTest::$product_lang_map[$en_parent];
			PmWpmlSyncTest::$products[$new_id] = new PmWpmlMockVariableProduct($new_id, []);
			return $new_id;
		};

		MockFilters::$filters['intersoccer_pm_wcml_synchronize_product'] = static function ($handled, $product_post, $tr_ids, $lang_map) use ($en_var, $fr_var, $de_var, $en_parent) {
			PmWpmlSyncTest::$wcml_sync_calls++;
			// Link variation siblings after parent create/sync.
			PmWpmlSyncTest::$variation_lang_map[$en_var] = [
				'en' => $en_var,
				'fr' => $fr_var,
				'de' => $de_var,
			];
			PmWpmlSyncTest::$variation_lang_map[$fr_var] = PmWpmlSyncTest::$variation_lang_map[$en_var];
			PmWpmlSyncTest::$variation_lang_map[$de_var] = PmWpmlSyncTest::$variation_lang_map[$en_var];
			PmWpmlSyncTest::$products[$fr_var] = new PmWpmlMockVariationProduct($fr_var);
			PmWpmlSyncTest::$products[$de_var] = new PmWpmlMockVariationProduct($de_var);
			foreach ($tr_ids as $tr_id) {
				if (isset(PmWpmlSyncTest::$products[(int) $tr_id])) {
					PmWpmlSyncTest::$products[(int) $tr_id]->children = [$fr_var, $de_var];
				}
			}
			return true;
		};
	}

	public function test_no_active_languages_returns_error(): void {
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return null;
		};
		$result = intersoccer_pm_sync_product_translations($this->en_parent);
		$this->assertFalse($result['ok']);
		$this->assertContains('no_languages', $result['errors']);
	}

	public function test_not_variable_returns_error(): void {
		self::$products[$this->en_parent]->type = 'simple';
		$result = intersoccer_pm_sync_product_translations($this->en_parent);
		$this->assertFalse($result['ok']);
		$this->assertContains('not_variable', $result['errors']);
	}

	public function test_create_path_duplicates_missing_languages_and_fans_out(): void {
		update_post_meta($this->en_var, '_camp_start_date', '2026-07-06');
		update_post_meta($this->en_var, '_camp_end_date', '2026-07-10');
		update_post_meta($this->en_var, '_camp_week_index', 1);
		update_post_meta($this->en_var, 'attribute_pa_intersoccer-venues', 'geneve-stade');

		$result = intersoccer_pm_sync_product_translations($this->en_parent);

		$this->assertTrue($result['ok'], $result['message'] ?? '');
		$this->assertArrayHasKey('fr', $result['parents_created']);
		$this->assertArrayHasKey('de', $result['parents_created']);
		$this->assertSame($this->fr_parent, $result['parents_created']['fr']);
		$this->assertSame($this->de_parent, $result['parents_created']['de']);
		$this->assertSame([], $result['parents_synced']);
		$this->assertArrayHasKey('fr', self::$make_duplicate_calls);
		$this->assertArrayHasKey('de', self::$make_duplicate_calls);
		$this->assertSame(1, self::$wcml_sync_calls);
		$this->assertGreaterThan(0, $result['meta_synced']);

		$this->assertSame('2026-07-06', get_post_meta($this->fr_var, '_camp_start_date', true));
		$this->assertSame('2026-07-10', get_post_meta($this->de_var, '_camp_end_date', true));
		$this->assertSame('geneve-stade', get_post_meta($this->fr_var, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('geneve-stade', get_post_meta($this->de_var, 'attribute_pa_intersoccer-venues', true));
	}

	public function test_sync_path_refreshes_existing_translations_without_duplicate(): void {
		// Pre-link parents.
		self::$product_lang_map = [
			$this->en_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
			$this->fr_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
			$this->de_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
		];
		self::$products[$this->fr_parent] = new PmWpmlMockVariableProduct($this->fr_parent, [$this->fr_var]);
		self::$products[$this->de_parent] = new PmWpmlMockVariableProduct($this->de_parent, [$this->de_var]);
		self::$variation_lang_map = [
			$this->en_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
			$this->fr_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
			$this->de_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
		];
		self::$products[$this->fr_var] = new PmWpmlMockVariationProduct($this->fr_var);
		self::$products[$this->de_var] = new PmWpmlMockVariationProduct($this->de_var);

		update_post_meta($this->en_var, '_camp_start_date', '2026-08-03');
		update_post_meta($this->en_var, '_camp_end_date', '2026-08-07');
		update_post_meta($this->en_var, '_camp_week_index', 2);

		$result = intersoccer_pm_sync_product_translations($this->en_parent);

		$this->assertTrue($result['ok'], $result['message'] ?? '');
		$this->assertSame([], $result['parents_created']);
		$this->assertArrayHasKey('fr', $result['parents_synced']);
		$this->assertArrayHasKey('de', $result['parents_synced']);
		$this->assertSame([], self::$make_duplicate_calls);
		$this->assertSame(1, self::$wcml_sync_calls);
		$this->assertSame('2026-08-03', get_post_meta($this->fr_var, '_camp_start_date', true));
		$this->assertSame('2026-08-07', get_post_meta($this->de_var, '_camp_end_date', true));
	}

	public function test_resolves_to_default_language_source(): void {
		self::$product_lang_map = [
			$this->en_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
			],
			$this->fr_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
			],
		];
		self::$products[$this->fr_parent] = new PmWpmlMockVariableProduct($this->fr_parent, []);

		$resolved = intersoccer_pm_wpml_resolve_source_product($this->fr_parent);
		$this->assertSame($this->en_parent, $resolved['id']);
		$this->assertSame('en', $resolved['default_lang']);
	}

	public function test_idempotent_second_run_still_ok(): void {
		self::$product_lang_map = [
			$this->en_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
			$this->fr_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
			$this->de_parent => [
				'en' => $this->en_parent,
				'fr' => $this->fr_parent,
				'de' => $this->de_parent,
			],
		];
		self::$products[$this->fr_parent] = new PmWpmlMockVariableProduct($this->fr_parent, [$this->fr_var]);
		self::$products[$this->de_parent] = new PmWpmlMockVariableProduct($this->de_parent, [$this->de_var]);
		self::$variation_lang_map = [
			$this->en_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
			$this->fr_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
			$this->de_var => ['en' => $this->en_var, 'fr' => $this->fr_var, 'de' => $this->de_var],
		];
		self::$products[$this->fr_var] = new PmWpmlMockVariationProduct($this->fr_var);
		self::$products[$this->de_var] = new PmWpmlMockVariationProduct($this->de_var);

		$first = intersoccer_pm_sync_product_translations($this->en_parent);
		self::$make_duplicate_calls = [];
		self::$wcml_sync_calls = 0;
		$second = intersoccer_pm_sync_product_translations($this->en_parent);

		$this->assertTrue($first['ok']);
		$this->assertTrue($second['ok']);
		$this->assertSame([], $second['parents_created']);
		$this->assertSame([], self::$make_duplicate_calls);
	}
}
