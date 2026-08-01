<?php
/**
 * Program Manager — Sync all languages (WPML/WCML).
 *
 * Creates/links missing FR/DE product + variation translations via WPML/WCML APIs,
 * then fans out InterSoccer catalogue data using shared EN attribute slugs.
 * Also copies parent-level taxonomy attributes (e.g. pa_program-year, pa_girls-only)
 * so legacy translations incomplete before Program Manager become catalogue-complete.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Taxonomy attributes copied to translations as shared EN slugs.
 *
 * @return string[]
 */
function intersoccer_pm_wpml_sync_attribute_taxonomies() {
	return [
		'pa_activity-type',
		'pa_intersoccer-venues',
		'pa_program-season',
		'pa_age-group',
		'pa_canton-region',
		'pa_city',
		'pa_booking-type',
		'pa_days-of-week',
		'pa_camp-terms',
		'pa_course-day',
		'pa_course-times',
		'pa_camp-times',
		'pa_girls-only',
		'pa_date',
		'pa_tournament-day',
		'pa_tournament-time',
		'pa_note',
	];
}

/**
 * Whether WPML is available for product translation sync.
 *
 * @return bool
 */
function intersoccer_pm_wpml_available() {
	return defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_current_language');
}

/**
 * Resolve a WC product (filterable for unit tests).
 *
 * @param int $product_id
 * @return mixed WC_Product|false
 */
function intersoccer_pm_wpml_get_product($product_id) {
	$product_id = (int) $product_id;
	$filtered = apply_filters('intersoccer_pm_wpml_get_product', null, $product_id);
	if ($filtered !== null) {
		return $filtered;
	}
	if (!function_exists('wc_get_product')) {
		return false;
	}
	return wc_get_product($product_id);
}

/**
 * Empty sync report.
 *
 * @param int $product_id
 * @return array
 */
function intersoccer_pm_wpml_sync_empty_report($product_id = 0) {
	return [
		'ok'                   => false,
		'source_product_id'    => (int) $product_id,
		'parents_created'      => [],
		'parents_synced'       => [],
		'parents_attrs_updated'=> 0,
		'status_synced'        => [],
		'variations_linked'    => 0,
		'meta_synced'          => 0,
		'skipped'              => [],
		'errors'               => [],
		'message'              => '',
	];
}

/**
 * Resolve the default-language product ID to sync from.
 *
 * @param int $product_id Requested product ID (any language).
 * @return array{id:int,default_lang:string,error:?string}
 */
function intersoccer_pm_wpml_resolve_source_product($product_id) {
	$product_id = (int) $product_id;
	$default_lang = apply_filters('wpml_default_language', null) ?: 'en';

	if ($product_id <= 0) {
		return ['id' => 0, 'default_lang' => $default_lang, 'error' => 'invalid_product'];
	}

	$source_id = (int) apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);
	if ($source_id <= 0) {
		$source_id = $product_id;
	}

	$lang = apply_filters('wpml_element_language_code', null, [
		'element_id'   => $source_id,
		'element_type' => 'post_product',
	]);
	if ($lang && $lang !== $default_lang) {
		$resolved = (int) apply_filters('wpml_object_id', $source_id, 'product', true, $default_lang);
		if ($resolved > 0) {
			$source_id = $resolved;
		}
	}

	return ['id' => $source_id, 'default_lang' => $default_lang, 'error' => null];
}

/**
 * Create a product translation via WPML (filterable for tests).
 *
 * @param int    $master_id Default-language product ID.
 * @param string $lang      Target language code.
 * @return int|false New product ID or false.
 */
function intersoccer_pm_wpml_make_duplicate($master_id, $lang) {
	$master_id = (int) $master_id;
	$lang      = sanitize_key((string) $lang);
	if ($master_id <= 0 || $lang === '') {
		return false;
	}

	$filtered = apply_filters('intersoccer_pm_wpml_make_duplicate', null, $master_id, $lang);
	if ($filtered !== null) {
		return is_numeric($filtered) ? (int) $filtered : false;
	}

	global $sitepress;
	if (!$sitepress || !is_object($sitepress) || !method_exists($sitepress, 'make_duplicate')) {
		return false;
	}

	$result = $sitepress->make_duplicate($master_id, $lang);
	if (is_wp_error($result) || !$result) {
		return false;
	}

	return (int) $result;
}

/**
 * Ask WCML to synchronize product components (variations, attributes, …).
 *
 * @param WP_Post|object $product_post EN product post.
 * @param int[]          $tr_ids       Translation product IDs.
 * @param array          $lang_map     Map translation_id => language code.
 * @return void
 */
