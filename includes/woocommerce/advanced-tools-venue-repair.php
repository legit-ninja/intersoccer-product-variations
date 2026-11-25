<?php
/**
 * Language-bar-independent venue / camp-term repair for Advanced tools.
 *
 * Preview, Apply, and rollback resolve the English source and each twin from
 * icl_translations by element ID (raw SQL or the test store). Writes go to
 * post meta and term relationships by post ID and term ID and never call
 * WC/WCML product save or change icl_translations.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('intersoccer_pv_repair_default_language')) {
	/**
	 * Default catalogue language (not the admin language bar).
	 *
	 * @return string
	 */
	function intersoccer_pv_repair_default_language() {
		if (!empty($GLOBALS['intersoccer_test_wpml_default_language'])) {
			return (string) $GLOBALS['intersoccer_test_wpml_default_language'];
		}
		$lang = 'en';
		if (function_exists('apply_filters')) {
			$filtered = apply_filters('wpml_default_language', $lang);
			if (is_string($filtered) && $filtered !== '' && strtolower($filtered) !== 'all') {
				$lang = $filtered;
			}
		}
		return $lang;
	}
}

if (!function_exists('intersoccer_pv_repair_icl_rows_all')) {
	/**
	 * All icl_translations rows. Lookups may reuse a per-request SQL cache;
	 * snapshots pass $fresh so the after-Apply guard sees the live table.
	 *
	 * @param bool $fresh When true, skip the per-request SQL cache and reread.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_repair_icl_rows_all($fresh = false) {
		if (isset($GLOBALS['intersoccer_test_icl_translations']) && is_array($GLOBALS['intersoccer_test_icl_translations'])) {
			return array_values($GLOBALS['intersoccer_test_icl_translations']);
		}
		if (!$fresh && isset($GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache']) && is_array($GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'])) {
			return $GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'];
		}
		global $wpdb;
		if (!$wpdb || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
			$GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'] = [];
			return [];
		}
		if (!function_exists('intersoccer_pv_wpml_icl_translations_table_exists') || !intersoccer_pv_wpml_icl_translations_table_exists()) {
			$GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'] = [];
			return [];
		}
		$table = $wpdb->prefix . 'icl_translations';
		$rows = $wpdb->get_results("SELECT translation_id, element_type, element_id, trid, language_code, source_language_code FROM {$table}", ARRAY_A);
		$GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'] = is_array($rows) ? $rows : [];
		return $GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache'];
	}
}

if (!function_exists('intersoccer_pv_repair_icl_rows_clear_cache')) {
	/**
	 * Drop the per-request SQL cache of icl_translations (after a restore).
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_icl_rows_clear_cache() {
		unset($GLOBALS['intersoccer_pv_repair_icl_rows_sql_cache']);
	}
}


if (!function_exists('intersoccer_pv_repair_abort_if_icl_changed')) {
	/**
	 * Compare a fresh after-Apply icl snapshot to the pre-Apply snapshot.
	 * On a mismatch, roll back writes and restore the snapshot.
	 *
	 * @param array<int,array<string,string>>|null $icl_before Pre-Apply snapshot.
	 * @param array<int,array<string,mixed>>       $rows       Apply rows.
	 * @param mixed                                $backup     Backup filename or payload.
	 * @return void
	 */
	function intersoccer_pv_repair_abort_if_icl_changed($icl_before, array $rows, $backup = null) {
		if ($icl_before === null || !function_exists('intersoccer_pv_repair_icl_snapshot')) {
			return;
		}
		$ids = function_exists('intersoccer_pv_repair_element_ids_from_rows')
			? intersoccer_pv_repair_element_ids_from_rows($rows)
			: [];
		$icl_after = intersoccer_pv_repair_icl_snapshot($ids);
		if ($icl_after === $icl_before) {
			return;
		}
		$had_conflicts = false;
		if (function_exists('intersoccer_pv_repair_rollback_rows')) {
			$payload = null;
			if (is_array($backup)) {
				$payload = $backup;
			} elseif (is_string($backup) && $backup !== '' && function_exists('intersoccer_pv_repair_read_backup')) {
				$payload = intersoccer_pv_repair_read_backup($backup);
			}
			if (!is_array($payload)) {
				$payload = ['rows' => $rows];
			}
			$rolled = intersoccer_pv_repair_rollback_rows($payload, 'intersoccer_pv_repair_read_value', 'intersoccer_pv_repair_restore_value');
			$had_conflicts = is_array($rolled) && !empty($rolled['conflicts']);
		}
		if (function_exists('intersoccer_pv_repair_icl_restore')) {
			intersoccer_pv_repair_icl_restore($icl_before);
		}
		if ($had_conflicts) {
			throw new RuntimeException(__('Apply was aborted because translation records changed. Writes that could be rolled back were undone; conflicting rows were left as they are.', 'intersoccer-product-variations'));
		}
		throw new RuntimeException(__('Apply was aborted because translation records changed. All writes were undone.', 'intersoccer-product-variations'));
	}
}

if (!function_exists('intersoccer_pv_repair_icl_normalize_row')) {
	/**
	 * @param array<string,mixed> $row Raw icl row.
	 * @return array<string,string>
	 */
	function intersoccer_pv_repair_icl_normalize_row(array $row) {
		return [
			'translation_id' => (string) ($row['translation_id'] ?? ''),
			'element_type' => (string) ($row['element_type'] ?? ''),
			'element_id' => (string) (int) ($row['element_id'] ?? 0),
			'trid' => (string) (int) ($row['trid'] ?? 0),
			'language_code' => (string) ($row['language_code'] ?? ''),
			'source_language_code' => (string) ($row['source_language_code'] ?? ''),
		];
	}
}

if (!function_exists('intersoccer_pv_repair_icl_group')) {
	/**
	 * Translation group for one post, from raw icl rows (never current-language filters).
	 *
	 * @param int          $element_id Post ID.
	 * @param array<int,string> $element_types icl element_type values.
	 * @return array<int,array<string,string>>
	 */
	function intersoccer_pv_repair_icl_group($element_id, array $element_types = ['post_product', 'post_product_variation']) {
		$element_id = (int) $element_id;
		if ($element_id <= 0) {
			return [];
		}
		$types = [];
		foreach ($element_types as $type) {
			$type = (string) $type;
			if ($type !== '') {
				$types[$type] = true;
			}
		}
		$all = intersoccer_pv_repair_icl_rows_all();
		$trids = [];
		foreach ($all as $row) {
			$row = intersoccer_pv_repair_icl_normalize_row(is_array($row) ? $row : []);
			if ((int) $row['element_id'] === $element_id && isset($types[$row['element_type']])) {
				$trids[(int) $row['trid']] = true;
			}
		}
		if ($trids === []) {
			return [];
		}
		$group = [];
		foreach ($all as $row) {
			$row = intersoccer_pv_repair_icl_normalize_row(is_array($row) ? $row : []);
			if (isset($trids[(int) $row['trid']]) && isset($types[$row['element_type']])) {
				$group[] = $row;
			}
		}
		usort(
			$group,
			static function ($left, $right) {
				$a = [(int) $left['trid'], (int) $left['element_id'], (int) $left['translation_id']];
				$b = [(int) $right['trid'], (int) $right['element_id'], (int) $right['translation_id']];
				return $a <=> $b;
			}
		);
		return $group;
	}
}

if (!function_exists('intersoccer_pv_repair_icl_source_and_twins')) {
	/**
	 * @param int               $element_id Post ID.
	 * @param array<int,string> $element_types icl element types.
	 * @return array{source_id:int,source_lang:string,twins:array<int,array{id:int,lang:string}>,group:array}
	 */
	function intersoccer_pv_repair_icl_source_and_twins($element_id, array $element_types = ['post_product', 'post_product_variation']) {
		$element_id = (int) $element_id;
		$group = intersoccer_pv_repair_icl_group($element_id, $element_types);
		$default = intersoccer_pv_repair_default_language();
		$source = null;
		foreach ($group as $row) {
			$src = trim((string) $row['source_language_code']);
			if ($src === '' || $src === '0') {
				$source = $row;
				break;
			}
		}
		if ($source === null) {
			foreach ($group as $row) {
				if ((string) $row['language_code'] === $default) {
					$source = $row;
					break;
				}
			}
		}
		if ($source === null && $group !== []) {
			$source = $group[0];
		}
		$source_id = $source ? (int) $source['element_id'] : $element_id;
		$source_lang = $source ? (string) $source['language_code'] : $default;
		$twins = [];
		foreach ($group as $row) {
			$tid = (int) $row['element_id'];
			if ($tid <= 0 || $tid === $source_id) {
				continue;
			}
			$twins[] = [
				'id' => $tid,
				'lang' => (string) $row['language_code'],
			];
		}
		return [
			'source_id' => $source_id,
			'source_lang' => $source_lang !== '' ? $source_lang : $default,
			'twins' => $twins,
			'group' => $group,
		];
	}
}

