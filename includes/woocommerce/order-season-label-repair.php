<?php
/**
 * Order-item Season label repair (rename-in-place term labels).
 *
 * Rewrites denormalized order-line Season labels after pa_program-season terms
 * are renamed. Does not touch _intersoccer_discount_season_key (canonical key).
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Season-label repair engine.
 */
class InterSoccer_Order_Season_Label_Repair {

	const TAXONOMY = 'pa_program-season';
	const BATCH_SIZE = 200;
	const PREVIEW_TRANSIENT = 'intersoccer_pv_season_label_repair_preview';
	const BACKUP_SUBDIR = 'intersoccer-backups';
	const ACTION_REPAIRED = 'intersoccer_pv_order_season_labels_repaired';

	/**
	 * Meta keys that always hold a human season label when present.
	 *
	 * @return string[]
	 */
	public static function primary_meta_keys() {
		$keys = ['Season'];
		if (function_exists('intersoccer_attr_definition')) {
			$def = intersoccer_attr_definition('program-season');
			if (is_array($def) && !empty($def['legacy_order_meta_labels']) && is_array($def['legacy_order_meta_labels'])) {
				foreach ($def['legacy_order_meta_labels'] as $legacy) {
					$legacy = (string) $legacy;
					if ($legacy !== '' && !in_array($legacy, $keys, true)) {
						$keys[] = $legacy;
					}
				}
			}
		}
		return $keys;
	}

	/**
	 * Attribute twin keys — rewrite only when the stored value is a label, not a slug.
	 *
	 * @return string[]
	 */
	public static function conditional_meta_keys() {
		return ['pa_program-season', 'attribute_pa_program-season'];
	}

	/**
	 * Built-in rename map: old display labels → current EN term name.
	 *
	 * FR/DE candidates are best-effort historical strings; dry-run unmatched
	 * values let operators extend via --mapping / JSON.
	 *
	 * @return array<int,array{target_en:string,old_labels:string[]}>
	 */
	public static function default_mapping_spec() {
		return [
			[
				'target_en'   => 'Winter Courses',
				'old_labels'  => [
					'Winter 2027',
					'Winter Courses 2027',
					'Hiver 2027',
					"Cours d'hiver 2027",
					'Winterkurse 2027',
					'Winter 27',
				],
			],
			[
				'target_en'   => 'Spring/Summer Courses',
				'old_labels'  => [
					'Spring/Summer 2027',
					'Spring / Summer 2027',
					'Printemps/Été 2027',
					'Printemps/Ete 2027',
					'Printemps-Été 2027',
					'Frühling/Sommer 2027',
					'Fruehling/Sommer 2027',
					'Spring/Summer Courses 2027',
				],
			],
		];
	}