function intersoccer_pm_wcml_synchronize_product($product_post, array $tr_ids, array $lang_map) {
	$handled = apply_filters('intersoccer_pm_wcml_synchronize_product', false, $product_post, $tr_ids, $lang_map);
	if ($handled) {
		return;
	}

	if (!function_exists('has_action') || has_action('wcml_synchronize_product_translations')) {
		if (function_exists('do_action')) {
			do_action('wcml_synchronize_product_translations', $product_post, $tr_ids, $lang_map);
		}
		return;
	}

	global $woocommerce_wpml;
	if ($woocommerce_wpml && isset($woocommerce_wpml->sync_variations_data)
		&& is_object($woocommerce_wpml->sync_variations_data)
		&& method_exists($woocommerce_wpml->sync_variations_data, 'sync_product_variations')) {
		foreach ($lang_map as $tr_id => $lang) {
			$woocommerce_wpml->sync_variations_data->sync_product_variations(
				(int) $product_post->ID,
				(int) $tr_id,
				$lang,
				['is_duplicate' => true]
			);
		}
	}
}

/**
 * Copy parent taxonomy attribute options (shared EN slugs) onto translated parents.
 * Fixes legacy FR/DE products missing non-variation attrs like pa_program-year / pa_girls-only.
 *
 * @param int   $source_id Default-language product ID.
 * @param int[] $tr_ids    Translation product IDs.
 * @return int Number of translation parents updated.
 */
function intersoccer_pm_wpml_sync_parent_attributes($source_id, array $tr_ids) {
	$source_id = (int) $source_id;
	$source    = intersoccer_pm_wpml_get_product($source_id);
	if (!$source || !is_object($source) || !method_exists($source, 'get_attributes')) {
		return 0;
	}
	if (!class_exists('WC_Product_Attribute')) {
		return 0;
	}

	$source_attrs = $source->get_attributes();
	if (!is_array($source_attrs) || $source_attrs === []) {
		return 0;
	}

	$updated = 0;
	foreach ($tr_ids as $tr_id) {
		$tr_id = (int) $tr_id;
		if ($tr_id <= 0 || $tr_id === $source_id) {
			continue;
		}
		$tr = intersoccer_pm_wpml_get_product($tr_id);
		if (!$tr || !is_object($tr) || !method_exists($tr, 'get_attributes')) {
			continue;
		}

		$new_attrs = $tr->get_attributes();
		if (!is_array($new_attrs)) {
			$new_attrs = [];
		}
		$changed = false;

		foreach ($source_attrs as $taxonomy => $attr) {
			$taxonomy = (string) $taxonomy;
			if ($taxonomy === '' || !is_object($attr)) {
				continue;
			}
			if (strpos($taxonomy, 'pa_') !== 0 || !taxonomy_exists($taxonomy)) {
				continue;
			}

			$slugs = [];
			if (function_exists('wc_get_product_terms')) {
				$slugs = wc_get_product_terms($source_id, $taxonomy, ['fields' => 'slugs']);
				if (is_wp_error($slugs)) {
					$slugs = [];
				}
			}
			if ($slugs === [] && method_exists($attr, 'get_options')) {
				foreach ((array) $attr->get_options() as $opt) {
					if (is_numeric($opt)) {
						$term = get_term((int) $opt, $taxonomy);
						if ($term && !is_wp_error($term)) {
							$slugs[] = $term->slug;
						}
					} elseif (is_string($opt) && $opt !== '') {
						$slugs[] = $opt;
					}
				}
			}
			$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
			if ($slugs === []) {
				continue;
			}

			if (function_exists('wp_set_object_terms')) {
				wp_set_object_terms($tr_id, $slugs, $taxonomy);
			}

			$term_ids = [];
			foreach ($slugs as $slug) {
				$term = get_term_by('slug', $slug, $taxonomy);
				if ($term && !is_wp_error($term)) {
					$term_ids[] = (int) $term->term_id;
				}
			}
			if ($term_ids === []) {
				continue;
			}

			$is_variation = method_exists($attr, 'get_variation') ? (bool) $attr->get_variation() : false;
			$is_visible   = method_exists($attr, 'get_visible') ? (bool) $attr->get_visible() : true;
			$attr_id      = function_exists('wc_attribute_taxonomy_id_by_name')
				? (int) wc_attribute_taxonomy_id_by_name($taxonomy)
				: 0;

			if (isset($new_attrs[$taxonomy]) && is_object($new_attrs[$taxonomy])) {
				$new_attrs[$taxonomy]->set_id($attr_id);
				$new_attrs[$taxonomy]->set_name($taxonomy);
				$new_attrs[$taxonomy]->set_options($term_ids);
				$new_attrs[$taxonomy]->set_variation($is_variation);
				$new_attrs[$taxonomy]->set_visible($is_visible);
			} else {
				$attribute = new WC_Product_Attribute();
				$attribute->set_id($attr_id);
				$attribute->set_name($taxonomy);
				$attribute->set_options($term_ids);
				$attribute->set_visible($is_visible);
				$attribute->set_variation($is_variation);
				$new_attrs[$taxonomy] = $attribute;
			}
			$changed = true;
		}

		if ($changed) {
			$tr->set_attributes($new_attrs);
			$tr->save();
			if (function_exists('wc_delete_product_transients')) {
				wc_delete_product_transients($tr_id);
			}
			$updated++;
		}
	}

	return $updated;
}

