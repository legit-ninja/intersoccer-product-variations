<?php

use PHPUnit\Framework\TestCase;

/**
 * Fake $wpdb that stores term_relationships, term_taxonomy, postmeta, posts,
 * and icl_translations so the production SQL paths can be exercised.
 */
class InterSoccerPvRepairFakeWpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $terms_rows = [];
	public $rels = [];
	public $meta = [];
	public $posts_rows = [];
	public $icl = [];
	public $queries = [];

	public function add_term($term_id, $slug, $taxonomy, $tt_id = null) {
		$term_id = (int) $term_id;
		$this->terms_rows[$term_id] = [
			'term_id' => $term_id,
			'slug' => (string) $slug,
			'name' => (string) $slug,
			'taxonomy' => (string) $taxonomy,
			'term_taxonomy_id' => $tt_id === null ? $term_id : (int) $tt_id,
		];
	}

	public function add_rel($object_id, $term_id) {
		$object_id = (int) $object_id;
		$term_id = (int) $term_id;
		if (!isset($this->terms_rows[$term_id])) {
			return;
		}
		$this->rels[] = [
			'object_id' => $object_id,
			'term_taxonomy_id' => (int) $this->terms_rows[$term_id]['term_taxonomy_id'],
		];
	}

	public function add_meta($post_id, $key, $value) {
		if (is_array($value) || is_object($value)) {
			$value = serialize($value);
		}
		$this->meta[] = [
			'post_id' => (int) $post_id,
			'meta_key' => (string) $key,
			'meta_value' => (string) $value,
		];
	}

	public function add_post($id, $type, $parent = 0, $status = 'publish') {
		$this->posts_rows[] = [
			'ID' => (int) $id,
			'post_type' => (string) $type,
			'post_parent' => (int) $parent,
			'post_status' => (string) $status,
		];
	}

	public function add_icl($translation_id, $element_type, $element_id, $trid, $language_code, $source_language_code = '') {
		$this->icl[] = [
			'translation_id' => (int) $translation_id,
			'element_type' => (string) $element_type,
			'element_id' => (int) $element_id,
			'trid' => (int) $trid,
			'language_code' => (string) $language_code,
			'source_language_code' => $source_language_code === '' ? null : (string) $source_language_code,
		];
	}

	public function term_ids_for($object_id, $taxonomy) {
		$ids = [];
		foreach ($this->rels as $rel) {
			if ((int) $rel['object_id'] !== (int) $object_id) {
				continue;
			}
			foreach ($this->terms_rows as $term) {
				if ((int) $term['term_taxonomy_id'] === (int) $rel['term_taxonomy_id'] && $term['taxonomy'] === $taxonomy) {
					$ids[] = (int) $term['term_id'];
				}
			}
		}
		sort($ids);
		return $ids;
	}

	public function meta_value($post_id, $key) {
		$found = null;
		foreach ($this->meta as $row) {
			if ((int) $row['post_id'] === (int) $post_id && $row['meta_key'] === $key) {
				$found = $row['meta_value'];
			}
		}
		return $found;
	}

	public function prepare($query, ...$args) {
		if (isset($args[0]) && is_array($args[0]) && count($args) === 1) {
			$args = $args[0];
		}
		$index = 0;
		return preg_replace_callback(
			'/%[dsf]|%%/',
			static function ($match) use (&$index, $args) {
				if ($match[0] === '%%') {
					return '%';
				}
				$value = $args[$index] ?? '';
				$index++;
				if ($match[0] === '%d') {
					return (string) (int) $value;
				}
				return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
			},
			(string) $query
		);
	}

	public function esc_like($text) {
		return (string) $text;
	}

	public function insert($table, $data) {
		if (strpos((string) $table, 'icl_translations') !== false) {
			foreach ($this->icl as $row) {
				if ((int) $row['translation_id'] === (int) ($data['translation_id'] ?? 0)) {
					return false;
				}
			}
			$this->icl[] = $data;
			return 1;
		}
		return 1;
	}

	public function get_results($query, $output = null) {
		unset($output);
		$query = (string) $query;
		if (stripos($query, 'SELECT t.term_id, t.slug FROM') !== false && stripos($query, 'term_relationships') !== false) {
			if (!preg_match("/object_id = (\\d+).+taxonomy = '([^']+)'/s", $query, $match)) {
				return [];
			}
			return $this->term_slug_rows((int) $match[1], $match[2]);
		}
		if (stripos($query, 'SELECT t.term_id, t.slug FROM') !== false && stripos($query, 't.slug IN') !== false) {
			if (!preg_match("/taxonomy = '([^']+)'.+slug IN \\((.+)\\)/s", $query, $match)) {
				return [];
			}
			$slugs = array_map(static function ($item) {
				return trim($item, " '");
			}, explode(',', $match[2]));
			$out = [];
			foreach ($this->terms_rows as $term) {
				if ($term['taxonomy'] === $match[1] && in_array($term['slug'], $slugs, true)) {
					$out[] = ['term_id' => $term['term_id'], 'slug' => $term['slug']];
				}
			}
			return $out;
		}
		if (stripos($query, 'SELECT translation_id') !== false) {
			return $this->icl;
		}
		return [];
	}

	public function get_var($query) {
		$query = (string) $query;
		if (stripos($query, 'SHOW TABLES LIKE') !== false) {
			return (strpos($query, 'icl_translations') !== false) ? 'wp_icl_translations' : null;
		}
		if (stripos($query, 'term_taxonomy_id') !== false && stripos($query, 'term_id') !== false) {
			if (!preg_match("/term_id = (\\d+).+taxonomy = '([^']+)'/s", $query, $match)) {
				return null;
			}
			$term_id = (int) $match[1];
			if (isset($this->terms_rows[$term_id]) && $this->terms_rows[$term_id]['taxonomy'] === $match[2]) {
				return $this->terms_rows[$term_id]['term_taxonomy_id'];
			}
			return null;
		}
		if (stripos($query, 'SELECT meta_value FROM') !== false) {
			if (!preg_match("/post_id = (\\d+) AND meta_key = '([^']+)'/s", $query, $match)) {
				return null;
			}
			return $this->meta_value((int) $match[1], $match[2]);
		}
		if (stripos($query, 'SELECT t.slug FROM') !== false) {
			if (!preg_match("/t\\.term_id = (\\d+).+taxonomy = '([^']+)'/s", $query, $match)) {
				return null;
			}
			$term_id = (int) $match[1];
			if (isset($this->terms_rows[$term_id]) && $this->terms_rows[$term_id]['taxonomy'] === $match[2]) {
				return $this->terms_rows[$term_id]['slug'];
			}
			return null;
		}
		if (stripos($query, 'SELECT t.term_id FROM') !== false && stripos($query, 't.slug') !== false) {
			if (preg_match("/taxonomy = '([^']+)'.+t\\.slug = '([^']+)'/s", $query, $match)) {
				$taxonomy = $match[1];
				$slug = $match[2];
			} elseif (preg_match("/t\\.slug = '([^']+)'.+taxonomy = '([^']+)'/s", $query, $match)) {
				$slug = $match[1];
				$taxonomy = $match[2];
			} else {
				return null;
			}
			foreach ($this->terms_rows as $term) {
				if ($term['taxonomy'] === $taxonomy && $term['slug'] === $slug) {
					return $term['term_id'];
				}
			}
			return null;
		}
		return null;
	}

	public function get_col($query) {
		$query = (string) $query;
		if (stripos($query, 'SELECT tr.term_taxonomy_id') !== false) {
			if (!preg_match("/object_id = (\\d+).+taxonomy = '([^']+)'/s", $query, $match)) {
				return [];
			}
			$out = [];
			foreach ($this->term_slug_rows((int) $match[1], $match[2]) as $row) {
				$term_id = (int) $row['term_id'];
				$out[] = $this->terms_rows[$term_id]['term_taxonomy_id'];
			}
			return $out;
		}
		if (stripos($query, 'SELECT meta_key FROM') !== false) {
			if (!preg_match("/post_id = (\\d+)/", $query, $match)) {
				return [];
			}
			$out = [];
			foreach ($this->meta as $row) {
				if ((int) $row['post_id'] === (int) $match[1] && strpos($row['meta_key'], 'attribute_') === 0) {
					$out[] = $row['meta_key'];
				}
			}
			return $out;
		}
		if (stripos($query, 'post_parent') !== false && stripos($query, 'product_variation') !== false) {
			if (!preg_match("/post_parent = (\\d+)/", $query, $match)) {
				return [];
			}
			$parent = (int) $match[1];
			$out = [];
			foreach ($this->posts_rows as $post) {
				if ((int) $post['post_parent'] === $parent && $post['post_type'] === 'product_variation' && $post['post_status'] !== 'trash') {
					$out[] = $post['ID'];
				}
			}
			return $out;
		}
		return [];
	}

	public function get_row($query, $output = null) {
		unset($output);
		$query = (string) $query;
		if (stripos($query, 'SELECT t.term_id, t.slug') !== false && stripos($query, 'dest.trid') !== false) {
			if (!preg_match("/src_tt\.taxonomy = '([^']+)'/", $query, $tax_match)
				|| !preg_match("/dest\.language_code = '([^']+)'/", $query, $lang_match)
				|| !preg_match("/src\.element_type = '([^']+)'/", $query, $type_match)
				|| !preg_match("/src_tt\.term_id = (\d+)/", $query, $term_match)
			) {
				return null;
			}
			$taxonomy = $tax_match[1];
			$lang = $lang_match[1];
			$element_type = $type_match[1];
			$source_term_id = (int) $term_match[1];
			if (!isset($this->terms_rows[$source_term_id]) || $this->terms_rows[$source_term_id]['taxonomy'] !== $taxonomy) {
				return null;
			}
			$src_tt = (int) $this->terms_rows[$source_term_id]['term_taxonomy_id'];
			$trid = 0;
			foreach ($this->icl as $row) {
				if ((string) ($row['element_type'] ?? '') === $element_type && (int) ($row['element_id'] ?? 0) === $src_tt) {
					$trid = (int) ($row['trid'] ?? 0);
					break;
				}
			}
			if ($trid <= 0) {
				return null;
			}
			foreach ($this->icl as $row) {
				if ((int) ($row['trid'] ?? 0) !== $trid
					|| (string) ($row['element_type'] ?? '') !== $element_type
					|| (string) ($row['language_code'] ?? '') !== $lang
				) {
					continue;
				}
				$dest_tt = (int) ($row['element_id'] ?? 0);
				foreach ($this->terms_rows as $term) {
					if ((int) $term['term_taxonomy_id'] === $dest_tt && $term['taxonomy'] === $taxonomy) {
						return [
							'term_id' => (int) $term['term_id'],
							'slug' => (string) $term['slug'],
						];
					}
				}
			}
			return null;
		}
		return null;
	}

	public function query($query) {

		$query = (string) $query;
		$this->queries[] = $query;
		if (preg_match('/^(START TRANSACTION|COMMIT|ROLLBACK)/i', $query)) {
			return 1;
		}
		if (stripos($query, 'INSERT IGNORE INTO') !== false && stripos($query, 'term_relationships') !== false) {
			if (!preg_match('/VALUES \\((\\d+), (\\d+), 0\\)/', $query, $match)) {
				return 1;
			}
			$object_id = (int) $match[1];
			$tt_id = (int) $match[2];
			foreach ($this->rels as $rel) {
				if ((int) $rel['object_id'] === $object_id && (int) $rel['term_taxonomy_id'] === $tt_id) {
					return 1;
				}
			}
			$this->rels[] = ['object_id' => $object_id, 'term_taxonomy_id' => $tt_id];
			return 1;
		}
		if (stripos($query, 'INSERT INTO') !== false && stripos($query, 'postmeta') !== false) {
			if (preg_match("/VALUES \\((\\d+), '([^']*)', '(.*)'\\)\\s*$/s", $query, $match)) {
				$this->meta[] = [
					'post_id' => (int) $match[1],
					'meta_key' => $match[2],
					'meta_value' => stripcslashes($match[3]),
				];
			}
			return 1;
		}
		if (stripos($query, 'DELETE FROM') !== false && stripos($query, 'postmeta') !== false) {
			if (preg_match("/post_id = (\\d+) AND meta_key = '([^']+)'/", $query, $match)) {
				$post_id = (int) $match[1];
				$key = $match[2];
				$this->meta = array_values(array_filter($this->meta, static function ($row) use ($post_id, $key) {
					return !((int) $row['post_id'] === $post_id && $row['meta_key'] === $key);
				}));
			}
			return 1;
		}
		if (stripos($query, 'DELETE FROM') !== false && stripos($query, 'icl_translations') !== false) {
			$translation_ids = $this->in_list($query, 'translation_id');
			$element_ids = $this->in_list($query, 'element_id');
			$trids = $this->in_list($query, 'trid');
			$this->icl = array_values(array_filter($this->icl, static function ($row) use ($translation_ids, $element_ids, $trids) {
				$type = (string) ($row['element_type'] ?? '');
				if ($type !== 'post_product' && $type !== 'post_product_variation') {
					return true;
				}
				$hit = in_array((int) ($row['translation_id'] ?? 0), $translation_ids, true)
					|| in_array((int) ($row['element_id'] ?? 0), $element_ids, true)
					|| in_array((int) ($row['trid'] ?? 0), $trids, true);
				return !$hit;
			}));
			return 1;
		}
		if (stripos($query, 'DELETE tr FROM') !== false) {
			if (!preg_match("/object_id = (\\d+).+taxonomy = '([^']+)'/s", $query, $match)) {
				return 1;
			}
			$object_id = (int) $match[1];
			$taxonomy = $match[2];
			$not_in = [];
			if (preg_match('/NOT IN \\(([^)]+)\\)/', $query, $not_match)) {
				$not_in = array_map('intval', explode(',', $not_match[1]));
			}
			$keep = [];
			foreach ($this->rels as $rel) {
				if ((int) $rel['object_id'] !== $object_id) {
					$keep[] = $rel;
					continue;
				}
				$term = null;
				foreach ($this->terms_rows as $candidate) {
					if ((int) $candidate['term_taxonomy_id'] === (int) $rel['term_taxonomy_id']) {
						$term = $candidate;
						break;
					}
				}
				if (!$term || $term['taxonomy'] !== $taxonomy) {
					$keep[] = $rel;
					continue;
				}
				if ($not_in !== [] && in_array((int) $rel['term_taxonomy_id'], $not_in, true)) {
					$keep[] = $rel;
				}
			}
			$this->rels = $keep;
			return 1;
		}
		return 1;
	}

	private function term_slug_rows($object_id, $taxonomy) {
		$out = [];
		foreach ($this->rels as $rel) {
			if ((int) $rel['object_id'] !== (int) $object_id) {
				continue;
			}
			foreach ($this->terms_rows as $term) {
				if ((int) $term['term_taxonomy_id'] === (int) $rel['term_taxonomy_id'] && $term['taxonomy'] === $taxonomy) {
					$out[] = ['term_id' => $term['term_id'], 'slug' => $term['slug']];
				}
			}
		}
		return $out;
	}

	private function in_list($query, $column) {
		if (!preg_match('/' . preg_quote($column, '/') . ' IN \\(([^)]+)\\)/', $query, $match)) {
			return [];
		}
		return array_map('intval', explode(',', $match[1]));
	}
}

