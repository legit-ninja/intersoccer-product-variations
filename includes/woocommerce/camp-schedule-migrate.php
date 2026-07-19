<?php
/**
 * Migrate camp schedule meta from deprecated camp-terms string parsing.
 *
 * @package InterSoccer_Product_Variations
 * @since 2.7.18
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Migrate camp dates for one variable product.
 *
 * @param int   $product_id Product ID.
 * @param array $args {
 *     @type bool $force   Overwrite existing start dates.
 *     @type bool $dry_run Do not write.
 * }
 * @return array|WP_Error
 */
function intersoccer_migrate_camp_dates_for_product($product_id, $args = []) {
	$args = array_merge([
		'force'   => false,
		'dry_run' => false,
	], is_array($args) ? $args : []);

	$product_id = (int) $product_id;
	if ($product_id <= 0) {
		return new WP_Error('invalid_product', __('Invalid or non-variable product.', 'intersoccer-product-variations'));
	}
	$product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
	if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
		return new WP_Error('invalid_product', __('Invalid or non-variable product.', 'intersoccer-product-variations'));
	}

	$type = class_exists('InterSoccer_Product_Types')
		? InterSoccer_Product_Types::get_product_type($product_id)
		: '';

	if ($type !== 'camp') {
		return new WP_Error('not_camp', __('Product is not a camp.', 'intersoccer-product-variations'));
	}

	$result = [
		'product_id' => $product_id,
		'updated'    => 0,
		'skipped'    => 0,
		'failed'     => 0,
		'rows'       => [],
	];

	foreach ($product->get_children() as $var_id) {
		$meta = function_exists('intersoccer_get_camp_schedule_meta')
			? intersoccer_get_camp_schedule_meta($var_id)
			: ['start' => '', 'end' => '', 'week' => null];

		if ($meta['start'] !== '' && !$args['force']) {
			$result['skipped']++;
			$result['rows'][] = [
				'variation_id' => $var_id,
				'status'       => 'skipped',
				'reason'       => 'already_has_start',
			];
			continue;
		}

		$terms = '';
		if (function_exists('intersoccer_get_camp_terms_slug')) {
			$terms = intersoccer_get_camp_terms_slug($product_id, $var_id);
		}
		$variation = wc_get_product($var_id);
		if ($terms === '' && $variation) {
			$terms = (string) $variation->get_attribute('pa_camp-terms');
		}

		$season = '';
		if (function_exists('intersoccer_get_variation_program_season_string')) {
			$season = intersoccer_get_variation_program_season_string($product_id, $var_id);
		}

		$parsed = function_exists('intersoccer_camp_schedule_from_terms_deprecated')
			? intersoccer_camp_schedule_from_terms_deprecated($terms, $season)
			: ['start' => '', 'end' => '', 'week' => null];

		if ($parsed['start'] === '') {
			$result['failed']++;
			$result['rows'][] = [
				'variation_id' => $var_id,
				'status'       => 'failed',
				'reason'       => 'unparseable',
				'camp_terms'   => $terms,
			];
			continue;
		}

		if (!$args['dry_run'] && function_exists('intersoccer_update_camp_schedule')) {
			intersoccer_update_camp_schedule(
				$var_id,
				$parsed['start'],
				$parsed['end'],
				$parsed['week'],
				true
			);
		}

		$result['updated']++;
		$result['rows'][] = [
			'variation_id' => $var_id,
			'status'       => $args['dry_run'] ? 'would_update' : 'updated',
			'schedule'     => [
				'start' => $parsed['start'],
				'end'   => $parsed['end'],
				'week'  => $parsed['week'],
			],
		];
	}

	if (!$args['dry_run']) {
		wc_delete_product_transients($product_id);
	}

	return $result;
}

/**
 * Migrate all camp products.
 *
 * @param array $args force, dry_run.
 * @return array
 */
function intersoccer_migrate_camp_dates_all($args = []) {
	$args = array_merge([
		'force'   => false,
		'dry_run' => false,
	], is_array($args) ? $args : []);

	$summary = [
		'products' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'failed'   => 0,
		'details'  => [],
	];

	$query = new WP_Query([
		'post_type'      => 'product',
		'post_status'    => ['publish', 'draft', 'private'],
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [
			[
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => 'variable',
			],
		],
	]);

	foreach ($query->posts as $product_id) {
		$type = class_exists('InterSoccer_Product_Types')
			? InterSoccer_Product_Types::get_product_type((int) $product_id)
			: '';
		if ($type !== 'camp') {
			continue;
		}
		$summary['products']++;
		$one = intersoccer_migrate_camp_dates_for_product((int) $product_id, $args);
		if (is_wp_error($one)) {
			$summary['failed']++;
			$summary['details'][] = ['product_id' => (int) $product_id, 'error' => $one->get_error_message()];
			continue;
		}
		$summary['updated'] += (int) $one['updated'];
		$summary['skipped'] += (int) $one['skipped'];
		$summary['failed']  += (int) $one['failed'];
		$summary['details'][] = $one;
	}

	return $summary;
}

if (defined('WP_CLI') && WP_CLI) {
	/**
	 * Migrate camp start/end/week meta from camp-terms parsing.
	 *
	 * ## OPTIONS
	 *
	 * [--product=<id>]
	 * : Limit to one product ID.
	 *
	 * [--force]
	 * : Overwrite existing `_camp_start_date` values.
	 *
	 * [--dry-run]
	 * : Report only; do not write meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp intersoccer migrate-camp-dates --dry-run
	 *     wp intersoccer migrate-camp-dates --product=39700
	 */
	WP_CLI::add_command('intersoccer migrate-camp-dates', function ($args, $assoc_args) {
		$opts = [
			'force'   => isset($assoc_args['force']),
			'dry_run' => isset($assoc_args['dry-run']),
		];

		if (!empty($assoc_args['product'])) {
			$result = intersoccer_migrate_camp_dates_for_product((int) $assoc_args['product'], $opts);
			if (is_wp_error($result)) {
				WP_CLI::error($result->get_error_message());
			}
			WP_CLI::success(sprintf(
				'Product %d: updated=%d skipped=%d failed=%d%s',
				$result['product_id'],
				$result['updated'],
				$result['skipped'],
				$result['failed'],
				$opts['dry_run'] ? ' (dry-run)' : ''
			));
			return;
		}

		$summary = intersoccer_migrate_camp_dates_all($opts);
		WP_CLI::success(sprintf(
			'Camps=%d updated=%d skipped=%d failed=%d%s',
			$summary['products'],
			$summary['updated'],
			$summary['skipped'],
			$summary['failed'],
			$opts['dry_run'] ? ' (dry-run)' : ''
		));
	});
}