	/**
	 * Normalize a label for comparison (case + whitespace insensitive).
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	public static function normalize_label($label) {
		$label = trim(preg_replace('/\s+/u', ' ', (string) $label));
		if ($label === '') {
			return '';
		}
		if (function_exists('mb_strtolower')) {
			return mb_strtolower($label, 'UTF-8');
		}
		return strtolower($label);
	}

	/**
	 * Whether a stored meta value looks like a taxonomy slug (not a display label).
	 *
	 * @param string $value Meta value.
	 * @return bool
	 */
	public static function value_looks_like_slug($value) {
		$value = trim((string) $value);
		if ($value === '' || strpos($value, ' ') !== false) {
			return false;
		}
		if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/i', $value)) {
			return false;
		}
		if (function_exists('get_term_by')) {
			$term = get_term_by('slug', $value, self::TAXONOMY);
			if ($term && !is_wp_error($term)) {
				return true;
			}
		}
		// Known registry slugs even before terms exist in the stub environment.
		$known = ['summer', 'autumn', 'spring', 'winter', 'winter-courses', 'spring-summer-courses', 'easter-camps', 'winter-camps'];
		return in_array(strtolower($value), $known, true);
	}

	/**
	 * Merge built-in / JSON / extra old labels into a runtime map keyed by normalized old label.
	 *
	 * @param array<int,array{target_en:string,old_labels?:string[]}>|null $spec
	 * @param string|null                                                     $json_file Absolute path.
	 * @return array{by_old:array<string,string>,targets:array<string,array<string,mixed>>,errors:string[]}
	 */
	public static function build_runtime_mapping($spec = null, $json_file = null) {
		$errors = [];
		$entries = is_array($spec) ? $spec : self::default_mapping_spec();

		if ($json_file !== null && $json_file !== '') {
			$loaded = self::load_mapping_file($json_file);
			if (is_wp_error($loaded)) {
				$errors[] = $loaded->get_error_message();
			} elseif (is_array($loaded)) {
				$entries = $loaded;
			}
		}

		$by_old  = [];
		$targets = [];

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$target_en = isset($entry['target_en']) ? trim((string) $entry['target_en']) : '';
			if ($target_en === '' && isset($entry['target'])) {
				$target_en = trim((string) $entry['target']);
			}
			if ($target_en === '') {
				continue;
			}

			$resolved = self::resolve_target_term($target_en);
			$targets[$target_en] = $resolved;

			// Current FR/DE term names are the *new* labels (not old). Historical
			// translated strings must be listed under old_labels (built-in or JSON).
			$old_labels = [];
			if (!empty($entry['old_labels']) && is_array($entry['old_labels'])) {
				$old_labels = $entry['old_labels'];
			}

			foreach ($old_labels as $old) {
				$old = trim((string) $old);
				if ($old === '') {
					continue;
				}
				$norm = self::normalize_label($old);
				if ($norm === '') {
					continue;
				}
				$by_old[$norm] = $target_en;
			}
		}

		return [
			'by_old'   => $by_old,
			'targets'  => $targets,
			'errors'   => $errors,
		];
	}

	/**
	 * Load a JSON mapping file.
	 *
	 * Accepted shapes:
	 * - {"mappings":[{"target_en":"...","old_labels":["..."]}]}
	 * - [{"target_en":"...","old_labels":["..."]}]
	 * - {"Winter 2027":"Winter Courses", ...}  (old → target_en)
	 *
	 * @param string $path File path.
	 * @return array<int,array{target_en:string,old_labels:string[]}>|WP_Error
	 */
	public static function load_mapping_file($path) {
		$path = (string) $path;
		if ($path === '' || !is_readable($path)) {
			return new WP_Error('mapping_unreadable', __('Mapping file is not readable.', 'intersoccer-product-variations'));
		}
		$raw = file_get_contents($path);
		if ($raw === false) {
			return new WP_Error('mapping_unreadable', __('Mapping file is not readable.', 'intersoccer-product-variations'));
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return new WP_Error('mapping_invalid', __('Mapping file is not valid JSON.', 'intersoccer-product-variations'));
		}

		if (isset($data['mappings']) && is_array($data['mappings'])) {
			return array_values($data['mappings']);
		}

		if (isset($data[0]) && is_array($data[0]) && (isset($data[0]['target_en']) || isset($data[0]['old_labels']))) {
			return array_values($data);
		}

		// Flat old → target_en map.
		$grouped = [];
		foreach ($data as $old => $target) {
			if (!is_string($old) || !is_string($target)) {
				continue;
			}
			$target = trim($target);
			$old    = trim($old);
			if ($target === '' || $old === '') {
				continue;
			}
			if (!isset($grouped[$target])) {
				$grouped[$target] = [
					'target_en'  => $target,
					'old_labels' => [],
				];
			}
			$grouped[$target]['old_labels'][] = $old;
		}
		return array_values($grouped);
	}

	/**
	 * Resolve a target term by current EN name (+ WPML translations / registry fallback).
	 *
	 * @param string $target_en English term name.
	 * @return array<string,mixed>
	 */
	public static function resolve_target_term($target_en) {
		$target_en = trim((string) $target_en);
		$result    = [
			'target_en'             => $target_en,
			'term_id'               => 0,
			'slug'                  => '',
			'names_by_lang'         => ['en' => $target_en],
			'translation_names'     => ['en' => $target_en],
			'registry_translations' => [],
		];

		$slug = '';
		if (function_exists('intersoccer_attr_english_term_slug')) {
			$slug = (string) intersoccer_attr_english_term_slug($target_en);
		}
		if ($slug === '' && function_exists('intersoccer_attr_program_season_slug_for_label')) {
			$slug = (string) intersoccer_attr_program_season_slug_for_label($target_en);
		}
		if ($slug === '' && function_exists('sanitize_title')) {
			$slug = sanitize_title($target_en);
		}
		$result['slug'] = $slug;

		if (function_exists('intersoccer_attr_program_season_extras') && $slug !== '') {
			$extras = intersoccer_attr_program_season_extras();
			if (isset($extras[$slug]['translations']) && is_array($extras[$slug]['translations'])) {
				$result['registry_translations'] = $extras[$slug]['translations'];
				foreach ($extras[$slug]['translations'] as $lang => $name) {
					$result['names_by_lang'][(string) $lang] = (string) $name;
					$result['translation_names'][(string) $lang] = (string) $name;
				}
			}
		}

		$term = null;
		if (function_exists('get_term_by')) {
			$term = get_term_by('name', $target_en, self::TAXONOMY);
			if ((!$term || is_wp_error($term)) && $slug !== '') {
				$term = get_term_by('slug', $slug, self::TAXONOMY);
			}
		}
		if ($term && !is_wp_error($term)) {
			$result['term_id'] = (int) $term->term_id;
			$result['slug']    = isset($term->slug) ? (string) $term->slug : $slug;
			$result['names_by_lang']['en'] = isset($term->name) ? (string) $term->name : $target_en;
			$result['translation_names']['en'] = $result['names_by_lang']['en'];

			foreach (['fr', 'de'] as $lang) {
				$translated_id = self::translate_term_id((int) $term->term_id, $lang);
				if ($translated_id > 0 && function_exists('get_term')) {
					$tr = get_term($translated_id, self::TAXONOMY);
					if ($tr && !is_wp_error($tr) && !empty($tr->name)) {
						$result['names_by_lang'][$lang] = (string) $tr->name;
						$result['translation_names'][$lang] = (string) $tr->name;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * WPML term ID for a language.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang    Language code.
	 * @return int
	 */
	public static function translate_term_id($term_id, $lang) {
		$term_id = (int) $term_id;
		$lang    = strtolower(trim((string) $lang));
		if ($term_id <= 0 || $lang === '' || $lang === 'en') {
			return $term_id;
		}
		if (function_exists('apply_filters')) {
			$translated = (int) apply_filters('wpml_object_id', $term_id, self::TAXONOMY, false, $lang);
			if ($translated > 0) {
				return $translated;
			}
		}
		if (function_exists('icl_object_id')) {
			$translated = (int) icl_object_id($term_id, self::TAXONOMY, false, $lang);
			if ($translated > 0) {
				return $translated;
			}
		}
		return 0;
	}

	/**
	 * Current EN term name for an order language.
	 *
	 * @param array  $target_info From resolve_target_term().
	 * @param string $language    Order language code.
	 * @return string
	 */
	public static function new_label_for_language(array $target_info, $language) {
		$language = strtolower(trim((string) $language));
		if ($language === '') {
			$language = 'en';
		}
		$names = isset($target_info['names_by_lang']) && is_array($target_info['names_by_lang'])
			? $target_info['names_by_lang']
			: [];
		if (isset($names[$language]) && (string) $names[$language] !== '') {
			return (string) $names[$language];
		}
		if (isset($names['en']) && (string) $names['en'] !== '') {
			return (string) $names['en'];
		}
		return isset($target_info['target_en']) ? (string) $target_info['target_en'] : '';
	}

	/**
	 * Default WPML language code.
	 *
	 * @return string
	 */
	public static function default_language() {
		if (function_exists('apply_filters')) {
			$lang = apply_filters('wpml_default_language', null);
			if (is_string($lang) && $lang !== '') {
				return strtolower($lang);
			}
		}
		return 'en';
	}

	/**
	 * Order language from wpml_language meta (fallback EN).
	 *
	 * @param object|null $order Order object.
	 * @return string
	 */
	public static function order_language($order) {
		if ($order && is_object($order) && method_exists($order, 'get_meta')) {
			$lang = strtolower(trim((string) $order->get_meta('wpml_language', true)));
			if ($lang !== '') {
				return $lang;
			}
		}
		return 'en';
	}

	/**
	 * Default-language parent product ID for a line item.
	 *
	 * @param object $item  Order item.
	 * @param object $order Order.
	 * @return int
	 */
	public static function default_language_parent_product_id($item, $order = null) {
		$product_id   = 0;
		$variation_id = 0;
		if (is_object($item) && method_exists($item, 'get_product_id')) {
			$product_id = (int) $item->get_product_id();
		}
		if (is_object($item) && method_exists($item, 'get_variation_id')) {
			$variation_id = (int) $item->get_variation_id();
		}

		$parent_id = $product_id;
		if ($variation_id > 0 && function_exists('wc_get_product')) {
			$variation = wc_get_product($variation_id);
			if ($variation && method_exists($variation, 'get_parent_id')) {
				$pid = (int) $variation->get_parent_id();
				if ($pid > 0) {
					$parent_id = $pid;
				}
			}
		} elseif ($product_id > 0 && function_exists('wc_get_product')) {
			$product = wc_get_product($product_id);
			if ($product && method_exists($product, 'get_parent_id')) {
				$pid = (int) $product->get_parent_id();
				if ($pid > 0) {
					$parent_id = $pid;
				}
			}
		}

		if ($parent_id <= 0) {
			return 0;
		}

		$default_lang = self::default_language();
		if (function_exists('apply_filters')) {
			$translated = (int) apply_filters('wpml_object_id', $parent_id, 'product', true, $default_lang);
			if ($translated > 0) {
				return $translated;
			}
		}
		return $parent_id;
	}

	/**
	 * Whether a default-language parent product currently has the target season term.
	 *
	 * @param int   $parent_id   Product ID.
	 * @param array $target_info Target term info.
	 * @return bool
	 */
	public static function product_has_target_term($parent_id, array $target_info) {
		$parent_id = (int) $parent_id;
		if ($parent_id <= 0) {
			return false;
		}
		$term_id = isset($target_info['term_id']) ? (int) $target_info['term_id'] : 0;
		$ids     = [];
		if (function_exists('wc_get_product_terms')) {
			$ids = wc_get_product_terms($parent_id, self::TAXONOMY, ['fields' => 'ids']);
			if (is_wp_error($ids) || !is_array($ids)) {
				$ids = [];
			}
		}
		$ids = array_map('intval', $ids);
		if ($term_id > 0 && in_array($term_id, $ids, true)) {
			return true;
		}
		// Accept WPML translation term IDs assigned on the product.
		foreach ($ids as $assigned) {
			if ($term_id > 0 && self::translate_term_id($assigned, 'en') === $term_id) {
				return true;
			}
			if ($term_id > 0 && self::translate_term_id($term_id, self::default_language()) === $assigned) {
				return true;
			}
		}
		// Name fallback when term IDs are unavailable in stubs.
		if ($term_id <= 0 && function_exists('wc_get_product_terms')) {
			$names = wc_get_product_terms($parent_id, self::TAXONOMY, ['fields' => 'names']);
			if (is_array($names)) {
				$want = self::normalize_label($target_info['target_en'] ?? '');
				foreach ($names as $name) {
					if (self::normalize_label((string) $name) === $want) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Match a stored label against the old-label map.
	 *
	 * @param string $value Stored meta value.
	 * @param array  $by_old Normalized old → target_en.
	 * @return string Target EN name or empty.
	 */
	public static function match_old_label($value, array $by_old) {
		$norm = self::normalize_label($value);
		if ($norm === '') {
			return '';
		}
		return isset($by_old[$norm]) ? (string) $by_old[$norm] : '';
	}

	/**
	 * Collect rewrite candidates for one line item.
	 *
	 * @param object $item     Order item.
	 * @param object $order    Order.
	 * @param array  $runtime  From build_runtime_mapping().
	 * @param array  $options  order_id filter etc.
	 * @return array{changes:array<int,array>,skipped:array<int,array>,unmatched:string[]}
	 */
	public static function evaluate_item($item, $order, array $runtime, array $options = []) {
		$changes   = [];
		$skipped   = [];
		$unmatched = [];

		if (!is_object($item) || !method_exists($item, 'get_meta')) {
			return compact('changes', 'skipped', 'unmatched');
		}

		$item_id  = method_exists($item, 'get_id') ? (int) $item->get_id() : 0;
		$order_id = ($order && method_exists($order, 'get_id')) ? (int) $order->get_id() : 0;
		$status   = ($order && method_exists($order, 'get_status')) ? (string) $order->get_status() : '';
		$language = self::order_language($order);
		$parent   = self::default_language_parent_product_id($item, $order);

		$by_old  = $runtime['by_old'] ?? [];
		$targets = $runtime['targets'] ?? [];

		$keys = array_merge(self::primary_meta_keys(), self::conditional_meta_keys());
		$seen_values_for_unmatched = [];

		foreach ($keys as $meta_key) {
			$value = $item->get_meta($meta_key, true);
			if (is_array($value)) {
				continue;
			}
			$value = is_scalar($value) ? trim((string) $value) : '';
			if ($value === '') {
				continue;
			}

			$is_conditional = in_array($meta_key, self::conditional_meta_keys(), true);
			if ($is_conditional && self::value_looks_like_slug($value)) {
				continue;
			}

			$target_en = self::match_old_label($value, $by_old);
			if ($target_en === '') {
				if (in_array($meta_key, self::primary_meta_keys(), true)) {
					$seen_values_for_unmatched[] = $value;
				}
				continue;
			}

			$target_info = $targets[$target_en] ?? null;
			if (!is_array($target_info)) {
				$skipped[] = [
					'order_id' => $order_id,
					'item_id'  => $item_id,
					'meta_key' => $meta_key,
					'old'      => $value,
					'reason'   => 'target_unresolved',
					'status'   => $status,
					'language' => $language,
				];
				continue;
			}

			if (!self::product_has_target_term($parent, $target_info)) {
				$skipped[] = [
					'order_id' => $order_id,
					'item_id'  => $item_id,
					'meta_key' => $meta_key,
					'old'      => $value,
					'reason'   => 'product_missing_target_term',
					'status'   => $status,
					'language' => $language,
					'parent_product_id' => $parent,
					'target_en' => $target_en,
				];
				continue;
			}

			$new_value = self::new_label_for_language($target_info, $language);
			if ($new_value === '') {
				$skipped[] = [
					'order_id' => $order_id,
					'item_id'  => $item_id,
					'meta_key' => $meta_key,
					'old'      => $value,
					'reason'   => 'empty_new_label',
					'status'   => $status,
					'language' => $language,
				];
				continue;
			}

			if (self::normalize_label($value) === self::normalize_label($new_value)) {
				continue;
			}

			$changes[] = [
				'order_id'   => $order_id,
				'item_id'    => $item_id,
				'meta_key'   => $meta_key,
				'old'        => $value,
				'new'        => $new_value,
				'language'   => $language,
				'status'     => $status,
				'target_en'  => $target_en,
				'parent_product_id' => $parent,
			];
		}

		// Unmatched Season values on products that carry any mapped target term.
		foreach ($seen_values_for_unmatched as $raw) {
			$norm = self::normalize_label($raw);
			$is_current_new = false;
			foreach ($targets as $tinfo) {
				if (!is_array($tinfo)) {
					continue;
				}
				foreach (($tinfo['names_by_lang'] ?? []) as $n) {
					if (self::normalize_label((string) $n) === $norm) {
						$is_current_new = true;
						break 2;
					}
				}
				if (self::product_has_target_term($parent, $tinfo)) {
					// value is on an affected product but not old and not current → report
				}
			}
			if ($is_current_new) {
				continue;
			}
			$on_affected = false;
			foreach ($targets as $tinfo) {
				if (is_array($tinfo) && self::product_has_target_term($parent, $tinfo)) {
					$on_affected = true;
					break;
				}
			}
			if ($on_affected) {
				$unmatched[] = $raw;
			}
		}

		return compact('changes', 'skipped', 'unmatched');
	}

	/**
	 * Scan orders and build a report (no writes).
	 *
	 * @param array $args {
	 *     @type bool         $apply        Unused (always false here).
	 *     @type int|null     $order_id     Limit to one order.
	 *     @type string|null  $mapping_file JSON path.
	 *     @type array|null   $mapping_spec Override built-in spec.
	 *     @type int          $batch_size   Orders page size.
	 * }
	 * @return array<string,mixed>
	 */
	public static function run_dry($args = []) {
		return self::run(array_merge($args, ['apply' => false]));
	}

	/**
	 * Apply rewrites (backup first).
	 *
	 * @param array $args Same as run_dry plus apply=true.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run_apply($args = []) {
		return self::run(array_merge($args, ['apply' => true]));
	}

	/**
	 * Core dry-run / apply.
	 *
	 * @param array $args Args.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run(array $args = []) {
		$args = array_merge([
			'apply'        => false,
			'order_id'     => null,
			'mapping_file' => null,
			'mapping_spec' => null,
			'batch_size'   => self::BATCH_SIZE,
		], $args);

		$runtime = self::build_runtime_mapping($args['mapping_spec'], $args['mapping_file']);
		if (!empty($runtime['errors'])) {
			return new WP_Error('mapping_error', implode('; ', $runtime['errors']));
		}
		if (empty($runtime['by_old'])) {
			return new WP_Error('mapping_empty', __('No old labels in mapping.', 'intersoccer-product-variations'));
		}

		$report = [
			'mode'              => !empty($args['apply']) ? 'apply' : 'dry_run',
			'counts'            => [],
			'orders'            => 0,
			'lines'             => 0,
			'rows'              => [],
			'skipped'           => [],
			'unmatched_values'  => [],
			'backup_file'       => null,
			'updated'           => 0,
			'order_ids'         => [],
		];

		$all_changes = [];
		$all_skipped = [];
		$unmatched   = [];
		$order_ids_touched = [];

		$callback = isset($args['order_iterator']) && is_callable($args['order_iterator'])
			? $args['order_iterator']
			: null;

		if ($callback) {
			foreach ($callback() as $order) {
				self::accumulate_order($order, $runtime, $all_changes, $all_skipped, $unmatched, $order_ids_touched, $args);
			}
		} else {
			$page      = 1;
			$per_page  = max(1, (int) $args['batch_size']);
			$order_id  = $args['order_id'] !== null ? (int) $args['order_id'] : 0;

			do {
				$query = [
					'limit'    => $per_page,
					'page'     => $page,
					'paginate' => true,
					'return'   => 'objects',
					'status'   => self::order_statuses_except_trash(),
					'type'     => 'shop_order',
				];
				if ($order_id > 0) {
					$query['limit'] = 1;
					$query['page']  = 1;
					$query['include'] = [$order_id];
				}

				$result = function_exists('wc_get_orders') ? wc_get_orders($query) : [];
				$orders = [];
				$total_pages = 1;
				if (is_object($result) && isset($result->orders)) {
					$orders = $result->orders;
					$total_pages = isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;
				} elseif (is_array($result)) {
					$orders = $result;
					$total_pages = $order_id > 0 || count($orders) < $per_page ? $page : $page + 1;
				}

				foreach ($orders as $order) {
					self::accumulate_order($order, $runtime, $all_changes, $all_skipped, $unmatched, $order_ids_touched, $args);
				}

				if ($order_id > 0) {
					break;
				}
				$page++;
			} while ($page <= $total_pages && !empty($orders));
		}

		$report['rows']    = $all_changes;
		$report['skipped'] = $all_skipped;
		$report['unmatched_values'] = array_values(array_unique($unmatched));
		$report['lines']   = count($all_changes);
		$report['orders']  = count(array_unique(array_column($all_changes, 'order_id')));
		$report['counts']  = self::summarize_counts($all_changes);
		$report['order_ids'] = array_values(array_unique($order_ids_touched));

		if (empty($args['apply'])) {
			return $report;
		}

		if (empty($all_changes)) {
			return $report;
		}

		$backup = self::write_backup($all_changes);
		if (is_wp_error($backup)) {
			return $backup;
		}
		$report['backup_file'] = $backup;

		$updated_orders = [];
		$chunks = array_chunk($all_changes, max(1, (int) $args['batch_size']));
		foreach ($chunks as $chunk) {
			foreach ($chunk as $change) {
				$ok = self::write_item_meta_change($change);
				if ($ok) {
					$report['updated']++;
					$updated_orders[(int) $change['order_id']] = true;
				}
			}
		}

		$order_ids = array_keys($updated_orders);
		$report['order_ids'] = $order_ids;
		if (!empty($order_ids)) {
			do_action(self::ACTION_REPAIRED, $order_ids);
		}

		return $report;
	}

	/**
	 * @param object $order Order.
	 * @param array  $runtime Mapping.
	 * @param array  $all_changes By ref.
	 * @param array  $all_skipped By ref.
	 * @param array  $unmatched By ref.
	 * @param array  $order_ids_touched By ref.
	 * @param array  $args Args.
	 */
	private static function accumulate_order($order, array $runtime, array &$all_changes, array &$all_skipped, array &$unmatched, array &$order_ids_touched, array $args) {
		if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
			return;
		}
		if (method_exists($order, 'get_status') && $order->get_status() === 'trash') {
			return;
		}
		foreach ($order->get_items() as $item) {
			$result = self::evaluate_item($item, $order, $runtime, $args);
			foreach ($result['changes'] as $change) {
				$all_changes[] = $change;
				$order_ids_touched[(int) $change['order_id']] = true;
			}
			foreach ($result['skipped'] as $skip) {
				$all_skipped[] = $skip;
			}
			foreach ($result['unmatched'] as $u) {
				$unmatched[] = $u;
			}
		}
	}

	/**
	 * Order statuses to include (everything except trash).
	 *
	 * @return string[]
	 */
	public static function order_statuses_except_trash() {
		if (function_exists('wc_get_order_statuses')) {
			$statuses = array_keys(wc_get_order_statuses());
			$out = [];
			foreach ($statuses as $status) {
				$status = (string) $status;
				$key = strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
				if ($key !== 'trash') {
					$out[] = $key;
				}
			}
			if (!empty($out)) {
				return $out;
			}
		}
		return ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
	}

	/**
	 * Per old→new / language counts.
	 *
	 * @param array $changes Change rows.
	 * @return array<string,array{orders:int,lines:int,old:string,new:string,language:string}>
	 */
	public static function summarize_counts(array $changes) {
		$buckets = [];
		$order_sets = [];
		foreach ($changes as $row) {
			$old = (string) ($row['old'] ?? '');
			$new = (string) ($row['new'] ?? '');
			$lang = (string) ($row['language'] ?? 'en');
			$key = $old . "\0" . $new . "\0" . $lang;
			if (!isset($buckets[$key])) {
				$buckets[$key] = [
					'old'      => $old,
					'new'      => $new,
					'language' => $lang,
					'orders'   => 0,
					'lines'    => 0,
				];
				$order_sets[$key] = [];
			}
			$buckets[$key]['lines']++;
			$oid = (int) ($row['order_id'] ?? 0);
			if ($oid > 0) {
				$order_sets[$key][$oid] = true;
			}
		}
		foreach ($buckets as $key => $bucket) {
			$buckets[$key]['orders'] = count($order_sets[$key] ?? []);
		}
		return array_values($buckets);
	}

	/**
	 * Persist one meta change via WC APIs.
	 *
	 * @param array $change Change row.
	 * @return bool
	 */
	public static function write_item_meta_change(array $change) {
		$item_id  = (int) ($change['item_id'] ?? 0);
		$meta_key = (string) ($change['meta_key'] ?? '');
		$new      = $change['new'] ?? '';
		if ($item_id <= 0 || $meta_key === '') {
			return false;
		}

		if (function_exists('wc_update_order_item_meta')) {
			wc_update_order_item_meta($item_id, $meta_key, $new);
			return true;
		}

		$order_id = (int) ($change['order_id'] ?? 0);
		if ($order_id > 0 && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order && method_exists($order, 'get_item')) {
				$item = $order->get_item($item_id);
				if ($item && method_exists($item, 'update_meta_data')) {
					$item->update_meta_data($meta_key, $new);
					if (method_exists($item, 'save')) {
						$item->save();
					}
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Backup directory under uploads (create + deny web access).
	 *
	 * @return string|WP_Error Absolute path.
	 */
	public static function backup_dir() {
		if (!empty($GLOBALS['intersoccer_test_season_label_backup_dir'])) {
			$dir = (string) $GLOBALS['intersoccer_test_season_label_backup_dir'];
		} elseif (function_exists('wp_upload_dir')) {
			$uploads = wp_upload_dir();
			if (!empty($uploads['error'])) {
				return new WP_Error('backup_dir', (string) $uploads['error']);
			}
			$dir = intersoccer_pv_season_label_trailingslashit($uploads['basedir']) . self::BACKUP_SUBDIR;
		} else {
			return new WP_Error('backup_dir', __('Uploads directory unavailable.', 'intersoccer-product-variations'));
		}

		if (!is_dir($dir)) {
			if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
				if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
					return new WP_Error('backup_dir', __('Could not create backup directory.', 'intersoccer-product-variations'));
				}
			}
		}

		$index = intersoccer_pv_season_label_trailingslashit($dir) . 'index.php';
		if (!file_exists($index)) {
			@file_put_contents($index, "<?php\n// Silence is golden.\n");
		}
		$htaccess = intersoccer_pv_season_label_trailingslashit($dir) . '.htaccess';
		if (!file_exists($htaccess)) {
			@file_put_contents($htaccess, "Deny from all\n");
		}

		return $dir;
	}

	/**
	 * Write JSON backup before apply.
	 *
	 * @param array $changes Rows to write.
	 * @return string|WP_Error Absolute backup path.
	 */
	public static function write_backup(array $changes) {
		$dir = self::backup_dir();
		if (is_wp_error($dir)) {
			return $dir;
		}

		$stamp = gmdate('Ymd-His');
		$path  = intersoccer_pv_season_label_trailingslashit($dir) . 'season-label-repair-' . $stamp . '.json';
		$payload = [
			'created_at' => gmdate('c'),
			'tool'       => 'order-season-label-repair',
			'entries'    => [],
		];
		foreach ($changes as $change) {
			$payload['entries'][] = [
				'item_id'    => (int) ($change['item_id'] ?? 0),
				'meta_key'   => (string) ($change['meta_key'] ?? ''),
				'old'        => $change['old'] ?? '',
				'new'        => $change['new'] ?? '',
				'order_id'   => (int) ($change['order_id'] ?? 0),
				'timestamp'  => gmdate('c'),
			];
		}

		$json = intersoccer_pv_season_label_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return new WP_Error('backup_encode', __('Could not encode backup JSON.', 'intersoccer-product-variations'));
		}
		$written = @file_put_contents($path, $json);
		if ($written === false) {
			return new WP_Error('backup_write', __('Could not write backup file.', 'intersoccer-product-variations'));
		}
		return $path;
	}

	/**
	 * List backup JSON files (newest first).
	 *
	 * @return array<int,array{path:string,basename:string,mtime:int}>
	 */
	public static function list_backups() {
		$dir = self::backup_dir();
		if (is_wp_error($dir) || !is_dir($dir)) {
			return [];
		}
		$files = glob(intersoccer_pv_season_label_trailingslashit($dir) . 'season-label-repair-*.json') ?: [];
		$out = [];
		foreach ($files as $path) {
			$out[] = [
				'path'     => $path,
				'basename' => basename($path),
				'mtime'    => (int) filemtime($path),
			];
		}
		usort($out, static function ($a, $b) {
			return $b['mtime'] <=> $a['mtime'];
		});
		return $out;
	}

	/**
	 * Rollback from a backup file. Only restores when current value still equals "new".
	 *
	 * @param string $backup_path Backup JSON path.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function rollback($backup_path) {
		$backup_path = (string) $backup_path;
		if ($backup_path === '' || !is_readable($backup_path)) {
			return new WP_Error('rollback_missing', __('Backup file not found.', 'intersoccer-product-variations'));
		}
		$dir = self::backup_dir();
		if (!is_wp_error($dir)) {
			$real_backup = realpath($backup_path);
			$real_dir    = realpath($dir);
			if ($real_backup === false || $real_dir === false || strpos($real_backup, $real_dir) !== 0) {
				return new WP_Error('rollback_path', __('Backup path is not inside the backup directory.', 'intersoccer-product-variations'));
			}
		}

		$data = json_decode((string) file_get_contents($backup_path), true);
		if (!is_array($data) || empty($data['entries']) || !is_array($data['entries'])) {
			return new WP_Error('rollback_invalid', __('Backup file is invalid.', 'intersoccer-product-variations'));
		}

		$result = [
			'restored'  => 0,
			'conflicts' => [],
			'skipped'   => [],
			'order_ids' => [],
		];
		$order_ids = [];

		foreach ($data['entries'] as $entry) {
			$item_id  = (int) ($entry['item_id'] ?? 0);
			$meta_key = (string) ($entry['meta_key'] ?? '');
			$old      = $entry['old'] ?? '';
			$new      = $entry['new'] ?? '';
			$order_id = (int) ($entry['order_id'] ?? 0);
			if ($item_id <= 0 || $meta_key === '') {
				continue;
			}

			$current = self::read_item_meta($item_id, $meta_key, $order_id);
			if ($current === null) {
				$result['skipped'][] = [
					'item_id'  => $item_id,
					'meta_key' => $meta_key,
					'reason'   => 'missing_item',
				];
				continue;
			}

			if (self::normalize_label((string) $current) !== self::normalize_label((string) $new)) {
				$result['conflicts'][] = [
					'item_id'  => $item_id,
					'meta_key' => $meta_key,
					'order_id' => $order_id,
					'expected_new' => $new,
					'current'  => $current,
					'would_restore' => $old,
				];
				continue;
			}

			$ok = self::write_item_meta_change([
				'item_id'  => $item_id,
				'meta_key' => $meta_key,
				'new'      => $old,
				'order_id' => $order_id,
			]);
			if ($ok) {
				$result['restored']++;
				if ($order_id > 0) {
					$order_ids[$order_id] = true;
				}
			}
		}

		$result['order_ids'] = array_keys($order_ids);
		if (!empty($result['order_ids'])) {
			do_action(self::ACTION_REPAIRED, $result['order_ids']);
		}

		return $result;
	}

	/**
	 * Read current item meta value.
	 *
	 * @param int    $item_id  Item ID.
	 * @param string $meta_key Meta key.
	 * @param int    $order_id Order ID hint.
	 * @return mixed|null
	 */
	public static function read_item_meta($item_id, $meta_key, $order_id = 0) {
		if (function_exists('wc_get_order_item_meta')) {
			$val = wc_get_order_item_meta($item_id, $meta_key, true);
			return $val;
		}
		if ($order_id > 0 && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order && method_exists($order, 'get_item')) {
				$item = $order->get_item($item_id);
				if ($item && method_exists($item, 'get_meta')) {
					return $item->get_meta($meta_key, true);
				}
			}
		}
		return null;
	}
}

/**
 * Admin tab slug for season label repair (Order Meta Repair).
 *
 * @return string
 */
function intersoccer_season_label_repair_tab_slug() {
	return 'season-labels';
}

/**
 * @param string $path Path.
 * @return string
 */
function intersoccer_pv_season_label_trailingslashit($path) {
	if (function_exists('trailingslashit')) {
		return trailingslashit($path);
	}
	return rtrim((string) $path, '/\\') . '/';
}

/**
 * @param mixed $data Data.
 * @param int   $options JSON options.
 * @return string|false
 */
function intersoccer_pv_season_label_json_encode($data, $options = 0) {
	if (function_exists('wp_json_encode')) {
		return wp_json_encode($data, $options);
	}
	return json_encode($data, $options);
}

/**
 * Persist dry-run preview for admin Apply gate.
 *
 * @param array $report Dry-run report.
 */
function intersoccer_season_label_repair_store_preview(array $report) {
	$payload = [
		'at'     => time(),
		'report' => $report,
	];
	$ttl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
	if (function_exists('set_transient')) {
		set_transient(InterSoccer_Order_Season_Label_Repair::PREVIEW_TRANSIENT, $payload, $ttl);
	} else {
		$GLOBALS[InterSoccer_Order_Season_Label_Repair::PREVIEW_TRANSIENT] = $payload;
	}
}

/**
 * @return array|null
 */
function intersoccer_season_label_repair_get_preview() {
	if (function_exists('get_transient')) {
		$data = get_transient(InterSoccer_Order_Season_Label_Repair::PREVIEW_TRANSIENT);
		return is_array($data) ? $data : null;
	}
	return isset($GLOBALS[InterSoccer_Order_Season_Label_Repair::PREVIEW_TRANSIENT])
		? $GLOBALS[InterSoccer_Order_Season_Label_Repair::PREVIEW_TRANSIENT]
		: null;
}

/**
 * Render season-label repair admin UI (English admin copy).
 *
 * @param bool $embedded When true, omit outer wrap (Order Meta Repair tab).
 */
function intersoccer_render_season_label_repair_page($embedded = false) {
	if (!current_user_can('manage_woocommerce')) {
		wp_die(__('You do not have permission to access this page.', 'intersoccer-product-variations'));
	}

	$notice  = '';
	$report  = null;
	$rollback_result = null;

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intersoccer_season_label_repair_action'])) {
		check_admin_referer('intersoccer_season_label_repair');
		$action = sanitize_key(wp_unslash((string) $_POST['intersoccer_season_label_repair_action']));

		if ($action === 'preview') {
			$report = InterSoccer_Order_Season_Label_Repair::run_dry();
			if (is_wp_error($report)) {
				$notice = $report->get_error_message();
				$report = null;
			} else {
				intersoccer_season_label_repair_store_preview($report);
				$notice = __('Dry-run preview completed. No meta was written.', 'intersoccer-product-variations');
			}
		} elseif ($action === 'apply') {
			$confirm = !empty($_POST['intersoccer_season_label_repair_confirm']);
			$preview = intersoccer_season_label_repair_get_preview();
			if (!$confirm) {
				$notice = __('Confirm the Apply checkbox after running a preview in this session.', 'intersoccer-product-variations');
			} elseif (!$preview || empty($preview['report'])) {
				$notice = __('Run Preview (dry run) in this session before Apply.', 'intersoccer-product-variations');
			} else {
				$report = InterSoccer_Order_Season_Label_Repair::run_apply();
				if (is_wp_error($report)) {
					$notice = $report->get_error_message();
					$report = null;
				} else {
					$notice = sprintf(
						/* translators: 1: updated line count, 2: backup filename */
						__('Updated %1$d line(s). Backup: %2$s', 'intersoccer-product-variations'),
						(int) ($report['updated'] ?? 0),
						$report['backup_file'] ? basename((string) $report['backup_file']) : '—'
					);
					intersoccer_season_label_repair_store_preview($report);
				}
			}
		} elseif ($action === 'rollback') {
			$file = isset($_POST['backup_file']) ? sanitize_file_name(wp_unslash((string) $_POST['backup_file'])) : '';
			$dir  = InterSoccer_Order_Season_Label_Repair::backup_dir();
			$path = is_wp_error($dir) ? '' : intersoccer_pv_season_label_trailingslashit($dir) . $file;
			$rollback_result = InterSoccer_Order_Season_Label_Repair::rollback($path);
			if (is_wp_error($rollback_result)) {
				$notice = $rollback_result->get_error_message();
				$rollback_result = null;
			} else {
				$notice = sprintf(
					/* translators: 1: restored count, 2: conflict count */
					__('Rollback restored %1$d value(s); %2$d conflict(s).', 'intersoccer-product-variations'),
					(int) ($rollback_result['restored'] ?? 0),
					count($rollback_result['conflicts'] ?? [])
				);
			}
		}
	}

	if ($report === null) {
		$stored = intersoccer_season_label_repair_get_preview();
		if ($stored && isset($stored['report']) && is_array($stored['report'])) {
			$report = $stored['report'];
		}
	}

	$backups = InterSoccer_Order_Season_Label_Repair::list_backups();
	?>
	<?php if (!$embedded) : ?>
	<div class="wrap">
		<h1><?php esc_html_e('Season label repair', 'intersoccer-product-variations'); ?></h1>
	<?php else : ?>
		<h2><?php esc_html_e('Season label repair', 'intersoccer-product-variations'); ?></h2>
	<?php endif; ?>
		<p><?php esc_html_e('Rewrite denormalized order-line Season labels after program-season terms were renamed in place. Dry run is the default; Apply writes a JSON backup first. Discount season keys are not modified.', 'intersoccer-product-variations'); ?></p>

		<?php if ($notice !== '') : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
		<?php endif; ?>

		<form method="post" style="margin-bottom: 1.5em;">
			<?php wp_nonce_field('intersoccer_season_label_repair'); ?>
			<button type="submit" class="button button-secondary" name="intersoccer_season_label_repair_action" value="preview">
				<?php esc_html_e('Preview (dry run)', 'intersoccer-product-variations'); ?>
			</button>
		</form>

		<form method="post" style="margin-bottom: 2em;">
			<?php wp_nonce_field('intersoccer_season_label_repair'); ?>
			<label>
				<input type="checkbox" name="intersoccer_season_label_repair_confirm" value="1" />
				<?php esc_html_e('I confirm this Apply follows a Preview in this session.', 'intersoccer-product-variations'); ?>
			</label>
			<p>
				<button type="submit" class="button button-primary" name="intersoccer_season_label_repair_action" value="apply">
					<?php esc_html_e('Apply', 'intersoccer-product-variations'); ?>
				</button>
			</p>
		</form>

		<?php if (is_array($report)) : ?>
			<h3><?php esc_html_e('Summary', 'intersoccer-product-variations'); ?></h3>
			<p>
				<?php
				echo esc_html(sprintf(
					/* translators: 1: mode, 2: orders, 3: lines */
					__('Mode: %1$s — orders: %2$d — lines: %3$d', 'intersoccer-product-variations'),
					(string) ($report['mode'] ?? ''),
					(int) ($report['orders'] ?? 0),
					(int) ($report['lines'] ?? 0)
				));
				?>
			</p>

			<?php if (!empty($report['counts'])) : ?>
				<table class="widefat striped" style="max-width: 960px; margin-bottom: 1.5em;">
					<thead>
						<tr>
							<th><?php esc_html_e('Old', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('New', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Language', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Orders', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Lines', 'intersoccer-product-variations'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($report['counts'] as $bucket) : ?>
							<tr>
								<td><?php echo esc_html((string) ($bucket['old'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($bucket['new'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($bucket['language'] ?? '')); ?></td>
								<td><?php echo esc_html((string) (int) ($bucket['orders'] ?? 0)); ?></td>
								<td><?php echo esc_html((string) (int) ($bucket['lines'] ?? 0)); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e('Per-order changes', 'intersoccer-product-variations'); ?></h3>
			<table class="widefat striped" style="max-width: 1100px; margin-bottom: 1.5em;">
				<thead>
					<tr>
						<th><?php esc_html_e('Order ID', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Item ID', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Old value', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('New value', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Language', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Status', 'intersoccer-product-variations'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($report['rows'])) : ?>
						<tr><td colspan="6"><?php esc_html_e('No matching lines.', 'intersoccer-product-variations'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($report['rows'] as $row) : ?>
							<tr>
								<td><?php echo esc_html((string) (int) ($row['order_id'] ?? 0)); ?></td>
								<td><?php echo esc_html((string) (int) ($row['item_id'] ?? 0)); ?></td>
								<td><?php echo esc_html((string) ($row['old'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($row['new'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($row['language'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($row['status'] ?? '')); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if (!empty($report['skipped'])) : ?>
				<h3><?php esc_html_e('Skipped', 'intersoccer-product-variations'); ?></h3>
				<table class="widefat striped" style="max-width: 1100px; margin-bottom: 1.5em;">
					<thead>
						<tr>
							<th><?php esc_html_e('Order ID', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Item ID', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Old value', 'intersoccer-product-variations'); ?></th>
							<th><?php esc_html_e('Reason', 'intersoccer-product-variations'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($report['skipped'] as $row) : ?>
							<tr>
								<td><?php echo esc_html((string) (int) ($row['order_id'] ?? 0)); ?></td>
								<td><?php echo esc_html((string) (int) ($row['item_id'] ?? 0)); ?></td>
								<td><?php echo esc_html((string) ($row['old'] ?? '')); ?></td>
								<td><?php echo esc_html((string) ($row['reason'] ?? '')); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if (!empty($report['unmatched_values'])) : ?>
				<h3><?php esc_html_e('Distinct unmatched Season values (affected products)', 'intersoccer-product-variations'); ?></h3>
				<ul>
					<?php foreach ($report['unmatched_values'] as $val) : ?>
						<li><code><?php echo esc_html((string) $val); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endif; ?>

		<h3><?php esc_html_e('Backups', 'intersoccer-product-variations'); ?></h3>
		<?php if (empty($backups)) : ?>
			<p><?php esc_html_e('No backups yet.', 'intersoccer-product-variations'); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 720px;">
				<thead>
					<tr>
						<th><?php esc_html_e('File', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Modified', 'intersoccer-product-variations'); ?></th>
						<th><?php esc_html_e('Actions', 'intersoccer-product-variations'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($backups as $backup) : ?>
						<tr>
							<td><code><?php echo esc_html($backup['basename']); ?></code></td>
							<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $backup['mtime'])); ?> UTC</td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field('intersoccer_season_label_repair'); ?>
									<input type="hidden" name="backup_file" value="<?php echo esc_attr($backup['basename']); ?>" />
									<button type="submit" class="button" name="intersoccer_season_label_repair_action" value="rollback">
										<?php esc_html_e('Rollback', 'intersoccer-product-variations'); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (is_array($rollback_result) && !empty($rollback_result['conflicts'])) : ?>
			<h3><?php esc_html_e('Rollback conflicts', 'intersoccer-product-variations'); ?></h3>
			<pre><?php echo esc_html((string) intersoccer_pv_season_label_json_encode($rollback_result['conflicts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
		<?php endif; ?>
	<?php if (!$embedded) : ?>
	</div>
	<?php endif; ?>
	<?php
}

if (defined('WP_CLI') && WP_CLI) {
	/**
	 * Repair denormalized order-line Season labels after term renames.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write changes (default is dry-run). Writes a JSON backup first.
	 *
	 * [--mapping=<file>]
	 * : Optional JSON mapping file (old labels → target EN term names).
	 *
	 * [--order=<id>]
	 * : Limit to one order ID.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * [--rollback=<backup.json>]
	 * : Restore from a backup basename or absolute path inside the backup dir.
	 *
	 * ## EXAMPLES
	 *
	 *     wp intersoccer season-label-repair
	 *     wp intersoccer season-label-repair --apply
	 *     wp intersoccer season-label-repair --mapping=/path/map.json --order=12345
	 *     wp intersoccer season-label-repair --rollback=season-label-repair-20260101-120000.json
	 */
	WP_CLI::add_command('intersoccer season-label-repair', function ($args, $assoc_args) {
		$format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
		if (!in_array($format, ['table', 'json'], true)) {
			WP_CLI::error('Invalid --format; use table or json.');
		}

		if (!empty($assoc_args['rollback'])) {
			$rollback = (string) $assoc_args['rollback'];
			$dir = InterSoccer_Order_Season_Label_Repair::backup_dir();
			if (is_wp_error($dir)) {
				WP_CLI::error($dir->get_error_message());
			}
			$path = $rollback;
			if ($path[0] !== '/' && strpos($path, DIRECTORY_SEPARATOR) === false) {
				$path = intersoccer_pv_season_label_trailingslashit($dir) . basename($rollback);
			}
			$result = InterSoccer_Order_Season_Label_Repair::rollback($path);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			if ($format === 'json') {
				WP_CLI::line((string) intersoccer_pv_season_label_json_encode($result));
			} else {
				WP_CLI::success(sprintf(
					'Restored %d; conflicts %d.',
					(int) ($result['restored'] ?? 0),
					count($result['conflicts'] ?? [])
				));
				if (!empty($result['conflicts'])) {
					WP_CLI::warning('Conflicts: ' . intersoccer_pv_season_label_json_encode($result['conflicts']));
				}
			}
			return;
		}

		$opts = [
			'apply'        => isset($assoc_args['apply']),
			'mapping_file' => isset($assoc_args['mapping']) ? (string) $assoc_args['mapping'] : null,
			'order_id'     => isset($assoc_args['order']) ? (int) $assoc_args['order'] : null,
		];

		$result = InterSoccer_Order_Season_Label_Repair::run($opts);
		if (is_wp_error($result)) {
			WP_CLI::error($result->get_error_message());
		}

		if ($format === 'json') {
			WP_CLI::line((string) intersoccer_pv_season_label_json_encode($result));
			return;
		}

		WP_CLI::log(sprintf(
			'Mode=%s orders=%d lines=%d unmatched=%d skipped=%d%s',
			$result['mode'],
			(int) $result['orders'],
			(int) $result['lines'],
			count($result['unmatched_values'] ?? []),
			count($result['skipped'] ?? []),
			!empty($result['backup_file']) ? ' backup=' . basename((string) $result['backup_file']) : ''
		));

		if (!empty($result['counts'])) {
			WP_CLI::log('Counts by old→new / language:');
			foreach ($result['counts'] as $bucket) {
				WP_CLI::log(sprintf(
					'  [%s] %s → %s | orders=%d lines=%d',
					$bucket['language'],
					$bucket['old'],
					$bucket['new'],
					(int) $bucket['orders'],
					(int) $bucket['lines']
				));
			}
		}

		if (!empty($result['rows'])) {
			$items = [];
			foreach ($result['rows'] as $row) {
				$items[] = [
					'order_id' => $row['order_id'],
					'item_id'  => $row['item_id'],
					'old'      => $row['old'],
					'new'      => $row['new'],
					'language' => $row['language'],
					'status'   => $row['status'],
				];
			}
			WP_CLI\Utils\format_items('table', $items, ['order_id', 'item_id', 'old', 'new', 'language', 'status']);
		}

		if (!empty($result['unmatched_values'])) {
			WP_CLI::log('Distinct unmatched Season values on affected products:');
			foreach ($result['unmatched_values'] as $val) {
				WP_CLI::log('  - ' . $val);
			}
		}

		if (!empty($result['skipped'])) {
			WP_CLI::log(sprintf('Skipped lines: %d (see JSON format for details).', count($result['skipped'])));
		}

		if ($result['mode'] === 'apply') {
			WP_CLI::success(sprintf('Updated %d line meta value(s).', (int) ($result['updated'] ?? 0)));
		} else {
			WP_CLI::success('Dry-run complete (no writes).');
		}
	});
}
