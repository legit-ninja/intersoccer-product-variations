<?php
/**
 * Camp schedule meta: first-class start/end/week on variations.
 *
 * Source of truth: `_camp_start_date`, `_camp_end_date`, `_camp_week_index`.
 * String parsing of pa_camp-terms is deprecated transitional fallback.
 *
 * @package InterSoccer_Product_Variations
 * @since 2.7.18
 */

if (!defined('ABSPATH')) {
	exit;
}

/** @var bool Flag for removal tracking — set false only after parse helpers are deleted. */
if (!defined('INTERSOCCER_CAMP_TERMS_DATE_PARSE_DEPRECATED')) {
	define('INTERSOCCER_CAMP_TERMS_DATE_PARSE_DEPRECATED', true);
}

/**
 * Camp schedule meta keys stored on variations.
 *
 * @return array{start:string,end:string,week:string}
 */
function intersoccer_camp_schedule_meta_keys() {
	return [
		'start' => '_camp_start_date',
		'end'   => '_camp_end_date',
		'week'  => '_camp_week_index',
	];
}

/**
 * Normalize a Y-m-d date string or return empty.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function intersoccer_camp_schedule_normalize_ymd($value) {
	$value = is_string($value) ? trim($value) : '';
	if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return '';
	}
	$ts = strtotime($value . ' 00:00:00');
	return $ts ? date('Y-m-d', $ts) : '';
}

/**
 * Read raw schedule meta from a variation (no fallback).
 *
 * @param int $variation_id Variation ID.
 * @return array{start:string,end:string,week:int|null}
 */
function intersoccer_get_camp_schedule_meta($variation_id) {
	$variation_id = (int) $variation_id;
	$keys         = intersoccer_camp_schedule_meta_keys();
	$start        = $variation_id ? intersoccer_camp_schedule_normalize_ymd(get_post_meta($variation_id, $keys['start'], true)) : '';
	$end          = $variation_id ? intersoccer_camp_schedule_normalize_ymd(get_post_meta($variation_id, $keys['end'], true)) : '';
	$week_raw     = $variation_id ? get_post_meta($variation_id, $keys['week'], true) : '';
	$week         = ($week_raw !== '' && $week_raw !== null && is_numeric($week_raw)) ? (int) $week_raw : null;

	return [
		'start' => $start,
		'end'   => $end,
		'week'  => $week,
	];
}

/**
 * Persist camp schedule meta on a variation.
 *
 * @param int         $variation_id Variation ID.
 * @param string|null $start        Y-m-d or empty to clear.
 * @param string|null $end          Y-m-d or empty to clear.
 * @param int|null    $week         Week index or null to leave unchanged when $week_set false.
 * @param bool        $week_set     Whether to write week (including clearing with null/0).
 * @return bool
 */
function intersoccer_update_camp_schedule($variation_id, $start = null, $end = null, $week = null, $week_set = true) {
	$variation_id = (int) $variation_id;
	if ($variation_id <= 0) {
		return false;
	}

	$keys = intersoccer_camp_schedule_meta_keys();

	if ($start !== null) {
		$norm = intersoccer_camp_schedule_normalize_ymd((string) $start);
		if ($norm === '') {
			delete_post_meta($variation_id, $keys['start']);
		} else {
			update_post_meta($variation_id, $keys['start'], $norm);
		}
	}

	if ($end !== null) {
		$norm = intersoccer_camp_schedule_normalize_ymd((string) $end);
		if ($norm === '') {
			delete_post_meta($variation_id, $keys['end']);
		} else {
			update_post_meta($variation_id, $keys['end'], $norm);
		}
	}

	if ($week_set) {
		if ($week === null || (int) $week <= 0) {
			delete_post_meta($variation_id, $keys['week']);
		} else {
			update_post_meta($variation_id, $keys['week'], (int) $week);
		}
	}

	if (function_exists('intersoccer_sync_camp_schedule_to_translations')) {
		intersoccer_sync_camp_schedule_to_translations($variation_id);
	}

	return true;
}

/**
 * Whether WPML (or compatible filter surface) is available for variation sync.
 *
 * @return bool
 */
function intersoccer_camp_schedule_wpml_available() {
	return defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_current_language');
}