class VenueSqlPathsTest extends TestCase {
	/** @var InterSoccerPvRepairFakeWpdb */
	private $db;

	/** @var object|null */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		foreach ([
			'intersoccer_test_products',
			'intersoccer_test_terms',
			'intersoccer_test_product_terms',
			'intersoccer_test_product_term_ids',
			'intersoccer_test_icl_translations',
			'intersoccer_test_icl_term_translations',
			'intersoccer_test_term_translations',
			'intersoccer_test_term_translation_ids',
			'intersoccer_pv_repair_icl_rows_sql_cache',
		] as $key) {
			unset($GLOBALS[$key]);
		}
		if (class_exists('MockMetaData')) {
			MockMetaData::$data = [];
		}
		if (function_exists('intersoccer_pv_wpml_icl_translations_table_reset_exists_cache')) {
			intersoccer_pv_wpml_icl_translations_table_reset_exists_cache();
		}
		if (!defined('ARRAY_A')) {
			define('ARRAY_A', 'ARRAY_A');
		}
		$GLOBALS['intersoccer_pv_test_cache_touches'] = [];
		if (isset($GLOBALS['intersoccer_test_transients']) && is_array($GLOBALS['intersoccer_test_transients'])) {
			foreach (array_keys($GLOBALS['intersoccer_test_transients']) as $transient) {
				if (strpos((string) $transient, 'intersoccer_type_') === 0) {
					unset($GLOBALS['intersoccer_test_transients'][$transient]);
				}
			}
		}
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db = new InterSoccerPvRepairFakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$helpers = dirname(__DIR__) . '/includes/helpers.php';
		if (is_readable($helpers)) {
			require_once $helpers;
		}
		require_once dirname(__DIR__) . '/includes/woocommerce/repair-contract.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/advanced-tools-venue-repair.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/advanced-tools-admin.php';
		if (function_exists('intersoccer_pv_wpml_icl_translations_table_reset_exists_cache')) {
			intersoccer_pv_wpml_icl_translations_table_reset_exists_cache();
		}
	}

	protected function tearDown(): void {
		if ($this->previous_wpdb !== null) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset($GLOBALS['wpdb']);
		}
		unset($GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'], $GLOBALS['intersoccer_pv_test_cache_touches'], $GLOBALS['intersoccer_pv_test_after_write']);
		if (function_exists('intersoccer_pv_wpml_icl_translations_table_reset_exists_cache')) {
			intersoccer_pv_wpml_icl_translations_table_reset_exists_cache();
		}
		parent::tearDown();
	}

	public function test_raw_term_records_keeps_id_slug_pairs_when_id_order_differs(): void {
		$this->db->add_term(5, 'zurich', 'pa_intersoccer-venues');
		$this->db->add_term(10, 'nyon', 'pa_intersoccer-venues');
		$this->db->add_term(21, 'week-2', 'pa_camp-terms');
		$this->db->add_term(30, 'week-1', 'pa_camp-terms');
		$this->db->add_rel(100, 10);
		$this->db->add_rel(100, 5);
		$this->db->add_rel(100, 30);
		$this->db->add_rel(100, 21);

		$venues = intersoccer_pv_repair_raw_term_records(100, 'pa_intersoccer-venues');
		$camps = intersoccer_pv_repair_raw_term_records(100, 'pa_camp-terms');
		$venue_map = [];
		foreach ($venues['ids'] as $index => $term_id) {
			$venue_map[$venues['slugs'][$index]] = (int) $term_id;
		}
		$camp_map = [];
		foreach ($camps['ids'] as $index => $term_id) {
			$camp_map[$camps['slugs'][$index]] = (int) $term_id;
		}
		$this->assertEquals(['zurich' => 5, 'nyon' => 10], $venue_map);
		$this->assertEquals(['week-1' => 30, 'week-2' => 21], $camp_map);
		$venue_pairs = [];
		foreach ($venues['pairs'] as $pair) {
			$venue_pairs[(int) $pair['id']] = $pair['slug'];
		}
		$this->assertSame([5 => 'zurich', 10 => 'nyon'], $venue_pairs);
		$this->assertSame([5, 10], $venues['ids']);
		$this->assertSame(['zurich', 'nyon'], $venues['slugs']);
	}

	public function test_parent_attr_write_and_rollback_keep_and_restore_terms(): void {
		$this->db->add_term(5, 'zurich', 'pa_intersoccer-venues');
		$this->db->add_term(10, 'nyon', 'pa_intersoccer-venues');
		$this->db->add_rel(100, 10);
		$this->db->add_rel(100, 5);
		$this->db->add_meta(100, '_product_attributes', [
			'pa_intersoccer-venues' => [
				'name' => 'pa_intersoccer-venues',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 0,
				'is_taxonomy' => 1,
			],
		]);
		$before_ids = $this->db->term_ids_for(100, 'pa_intersoccer-venues');
		$this->assertSame([5, 10], $before_ids);

		intersoccer_pv_venue_write_parent_attr(100, 'pa_intersoccer-venues', [
			'is_variation' => true,
			'slugs' => ['nyon', 'zurich'],
		]);
		$this->assertSame([5, 10], $this->db->term_ids_for(100, 'pa_intersoccer-venues'), 'Apply must not wipe parent terms');

		intersoccer_pv_repair_write_object_terms(100, 'pa_intersoccer-venues', [10], ['nyon']);
		$this->assertSame([10], $this->db->term_ids_for(100, 'pa_intersoccer-venues'));

		intersoccer_pv_repair_restore_parent_attr(100, 'pa_intersoccer-venues', [
			'is_variation' => false,
			'slugs' => ['nyon', 'zurich'],
			'term_ids' => [10, 5],
		]);
		$this->assertSame([5, 10], $this->db->term_ids_for(100, 'pa_intersoccer-venues'));
	}

	public function test_refresh_apply_and_rollback_change_and_restore_attribute_meta(): void {
		$this->seedRefreshCourse(200, 201, 0);
		$this->db->add_meta(201, 'attribute_pa_course-day', '');
		$this->db->add_meta(201, 'attribute_pa_age-group', 'full-day');

		$preview = intersoccer_pv_tool_refresh_attributes(true, ['product_id' => 200]);
		$attr_row = $this->rowById($preview['rows'], 'attrs:201');
		$this->assertIsArray($attr_row);
		$this->assertSame('monday', $attr_row['after']['attributes']['pa_course-day']);
		$this->assertSame('', (string) $this->db->meta_value(201, 'attribute_pa_course-day'));

		$GLOBALS['intersoccer_pv_test_cache_touches'] = [];
		$applied = intersoccer_pv_tool_refresh_attributes(false, ['product_id' => 200]);
		$attr_row = $this->rowById($applied['rows'], 'attrs:201');
		$this->assertSame('monday', (string) $this->db->meta_value(201, 'attribute_pa_course-day'));
		$this->assertTrue($this->cacheTouched('post', 201), 'Refresh Apply must refresh post cache/transients/lookup');

		$GLOBALS['intersoccer_pv_test_cache_touches'] = [];
		$result = intersoccer_pv_repair_rollback_rows(
			['rows' => [$attr_row]],
			'intersoccer_pv_repair_read_value',
			'intersoccer_pv_repair_restore_value'
		);
		$this->assertSame(1, (int) $result['restored'], 'Refresh rollback must pass the after-state conflict check');
		$this->assertSame([], $result['conflicts']);
		$this->assertSame('', (string) $this->db->meta_value(201, 'attribute_pa_course-day'));
		$this->assertTrue($this->cacheTouched('post', 201), 'Refresh rollback must refresh post cache/transients/lookup');
	}

	public function test_refresh_under_admin_language_touches_only_source_and_leaves_icl_unchanged(): void {
		foreach (['en', 'fr', 'all'] as $lang) {
			unset($GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache']);
			if (class_exists('MockMetaData')) {
				MockMetaData::$data = [];
			}
			$this->db = new InterSoccerPvRepairFakeWpdb();
			$GLOBALS['wpdb'] = $this->db;
			if (function_exists('intersoccer_pv_wpml_icl_translations_table_reset_exists_cache')) {
				intersoccer_pv_wpml_icl_translations_table_reset_exists_cache();
			}
			$GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
			$this->seedRefreshCourse(200, 201, 202);
			$this->db->add_meta(201, 'attribute_pa_course-day', '');
			$this->db->add_meta(202, 'attribute_pa_course-day', '');
			$icl_before = intersoccer_pv_repair_icl_snapshot([200, 201, 202]);
			$this->assertNotSame([], $icl_before, $lang);

			$applied = intersoccer_pv_tool_refresh_attributes(false, ['product_id' => 200]);
			$ids = array_map(static function ($row) {
				return (string) ($row['id'] ?? '');
			}, $applied['rows']);
			$this->assertContains('attrs:201', $ids, $lang);
			$this->assertNotContains('attrs:202', $ids, $lang);
			$this->assertContains('manual:attr:202:pa_course-day', $ids, $lang);
			$this->assertSame('monday', (string) $this->db->meta_value(201, 'attribute_pa_course-day'), $lang);
			$this->assertSame('', (string) $this->db->meta_value(202, 'attribute_pa_course-day'), $lang);
			$this->assertSame($icl_before, intersoccer_pv_repair_icl_snapshot([200, 201, 202]), $lang);
		}
	}


	public function test_refresh_needs_manual_omits_unused_course_times_on_camp(): void {
		$this->db->add_post(300, 'product');
		$this->db->add_post(301, 'product_variation', 300);
		$this->db->add_meta(300, '_intersoccer_product_type', 'camp');
		$this->db->add_meta(300, '_product_attributes', [
			'pa_booking-type' => [
				'name' => 'pa_booking-type',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
			'pa_age-group' => [
				'name' => 'pa_age-group',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
			'pa_camp-times' => [
				'name' => 'pa_camp-times',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
		]);
		$this->db->add_meta(301, 'attribute_pa_camp-times', '');
		$this->db->add_icl(41, 'post_product', 300, 800, 'en', '');
		$this->db->add_icl(42, 'post_product_variation', 301, 801, 'en', '');

		$preview = intersoccer_pv_tool_refresh_attributes(true, ['product_id' => 300]);
		$ids = array_map(static function ($row) {
			return (string) ($row['id'] ?? '');
		}, $preview['rows']);
		$this->assertNotContains('manual:attr:301:pa_course-times', $ids);
		$this->assertContains('attrs:301', $ids);
	}

	public function test_venue_apply_aborts_and_restores_when_icl_changes_mid_write(): void {
		$this->seedCampVenueTwin();
		$this->assertIclAbortRestores(
			'repair_venue',
			100,
			102,
			static function () {
				return (string) ($GLOBALS['wpdb']->meta_value(101, 'attribute_pa_intersoccer-venues') ?? '');
			},
			''
		);
	}

	public function test_refresh_apply_aborts_and_restores_when_icl_changes_mid_write(): void {
		$this->seedRefreshCourse(200, 201, 202);
		$this->db->add_meta(201, 'attribute_pa_course-day', '');
		$this->db->add_meta(200, '_product_attributes', [
			'pa_course-day' => [
				'name' => 'pa_course-day',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
		]);
		$this->assertIclAbortRestores(
			'refresh_attributes',
			200,
			202,
			static function () {
				return (string) ($GLOBALS['wpdb']->meta_value(201, 'attribute_pa_course-day') ?? '');
			},
			''
		);
	}

	public function test_partial_slug_resolution_refuses_the_write(): void {
		$this->db->add_term(5, 'zurich', 'pa_intersoccer-venues');
		$this->db->add_term(10, 'nyon', 'pa_intersoccer-venues');
		$this->db->add_rel(100, 10);
		$this->db->add_rel(100, 5);

		intersoccer_pv_repair_write_object_terms(100, 'pa_intersoccer-venues', [], ['zurich', 'ghost']);
		$this->assertSame([5, 10], $this->db->term_ids_for(100, 'pa_intersoccer-venues'), 'Partial slug lists must not drop existing terms');
	}

	public function test_venue_twin_mapping_apply_and_rollback_on_sql_path(): void {
		$this->db->add_post(100, 'product');
		$this->db->add_post(150, 'product');
		$this->db->add_post(101, 'product_variation', 100);
		$this->db->add_post(102, 'product_variation', 150);
		$this->db->add_meta(100, '_intersoccer_product_type', 'camp');
		$this->db->add_meta(100, '_product_attributes', [
			'pa_intersoccer-venues' => [
				'name' => 'pa_intersoccer-venues',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
		]);
		$this->db->add_term(5, 'nyon', 'pa_intersoccer-venues', 50);
		$this->db->add_term(15, 'nyon-fr', 'pa_intersoccer-venues', 115);
		$this->db->add_rel(100, 5);
		$this->db->add_icl(21, 'post_product', 100, 500, 'en', '');
		$this->db->add_icl(22, 'post_product', 150, 500, 'fr', 'en');
		$this->db->add_icl(23, 'post_product_variation', 101, 600, 'en', '');
		$this->db->add_icl(24, 'post_product_variation', 102, 600, 'fr', 'en');
		$this->db->add_icl(31, 'tax_pa_intersoccer-venues', 50, 700, 'en', '');
		$this->db->add_icl(32, 'tax_pa_intersoccer-venues', 115, 700, 'fr', 'en');

		$mapped = intersoccer_pv_repair_map_term_for_language(5, 'pa_intersoccer-venues', 'fr');
		$this->assertIsArray($mapped);

		$this->assertSame(15, (int) $mapped['term_id']);
		$this->assertSame('nyon-fr', $mapped['slug']);

		$GLOBALS['intersoccer_pv_test_cache_touches'] = [];
		$applied = intersoccer_pv_tool_repair_venue(false, ['product_id' => 100]);
		$attr_row = $this->rowById($applied['rows'], 'attrs:101');
		$this->assertIsArray($attr_row);
		$this->assertSame('nyon', (string) $this->db->meta_value(101, 'attribute_pa_intersoccer-venues'));
		$this->assertSame([5], $this->db->term_ids_for(101, 'pa_intersoccer-venues'));
		$this->assertSame('nyon-fr', (string) $this->db->meta_value(102, 'attribute_pa_intersoccer-venues'));
		$this->assertSame([15], $this->db->term_ids_for(102, 'pa_intersoccer-venues'));
		$this->assertTrue($this->cacheTouched('post', 101));
		$this->assertTrue($this->cacheTouched('post', 102));

		$GLOBALS['intersoccer_pv_test_cache_touches'] = [];
		$result = intersoccer_pv_repair_rollback_rows(
			['rows' => $applied['rows']],
			'intersoccer_pv_repair_read_value',
			'intersoccer_pv_repair_restore_value'
		);
		$this->assertSame([], $result['conflicts'], 'Venue twin rollback must pass the after-state conflict check');
		$this->assertGreaterThanOrEqual(1, (int) $result['restored']);
		$this->assertSame('', (string) ($this->db->meta_value(101, 'attribute_pa_intersoccer-venues') ?? ''));
		$this->assertSame([], $this->db->term_ids_for(101, 'pa_intersoccer-venues'));
		$this->assertSame('', (string) ($this->db->meta_value(102, 'attribute_pa_intersoccer-venues') ?? ''));
		$this->assertSame([], $this->db->term_ids_for(102, 'pa_intersoccer-venues'));
		$this->assertTrue($this->cacheTouched('post', 101), 'Venue rollback must refresh post cache/transients/lookup');
	}


	private function seedCampVenueTwin(): void {
		$this->db->add_post(100, 'product');
		$this->db->add_post(150, 'product');
		$this->db->add_post(101, 'product_variation', 100);
		$this->db->add_post(102, 'product_variation', 150);
		$this->db->add_meta(100, '_intersoccer_product_type', 'camp');
		$this->db->add_meta(100, '_product_attributes', [
			'pa_intersoccer-venues' => [
				'name' => 'pa_intersoccer-venues',
				'value' => '',
				'is_visible' => 1,
				'is_variation' => 1,
				'is_taxonomy' => 1,
			],
		]);
		$this->db->add_term(5, 'nyon', 'pa_intersoccer-venues', 50);
		$this->db->add_term(15, 'nyon-fr', 'pa_intersoccer-venues', 115);
		$this->db->add_rel(100, 5);
		$this->db->add_icl(21, 'post_product', 100, 500, 'en', '');
		$this->db->add_icl(22, 'post_product', 150, 500, 'fr', 'en');
		$this->db->add_icl(23, 'post_product_variation', 101, 600, 'en', '');
		$this->db->add_icl(24, 'post_product_variation', 102, 600, 'fr', 'en');
		$this->db->add_icl(31, 'tax_pa_intersoccer-venues', 50, 700, 'en', '');
		$this->db->add_icl(32, 'tax_pa_intersoccer-venues', 115, 700, 'fr', 'en');
	}

	private function assertIclAbortRestores($tool, $product_id, $twin_id, $read_written, $restored_value): void {
		$executor = $tool === 'repair_venue' ? 'intersoccer_pv_tool_repair_venue' : 'intersoccer_pv_tool_refresh_attributes';
		$preview = $executor(true, ['product_id' => $product_id]);
		$this->assertNotSame([], $preview['rows']);
		// Warm the per-request lookup cache the way a real Apply does.
		intersoccer_pv_repair_icl_rows_all();
		$icl_before = intersoccer_pv_repair_icl_snapshot(intersoccer_pv_repair_element_ids_from_rows($preview['rows']));
		$this->assertNotSame([], $icl_before);

		$twin_id = (int) $twin_id;
		$GLOBALS['intersoccer_pv_test_after_write'] = static function () use ($twin_id) {
			foreach ($GLOBALS['wpdb']->icl as $index => $row) {
				if ((int) ($row['element_id'] ?? 0) === $twin_id && ($row['element_type'] ?? '') === 'post_product_variation') {
					$GLOBALS['wpdb']->icl[$index]['source_language_code'] = null;
				}
			}
		};

		$executor(false, ['product_id' => $product_id]);
		$aborted = false;
		$message = '';
		try {
			intersoccer_pv_repair_abort_if_icl_changed($icl_before, $preview['rows'], ['rows' => $preview['rows']]);
		} catch (RuntimeException $e) {
			$aborted = true;
			$message = $e->getMessage();
		}
		unset($GLOBALS['intersoccer_pv_test_after_write']);
		$this->assertTrue($aborted, $tool . ' must abort when icl changes mid-Apply');
		$this->assertStringContainsString('translation records changed', $message);
		$this->assertStringContainsString('All writes were undone', $message);
		$this->assertSame($restored_value, $read_written(), $tool . ' writes must be rolled back');
		$this->assertSame($icl_before, intersoccer_pv_repair_icl_snapshot(intersoccer_pv_repair_element_ids_from_rows($preview['rows'])), $tool . ' icl must be restored');
	}

	private function cacheTouched($kind, $post_id): bool {
		$kind = (string) $kind;
		$post_id = (int) $post_id;
		foreach ($GLOBALS['intersoccer_pv_test_cache_touches'] ?? [] as $entry) {
			if (!is_array($entry) || ($entry[0] ?? '') !== $kind) {
				continue;
			}
			if ((int) ($entry[1] ?? 0) === $post_id) {
				return true;
			}
		}
		return false;
	}

	private function seedRefreshCourse($parent_id, $source_variation, $twin_variation) {

		$this->db->add_post($parent_id, 'product');
		$this->db->add_post($source_variation, 'product_variation', $parent_id);
		$this->db->add_meta($parent_id, '_intersoccer_product_type', 'course');
		$this->db->add_meta($source_variation, '_course_start_date', '2099-09-01');
		$this->db->add_icl(11, 'post_product', $parent_id, 500, 'en', '');
		$this->db->add_icl(12, 'post_product', $parent_id + 50, 500, 'fr', 'en');
		$this->db->add_icl(13, 'post_product_variation', $source_variation, 600, 'en', '');
		if ($twin_variation > 0) {
			$this->db->add_post($twin_variation, 'product_variation', $parent_id + 50);
			$this->db->add_icl(14, 'post_product_variation', $twin_variation, 600, 'fr', 'en');
		}
	}

	private function rowById(array $rows, $id) {
		foreach ($rows as $row) {
			if ((string) ($row['id'] ?? '') === (string) $id) {
				return $row;
			}
		}
		return null;
	}
}