/**
 * Fan out InterSoccer variation data from an EN variation to WPML siblings.
 *
 * @param int $variation_id EN (source) variation ID.
 * @return int Number of sibling variations touched (attrs/price/schedule/meta).
 */
function intersoccer_pm_wpml_fan_out_variation($variation_id) {
	$variation_id = (int) $variation_id;
	if ($variation_id <= 0) {
		return 0;
	}

	$siblings = function_exists('intersoccer_foreach_translated_product_variations')
		? intersoccer_foreach_translated_product_variations($variation_id)
		: [];
	if ($siblings === []) {
		return 0;
	}

	$touched = count($siblings);

	if (function_exists('intersoccer_sync_camp_schedule_to_translations')) {
		intersoccer_sync_camp_schedule_to_translations($variation_id);
	}

	$attrs = [];
	if (function_exists('wc_get_product')) {
		$variation = wc_get_product($variation_id);
		if ($variation && is_a($variation, 'WC_Product_Variation')) {
			$attrs = $variation->get_attributes();
			$regular = $variation->get_regular_price();
			$price   = $variation->get_price();
			if ($regular !== '' && $regular !== null && function_exists('intersoccer_sync_variation_prices_to_translations')) {
				intersoccer_sync_variation_prices_to_translations($variation_id, (string) $regular, (string) $price);
			}
		}
	}

	foreach (intersoccer_pm_wpml_sync_attribute_taxonomies() as $taxonomy) {
		$slug = '';
		if (isset($attrs[$taxonomy]) && $attrs[$taxonomy] !== '') {
			$slug = (string) $attrs[$taxonomy];
		} else {
			$meta = get_post_meta($variation_id, 'attribute_' . $taxonomy, true);
			if (is_string($meta) && $meta !== '') {
				$slug = $meta;
			}
		}
		if ($slug === '' && empty($attrs[$taxonomy])) {
			continue;
		}
		if (function_exists('intersoccer_sync_variation_taxonomy_attribute_to_translations')) {
			intersoccer_sync_variation_taxonomy_attribute_to_translations($variation_id, $taxonomy, $slug);
		}
	}

	if (function_exists('intersoccer_sync_course_metadata_to_translations')) {
		$start = (string) get_post_meta($variation_id, '_course_start_date', true);
		if ($start !== '') {
			$weeks    = (int) get_post_meta($variation_id, '_course_total_weeks', true);
			$holidays = get_post_meta($variation_id, '_course_holiday_dates', true);
			$discount = get_post_meta($variation_id, '_course_weekly_discount', true);
			$end      = (string) get_post_meta($variation_id, '_end_date', true);
			intersoccer_sync_course_metadata_to_translations(
				$variation_id,
				$start,
				$weeks,
				is_array($holidays) ? $holidays : [],
				is_numeric($discount) ? (float) $discount : 0.0,
				$end
			);
		}
	}

	foreach ([
		'_intersoccer_enable_late_pickup',
		'_intersoccer_camp_days_available',
		'_intersoccer_late_pickup_days_available',
		'_intersoccer_product_type',
		'_camp_times',
	] as $meta_key) {
		$value = get_post_meta($variation_id, $meta_key, true);
		if ($value === '' || $value === null || $value === false) {
			continue;
		}
		foreach ($siblings as $tid) {
			update_post_meta((int) $tid, $meta_key, $value);
		}
	}

	return $touched;
}

/**
 * Sync all active languages for a variable product (EN source of truth).
 *
 * Applies to any Program Manager program (camp, course, birthday, tournament):
 * create/link FR/DE parents + variations, copy parent attrs, fan out variation data.
 *
 * @param int $product_id Product ID (resolved to default language).
 * @return array Report (see intersoccer_pm_wpml_sync_empty_report).
 */