/**
 * Copy camp schedule meta from a variation to its WPML translations (FR/DE, …).
 * Uses update_post_meta only — does not call intersoccer_update_camp_schedule (no recursion).
 *
 * @param int $variation_id Source variation ID (any language).
 * @return void
 */
function intersoccer_sync_camp_schedule_to_translations($variation_id) {
	$variation_id = (int) $variation_id;
	if ($variation_id <= 0 || !intersoccer_camp_schedule_wpml_available()) {
		return;
	}

	$languages = apply_filters('wpml_active_languages', null);
	if (!$languages || !is_array($languages)) {
		return;
	}

	$keys     = intersoccer_camp_schedule_meta_keys();
	$schedule = intersoccer_get_camp_schedule_meta($variation_id);

	$siblings = function_exists('intersoccer_foreach_translated_product_variations')
		? intersoccer_foreach_translated_product_variations($variation_id)
		: [];

	if ($siblings === []) {
		// Fallback when language-helpers not loaded yet (same resolution as course sync).
		$original_lang = apply_filters('wpml_element_language_code', null, [
			'element_id'   => $variation_id,
			'element_type' => 'post_product_variation',
		]);
		if (!$original_lang) {
			$original_lang = apply_filters('wpml_current_language', null);
		}
		foreach (array_keys($languages) as $lang_code) {
			if ($lang_code === $original_lang) {
				continue;
			}
			$tid = (int) apply_filters('wpml_object_id', $variation_id, 'product_variation', false, $lang_code);
			if ($tid > 0 && $tid !== $variation_id) {
				$siblings[] = $tid;
			}
		}
		$siblings = array_values(array_unique($siblings));
	}

	foreach ($siblings as $translated_variation_id) {
		$translated_variation_id = (int) $translated_variation_id;
		if ($translated_variation_id <= 0 || $translated_variation_id === $variation_id) {
			continue;
		}

		if ($schedule['start'] === '') {
			delete_post_meta($translated_variation_id, $keys['start']);
		} else {
			update_post_meta($translated_variation_id, $keys['start'], $schedule['start']);
		}
		if ($schedule['end'] === '') {
			delete_post_meta($translated_variation_id, $keys['end']);
		} else {
			update_post_meta($translated_variation_id, $keys['end'], $schedule['end']);
		}
		if ($schedule['week'] === null || (int) $schedule['week'] <= 0) {
			delete_post_meta($translated_variation_id, $keys['week']);
		} else {
			update_post_meta($translated_variation_id, $keys['week'], (int) $schedule['week']);
		}

		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients($translated_variation_id);
		}

		if (defined('WP_DEBUG') && WP_DEBUG && function_exists('intersoccer_debug')) {
			intersoccer_debug(
				'Synced camp schedule from variation ' . $variation_id . ' to translated variation ' . $translated_variation_id
			);
		}
	}
}

/**
 * When a WPML translation of a camp variation is completed, copy schedule from default language.
 *
 * @param int                  $post_id Translated post ID.
 * @param array<string,mixed>  $data    Job data.
 * @param object|null          $job     Translation job.
 * @return void
 */
function intersoccer_sync_camp_schedule_on_translation_complete($post_id, $data = [], $job = null) {
	$post_id = (int) $post_id;
	if ($post_id <= 0 || !intersoccer_camp_schedule_wpml_available()) {
		return;
	}
	if (function_exists('get_post_type') && get_post_type($post_id) !== 'product_variation') {
		return;
	}

	$parent_id = function_exists('wp_get_post_parent_id') ? (int) wp_get_post_parent_id($post_id) : 0;
	if ($parent_id <= 0) {
		return;
	}
	if (function_exists('intersoccer_is_camp') && !intersoccer_is_camp($parent_id)) {
		return;
	}

	$original_lang = apply_filters('wpml_default_language', null) ?: 'en';
	$original_variation_id = (int) apply_filters('wpml_object_id', $post_id, 'product_variation', true, $original_lang);
	if ($original_variation_id <= 0 || $original_variation_id === $post_id) {
		return;
	}

	$keys = intersoccer_camp_schedule_meta_keys();
	foreach ($keys as $meta_key) {
		$original_value = get_post_meta($original_variation_id, $meta_key, true);
		if ($original_value !== '' && $original_value !== null) {
			update_post_meta($post_id, $meta_key, $original_value);
		}
	}

	if (function_exists('wc_delete_product_transients')) {
		wc_delete_product_transients($post_id);
	}
}