if (!function_exists('intersoccer_pv_repair_icl_snapshot')) {
	/**
	 * Byte-comparable icl_translations rows for the trids of the given post IDs.
	 *
	 * @param array<int,int> $element_ids Post IDs.
	 * @return array<int,array<string,string>>
	 */
	function intersoccer_pv_repair_icl_snapshot(array $element_ids) {
		$ids = [];
		foreach ($element_ids as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[$id] = true;
			}
		}
		if ($ids === []) {
			return [];
		}
		$types = ['post_product' => true, 'post_product_variation' => true];
		$all = intersoccer_pv_repair_icl_rows_all(true);
		$trids = [];
		foreach ($all as $row) {
			$row = intersoccer_pv_repair_icl_normalize_row(is_array($row) ? $row : []);
			if (isset($ids[(int) $row['element_id']]) && isset($types[$row['element_type']])) {
				$trids[(int) $row['trid']] = true;
			}
		}
		$out = [];
		foreach ($all as $row) {
			$row = intersoccer_pv_repair_icl_normalize_row(is_array($row) ? $row : []);
			if (isset($trids[(int) $row['trid']]) && isset($types[$row['element_type']])) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static function ($left, $right) {
				$a = [(int) $left['trid'], (int) $left['element_id'], (int) $left['translation_id']];
				$b = [(int) $right['trid'], (int) $right['element_id'], (int) $right['translation_id']];
				return $a <=> $b;
			}
		);
		return $out;
	}
}

if (!function_exists('intersoccer_pv_repair_icl_restore')) {
	/**
	 * Restore a previous icl_translations snapshot (abort path only).
	 *
	 * @param array<int,array<string,string>> $snapshot Snapshot rows.
	 * @return void
	 */
	function intersoccer_pv_repair_icl_restore(array $snapshot) {
		$normalized = [];
		foreach ($snapshot as $row) {
			if (is_array($row)) {
				$normalized[] = intersoccer_pv_repair_icl_normalize_row($row);
			}
		}
		$keep_types = ['post_product' => true, 'post_product_variation' => true];
		$trids = [];
		$translation_ids = [];
		$element_ids = [];
		foreach ($normalized as $row) {
			$trid = (int) $row['trid'];
			$translation_id = (int) $row['translation_id'];
			$element_id = (int) $row['element_id'];
			if ($trid > 0) {
				$trids[$trid] = true;
			}
			if ($translation_id > 0) {
				$translation_ids[$translation_id] = true;
			}
			if ($element_id > 0) {
				$element_ids[$element_id] = true;
			}
		}
		$should_drop = static function (array $row) use ($keep_types, $trids, $translation_ids, $element_ids) {
			if (!isset($keep_types[$row['element_type']])) {
				return false;
			}
			return isset($trids[(int) $row['trid']])
				|| isset($translation_ids[(int) $row['translation_id']])
				|| isset($element_ids[(int) $row['element_id']]);
		};
		if (isset($GLOBALS['intersoccer_test_icl_translations']) && is_array($GLOBALS['intersoccer_test_icl_translations'])) {
			$kept = [];
			foreach ($GLOBALS['intersoccer_test_icl_translations'] as $row) {
				$row = intersoccer_pv_repair_icl_normalize_row(is_array($row) ? $row : []);
				if (!$should_drop($row)) {
					$kept[] = $row;
				}
			}
			$GLOBALS['intersoccer_test_icl_translations'] = array_merge($kept, $normalized);
			if (function_exists('intersoccer_pv_repair_icl_rows_clear_cache')) {
				intersoccer_pv_repair_icl_rows_clear_cache();
			}
			return;
		}
		global $wpdb;
		if (!$wpdb || !is_object($wpdb) || !method_exists($wpdb, 'query') || !function_exists('intersoccer_pv_wpml_icl_translations_table_exists') || !intersoccer_pv_wpml_icl_translations_table_exists()) {
			return;
		}
		$table = $wpdb->prefix . 'icl_translations';
		$clauses = [];
		if ($translation_ids !== []) {
			$clauses[] = 'translation_id IN (' . implode(',', array_map('intval', array_keys($translation_ids))) . ')';
		}
		if ($element_ids !== []) {
			$clauses[] = 'element_id IN (' . implode(',', array_map('intval', array_keys($element_ids))) . ')';
		}
		if ($trids !== []) {
			$clauses[] = 'trid IN (' . implode(',', array_map('intval', array_keys($trids))) . ')';
		}
		if ($clauses !== []) {
			$wpdb->query("DELETE FROM {$table} WHERE element_type IN ('post_product','post_product_variation') AND (" . implode(' OR ', $clauses) . ")");
		}
		foreach ($normalized as $row) {
			$wpdb->insert(
				$table,
				[
					'translation_id' => (int) $row['translation_id'],
					'element_type' => $row['element_type'],
					'element_id' => (int) $row['element_id'],
					'trid' => (int) $row['trid'],
					'language_code' => $row['language_code'],
					'source_language_code' => $row['source_language_code'] === '' ? null : $row['source_language_code'],
				]
			);
		}
		if (function_exists('intersoccer_pv_repair_icl_rows_clear_cache')) {
			intersoccer_pv_repair_icl_rows_clear_cache();
		}
	}
}

if (!function_exists('intersoccer_pv_repair_raw_post_meta')) {
	/**
	 * Single-key post meta, never get_post_meta($id) with no key (WCML filters that).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return mixed
	 */
	function intersoccer_pv_repair_raw_post_meta($post_id, $key) {
		$post_id = (int) $post_id;
		$key = (string) $key;
		if ($post_id <= 0 || $key === '') {
			return '';
		}
		if (class_exists('MockMetaData') && isset(MockMetaData::$data[$post_id][$key])) {
			return MockMetaData::$data[$post_id][$key];
		}
		global $wpdb;
		if ($wpdb && is_object($wpdb) && isset($wpdb->postmeta) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare') && empty($GLOBALS['intersoccer_test_products'])) {
			$value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $post_id, $key));
			if ($value !== null) {
				if (is_string($value) && isset($value[0]) && ($value[0] === 'a' || $value[0] === 's' || $value[0] === 'O') && function_exists('is_serialized') && is_serialized($value)) {
					$un = @unserialize($value);
					return $un === false && $value !== 'b:0;' ? $value : $un;
				}
				return $value;
			}
		}
		if (function_exists('get_post_meta')) {
			return get_post_meta($post_id, $key, true);
		}
		return '';
	}
}

if (!function_exists('intersoccer_pv_repair_raw_meta_keys')) {
	/**
	 * Attribute meta keys for a variation, discovered without get_post_meta($id).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,string>
	 */
	function intersoccer_pv_repair_raw_meta_keys($post_id) {
		$post_id = (int) $post_id;
		$keys = [];
		if ($post_id <= 0) {
			return $keys;
		}
		if (class_exists('MockMetaData') && isset(MockMetaData::$data[$post_id]) && is_array(MockMetaData::$data[$post_id])) {
			foreach (array_keys(MockMetaData::$data[$post_id]) as $key) {
				$key = (string) $key;
				if (strpos($key, 'attribute_') === 0) {
					$keys[] = $key;
				}
			}
			return $keys;
		}
		global $wpdb;
		if ($wpdb && is_object($wpdb) && isset($wpdb->postmeta) && method_exists($wpdb, 'get_col') && method_exists($wpdb, 'prepare')) {
			$found = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like('attribute_') . '%'));
			if (is_array($found)) {
				foreach ($found as $key) {
					$keys[] = (string) $key;
				}
			}
		}
		return $keys;
	}
}