function intersoccer_pm_sync_product_translations($product_id) {
	$report = intersoccer_pm_wpml_sync_empty_report($product_id);

	if (!intersoccer_pm_wpml_available()) {
		$report['errors'][] = 'wpml_unavailable';
		$report['message']  = __('WPML is not available.', 'intersoccer-product-variations');
		return $report;
	}

	$resolved = intersoccer_pm_wpml_resolve_source_product($product_id);
	$source_id = (int) $resolved['id'];
	$default_lang = (string) $resolved['default_lang'];
	$report['source_product_id'] = $source_id;

	if ($source_id <= 0) {
		$report['errors'][] = 'invalid_product';
		$report['message']  = __('Invalid product.', 'intersoccer-product-variations');
		return $report;
	}

	$product = intersoccer_pm_wpml_get_product($source_id);
	if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
		$report['errors'][] = 'not_variable';
		$report['message']  = __('Product must be a variable product.', 'intersoccer-product-variations');
		return $report;
	}

	$languages = apply_filters('wpml_active_languages', null);
	if (!$languages || !is_array($languages)) {
		$report['errors'][] = 'no_languages';
		$report['message']  = __('No active WPML languages found.', 'intersoccer-product-variations');
		return $report;
	}

	$product_post = function_exists('get_post') ? get_post($source_id) : null;
	if (!$product_post) {
		$product_post = (object) ['ID' => $source_id, 'post_type' => 'product'];
	}

	$tr_ids_for_sync = [];
	$lang_map        = [];

	foreach (array_keys($languages) as $lang_code) {
		$lang_code = sanitize_key((string) $lang_code);
		if ($lang_code === '' || $lang_code === $default_lang) {
			continue;
		}

		$existing = (int) apply_filters('wpml_object_id', $source_id, 'product', false, $lang_code);
		if ($existing > 0 && $existing !== $source_id) {
			$report['parents_synced'][$lang_code] = $existing;
			$tr_ids_for_sync[] = $existing;
			$lang_map[$existing] = $lang_code;
			continue;
		}

		$created = intersoccer_pm_wpml_make_duplicate($source_id, $lang_code);
		if ($created > 0 && $created !== $source_id) {
			$report['parents_created'][$lang_code] = $created;
			$tr_ids_for_sync[] = $created;
			$lang_map[$created] = $lang_code;
		} else {
			$report['errors'][] = 'create_failed_' . $lang_code;
			$report['skipped'][] = $lang_code;
		}
	}

	if ($tr_ids_for_sync !== []) {
		intersoccer_pm_wcml_synchronize_product($product_post, $tr_ids_for_sync, $lang_map);
		$report['parents_attrs_updated'] = intersoccer_pm_wpml_sync_parent_attributes($source_id, $tr_ids_for_sync);
	}

	$children = $product->get_children();
	$meta_synced = 0;
	$variations_linked = 0;

	foreach ($children as $var_id) {
		$var_id = (int) $var_id;
		$before = function_exists('intersoccer_foreach_translated_product_variations')
			? intersoccer_foreach_translated_product_variations($var_id)
			: [];
		$touched = intersoccer_pm_wpml_fan_out_variation($var_id);
		$after = function_exists('intersoccer_foreach_translated_product_variations')
			? intersoccer_foreach_translated_product_variations($var_id)
			: [];
		$new_links = count(array_diff($after, $before));
		$variations_linked += max($new_links, 0);
		if ($touched > 0) {
			$meta_synced += $touched;
		}
	}

	if ($variations_linked === 0 && $tr_ids_for_sync !== []) {
		foreach ($children as $var_id) {
			$siblings = function_exists('intersoccer_foreach_translated_product_variations')
				? intersoccer_foreach_translated_product_variations((int) $var_id)
				: [];
			$variations_linked += count($siblings);
		}
	}

	$report['variations_linked'] = $variations_linked;
	$report['meta_synced']       = $meta_synced;

	// Match EN product status onto FR/DE parents (create + refresh).
	$en_status = method_exists($product, 'get_status') ? (string) $product->get_status() : '';
	if ($en_status !== '' && function_exists('intersoccer_sync_product_status_to_translations')) {
		$report['status_synced'] = intersoccer_sync_product_status_to_translations($source_id, $en_status);
	} else {
		$report['status_synced'] = [];
	}

	$report['ok'] = empty($report['errors']);

	$created_n = count($report['parents_created']);
	$synced_n  = count($report['parents_synced']);
	$report['message'] = sprintf(
		/* translators: 1: parents created, 2: parents refreshed, 3: variation siblings synced */
		__('WPML sync: %1$d language(s) created, %2$d refreshed, %3$d variation sibling(s) updated.', 'intersoccer-product-variations'),
		$created_n,
		$synced_n,
		$meta_synced
	);

	if (function_exists('wc_delete_product_transients')) {
		wc_delete_product_transients($source_id);
		foreach ($lang_map as $tr_id => $_lang) {
			wc_delete_product_transients((int) $tr_id);
		}
	}

	return $report;
}