if (function_exists('add_action')) {
	add_action('wpml_pro_translation_completed', 'intersoccer_sync_camp_schedule_on_translation_complete', 10, 3);
}

/**
 * Deprecated: derive schedule from camp-terms label/slug + season.
 *
 * @deprecated since 2.7.18 Remove in 2.9.0 or next major — camp dates must live in variation meta.
 *
 * @param string $camp_terms Camp terms slug or display label.
 * @param string $season     Season string (for year).
 * @return array{start:string,end:string,week:int|null,source:string}
 */
function intersoccer_camp_schedule_from_terms_deprecated($camp_terms, $season = '') {
	$camp_terms = trim((string) $camp_terms);
	$season     = (string) $season;
	$result     = [
		'start'  => '',
		'end'    => '',
		'week'   => null,
		'source' => 'terms_parse',
	];

	if ($camp_terms === '' || $camp_terms === 'N/A') {
		return $result;
	}

	if (function_exists('intersoccer_parse_camp_dates_fixed')) {
		list($start, $end) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
		$result['start'] = intersoccer_camp_schedule_normalize_ymd((string) $start);
		$result['end']   = intersoccer_camp_schedule_normalize_ymd((string) $end);
	} elseif (function_exists('intersoccer_parse_camp_start_date_from_terms')) {
		$start = intersoccer_parse_camp_start_date_from_terms($camp_terms, $season);
		$result['start'] = intersoccer_camp_schedule_normalize_ymd((string) $start);
		// Best-effort end from same-month / cross-month slug patterns when RR helper absent.
		if ($result['start'] !== '' && preg_match('/(\w+)[\s\-]+(\d{1,2})[\s\-]+(?:(\w+)[\s\-]+)?(\d{1,2})/i', $camp_terms, $m)) {
			// Prefer dedicated PV end parse via start+duration from "(N days)" when present.
			if (preg_match('/\((\d+)\s*days?\)/i', $camp_terms, $dm)) {
				$days = max(1, (int) $dm[1]);
				$ts   = strtotime($result['start'] . ' 00:00:00');
				if ($ts) {
					$result['end'] = date('Y-m-d', strtotime('+' . ($days - 1) . ' days', $ts));
				}
			}
		}
	}

	if (function_exists('intersoccer_parse_camp_week_from_terms')) {
		$week = intersoccer_parse_camp_week_from_terms($camp_terms);
		$result['week'] = $week !== null ? (int) $week : null;
	} elseif (preg_match('/week[\s\-]+(\d+)/i', $camp_terms, $wm)) {
		$result['week'] = (int) $wm[1];
	}

	return $result;
}

/**
 * Rate-limited debug when deprecated camp-terms parse is used.
 *
 * @param int    $variation_id Variation ID.
 * @param string $context      Caller context.
 */
function intersoccer_camp_schedule_log_deprecated_parse($variation_id, $context = '') {
	if (!INTERSOCCER_CAMP_TERMS_DATE_PARSE_DEPRECATED) {
		return;
	}
	if (!defined('WP_DEBUG') || !WP_DEBUG) {
		return;
	}
	$bucket = 'intersoccer_camp_terms_parse_' . gmdate('YmdH');
	$count  = (int) get_transient($bucket);
	if ($count >= 20) {
		return;
	}
	set_transient($bucket, $count + 1, HOUR_IN_SECONDS);
	if (function_exists('intersoccer_debug')) {
		intersoccer_debug(sprintf(
			'InterSoccer: DEPRECATED camp-terms date parse used (variation=%d context=%s). Prefer _camp_start_date/_camp_end_date.',
			(int) $variation_id,
			$context
		));
	}
}

/**
 * Resolve camp schedule for a variation: meta first, then deprecated terms parse.
 *
 * @param int  $variation_id Variation ID.
 * @param bool $allow_fallback Whether to parse camp-terms when meta incomplete.
 * @return array{start:string,end:string,week:int|null,source:string}
 */