if (!function_exists('intersoccer_pv_repair_product_used_attribute_taxonomies')) {
	/**
	 * Attribute taxonomies the parent or variation actually uses (not a padded known list).
	 *
	 * @param int $parent_id    Parent product ID.
	 * @param int $variation_id Variation ID.
	 * @return array<string,bool>
	 */
	function intersoccer_pv_repair_product_used_attribute_taxonomies($parent_id, $variation_id) {
		$used = [];
		$add = static function ($key) use (&$used) {
			$key = (string) $key;
			if (strpos($key, 'attribute_') === 0) {
				$key = substr($key, 10);
			}
			if ($key !== '' && strpos($key, 'pa_') === 0) {
				$used[$key] = true;
			}
		};
		$variation_id = (int) $variation_id;
		if ($variation_id > 0 && function_exists('intersoccer_pv_repair_raw_meta_keys')) {
			foreach (intersoccer_pv_repair_raw_meta_keys($variation_id) as $key) {
				$add($key);
			}
		}
		$parent_id = (int) $parent_id;
		if ($parent_id > 0 && function_exists('intersoccer_pv_repair_raw_post_meta')) {
			$raw = intersoccer_pv_repair_raw_post_meta($parent_id, '_product_attributes');
			if (is_string($raw) && $raw !== '' && function_exists('maybe_unserialize')) {
				$raw = maybe_unserialize($raw);
			}
			if (is_array($raw)) {
				foreach (array_keys($raw) as $key) {
					$add($key);
				}
			}
		}
		foreach ([$parent_id, $variation_id] as $object_id) {
			$object_id = (int) $object_id;
			$attrs = [];
			if ($object_id > 0 && isset($GLOBALS['intersoccer_test_products'][$object_id]) && is_object($GLOBALS['intersoccer_test_products'][$object_id]) && method_exists($GLOBALS['intersoccer_test_products'][$object_id], 'get_attributes')) {
				$attrs = $GLOBALS['intersoccer_test_products'][$object_id]->get_attributes();
			} elseif ($object_id > 0 && function_exists('wc_get_product')) {
				$product = wc_get_product($object_id);
				if ($product && method_exists($product, 'get_attributes')) {
					$attrs = $product->get_attributes();
				}
			}
			if (is_array($attrs)) {
				foreach (array_keys($attrs) as $key) {
					$add($key);
				}
			}
		}
		return $used;
	}
}

if (!function_exists('intersoccer_pv_repair_raw_variation_attributes')) {
	/**
	 * Variation attribute values from single-key raw meta (not WCML all-meta reads).
	 *
	 * @param int $variation_id Variation ID.
	 * @return array<string,string>
	 */
	function intersoccer_pv_repair_raw_variation_attributes($variation_id) {
		$variation_id = (int) $variation_id;
		$attrs = [];
		if ($variation_id <= 0) {
			return $attrs;
		}
		$keys = intersoccer_pv_repair_raw_meta_keys($variation_id);
		$known = ['attribute_pa_camp-terms', 'attribute_pa_intersoccer-venues', 'attribute_pa_camp-times', 'attribute_pa_age-group', 'attribute_pa_booking-type', 'attribute_pa_course-times'];
		foreach ($known as $key) {
			if (!in_array($key, $keys, true)) {
				$keys[] = $key;
			}
		}
		foreach ($keys as $key) {
			if (strpos($key, 'attribute_') !== 0) {
				continue;
			}
			$value = intersoccer_pv_repair_raw_post_meta($variation_id, $key);
			if (is_array($value)) {
				$value = (string) ($value[0] ?? '');
			}
			$attrs[substr($key, 10)] = (string) $value;
		}
		ksort($attrs);
		return $attrs;
	}
}

if (!function_exists('intersoccer_pv_repair_raw_term_records')) {
	/**
	 * Term IDs and slugs for an object, kept as aligned (id, slug) pairs.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return array{ids:array<int,int>,slugs:array<int,string>,pairs:array<int,array{id:int,slug:string}>}
	 */
	function intersoccer_pv_repair_raw_term_records($object_id, $taxonomy) {
		$object_id = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		$empty = ['ids' => [], 'slugs' => [], 'pairs' => []];
		if ($object_id <= 0 || $taxonomy === '') {
			return $empty;
		}
		$pairs = [];
		$push = static function ($term_id, $slug) use (&$pairs) {
			$term_id = (int) $term_id;
			$slug = (string) $slug;
			if ($term_id <= 0 || $slug === '') {
				return;
			}
			foreach ($pairs as $existing) {
				if ((int) $existing['id'] === $term_id) {
					return;
				}
			}
			$pairs[] = ['id' => $term_id, 'slug' => $slug];
		};
		if (isset($GLOBALS['intersoccer_test_product_term_ids'][$object_id][$taxonomy]) || isset($GLOBALS['intersoccer_test_product_terms'][$object_id][$taxonomy]) || isset($GLOBALS['intersoccer_test_terms'])) {
			$ids = isset($GLOBALS['intersoccer_test_product_term_ids'][$object_id][$taxonomy])
				? array_values(array_map('intval', (array) $GLOBALS['intersoccer_test_product_term_ids'][$object_id][$taxonomy]))
				: [];
			$slugs = isset($GLOBALS['intersoccer_test_product_terms'][$object_id][$taxonomy])
				? array_values(array_map('strval', (array) $GLOBALS['intersoccer_test_product_terms'][$object_id][$taxonomy]))
				: [];
			if ($ids !== []) {
				foreach ($ids as $index => $term_id) {
					$slug = '';
					if (!empty($GLOBALS['intersoccer_test_terms'][$term_id]['slug'])) {
						$slug = (string) $GLOBALS['intersoccer_test_terms'][$term_id]['slug'];
					} elseif (isset($slugs[$index])) {
						$slug = (string) $slugs[$index];
					}
					$push($term_id, $slug);
				}
			} elseif ($slugs !== []) {
				foreach ($slugs as $slug) {
					$term_id = 0;
					if (!empty($GLOBALS['intersoccer_test_terms']) && is_array($GLOBALS['intersoccer_test_terms'])) {
						foreach ($GLOBALS['intersoccer_test_terms'] as $candidate => $row) {
							if (($row['taxonomy'] ?? '') === $taxonomy && (($row['slug'] ?? '') === $slug || ($row['name'] ?? '') === $slug)) {
								$term_id = (int) $candidate;
								break;
							}
						}
					}
					$push($term_id, $slug);
				}
			}
			return intersoccer_pv_repair_term_records_from_pairs($pairs);
		}
		global $wpdb;
		if (!$wpdb || !is_object($wpdb) || !method_exists($wpdb, 'get_results') || !isset($wpdb->term_relationships)) {
			return $empty;
		}
		$terms_table = $wpdb->terms;
		$tt_table = $wpdb->term_taxonomy;
		$tr_table = $wpdb->term_relationships;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT t.term_id, t.slug FROM {$tr_table} tr
			INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
			WHERE tr.object_id = %d AND tt.taxonomy = %s",
			$object_id,
			$taxonomy
		), ARRAY_A);
		foreach ((array) $rows as $row) {
			$push((int) ($row['term_id'] ?? 0), (string) ($row['slug'] ?? ''));
		}
		return intersoccer_pv_repair_term_records_from_pairs($pairs);
	}
}

if (!function_exists('intersoccer_pv_repair_term_records_from_pairs')) {
	/**
	 * @param array<int,array{id:int,slug:string}> $pairs Aligned pairs.
	 * @return array{ids:array<int,int>,slugs:array<int,string>,pairs:array<int,array{id:int,slug:string}>}
	 */
	function intersoccer_pv_repair_term_records_from_pairs(array $pairs) {
		usort(
			$pairs,
			static function ($left, $right) {
				return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
			}
		);
		$ids = [];
		$slugs = [];
		foreach ($pairs as $pair) {
			$ids[] = (int) $pair['id'];
			$slugs[] = (string) $pair['slug'];
		}
		return ['ids' => $ids, 'slugs' => $slugs, 'pairs' => $pairs];
	}
}

if (!function_exists('intersoccer_pv_repair_map_term_for_language')) {
	/**
	 * Map a source term ID onto a language using raw icl term rows (term_taxonomy_id).
	 *
	 * @param int    $term_id  Source term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Target language.
	 * @return array{term_id:int,slug:string}|null
	 */
	function intersoccer_pv_repair_map_term_for_language($term_id, $taxonomy, $lang) {
		$term_id = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		$lang = trim((string) $lang);
		if ($term_id <= 0 || $taxonomy === '' || $lang === '' || strtolower($lang) === 'all') {
			return null;
		}
		if (!empty($GLOBALS['intersoccer_test_icl_term_translations']) && is_array($GLOBALS['intersoccer_test_icl_term_translations'])) {
			$trid = 0;
			foreach ($GLOBALS['intersoccer_test_icl_term_translations'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				if ((string) ($row['taxonomy'] ?? '') === $taxonomy && (int) ($row['term_id'] ?? 0) === $term_id) {
					$trid = (int) ($row['trid'] ?? 0);
					break;
				}
			}
			if ($trid > 0) {
				foreach ($GLOBALS['intersoccer_test_icl_term_translations'] as $row) {
					if (!is_array($row) || (int) ($row['trid'] ?? 0) !== $trid || (string) ($row['taxonomy'] ?? '') !== $taxonomy) {
						continue;
					}
					if ((string) ($row['language_code'] ?? '') !== $lang) {
						continue;
					}
					$mapped_id = (int) ($row['term_id'] ?? 0);
					if ($mapped_id <= 0) {
						return null;
					}
					$slug = '';
					if (!empty($GLOBALS['intersoccer_test_terms'][$mapped_id]['slug'])) {
						$slug = (string) $GLOBALS['intersoccer_test_terms'][$mapped_id]['slug'];
					} elseif (!empty($row['slug'])) {
						$slug = (string) $row['slug'];
					}
					return ['term_id' => $mapped_id, 'slug' => $slug];
				}
				return null;
			}
		}
		if (!empty($GLOBALS['intersoccer_test_term_translation_ids']) && is_array($GLOBALS['intersoccer_test_term_translation_ids'])) {
			$source_slug = !empty($GLOBALS['intersoccer_test_terms'][$term_id]['slug'])
				? (string) $GLOBALS['intersoccer_test_terms'][$term_id]['slug']
				: '';
			if ($source_slug !== '' && isset($GLOBALS['intersoccer_test_term_translation_ids'][$taxonomy][$source_slug][$lang])) {
				$mapped_id = (int) $GLOBALS['intersoccer_test_term_translation_ids'][$taxonomy][$source_slug][$lang];
				$slug = $source_slug;
				if ($mapped_id > 0 && !empty($GLOBALS['intersoccer_test_terms'][$mapped_id]['slug'])) {
					$slug = (string) $GLOBALS['intersoccer_test_terms'][$mapped_id]['slug'];
				} elseif (isset($GLOBALS['intersoccer_test_term_translations'][$taxonomy][$source_slug][$lang])) {
					$slug = (string) $GLOBALS['intersoccer_test_term_translations'][$taxonomy][$source_slug][$lang];
				}
				if ($mapped_id > 0) {
					return ['term_id' => $mapped_id, 'slug' => $slug];
				}
			}
		}
		global $wpdb;
		if (!$wpdb || !is_object($wpdb) || !method_exists($wpdb, 'get_row') || !function_exists('intersoccer_pv_wpml_icl_translations_table_exists') || !intersoccer_pv_wpml_icl_translations_table_exists()) {
			return null;
		}
		$icl = $wpdb->prefix . 'icl_translations';
		$tt = $wpdb->term_taxonomy;
		$terms = $wpdb->terms;
		$element_type = 'tax_' . $taxonomy;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT t.term_id, t.slug
			FROM {$icl} src
			INNER JOIN {$tt} src_tt ON src_tt.term_taxonomy_id = src.element_id AND src_tt.taxonomy = %s
			INNER JOIN {$icl} dest ON dest.trid = src.trid AND dest.element_type = %s AND dest.language_code = %s
			INNER JOIN {$tt} dest_tt ON dest_tt.term_taxonomy_id = dest.element_id AND dest_tt.taxonomy = %s
			INNER JOIN {$terms} t ON t.term_id = dest_tt.term_id
			WHERE src.element_type = %s AND src_tt.term_id = %d
			LIMIT 1",
			$taxonomy,
			$element_type,
			$lang,
			$taxonomy,
			$element_type,
			$term_id
		), ARRAY_A);
		if (!$row || empty($row['term_id'])) {
			return null;
		}
		return [
			'term_id' => (int) $row['term_id'],
			'slug' => (string) ($row['slug'] ?? ''),
		];
	}
}

if (!function_exists('intersoccer_pv_repair_resolve_term_ids')) {
	/**
	 * Resolve taxonomy term IDs from explicit IDs or slug+taxonomy (raw SQL or the test store).
	 *
	 * @param string             $taxonomy     Taxonomy.
	 * @param array<int,string>  $slugs        Slugs.
	 * @param array<int,int>     $explicit_ids Optional known IDs.
	 * @return array<int,int>
	 */
	function intersoccer_pv_repair_resolve_term_ids($taxonomy, array $slugs, array $explicit_ids = []) {
		$taxonomy = (string) $taxonomy;
		$explicit = array_values(array_unique(array_filter(array_map('intval', $explicit_ids))));
		$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs), static function ($slug) {
			return $slug !== '';
		})));
		if ($taxonomy === '' || $slugs === []) {
			return $explicit;
		}

		$slug_of = [];
		if (!empty($GLOBALS['intersoccer_test_terms']) && is_array($GLOBALS['intersoccer_test_terms'])) {
			foreach ($explicit as $term_id) {
				$row = $GLOBALS['intersoccer_test_terms'][$term_id] ?? null;
				if (is_array($row) && ($row['taxonomy'] ?? '') === $taxonomy) {
					$slug_of[$term_id] = (string) ($row['slug'] ?? '');
				}
			}
		} else {
			global $wpdb;
			if ($wpdb && is_object($wpdb) && isset($wpdb->terms, $wpdb->term_taxonomy) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'prepare')) {
				foreach ($explicit as $term_id) {
					$slug = $wpdb->get_var($wpdb->prepare(
						"SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s LIMIT 1",
						$term_id,
						$taxonomy
					));
					if (is_string($slug) && $slug !== '') {
						$slug_of[$term_id] = $slug;
					}
				}
			}
		}

		$lookup_slug = static function ($slug) use ($taxonomy) {
			if (!empty($GLOBALS['intersoccer_test_terms']) && is_array($GLOBALS['intersoccer_test_terms'])) {
				foreach ($GLOBALS['intersoccer_test_terms'] as $term_id => $row) {
					if (!is_array($row) || ($row['taxonomy'] ?? '') !== $taxonomy) {
						continue;
					}
					if (($row['slug'] ?? '') === $slug || ($row['name'] ?? '') === $slug) {
						return (int) $term_id;
					}
				}
				return 0;
			}
			global $wpdb;
			if (!$wpdb || !is_object($wpdb) || !isset($wpdb->terms, $wpdb->term_taxonomy) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
				return 0;
			}
			return (int) $wpdb->get_var($wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1",
				$taxonomy,
				$slug
			));
		};

		$resolved = [];
		foreach ($slugs as $slug) {
			$matched = 0;
			foreach ($explicit as $term_id) {
				if (($slug_of[$term_id] ?? '') === $slug) {
					$matched = $term_id;
					break;
				}
			}
			if ($matched <= 0) {
				$matched = (int) $lookup_slug($slug);
			}
			if ($matched > 0) {
				$resolved[] = $matched;
			}
		}
		return array_values(array_unique(array_filter($resolved)));
	}
}