function intersoccer_get_camp_schedule($variation_id, $allow_fallback = true) {
	$variation_id = (int) $variation_id;
	$meta         = intersoccer_get_camp_schedule_meta($variation_id);
	$out          = [
		'start'  => $meta['start'],
		'end'    => $meta['end'],
		'week'   => $meta['week'],
		'source' => 'meta',
	];

	$needs_dates = ($out['start'] === '' || $out['end'] === '');
	$needs_week  = ($out['week'] === null);

	// WPML: empty translation meta → read default-language variation (before deprecated terms parse).
	if ($allow_fallback && ($needs_dates || $needs_week) && intersoccer_camp_schedule_wpml_available()) {
		$default_lang = apply_filters('wpml_default_language', null) ?: 'en';
		$default_id   = (int) apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);
		if ($default_id > 0 && $default_id !== $variation_id) {
			$default_meta = intersoccer_get_camp_schedule_meta($default_id);
			if ($out['start'] === '' && $default_meta['start'] !== '') {
				$out['start']  = $default_meta['start'];
				$out['source'] = 'wpml_default';
			}
			if ($out['end'] === '' && $default_meta['end'] !== '') {
				$out['end']    = $default_meta['end'];
				$out['source'] = 'wpml_default';
			}
			if ($out['week'] === null && $default_meta['week'] !== null) {
				$out['week']   = $default_meta['week'];
				if ($out['source'] === 'meta') {
					$out['source'] = 'wpml_default';
				}
			}
			$needs_dates = ($out['start'] === '' || $out['end'] === '');
			$needs_week  = ($out['week'] === null);
		}
	}

	if (!$allow_fallback || (!$needs_dates && !$needs_week)) {
		return $out;
	}

	$product = wc_get_product($variation_id);
	if (!$product) {
		return $out;
	}

	$parent_id = $product->get_parent_id() ?: $variation_id;
	$terms     = '';
	if (function_exists('intersoccer_get_camp_terms_slug')) {
		$terms = intersoccer_get_camp_terms_slug($parent_id, $variation_id);
	}
	if ($terms === '') {
		$terms = (string) $product->get_attribute('pa_camp-terms');
	}

	$season = '';
	if (function_exists('intersoccer_get_variation_program_season_string')) {
		$season = intersoccer_get_variation_program_season_string($parent_id, $variation_id);
	}

	$parsed = intersoccer_camp_schedule_from_terms_deprecated($terms, $season);
	intersoccer_camp_schedule_log_deprecated_parse($variation_id, 'intersoccer_get_camp_schedule');

	if ($out['start'] === '' && $parsed['start'] !== '') {
		$out['start']  = $parsed['start'];
		$out['source'] = 'terms_parse';
	}
	if ($out['end'] === '' && $parsed['end'] !== '') {
		$out['end']    = $parsed['end'];
		$out['source'] = 'terms_parse';
	}
	if ($out['week'] === null && $parsed['week'] !== null) {
		$out['week']   = $parsed['week'];
		$out['source'] = ($out['source'] === 'meta' && $out['start'] !== '' && $meta['start'] !== '') ? 'meta' : 'terms_parse';
	}

	return $out;
}

/**
 * Propose start/end for week N from a week-1 anchor.
 *
 * @param string $week1_start Y-m-d.
 * @param int    $week_index  1-based week index.
 * @param int    $duration_days Inclusive day count (default 5).
 * @return array{start:string,end:string}
 */
function intersoccer_camp_schedule_propose_from_week1($week1_start, $week_index, $duration_days = 5) {
	$week1_start   = intersoccer_camp_schedule_normalize_ymd($week1_start);
	$week_index    = max(1, (int) $week_index);
	$duration_days = max(1, (int) $duration_days);

	if ($week1_start === '') {
		return ['start' => '', 'end' => ''];
	}

	$ts = strtotime($week1_start . ' 00:00:00');
	if (!$ts) {
		return ['start' => '', 'end' => ''];
	}

	$offset = ($week_index - 1) * 7;
	$start  = date('Y-m-d', strtotime('+' . $offset . ' days', $ts));
	$end    = date('Y-m-d', strtotime('+' . ($duration_days - 1) . ' days', strtotime($start . ' 00:00:00')));

	return ['start' => $start, 'end' => $end];
}