if (!function_exists('intersoccer_pv_repair_touch_post_caches')) {
	/**
	 * Refresh post cache, product transients, and the WC attribute lookup table.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	function intersoccer_pv_repair_touch_post_caches($post_id) {
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			return;
		}
		if (isset($GLOBALS['intersoccer_pv_test_cache_touches']) && is_array($GLOBALS['intersoccer_pv_test_cache_touches'])) {
			$GLOBALS['intersoccer_pv_test_cache_touches'][] = ['post', $post_id];
		}
		if (function_exists('clean_post_cache')) {
			clean_post_cache($post_id);
		}
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients($post_id);
		}
		$class = 'Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
		if (!function_exists('wc_get_container') || !class_exists($class)) {
			return;
		}
		try {
			$store = wc_get_container()->get($class);
			if (is_object($store) && method_exists($store, 'on_product_changed')) {
				$store->on_product_changed($post_id);
			} elseif (is_object($store) && method_exists($store, 'create_data_for_product')) {
				$store->create_data_for_product($post_id);
			}
		} catch (Throwable $e) {
			unset($e);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_touch_term_object_caches')) {
	/**
	 * Refresh term counts, object/post caches, transients, and the WC attribute lookup table.
	 *
	 * @param int             $object_id Object ID.
	 * @param string          $taxonomy  Taxonomy.
	 * @param array<int,int>  $tt_ids    Term taxonomy IDs whose counts changed.
	 * @return void
	 */
	function intersoccer_pv_repair_touch_term_object_caches($object_id, $taxonomy, array $tt_ids = []) {
		$object_id = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		$tt_ids = array_values(array_unique(array_filter(array_map('intval', $tt_ids))));
		if (isset($GLOBALS['intersoccer_pv_test_cache_touches']) && is_array($GLOBALS['intersoccer_pv_test_cache_touches'])) {
			$GLOBALS['intersoccer_pv_test_cache_touches'][] = ['terms', $object_id, $taxonomy];
		}
		if ($tt_ids !== [] && function_exists('wp_update_term_count')) {
			wp_update_term_count($tt_ids, $taxonomy);
		}
		if ($object_id > 0 && $taxonomy !== '' && function_exists('clean_object_term_cache')) {
			clean_object_term_cache($object_id, $taxonomy);
		}
		if (function_exists('intersoccer_pv_repair_touch_post_caches')) {
			intersoccer_pv_repair_touch_post_caches($object_id);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_write_object_terms')) {
	/**
	 * Attach terms by ID without WCML term-sync hooks (direct relationships or the test stub).
	 *
	 * @param int               $object_id Object ID.
	 * @param string            $taxonomy  Taxonomy.
	 * @param array<int,int>    $term_ids  Term IDs.
	 * @param array<int,string> $slugs     Slugs used to resolve IDs when none are given.
	 * @return void
	 */
	function intersoccer_pv_repair_write_object_terms($object_id, $taxonomy, array $term_ids, array $slugs = []) {
		$object_id = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		if ($object_id <= 0 || $taxonomy === '') {
			return;
		}
		$term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
		$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs), static function ($slug) {
			return $slug !== '';
		})));
		if ($slugs !== [] && function_exists('intersoccer_pv_repair_resolve_term_ids')) {
			$term_ids = intersoccer_pv_repair_resolve_term_ids($taxonomy, $slugs, $term_ids);
		}
		if ($slugs !== [] && count($term_ids) !== count($slugs)) {
			// Refuse a partial write: every slug must resolve, otherwise terms would be dropped.
			return;
		}
		if ($term_ids === [] && $slugs !== []) {
			return;
		}
		if (isset($GLOBALS['intersoccer_test_products']) || isset($GLOBALS['intersoccer_test_terms']) || isset($GLOBALS['intersoccer_test_product_terms'])) {
			if (function_exists('wp_set_object_terms')) {
				wp_set_object_terms($object_id, $term_ids !== [] ? $term_ids : $slugs, $taxonomy);
			}
			return;
		}
		global $wpdb;
		if (!$wpdb || !is_object($wpdb) || !isset($wpdb->term_relationships) || !method_exists($wpdb, 'query')) {
			if (function_exists('wp_set_object_terms')) {
				wp_set_object_terms($object_id, $term_ids !== [] ? $term_ids : $slugs, $taxonomy);
			}
			return;
		}
		$tt = $wpdb->term_taxonomy;
		$tr = $wpdb->term_relationships;
		$new_tt = [];
		foreach ($term_ids as $term_id) {
			$tt_id = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$tt} WHERE term_id = %d AND taxonomy = %s LIMIT 1", $term_id, $taxonomy));
			if ($tt_id > 0) {
				$new_tt[] = $tt_id;
			}
		}
		if ($term_ids !== [] && $new_tt === []) {
			return;
		}
		$old_tt = [];
		if (method_exists($wpdb, 'get_col')) {
			$old_tt = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
				"SELECT tr.term_taxonomy_id FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$object_id,
				$taxonomy
			)));
		}
		$use_tx = method_exists($wpdb, 'query');
		if ($use_tx) {
			$wpdb->query('START TRANSACTION');
		}
		$ok = true;
		foreach ($new_tt as $tt_id) {
			$result = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$tr} (object_id, term_taxonomy_id, term_order) VALUES (%d, %d, 0)", $object_id, $tt_id));
			if ($result === false) {
				$ok = false;
				break;
			}
		}
		if ($ok) {
			if ($new_tt === []) {
				$result = $wpdb->query($wpdb->prepare(
					"DELETE tr FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
					$object_id,
					$taxonomy
				));
			} else {
				$in = implode(',', array_map('intval', $new_tt));
				$result = $wpdb->query($wpdb->prepare(
					"DELETE tr FROM {$tr} tr INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s AND tr.term_taxonomy_id NOT IN ({$in})",
					$object_id,
					$taxonomy
				));
			}
			if ($result === false) {
				$ok = false;
			}
		}
		if ($use_tx) {
			$wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
		}
		if ($ok && function_exists('intersoccer_pv_repair_touch_term_object_caches')) {
			intersoccer_pv_repair_touch_term_object_caches($object_id, $taxonomy, array_merge($old_tt, $new_tt));
		}
	}
}


if (!function_exists('intersoccer_pv_repair_notify_test_after_write')) {
	/**
	 * Optional test hook after a post meta write (SQL or update_post_meta path).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return void
	 */
	function intersoccer_pv_repair_notify_test_after_write($post_id, $key, $value) {
		if (isset($GLOBALS['intersoccer_pv_test_after_write']) && is_callable($GLOBALS['intersoccer_pv_test_after_write'])) {
			call_user_func($GLOBALS['intersoccer_pv_test_after_write'], (int) $post_id, (string) $key, $value);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_write_post_meta')) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return void
	 */
	function intersoccer_pv_repair_write_post_meta($post_id, $key, $value) {
		$post_id = (int) $post_id;
		$key = (string) $key;
		if ($post_id <= 0 || $key === '') {
			return;
		}
		$use_sql = empty($GLOBALS['intersoccer_test_products']) && !isset($GLOBALS['intersoccer_test_terms']);
		global $wpdb;
		if ($use_sql && $wpdb && is_object($wpdb) && isset($wpdb->postmeta) && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')) {
			$stored = $value;
			if (!is_scalar($stored) && $stored !== null && function_exists('maybe_serialize')) {
				$stored = maybe_serialize($stored);
			} elseif (is_array($stored) || is_object($stored)) {
				$stored = serialize($stored);
			}
			$wpdb->query('START TRANSACTION');
			$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
			if ($deleted === false) {
				$wpdb->query('ROLLBACK');
				return;
			}
			$inserted = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)", $post_id, $key, (string) $stored));
			if ($inserted === false) {
				$wpdb->query('ROLLBACK');
				return;
			}
			$wpdb->query('COMMIT');
			if (function_exists('intersoccer_pv_repair_touch_post_caches')) {
				intersoccer_pv_repair_touch_post_caches($post_id);
			}
			if (function_exists('intersoccer_pv_repair_notify_test_after_write')) {
				intersoccer_pv_repair_notify_test_after_write($post_id, $key, $value);
			}
			return;
		}
		if (function_exists('update_post_meta')) {
			update_post_meta($post_id, $key, $value);
		}
		if (function_exists('intersoccer_pv_repair_touch_post_caches')) {
			intersoccer_pv_repair_touch_post_caches($post_id);
		}
		if (function_exists('intersoccer_pv_repair_notify_test_after_write')) {
			intersoccer_pv_repair_notify_test_after_write($post_id, $key, $value);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_write_variation_facets')) {
	/**
	 * Write attribute meta and term IDs without WC product save.
	 *
	 * @param int                    $variation_id Variation ID.
	 * @param array<string,string>   $slugs        Taxonomy => slug.
	 * @param array<string,int>      $term_ids     Taxonomy => term ID.
	 * @return void
	 */
	function intersoccer_pv_repair_write_variation_facets($variation_id, array $slugs, array $term_ids) {
		$variation_id = (int) $variation_id;
		if ($variation_id <= 0) {
			return;
		}
		if (isset($GLOBALS['intersoccer_test_products'][$variation_id]) && is_object($GLOBALS['intersoccer_test_products'][$variation_id]) && method_exists($GLOBALS['intersoccer_test_products'][$variation_id], 'get_attributes') && method_exists($GLOBALS['intersoccer_test_products'][$variation_id], 'set_attributes')) {
			$product = $GLOBALS['intersoccer_test_products'][$variation_id];
			$attrs = $product->get_attributes();
			if (!is_array($attrs)) {
				$attrs = [];
			}
			foreach ($slugs as $taxonomy => $slug) {
				$attrs[(string) $taxonomy] = (string) $slug;
			}
			$product->set_attributes($attrs);
		}
		foreach ($slugs as $taxonomy => $slug) {
			$taxonomy = (string) $taxonomy;
			intersoccer_pv_repair_write_post_meta($variation_id, 'attribute_' . $taxonomy, (string) $slug);
			$id = isset($term_ids[$taxonomy]) ? (int) $term_ids[$taxonomy] : 0;
			intersoccer_pv_repair_write_object_terms($variation_id, $taxonomy, $id > 0 ? [$id] : [], $slug !== '' ? [(string) $slug] : []);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_variable_children')) {
	/**
	 * Child variation IDs for a parent, from posts or the test store (not WCML get_children).
	 *
	 * @param int $parent_id Parent product ID.
	 * @return array<int,int>
	 */
	function intersoccer_pv_repair_variable_children($parent_id) {
		$parent_id = (int) $parent_id;
		if ($parent_id <= 0) {
			return [];
		}
		if (isset($GLOBALS['intersoccer_test_products'][$parent_id]) && is_object($GLOBALS['intersoccer_test_products'][$parent_id]) && method_exists($GLOBALS['intersoccer_test_products'][$parent_id], 'get_children')) {
			$ids = [];
			foreach ((array) $GLOBALS['intersoccer_test_products'][$parent_id]->get_children() as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$ids[] = $id;
				}
			}
			return $ids;
		}
		global $wpdb;
		if ($wpdb && is_object($wpdb) && isset($wpdb->posts) && method_exists($wpdb, 'get_col')) {
			$found = $wpdb->get_col($wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status != 'trash' ORDER BY ID ASC",
				$parent_id
			));
			return array_values(array_map('intval', (array) $found));
		}
		$product = function_exists('wc_get_product') ? wc_get_product($parent_id) : null;
		if ($product && method_exists($product, 'get_children')) {
			return array_values(array_map('intval', (array) $product->get_children()));
		}
		return [];
	}
}

if (!function_exists('intersoccer_pv_repair_source_product_ids')) {
	/**
	 * English (default-language) variable product IDs for the venue tool scope.
	 *
	 * @param int $product_id Scoped product ID, or 0 for shop-wide.
	 * @return array<int,int>
	 */
	function intersoccer_pv_repair_source_product_ids($product_id) {
		$product_id = (int) $product_id;
		$candidates = [];
		if ($product_id > 0) {
			$candidates[] = $product_id;
		} elseif (isset($GLOBALS['intersoccer_test_products']) && is_array($GLOBALS['intersoccer_test_products'])) {
			foreach ($GLOBALS['intersoccer_test_products'] as $id => $product) {
				if (is_object($product) && method_exists($product, 'is_type') && $product->is_type('variable')) {
					$candidates[] = (int) $id;
				}
			}
		} else {
			global $wpdb;
			if ($wpdb && is_object($wpdb) && isset($wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms) && method_exists($wpdb, 'get_col')) {
				$found = $wpdb->get_col(
					"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private')
					AND tt.taxonomy = 'product_type' AND t.slug = 'variable'"
				);
				$candidates = array_map('intval', (array) $found);
			} elseif (function_exists('wc_get_products')) {
				$candidates = array_map('intval', (array) wc_get_products([
					'type' => 'variable',
					'limit' => -1,
					'status' => ['publish', 'draft', 'private'],
					'return' => 'ids',
					'suppress_filters' => true,
				]));
			}
		}
		$ids = [];
		foreach ($candidates as $id) {
			$id = (int) $id;
			if ($id <= 0) {
				continue;
			}
			$resolved = intersoccer_pv_repair_icl_source_and_twins($id, ['post_product']);
			$source_id = (int) $resolved['source_id'];
			if ($source_id > 0) {
				$ids[$source_id] = true;
			}
		}
		return array_map('intval', array_keys($ids));
	}
}

if (!function_exists('intersoccer_pv_venue_parent_attr_row')) {
	/**
	 * @param int               $product_id Product ID.
	 * @param string            $taxonomy   Taxonomy.
	 * @param array<int,string> $slugs      Raw slugs.
	 * @param array<int,int>    $term_ids   Aligned term IDs.
	 * @return array<string,mixed>|null
	 */
	function intersoccer_pv_venue_parent_attr_row($product_id, $taxonomy, array $slugs, array $term_ids = []) {
		$product_id = (int) $product_id;
		$taxonomy = (string) $taxonomy;
		$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
		$term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
		if ($product_id <= 0 || $taxonomy === '' || $slugs === []) {
			return null;
		}
		$raw = intersoccer_pv_repair_raw_post_meta($product_id, '_product_attributes');
		if (is_string($raw) && $raw !== '' && function_exists('maybe_unserialize')) {
			$raw = maybe_unserialize($raw);
		}
		if (!is_array($raw)) {
			$raw = [];
		}
		$is_variation = !empty($raw[$taxonomy]['is_variation']) || !empty($raw[$taxonomy]['is_taxonomy']);
		if (isset($GLOBALS['intersoccer_test_products'][$product_id]) && is_object($GLOBALS['intersoccer_test_products'][$product_id]) && method_exists($GLOBALS['intersoccer_test_products'][$product_id], 'get_attributes')) {
			$attrs = $GLOBALS['intersoccer_test_products'][$product_id]->get_attributes();
			if (isset($attrs[$taxonomy]) && is_object($attrs[$taxonomy]) && method_exists($attrs[$taxonomy], 'get_variation')) {
				$is_variation = (bool) $attrs[$taxonomy]->get_variation();
			}
		}
		$before = ['is_variation' => (bool) $is_variation, 'slugs' => $slugs, 'term_ids' => $term_ids];
		$after = ['is_variation' => true, 'slugs' => $slugs, 'term_ids' => $term_ids];
		if ($before == $after) {
			return null;
		}
		return [
			'id' => 'parent-attr:' . $product_id . ':' . $taxonomy,
			'before' => $before,
			'after' => $after,
		];
	}
}

if (!function_exists('intersoccer_pv_venue_write_parent_attr')) {
	/**
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy.
	 * @param array  $state      is_variation + slugs + optional term_ids.
	 * @return void
	 */
	function intersoccer_pv_venue_write_parent_attr($product_id, $taxonomy, array $state) {
		$product_id = (int) $product_id;
		$taxonomy = (string) $taxonomy;
		$slugs = isset($state['slugs']) && is_array($state['slugs']) ? array_values(array_map('strval', $state['slugs'])) : [];
		$explicit = isset($state['term_ids']) && is_array($state['term_ids']) ? $state['term_ids'] : [];
		$term_ids = function_exists('intersoccer_pv_repair_resolve_term_ids')
			? intersoccer_pv_repair_resolve_term_ids($taxonomy, $slugs, $explicit)
			: array_values(array_map('intval', $explicit));
		intersoccer_pv_repair_write_object_terms($product_id, $taxonomy, $term_ids, $slugs);
		$raw = intersoccer_pv_repair_raw_post_meta($product_id, '_product_attributes');
		if (is_string($raw) && $raw !== '' && function_exists('maybe_unserialize')) {
			$raw = maybe_unserialize($raw);
		}
		if (!is_array($raw)) {
			$raw = [];
		}
		if (!isset($raw[$taxonomy]) || !is_array($raw[$taxonomy])) {
			$raw[$taxonomy] = [
				'name' => $taxonomy,
				'value' => '',
				'position' => 0,
				'is_visible' => 1,
				'is_variation' => !empty($state['is_variation']) ? 1 : 0,
				'is_taxonomy' => 1,
			];
		} else {
			$raw[$taxonomy]['is_variation'] = !empty($state['is_variation']) ? 1 : 0;
			$raw[$taxonomy]['is_taxonomy'] = 1;
		}
		intersoccer_pv_repair_write_post_meta($product_id, '_product_attributes', $raw);
		if (isset($GLOBALS['intersoccer_test_products'][$product_id]) && is_object($GLOBALS['intersoccer_test_products'][$product_id]) && method_exists($GLOBALS['intersoccer_test_products'][$product_id], 'get_attributes') && method_exists($GLOBALS['intersoccer_test_products'][$product_id], 'set_attributes')) {
			$product = $GLOBALS['intersoccer_test_products'][$product_id];
			$attributes = $product->get_attributes();
			if (!is_array($attributes)) {
				$attributes = [];
			}
			if (isset($attributes[$taxonomy]) && is_object($attributes[$taxonomy]) && method_exists($attributes[$taxonomy], 'set_variation')) {
				$attributes[$taxonomy]->set_variation(!empty($state['is_variation']));
				$product->set_attributes($attributes);
			}
		}
	}
}

if (!function_exists('intersoccer_pv_venue_build_plan')) {
	/**
	 * Language-neutral venue/camp-term plan for one English source product.
	 *
	 * @param int  $product_id Any product ID in the translation group.
	 * @param bool $write      Whether to write (Apply).
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_venue_build_plan($product_id, $write = false) {
		$resolved = intersoccer_pv_repair_icl_source_and_twins((int) $product_id, ['post_product']);
		$source_id = (int) $resolved['source_id'];
		if ($source_id <= 0) {
			$source_id = (int) $product_id;
		}
		$type = '';
		if (function_exists('intersoccer_pv_repair_raw_post_meta')) {
			$type = (string) intersoccer_pv_repair_raw_post_meta($source_id, '_intersoccer_product_type');
		}
		if ($type === '' && class_exists('InterSoccer_Product_Types')) {
			$type = (string) InterSoccer_Product_Types::get_product_type($source_id);
		}
		if ($type !== 'camp') {
			return [];
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			$helpers = dirname(__DIR__) . '/helpers.php';
			if (is_readable($helpers)) {
				require_once $helpers;
			}
		}
		$rows = [];
		$venue_records = intersoccer_pv_repair_raw_term_records($source_id, 'pa_intersoccer-venues');
		$camp_records = intersoccer_pv_repair_raw_term_records($source_id, 'pa_camp-terms');
		$parent_row = intersoccer_pv_venue_parent_attr_row($source_id, 'pa_intersoccer-venues', $venue_records['slugs'], $venue_records['ids']);
		if ($parent_row) {
			$rows[] = $parent_row;
			if ($write) {
				intersoccer_pv_venue_write_parent_attr($source_id, 'pa_intersoccer-venues', $parent_row['after']);
			}
		}
		$parent_row = intersoccer_pv_venue_parent_attr_row($source_id, 'pa_camp-terms', $camp_records['slugs'], $camp_records['ids']);
		if ($parent_row) {
			$rows[] = $parent_row;
			if ($write) {
				intersoccer_pv_venue_write_parent_attr($source_id, 'pa_camp-terms', $parent_row['after']);
			}
		}
		$venue_slug_to_id = [];
		$venue_pairs = isset($venue_records['pairs']) && is_array($venue_records['pairs']) ? $venue_records['pairs'] : [];
		if ($venue_pairs === []) {
			foreach ($venue_records['ids'] as $index => $tid) {
				$venue_pairs[] = ['id' => (int) $tid, 'slug' => (string) ($venue_records['slugs'][$index] ?? '')];
			}
		}
		foreach ($venue_pairs as $pair) {
			$slug = (string) ($pair['slug'] ?? '');
			if ($slug !== '') {
				$venue_slug_to_id[$slug] = (int) ($pair['id'] ?? 0);
			}
		}
		$camp_slug_to_id = [];
		$camp_pairs = isset($camp_records['pairs']) && is_array($camp_records['pairs']) ? $camp_records['pairs'] : [];
		if ($camp_pairs === []) {
			foreach ($camp_records['ids'] as $index => $tid) {
				$camp_pairs[] = ['id' => (int) $tid, 'slug' => (string) ($camp_records['slugs'][$index] ?? '')];
			}
		}
		foreach ($camp_pairs as $pair) {
			$slug = (string) ($pair['slug'] ?? '');
			if ($slug !== '') {
				$camp_slug_to_id[$slug] = (int) ($pair['id'] ?? 0);
			}
		}
		foreach (intersoccer_pv_repair_variable_children($source_id) as $variation_id) {
			$variation_id = (int) $variation_id;
			if ($variation_id <= 0) {
				continue;
			}
			$raw_attrs = intersoccer_pv_repair_raw_variation_attributes($variation_id);
			$venue_rec = intersoccer_pv_repair_raw_term_records($variation_id, 'pa_intersoccer-venues');
			$camp_rec = intersoccer_pv_repair_raw_term_records($variation_id, 'pa_camp-terms');
			$old_venue = (string) ($raw_attrs['pa_intersoccer-venues'] ?? '');
			if ($old_venue === '' && $venue_rec['slugs'] !== []) {
				$old_venue = (string) $venue_rec['slugs'][0];
			}
			$old_term = (string) ($raw_attrs['pa_camp-terms'] ?? '');
			if ($old_term === '' && $camp_rec['slugs'] !== []) {
				$old_term = (string) $camp_rec['slugs'][0];
			}
			$new_venue = $old_venue;
			$new_term = $old_term;
			$changed = false;
			if ($new_term === '' && function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
				$proposed = intersoccer_pm_infer_camp_term_slug_for_variation($variation_id, $camp_records['slugs']);
				if ($proposed !== '') {
					$new_term = $proposed;
					$changed = true;
				}
			}
			if ($new_venue === '' && function_exists('intersoccer_pm_infer_venue_slug_for_variation')) {
				$proposed = intersoccer_pm_infer_venue_slug_for_variation($venue_records['slugs']);
				if ($proposed !== '') {
					$new_venue = $proposed;
					$changed = true;
				}
			}
			if (!$changed) {
				continue;
			}
			$source_venue_id = (int) ($venue_slug_to_id[$new_venue] ?? 0);
			$source_term_id = (int) ($camp_slug_to_id[$new_term] ?? 0);
			$before_snap = [
				'meta' => [
					'attribute_pa_intersoccer-venues' => (string) intersoccer_pv_repair_raw_post_meta($variation_id, 'attribute_pa_intersoccer-venues'),
					'attribute_pa_camp-terms' => (string) intersoccer_pv_repair_raw_post_meta($variation_id, 'attribute_pa_camp-terms'),
				],
				'terms' => [
					'pa_intersoccer-venues' => $venue_rec['slugs'],
					'pa_camp-terms' => $camp_rec['slugs'],
				],
				'term_ids' => [
					'pa_intersoccer-venues' => $venue_rec['ids'],
					'pa_camp-terms' => $camp_rec['ids'],
				],
			];
			$after_snap = $before_snap;
			$after_snap['meta']['attribute_pa_intersoccer-venues'] = $new_venue;
			$after_snap['meta']['attribute_pa_camp-terms'] = $new_term;
			$after_snap['terms']['pa_intersoccer-venues'] = $new_venue === '' ? [] : [$new_venue];
			$after_snap['terms']['pa_camp-terms'] = $new_term === '' ? [] : [$new_term];
			$after_snap['term_ids']['pa_intersoccer-venues'] = $source_venue_id > 0 ? [$source_venue_id] : [];
			$after_snap['term_ids']['pa_camp-terms'] = $source_term_id > 0 ? [$source_term_id] : [];
			$twins_meta = intersoccer_pv_repair_icl_source_and_twins($variation_id, ['post_product_variation']);
			$before_twins = [];
			$after_twins = [];
			$manual = [];
			foreach ($twins_meta['twins'] as $twin) {
				$tid = (int) $twin['id'];
				$lang = (string) $twin['lang'];
				if ($tid <= 0) {
					continue;
				}
				$key = (string) $tid;
				$twin_attrs = intersoccer_pv_repair_raw_variation_attributes($tid);
				$twin_venue = intersoccer_pv_repair_raw_term_records($tid, 'pa_intersoccer-venues');
				$twin_camp = intersoccer_pv_repair_raw_term_records($tid, 'pa_camp-terms');
				$before_twins[$key] = [
					'attributes' => [
						'pa_intersoccer-venues' => (string) ($twin_attrs['pa_intersoccer-venues'] ?? ''),
						'pa_camp-terms' => (string) ($twin_attrs['pa_camp-terms'] ?? ''),
					],
					'meta' => [
						'attribute_pa_intersoccer-venues' => (string) intersoccer_pv_repair_raw_post_meta($tid, 'attribute_pa_intersoccer-venues'),
						'attribute_pa_camp-terms' => (string) intersoccer_pv_repair_raw_post_meta($tid, 'attribute_pa_camp-terms'),
					],
					'terms' => [
						'pa_intersoccer-venues' => $twin_venue['slugs'],
						'pa_camp-terms' => $twin_camp['slugs'],
					],
					'term_ids' => [
						'pa_intersoccer-venues' => $twin_venue['ids'],
						'pa_camp-terms' => $twin_camp['ids'],
					],
				];
				$attr_after = $before_twins[$key]['attributes'];
				$after_meta = $before_twins[$key]['meta'];
				$after_terms = $before_twins[$key]['terms'];
				$after_ids = $before_twins[$key]['term_ids'];
				foreach (['pa_intersoccer-venues' => [$new_venue, $source_venue_id], 'pa_camp-terms' => [$new_term, $source_term_id]] as $taxonomy => $pair) {
					$slug = (string) $pair[0];
					$source_term = (int) $pair[1];
					if ($slug === '') {
						continue;
					}
					$mapped = $source_term > 0 ? intersoccer_pv_repair_map_term_for_language($source_term, $taxonomy, $lang) : null;
					if ($mapped === null) {
						$manual[] = [
							'tid' => $tid,
							'taxonomy' => $taxonomy,
							'current' => (string) ($attr_after[$taxonomy] ?? ''),
						];
						continue;
					}
					$attr_after[$taxonomy] = $mapped['slug'];
					$after_meta['attribute_' . $taxonomy] = $mapped['slug'];
					$after_terms[$taxonomy] = $mapped['slug'] === '' ? [] : [$mapped['slug']];
					$after_ids[$taxonomy] = $mapped['term_id'] > 0 ? [$mapped['term_id']] : [];
				}
				$after_twins[$key] = [
					'attributes' => $attr_after,
					'meta' => $after_meta,
					'terms' => $after_terms,
					'term_ids' => $after_ids,
				];
			}
			foreach ($manual as $item) {
				$rows[] = [
					'id' => 'manual:twin:' . (int) $item['tid'] . ':' . (string) $item['taxonomy'],
					'needs_manual' => true,
					'before' => ['attributes' => [$item['taxonomy'] => $item['current']]],
					'after' => ['attributes' => [$item['taxonomy'] => $item['current']], 'needs_manual' => '1'],
				];
			}
			$row = [
				'id' => 'attrs:' . $variation_id,
				'before' => [
					'attributes' => [
						'pa_intersoccer-venues' => $old_venue,
						'pa_camp-terms' => $old_term,
					],
					'meta' => $before_snap['meta'],
					'terms' => $before_snap['terms'],
					'term_ids' => $before_snap['term_ids'],
					'translations' => $before_twins,
				],
				'after' => [
					'attributes' => [
						'pa_intersoccer-venues' => $new_venue,
						'pa_camp-terms' => $new_term,
					],
					'meta' => $after_snap['meta'],
					'terms' => $after_snap['terms'],
					'term_ids' => $after_snap['term_ids'],
					'translations' => $after_twins,
				],
			];
			$rows[] = $row;
			if ($write) {
				intersoccer_pv_repair_write_variation_facets(
					$variation_id,
					['pa_intersoccer-venues' => $new_venue, 'pa_camp-terms' => $new_term],
					['pa_intersoccer-venues' => $source_venue_id, 'pa_camp-terms' => $source_term_id]
				);
				foreach ($after_twins as $tid => $twin_after) {
					$tid = (int) $tid;
					$twin_slugs = [];
					$twin_ids = [];
					foreach (['pa_intersoccer-venues', 'pa_camp-terms'] as $taxonomy) {
						if (!isset($twin_after['term_ids'][$taxonomy]) && !isset($twin_after['attributes'][$taxonomy])) {
							continue;
						}
						if (!isset($row['after']['translations'][(string) $tid]['term_ids'][$taxonomy]) && isset($manual)) {
							$is_manual = false;
							foreach ($manual as $item) {
								if ((int) $item['tid'] === $tid && $item['taxonomy'] === $taxonomy) {
									$is_manual = true;
									break;
								}
							}
							if ($is_manual) {
								continue;
							}
						}
						$is_manual = false;
						foreach ($manual as $item) {
							if ((int) $item['tid'] === $tid && $item['taxonomy'] === $taxonomy) {
								$is_manual = true;
								break;
							}
						}
						if ($is_manual) {
							continue;
						}
						$before_twin = isset($before_twins[(string) $tid]) && is_array($before_twins[(string) $tid]) ? $before_twins[(string) $tid] : [];
						$before_slug = (string) (($before_twin['attributes'][$taxonomy] ?? $before_twin['meta']['attribute_' . $taxonomy] ?? ''));
						$after_slug = (string) ($twin_after['attributes'][$taxonomy] ?? '');
						$before_ids = array_values(array_map('intval', (array) ($before_twin['term_ids'][$taxonomy] ?? [])));
						$after_ids = array_values(array_map('intval', (array) ($twin_after['term_ids'][$taxonomy] ?? [])));
						if ($before_slug === $after_slug && $before_ids === $after_ids) {
							continue;
						}
						$twin_slugs[$taxonomy] = $after_slug;
						$twin_ids[$taxonomy] = (int) ($after_ids[0] ?? 0);
					}
					if ($twin_slugs !== []) {
						intersoccer_pv_repair_write_variation_facets($tid, $twin_slugs, $twin_ids);
					}
				}
			}
		}
		return $rows;
	}
}

if (!function_exists('intersoccer_pv_tool_repair_rows_for_product')) {
	/**
	 * @param int  $product_id Product ID (any language in the group).
	 * @param bool $dry_run    Preview when true.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_tool_repair_rows_for_product($product_id, $dry_run) {
		return intersoccer_pv_venue_build_plan((int) $product_id, !$dry_run);
	}
}

if (!function_exists('intersoccer_pv_tool_repair_venue')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_repair_venue($dry_run, array $scope) {
		$product_id = (int) ($scope['product_id'] ?? 0);
		$rows = [];
		foreach (intersoccer_pv_repair_source_product_ids($product_id) as $parent_id) {
			$rows = array_merge($rows, intersoccer_pv_venue_build_plan((int) $parent_id, !$dry_run));
		}
		$writes = 0;
		$manual = 0;
		foreach ($rows as $row) {
			if (!empty($row['needs_manual'])) {
				$manual++;
			} else {
				$writes++;
			}
		}
		$summary = sprintf(
			/* translators: %d: variation count */
			__('Venue or camp-term changes: %d.', 'intersoccer-product-variations'),
			$writes
		);
		if ($manual > 0) {
			$summary .= ' ' . sprintf(
				/* translators: %d: field count */
				__('Needs manual: %d.', 'intersoccer-product-variations'),
				$manual
			);
		}
		return [
			'summary' => $summary,
			'counts' => ['changed' => $writes, 'added' => 0, 'updated' => $writes, 'deleted' => 0],
			'rows' => $rows,
			'shop_wide' => $product_id <= 0,
			'wrote' => !$dry_run && $writes > 0,
		];
	}
}

if (!function_exists('intersoccer_pv_repair_element_ids_from_rows')) {
	/**
	 * @param array<int,array<string,mixed>> $rows Backup/plan rows.
	 * @return array<int,int>
	 */
	function intersoccer_pv_repair_element_ids_from_rows(array $rows) {
		$ids = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = (string) ($row['id'] ?? '');
			if (preg_match('/^(?:attrs|parent-attr|manual:twin|manual:attr):(\d+)/', $id, $matches)) {
				$ids[] = (int) $matches[1];
			}
			foreach (['before', 'after'] as $side) {
				if (empty($row[$side]['translations']) || !is_array($row[$side]['translations'])) {
					continue;
				}
				foreach (array_keys($row[$side]['translations']) as $tid) {
					$ids[] = (int) $tid;
				}
			}
		}
		$ids = array_values(array_unique(array_filter($ids)));
		sort($ids);
		return $ids;
	}
}
