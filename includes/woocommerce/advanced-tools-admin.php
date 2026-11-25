<?php
/**
 * WooCommerce → Advanced tools.
 *
 * Every repair write is a POST on this page: Preview, typed APPLY, GLOBAL
 * for shop-wide scope, a JSON backup, and a conflict-aware rollback.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/repair-contract.php';

if (!function_exists('intersoccer_pv_advanced_tools_definitions')) {
	/**
	 * @return array<string,array<string,mixed>>
	 */
	function intersoccer_pv_advanced_tools_definitions() {
		return [
			'automated_batch' => [
				'label' => __('Automated batch', 'intersoccer-product-variations'),
				'description' => __('Lists each order-line field that would change for the statuses and scan limit you choose. Strip deprecated metadata and prune legacy label twins stay off unless you check them. Removing metadata also requires DELETE. This path is shop-wide. Apply is refused above 200 changes.', 'intersoccer-product-variations'),
				'asks_delete' => true,
				'fields' => [
					['name' => 'order_statuses', 'label' => __('Order statuses', 'intersoccer-product-variations'), 'type' => 'text'],
					['name' => 'scan_limit', 'label' => __('Orders to scan', 'intersoccer-product-variations'), 'type' => 'number'],
					['name' => 'strip_deprecated', 'label' => __('Strip deprecated metadata', 'intersoccer-product-variations'), 'type' => 'checkbox'],
					['name' => 'prune_legacy', 'label' => __('Prune legacy label twins', 'intersoccer-product-variations'), 'type' => 'checkbox'],
				],
			],
			'recalculate_end_dates' => [
				'label' => __('Recalculate Course End Dates', 'intersoccer-product-variations'),
				'description' => __('Shows the proposed end date next to the current end date for each course variation. Leave the product ID empty only for a shop-wide run (GLOBAL).', 'intersoccer-product-variations'),
				'fields' => [
					['name' => 'product_id', 'label' => __('Product ID', 'intersoccer-product-variations'), 'type' => 'number'],
				],
			],
			'refresh_attributes' => [
				'label' => __('Refresh Attributes', 'intersoccer-product-variations'),
				'description' => __('Fills empty required camp and course attributes when the registry has a safe default. Existing values are kept. Missing course start date, length, and holidays are listed as needs manual and are not written. An empty attribute that means Any is listed as needs manual unless that attribute is required. Courses that have already started and not finished are skipped. Leave the product ID empty only for a shop-wide run (GLOBAL).', 'intersoccer-product-variations'),
				'fields' => [
					['name' => 'product_id', 'label' => __('Product ID', 'intersoccer-product-variations'), 'type' => 'number'],
				],
			],
			'repair_venue' => [
				'label' => __('Repair Venue / Camp Term', 'intersoccer-product-variations'),
				'description' => __('Promotes venue and camp term onto variations and backfills empty values. Leave the product ID empty only for a shop-wide run (GLOBAL).', 'intersoccer-product-variations'),
				'fields' => [
					['name' => 'product_id', 'label' => __('Product ID', 'intersoccer-product-variations'), 'type' => 'number'],
				],
			],
			'migrate_camp_dates' => [
				'label' => __('Apply parsed dates from camp-terms', 'intersoccer-product-variations'),
				'description' => __('Seeds camp start, end, and week from the camp-terms label when dates are empty. Overwrite existing dates, or leave the product ID empty, only with GLOBAL. The WP-CLI command dry-runs unless --apply is passed. This screen stays Preview-first.', 'intersoccer-product-variations'),
				'fields' => [
					['name' => 'product_id', 'label' => __('Product ID', 'intersoccer-product-variations'), 'type' => 'number'],
					['name' => 'force', 'label' => __('Overwrite existing start dates', 'intersoccer-product-variations'), 'type' => 'checkbox'],
				],
			],
			'late_pickup' => [
				'label' => __('Re-enable late pick up', 'intersoccer-product-variations'),
				'description' => __('Turns late pick up on for full-day variations of one camp. Half-day variations are left off. Enter the camp product ID.', 'intersoccer-product-variations'),
				'fields' => [
					['name' => 'product_id', 'label' => __('Product ID', 'intersoccer-product-variations'), 'type' => 'number'],
				],
			],
		];
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_scope_from_post')) {
	/**
	 * @param string $tool Tool key.
	 * @return array
	 */
	function intersoccer_pv_advanced_tools_scope_from_post($tool) {
		$definitions = intersoccer_pv_advanced_tools_definitions();
		$fields = $definitions[$tool]['fields'] ?? [];
		$scope = ['tool' => $tool];
		foreach ($fields as $field) {
			$name = (string) $field['name'];
			$raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
			$raw = is_array($raw) ? '' : (string) $raw;
			if (($field['type'] ?? '') === 'number') {
				$scope[$name] = (string) max(0, (int) $raw);
			} elseif (($field['type'] ?? '') === 'checkbox') {
				$scope[$name] = $raw === '1' ? '1' : '0';
			} else {
				$scope[$name] = function_exists('sanitize_text_field') ? sanitize_text_field($raw) : trim($raw);
			}
		}
		return $scope;
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_empty_result')) {
	/**
	 * @param string $summary  Summary.
	 * @param bool   $shop_wide Shop-wide flag.
	 * @param string $error    Error code or message.
	 * @return array
	 */
	function intersoccer_pv_advanced_tools_empty_result($summary, $shop_wide, $error = '') {
		return [
			'error' => $error,
			'summary' => $summary,
			'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
			'rows' => [],
			'shop_wide' => $shop_wide,
			'wrote' => false,
		];
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_normalize_result')) {
	/**
	 * @param array $result    Result.
	 * @param bool  $dry_run   Dry run.
	 * @param bool  $shop_wide Shop-wide.
	 * @return array
	 */
	function intersoccer_pv_advanced_tools_normalize_result(array $result, $dry_run, $shop_wide) {
		$counts = function_exists('wp_parse_args')
			? wp_parse_args(is_array($result['counts'] ?? null) ? $result['counts'] : [], [
				'changed' => 0,
				'added' => 0,
				'updated' => 0,
				'deleted' => 0,
			])
			: array_merge([
				'changed' => 0,
				'added' => 0,
				'updated' => 0,
				'deleted' => 0,
			], is_array($result['counts'] ?? null) ? $result['counts'] : []);
		if ((int) $counts['changed'] === 0) {
			$counts['changed'] = (int) $counts['added'] + (int) $counts['updated'] + (int) $counts['deleted'];
		}
		return [
			'error' => (string) ($result['error'] ?? ''),
			'summary' => (string) ($result['summary'] ?? ''),
			'counts' => $counts,
			'rows' => is_array($result['rows'] ?? null) ? $result['rows'] : [],
			'shop_wide' => $shop_wide || !empty($result['shop_wide']),
			'wrote' => $dry_run ? false : !empty($result['wrote']),
		];
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_execute')) {
	/**
	 * @param string $tool    Tool key.
	 * @param bool   $dry_run Dry run.
	 * @param array  $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_advanced_tools_execute($tool, $dry_run, array $scope) {
		$shop_wide = intersoccer_pv_scope_is_shop_wide($tool, $scope);
		if (isset($GLOBALS['intersoccer_pv_test_tool_runner']) && is_callable($GLOBALS['intersoccer_pv_test_tool_runner'])) {
			$override = call_user_func($GLOBALS['intersoccer_pv_test_tool_runner'], $tool, $dry_run, $scope);
			if (is_array($override)) {
				return intersoccer_pv_advanced_tools_normalize_result($override, $dry_run, $shop_wide);
			}
		}
		switch ($tool) {
			case 'automated_batch':
				$result = intersoccer_pv_tool_automated_batch($dry_run, $scope);
				break;
			case 'recalculate_end_dates':
				$result = intersoccer_pv_tool_recalculate_end_dates($dry_run, $scope);
				break;
			case 'refresh_attributes':
				$result = intersoccer_pv_tool_refresh_attributes($dry_run, $scope);
				break;
			case 'repair_venue':
				$result = intersoccer_pv_tool_repair_venue($dry_run, $scope);
				break;
			case 'migrate_camp_dates':
				$result = intersoccer_pv_tool_migrate_camp_dates($dry_run, $scope);
				break;
			case 'late_pickup':
				$result = intersoccer_pv_tool_late_pickup($dry_run, $scope);
				break;
			default:
				$result = intersoccer_pv_advanced_tools_empty_result(
					__('Unknown repair.', 'intersoccer-product-variations'),
					false,
					'unknown'
				);
				break;
		}
		return intersoccer_pv_advanced_tools_normalize_result($result, $dry_run, $shop_wide);
	}
}

if (!function_exists('intersoccer_pv_tool_product_ids')) {
	/**
	 * @param int  $product_id Product ID, or 0 for every variable product.
	 * @param bool $camps_only Limit to camps.
	 * @param bool $courses_only Limit to courses.
	 * @return int[]
	 */
	function intersoccer_pv_tool_product_ids($product_id, $camps_only = false, $courses_only = false) {
		$product_id = (int) $product_id;
		if ($product_id > 0) {
			return [$product_id];
		}
		if (!function_exists('wc_get_products')) {
			return [];
		}
		$ids = [];
		$products = wc_get_products([
			'type' => 'variable',
			'limit' => -1,
			'status' => ['publish', 'draft', 'private'],
			'return' => 'ids',
		]);
		foreach ((array) $products as $id) {
			$id = (int) $id;
			$type = class_exists('InterSoccer_Product_Types') ? InterSoccer_Product_Types::get_product_type($id) : '';
			if ($camps_only && $type !== 'camp') {
				continue;
			}
			if ($courses_only && $type !== 'course') {
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}
}

if (!function_exists('intersoccer_pv_tool_recalculate_end_dates')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_recalculate_end_dates($dry_run, array $scope) {
		$product_id = (int) ($scope['product_id'] ?? 0);
		$shop_wide = $product_id <= 0;
		if (!class_exists('InterSoccer_Course') || !method_exists('InterSoccer_Course', 'preview_end_date')) {
			return intersoccer_pv_advanced_tools_empty_result(
				__('Course end dates are unavailable.', 'intersoccer-product-variations'),
				$shop_wide,
				'unavailable'
			);
		}
		$rows = [];
		foreach (intersoccer_pv_tool_product_ids($product_id, false, true) as $parent_id) {
			$product = function_exists('wc_get_product') ? wc_get_product($parent_id) : null;
			if (!$product || !method_exists($product, 'get_children')) {
				continue;
			}
			foreach ($product->get_children() as $variation_id) {
				$variation_id = (int) $variation_id;
				$weeks = (int) get_post_meta($variation_id, '_course_total_weeks', true);
				$current = (string) get_post_meta($variation_id, '_end_date', true);
				$proposed = (string) InterSoccer_Course::preview_end_date($variation_id, $weeks);
				if ($proposed === '' || $proposed === $current) {
					continue;
				}
				$rows[] = [
					'id' => 'meta:' . $variation_id . ':_end_date',
					'before' => ['value' => $current],
					'after' => ['value' => $proposed],
				];
				if (!$dry_run) {
					update_post_meta($variation_id, '_end_date', $proposed);
				}
			}
		}
		return [
			'summary' => sprintf(
				/* translators: %d: variation count */
				__('Proposed end date changes: %d.', 'intersoccer-product-variations'),
				count($rows)
			),
			'counts' => ['changed' => count($rows), 'added' => 0, 'updated' => count($rows), 'deleted' => 0],
			'rows' => $rows,
			'shop_wide' => $shop_wide,
			'wrote' => !$dry_run && $rows !== [],
		];
	}
}

if (!function_exists('intersoccer_pv_course_variation_is_running')) {
	/**
	 * A course variation that has started and has not finished.
	 *
	 * @param int $variation_id Variation ID.
	 * @return bool
	 */
	function intersoccer_pv_course_variation_is_running($variation_id) {
		$variation_id = (int) $variation_id;
		if ($variation_id <= 0 || !function_exists('get_post_meta')) {
			return false;
		}
		$start = (string) get_post_meta($variation_id, '_course_start_date', true);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
			return false;
		}
		$today = function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
		if ($start > $today) {
			return false;
		}
		$end = (string) get_post_meta($variation_id, '_end_date', true);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end < $today) {
			return false;
		}
		return true;
	}
}

if (!function_exists('intersoccer_pv_tool_repair_rows_for_product')) {
	/**
	 * @param int  $product_id Product ID.
	 * @param bool $dry_run    Dry run.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_tool_repair_rows_for_product($product_id, $dry_run) {
		if (!class_exists('InterSoccer_Program_Manager') || !method_exists('InterSoccer_Program_Manager', 'repair_camp_variation_facets')) {
			return [];
		}
		$result = InterSoccer_Program_Manager::repair_camp_variation_facets((int) $product_id, (bool) $dry_run);
		$rows = [];
		foreach ((array) ($result['changes'] ?? []) as $change) {
			if (is_array($change) && isset($change['id'])) {
				$rows[] = $change;
			}
		}
		return $rows;
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
		foreach (intersoccer_pv_tool_product_ids($product_id, true, false) as $parent_id) {
			$rows = array_merge($rows, intersoccer_pv_tool_repair_rows_for_product($parent_id, $dry_run));
		}
		return [
			'summary' => sprintf(
				/* translators: %d: variation count */
				__('Venue or camp-term changes: %d.', 'intersoccer-product-variations'),
				count($rows)
			),
			'counts' => ['changed' => count($rows), 'added' => 0, 'updated' => count($rows), 'deleted' => 0],
			'rows' => $rows,
			'shop_wide' => $product_id <= 0,
			'wrote' => !$dry_run && $rows !== [],
		];
	}
}

if (!function_exists('intersoccer_pv_tool_refresh_attributes')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_refresh_attributes($dry_run, array $scope) {
		if (!function_exists('intersoccer_attr_refresh_defaults')) {
			$registry = __DIR__ . '/attribute-registry.php';
			if (is_readable($registry)) {
				require_once $registry;
			}
		}
		$product_id = (int) ($scope['product_id'] ?? 0);
		$rows = [];
		$ids = intersoccer_pv_tool_product_ids($product_id, false, false);
		foreach ($ids as $parent_id) {
			$type = class_exists('InterSoccer_Product_Types') ? (string) InterSoccer_Product_Types::get_product_type($parent_id) : '';
			$defaults = function_exists('intersoccer_attr_refresh_defaults') ? intersoccer_attr_refresh_defaults($type) : [];
			if ($defaults === []) {
				continue;
			}
			$product = function_exists('wc_get_product') ? wc_get_product($parent_id) : null;
			if (!$product || !method_exists($product, 'get_children')) {
				continue;
			}
			$required = [];
			if (function_exists('intersoccer_attr_required')) {
				foreach (intersoccer_attr_required($type, 'variation') as $taxonomy) {
					$required[(string) $taxonomy] = true;
				}
			}
			foreach ($product->get_children() as $variation_id) {
				$variation_id = (int) $variation_id;
				if ($type === 'course' && intersoccer_pv_course_variation_is_running($variation_id)) {
					continue;
				}
				$variation = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
				if (!$variation || !method_exists($variation, 'get_attributes')) {
					continue;
				}
				$current_attrs = $variation->get_attributes();
				if (!is_array($current_attrs)) {
					$current_attrs = [];
				}
				$original_attrs = $current_attrs;
				$before_attrs = [];
				$after_attrs = [];
				$attr_keys = [];
				foreach (array_keys($original_attrs) as $key) {
					$key = (string) $key;
					if (strpos($key, 'pa_') === 0) {
						$attr_keys[$key] = true;
					}
				}
				foreach ($defaults as $key => $default) {
					$key = (string) $key;
					if ($key !== '' && strpos($key, 'pa_') === 0 && !is_array($default)) {
						$attr_keys[$key] = true;
					}
				}
				foreach (array_keys($attr_keys) as $key) {
					$current = array_key_exists($key, $original_attrs) ? (string) $original_attrs[$key] : '';
					if ($current !== '') {
						continue;
					}
					$fill = isset($defaults[$key]) ? (string) $defaults[$key] : '';
					if (isset($required[$key]) && $fill !== '') {
						$before_attrs[$key] = '';
						$after_attrs[$key] = $fill;
						$current_attrs[$key] = $fill;
						continue;
					}
					$rows[] = [
						'id' => 'manual:attr:' . $variation_id . ':' . $key,
						'needs_manual' => true,
						'before' => ['attributes' => [$key => '']],
						'after' => ['attributes' => [$key => ''], 'needs_manual' => '1'],
					];
				}
				if ($before_attrs !== []) {
					$rows[] = [
						'id' => 'attrs:' . $variation_id,
						'before' => ['attributes' => $before_attrs],
						'after' => ['attributes' => $after_attrs],
					];
					if (!$dry_run && method_exists($variation, 'set_attributes')) {
						$stored = $variation->get_attributes();
						if (!is_array($stored)) {
							$stored = [];
						}
						foreach ($after_attrs as $attr_key => $attr_value) {
							$stored[$attr_key] = $attr_value;
						}
						$variation->set_attributes($stored);
						if (method_exists($variation, 'save')) {
							$variation->save();
						}
					}
				}
				if ($type === 'course') {
					foreach (['_course_start_date', '_course_total_weeks', '_course_holiday_dates'] as $meta_key) {
						$current_meta = function_exists('get_post_meta') ? get_post_meta($variation_id, $meta_key, true) : '';
						if (!intersoccer_pv_repair_course_meta_is_blank($current_meta)) {
							continue;
						}
						$rows[] = [
							'id' => 'manual:meta:' . $variation_id . ':' . $meta_key,
							'needs_manual' => true,
							'before' => ['value' => ''],
							'after' => ['value' => '', 'needs_manual' => '1'],
						];
					}
				}
			}
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
			__('Attribute refresh changes: %d.', 'intersoccer-product-variations'),
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

if (!function_exists('intersoccer_pv_repair_course_meta_is_blank')) {
	/**
	 * Course dates, length, and holidays that staff must enter.
	 *
	 * @param mixed $value Stored meta.
	 * @return bool
	 */
	function intersoccer_pv_repair_course_meta_is_blank($value) {
		if ($value === null || $value === false || $value === []) {
			return true;
		}
		if (is_string($value)) {
			return trim($value) === '';
		}
		return false;
	}
}

if (!function_exists('intersoccer_pv_tool_migrate_rows')) {
	/**
	 * @param array $result Migrate result for one product.
	 * @param bool  $dry_run Dry run.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_tool_migrate_rows(array $result) {
		$rows = [];
		foreach ((array) ($result['rows'] ?? []) as $row) {
			if (!is_array($row) || ($row['status'] ?? '') === 'skipped' || ($row['status'] ?? '') === 'failed') {
				continue;
			}
			$variation_id = (int) ($row['variation_id'] ?? 0);
			if ($variation_id <= 0) {
				continue;
			}
			$schedule = is_array($row['schedule'] ?? null) ? $row['schedule'] : [];
			$current = function_exists('intersoccer_get_camp_schedule_meta')
				? intersoccer_get_camp_schedule_meta($variation_id)
				: ['start' => '', 'end' => '', 'week' => ''];
			$rows[] = [
				'id' => 'camp-schedule:' . $variation_id,
				'before' => [
					'start' => (string) ($current['start'] ?? ''),
					'end' => (string) ($current['end'] ?? ''),
					'week' => (string) ($current['week'] ?? ''),
				],
				'after' => [
					'start' => (string) ($schedule['start'] ?? ''),
					'end' => (string) ($schedule['end'] ?? ''),
					'week' => (string) ($schedule['week'] ?? ''),
				],
			];
		}
		return $rows;
	}
}

if (!function_exists('intersoccer_pv_tool_migrate_camp_dates')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_migrate_camp_dates($dry_run, array $scope) {
		$product_id = (int) ($scope['product_id'] ?? 0);
		$force = (string) ($scope['force'] ?? '') === '1';
		if (!function_exists('intersoccer_migrate_camp_dates_for_product')) {
			return intersoccer_pv_advanced_tools_empty_result(
				__('Camp date migration is unavailable.', 'intersoccer-product-variations'),
				$product_id <= 0 || $force,
				'unavailable'
			);
		}
		$args = ['force' => $force, 'dry_run' => true];
		$rows = [];
		if ($product_id > 0) {
			$planned = intersoccer_migrate_camp_dates_for_product($product_id, $args);
			if (is_wp_error($planned)) {
				return intersoccer_pv_advanced_tools_empty_result($planned->get_error_message(), $force, 'invalid');
			}
			$rows = intersoccer_pv_tool_migrate_rows(is_array($planned) ? $planned : []);
			if (!$dry_run && $rows !== []) {
				intersoccer_migrate_camp_dates_for_product($product_id, ['force' => $force, 'dry_run' => false]);
			}
		} elseif (function_exists('intersoccer_migrate_camp_dates_all')) {
			$planned = intersoccer_migrate_camp_dates_all($args);
			foreach ((array) ($planned['details'] ?? []) as $detail) {
				if (is_array($detail)) {
					$rows = array_merge($rows, intersoccer_pv_tool_migrate_rows($detail));
				}
			}
			if (!$dry_run && $rows !== []) {
				intersoccer_migrate_camp_dates_all(['force' => $force, 'dry_run' => false]);
			}
		}
		return [
			'summary' => sprintf(
				/* translators: %d: variation count */
				__('Parsed camp date changes: %d.', 'intersoccer-product-variations'),
				count($rows)
			),
			'counts' => ['changed' => count($rows), 'added' => 0, 'updated' => count($rows), 'deleted' => 0],
			'rows' => $rows,
			'shop_wide' => $product_id <= 0 || $force,
			'wrote' => !$dry_run && $rows !== [],
		];
	}
}

if (!function_exists('intersoccer_pv_tool_late_pickup')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_late_pickup($dry_run, array $scope) {
		$product_id = (int) ($scope['product_id'] ?? 0);
		if ($product_id <= 0) {
			return intersoccer_pv_advanced_tools_empty_result(
				__('Enter a camp product ID.', 'intersoccer-product-variations'),
				false,
				'product'
			);
		}
		$product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
		if (!$product || !method_exists($product, 'get_children')) {
			return intersoccer_pv_advanced_tools_empty_result(
				__('Camp product was not found.', 'intersoccer-product-variations'),
				false,
				'product'
			);
		}
		$rows = [];
		foreach ($product->get_children() as $variation_id) {
			$variation_id = (int) $variation_id;
			$age_label = function_exists('intersoccer_get_variation_age_group_label')
				? (string) intersoccer_get_variation_age_group_label($product_id, $variation_id)
				: '';
			$age_slug = function_exists('intersoccer_get_variation_age_group_slug')
				? (string) intersoccer_get_variation_age_group_slug($variation_id)
				: '';
			if (function_exists('intersoccer_is_half_day_age_group') && intersoccer_is_half_day_age_group($age_label, $age_slug)) {
				continue;
			}
			$current = (string) get_post_meta($variation_id, '_intersoccer_enable_late_pickup', true);
			if ($current === 'yes') {
				continue;
			}
			$rows[] = [
				'id' => 'meta:' . $variation_id . ':_intersoccer_enable_late_pickup',
				'before' => ['value' => $current],
				'after' => ['value' => 'yes'],
			];
		}
		if (!$dry_run && $rows !== []) {
			$written = 0;
			foreach ($rows as $row) {
				$parts = explode(':', (string) ($row['id'] ?? ''));
				$variation_id = (int) ($parts[1] ?? 0);
				if ($variation_id > 0 && function_exists('update_post_meta')) {
					update_post_meta($variation_id, '_intersoccer_enable_late_pickup', 'yes');
					if (function_exists('wc_delete_product_transients')) {
						wc_delete_product_transients($variation_id);
					}
				}
				$written++;
				if (defined('INTERSOCCER_PV_REPAIR_TEST') && INTERSOCCER_PV_REPAIR_TEST
					&& !empty($GLOBALS['intersoccer_pv_test_interrupt_after'])
					&& $written >= (int) $GLOBALS['intersoccer_pv_test_interrupt_after']) {
					throw new RuntimeException('interrupted');
				}
			}
		}
		$summary = $dry_run
			? sprintf(
				/* translators: %d: variation count */
				__('Late pick up would be enabled on %d variation(s).', 'intersoccer-product-variations'),
				count($rows)
			)
			: sprintf(
				/* translators: %d: variation count */
				__('Late pick up was enabled on %d variation(s).', 'intersoccer-product-variations'),
				count($rows)
			);
		return [
			'summary' => $summary,
			'counts' => ['changed' => count($rows), 'added' => 0, 'updated' => count($rows), 'deleted' => 0],
			'rows' => $rows,
			'shop_wide' => false,
			'wrote' => !$dry_run && $rows !== [],
		];
	}
}

if (!function_exists('intersoccer_pv_order_meta_snapshot')) {
	/**
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>
	 */
	function intersoccer_pv_order_meta_snapshot($order_id) {
		if (!function_exists('wc_get_order')) {
			return ['order_id' => (string) (int) $order_id];
		}
		$order = wc_get_order((int) $order_id);
		if (!$order || !method_exists($order, 'get_items')) {
			return ['order_id' => (string) (int) $order_id];
		}
		$items = [];
		foreach ($order->get_items() as $item_id => $item) {
			$meta = [];
			if (is_object($item) && method_exists($item, 'get_meta_data')) {
				foreach ($item->get_meta_data() as $meta_obj) {
					$data = method_exists($meta_obj, 'get_data') ? $meta_obj->get_data() : [];
					$key = (string) ($data['key'] ?? '');
					if ($key === '') {
						continue;
					}
					$value = $data['value'] ?? '';
					$meta[$key] = is_scalar($value) ? (string) $value : intersoccer_pv_repair_json($value);
				}
			}
			ksort($meta);
			$items[(string) $item_id] = $meta;
		}
		ksort($items);
		return ['items' => $items];
	}
}

if (!function_exists('intersoccer_pv_item_meta_map')) {
	/**
	 * @param object $item Order item.
	 * @return array<string,string>
	 */
	function intersoccer_pv_item_meta_map($item) {
		$meta = [];
		if (!is_object($item) || !method_exists($item, 'get_meta_data')) {
			return $meta;
		}
		foreach ($item->get_meta_data() as $meta_obj) {
			$data = is_object($meta_obj) && method_exists($meta_obj, 'get_data') ? $meta_obj->get_data() : (array) $meta_obj;
			$key = (string) ($data['key'] ?? '');
			if ($key === '') {
				continue;
			}
			$value = $data['value'] ?? '';
			$meta[$key] = is_scalar($value) ? (string) $value : intersoccer_pv_repair_json($value);
		}
		ksort($meta);
		return $meta;
	}
}

if (!function_exists('intersoccer_pv_item_restore_meta_map')) {
	/**
	 * @param object                    $item   Order item.
	 * @param array<string,string>      $before Previous meta.
	 * @param array<string,string>|null $after  Apply result. When set, only that change set is restored.
	 * @return void
	 */
	function intersoccer_pv_item_restore_meta_map($item, array $before, $after = null) {
		if (!is_object($item) || !method_exists($item, 'get_meta_data')) {
			return;
		}
		$current = intersoccer_pv_item_meta_map($item);
		if (!is_array($after)) {
			foreach ($current as $key => $value) {
				if (!array_key_exists($key, $before) && method_exists($item, 'delete_meta_data')) {
					$item->delete_meta_data($key);
				}
			}
			foreach ($before as $key => $value) {
				if (!array_key_exists($key, $current) || (string) $current[$key] !== (string) $value) {
					if (method_exists($item, 'update_meta_data')) {
						$item->update_meta_data($key, $value);
					}
				}
			}
			return;
		}
		foreach ($after as $key => $value) {
			if (array_key_exists($key, $before)) {
				continue;
			}
			if (!array_key_exists($key, $current) || (string) $current[$key] !== (string) $value) {
				continue;
			}
			if (method_exists($item, 'delete_meta_data')) {
				$item->delete_meta_data($key);
			}
		}
		foreach ($before as $key => $value) {
			$changed = !array_key_exists($key, $after) || (string) $after[$key] !== (string) $value;
			if (!$changed) {
				continue;
			}
			if (!array_key_exists($key, $current) || (string) $current[$key] !== (string) $value) {
				if (method_exists($item, 'update_meta_data')) {
					$item->update_meta_data($key, $value);
				}
			}
		}
	}
}

if (!function_exists('intersoccer_pv_repair_counts_from_rows')) {
	/**
	 * @param array $rows Change rows.
	 * @return array{changed:int,added:int,updated:int,deleted:int}
	 */
	function intersoccer_pv_repair_counts_from_rows(array $rows) {
		$updated = 0;
		$deleted = 0;
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$before = is_array($row['before'] ?? null) ? $row['before'] : [];
			$after = is_array($row['after'] ?? null) ? $row['after'] : [];
			$wrapped = isset($before['value']) || isset($after['value']) || isset($before['attributes']) || isset($after['attributes'])
				|| isset($before['start']) || isset($after['start']) || isset($before['items']) || isset($after['items']);
			if ($wrapped) {
				$updated++;
				continue;
			}
			foreach ($before as $key => $value) {
				if (!array_key_exists($key, $after)) {
					$deleted++;
				} elseif ((string) $after[$key] !== (string) $value) {
					$updated++;
				}
			}
			foreach ($after as $key => $value) {
				if (!array_key_exists($key, $before)) {
					$updated++;
				}
			}
		}
		return [
			'changed' => count($rows),
			'added' => 0,
			'updated' => $updated,
			'deleted' => $deleted,
		];
	}
}

if (!function_exists('intersoccer_pv_order_repair_rows')) {
	/**
	 * Field changes update_order_metadata would write, using the same options.
	 *
	 * @param object $order     Order.
	 * @param bool   $fix_only  Activity type only.
	 * @param bool   $strip     Strip deprecated keys.
	 * @param bool   $prune     Prune legacy twins.
	 * @param bool   $persist   Save when true. Dry runs restore the item in memory.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_pv_order_repair_rows($order, $fix_only, $strip, $prune, $persist) {
		if (!is_object($order) || !method_exists($order, 'get_items')) {
			return [];
		}
		$rows = [];
		$order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
		foreach ($order->get_items() as $item_id => $item) {
			if (!is_object($item) || !method_exists($item, 'get_meta_data')) {
				continue;
			}
			if (class_exists('WC_Order_Item_Product') && !($item instanceof WC_Order_Item_Product)) {
				continue;
			}
			$before = intersoccer_pv_item_meta_map($item);
			if (function_exists('intersoccer_resolve_order_item_product_context') && function_exists('intersoccer_write_order_line_meta')) {
				$resolved = intersoccer_resolve_order_item_product_context($item);
				$type = (string) ($resolved['product_type'] ?? '');
				if (in_array($type, ['camp', 'course', 'birthday', 'tournament'], true)) {
					intersoccer_write_order_line_meta($item, [
						'order' => $order,
						'product_id' => (int) ($resolved['resolved_product_id'] ?? 0),
						'variation_id' => (int) ($resolved['variation_id'] ?? 0),
						'product_type' => $type,
						'fix_activity_type_only' => (bool) $fix_only,
						'mode' => 'repair',
					]);
					if (function_exists('intersoccer_prune_camp_schedule_order_meta_twins')) {
						intersoccer_prune_camp_schedule_order_meta_twins($item);
					}
					if ($prune && function_exists('intersoccer_prune_legacy_order_meta_twins')) {
						intersoccer_prune_legacy_order_meta_twins($item);
					}
					if ($strip && function_exists('intersoccer_strip_deprecated_order_line_meta')) {
						intersoccer_strip_deprecated_order_line_meta($item);
					}
				}
			}
			$after = intersoccer_pv_item_meta_map($item);
			if ($before !== $after) {
				$rows[] = [
					'id' => 'order-item:' . $order_id . ':' . (int) $item_id,
					'before' => $before,
					'after' => $after,
				];
			}
			if (!$persist || $before === $after) {
				intersoccer_pv_item_restore_meta_map($item, $before);
				continue;
			}
			if (method_exists($item, 'save')) {
				$item->save();
			}
		}
		if ($persist && $rows !== [] && method_exists($order, 'save')) {
			$order->save();
		}
		return $rows;
	}
}

if (!function_exists('intersoccer_pv_tool_automated_batch')) {
	/**
	 * @param bool  $dry_run Dry run.
	 * @param array $scope   Scope.
	 * @return array
	 */
	function intersoccer_pv_tool_automated_batch($dry_run, array $scope) {
		if (!function_exists('intersoccer_get_orders_needing_updates') || !function_exists('intersoccer_update_order_metadata')) {
			return intersoccer_pv_advanced_tools_empty_result(
				__('Order metadata repair is unavailable.', 'intersoccer-product-variations'),
				true,
				'unavailable'
			);
		}
		$statuses = array_filter(array_map('trim', explode(',', (string) ($scope['order_statuses'] ?? 'processing,completed'))));
		if ($statuses === []) {
			$statuses = ['processing', 'completed'];
		}
		$limit = (int) ($scope['scan_limit'] ?? 1000);
		if ($limit === 0) {
			$limit = 1000;
		}
		$strip = (string) ($scope['strip_deprecated'] ?? '') === '1';
		$prune = (string) ($scope['prune_legacy'] ?? '') === '1';
		$ids = array_map('intval', (array) intersoccer_get_orders_needing_updates($statuses, $limit));
		$rows = [];
		foreach ($ids as $order_id) {
			if ($order_id <= 0 || !function_exists('wc_get_order')) {
				continue;
			}
			$order = wc_get_order($order_id);
			if (!$order) {
				continue;
			}
			$rows = array_merge($rows, intersoccer_pv_order_repair_rows($order, false, $strip, $prune, false));
		}
		$within_cap = count($rows) > 0 && count($rows) <= (int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP;
		if (!$dry_run && $within_cap) {
			foreach ($ids as $order_id) {
				$order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
				if ($order) {
					intersoccer_pv_order_repair_rows($order, false, $strip, $prune, true);
				}
			}
		}
		$counts = intersoccer_pv_repair_counts_from_rows($rows);
		$summary = sprintf(
			/* translators: %d: field-change count */
			__('Order-line field changes: %d.', 'intersoccer-product-variations'),
			count($rows)
		);
		if (count($rows) > (int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP) {
			$summary .= ' ' . sprintf(
				/* translators: %d: backup row cap */
				__('Apply is refused above %d changes. Narrow the scan. Nothing was written.', 'intersoccer-product-variations'),
				(int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP
			);
		}
		return [
			'summary' => $summary,
			'counts' => $counts,
			'rows' => $rows,
			'shop_wide' => true,
			'wrote' => !$dry_run && $within_cap,
		];
	}
}

if (!function_exists('intersoccer_pv_repair_with_raw_wpml')) {
	/**
	 * Run a callback with WPML/WCML language filters off (admin language "all")
	 * so reads see the stored term and meta, not a translated view.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	function intersoccer_pv_repair_with_raw_wpml($callback) {
		$previous = null;
		$switched = false;
		$can_switch = function_exists('do_action') && (
			defined('ICL_SITEPRESS_VERSION')
			|| function_exists('icl_get_current_language')
			|| (function_exists('has_filter') && has_filter('wpml_current_language'))
			|| (function_exists('has_action') && has_action('wpml_switch_language'))
		);
		if ($can_switch) {
			$previous = function_exists('apply_filters') ? apply_filters('wpml_current_language', null) : null;
			do_action('wpml_switch_language', 'all');
			$switched = true;
		}
		$prev_suppress = $GLOBALS['intersoccer_test_wpml_suppress_term_filters'] ?? null;
		$GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = true;
		try {
			return $callback();
		} finally {
			if ($prev_suppress === null) {
				unset($GLOBALS['intersoccer_test_wpml_suppress_term_filters']);
			} else {
				$GLOBALS['intersoccer_test_wpml_suppress_term_filters'] = $prev_suppress;
			}
			if ($switched) {
				do_action('wpml_switch_language', $previous === null || $previous === '' ? 'all' : $previous);
			}
		}
	}
}

if (!function_exists('intersoccer_pv_repair_raw_variation_attributes')) {
	/**
	 * Variation attribute values from post meta, without WCML language filtering.
	 *
	 * @param int $variation_id Variation ID.
	 * @return array<string,string>
	 */
	function intersoccer_pv_repair_raw_variation_attributes($variation_id) {
		$variation_id = (int) $variation_id;
		$attrs = [];
		if ($variation_id <= 0 || !function_exists('get_post_meta')) {
			return $attrs;
		}
		$all = get_post_meta($variation_id);
		if (!is_array($all)) {
			return $attrs;
		}
		foreach ($all as $key => $values) {
			$key = (string) $key;
			if (strpos($key, 'attribute_') !== 0) {
				continue;
			}
			$value = is_array($values) ? ($values[0] ?? '') : $values;
			$attrs[substr($key, 10)] = is_array($value) ? '' : (string) $value;
		}
		ksort($attrs);
		return $attrs;
	}
}

if (!function_exists('intersoccer_pv_repair_set_object_term')) {
	/**
	 * Attach exactly one taxonomy term, identified by term ID when known.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $term_id   Term ID, or 0 to use the slug.
	 * @param string $slug      Fallback slug when no term ID is available.
	 * @return void
	 */
	function intersoccer_pv_repair_set_object_term($object_id, $taxonomy, $term_id, $slug = '') {
		if (!function_exists('wp_set_object_terms')) {
			return;
		}
		$object_id = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		$term_id = (int) $term_id;
		if ($object_id <= 0 || $taxonomy === '') {
			return;
		}
		if ($term_id > 0) {
			wp_set_object_terms($object_id, [$term_id], $taxonomy);
			return;
		}
		if ($slug !== '') {
			wp_set_object_terms($object_id, [$slug], $taxonomy);
			return;
		}
		wp_set_object_terms($object_id, [], $taxonomy);
	}
}

if (!function_exists('intersoccer_pv_facet_term_records')) {
	/**
	 * Object terms as stored, with term IDs, and WPML term filters suppressed.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return array{slugs:string[],ids:int[]}
	 */
	function intersoccer_pv_facet_term_records($object_id, $taxonomy) {
		$object_id = (int) $object_id;
		$taxonomy = (string) $taxonomy;
		$reader = static function () use ($object_id, $taxonomy) {
			$found = [];
			if (function_exists('wp_get_object_terms')) {
				$found = wp_get_object_terms($object_id, $taxonomy, ['fields' => 'all']);
			} elseif (function_exists('wc_get_product_terms')) {
				$found = wc_get_product_terms($object_id, $taxonomy, ['fields' => 'all']);
			}
			if ((function_exists('is_wp_error') && is_wp_error($found)) || !is_array($found)) {
				$found = [];
			}
			$slugs = [];
			$ids = [];
			foreach ($found as $term) {
				if (is_object($term) && !empty($term->term_id)) {
					$ids[] = (int) $term->term_id;
					if (!empty($term->slug)) {
						$slugs[] = (string) $term->slug;
					}
				} elseif (is_numeric($term)) {
					$ids[] = (int) $term;
				} elseif (is_string($term) && $term !== '') {
					$slugs[] = $term;
				}
			}
			$ids = array_values(array_unique(array_filter($ids)));
			sort($ids);
			$slugs = array_values(array_unique(array_filter($slugs, static function ($slug) {
				return $slug !== '';
			})));
			sort($slugs);
			return ['slugs' => $slugs, 'ids' => $ids];
		};
		return function_exists('intersoccer_pv_repair_with_raw_wpml')
			? intersoccer_pv_repair_with_raw_wpml($reader)
			: $reader();
	}
}

if (!function_exists('intersoccer_pv_facet_term_slugs')) {
	/**
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return string[]
	 */
	function intersoccer_pv_facet_term_slugs($object_id, $taxonomy) {
		$records = intersoccer_pv_facet_term_records($object_id, $taxonomy);
		return $records['slugs'];
	}
}

if (!function_exists('intersoccer_pv_facet_side_snapshot')) {
	/**
	 * Post meta and terms Apply writes for venue and camp-term repair.
	 *
	 * @param int      $variation_id Variation ID.
	 * @param string[] $taxonomies   Taxonomies.
	 * @return array{meta:array<string,string>,terms:array<string,string[]>}
	 */
	function intersoccer_pv_facet_side_snapshot($variation_id, array $taxonomies) {
		$meta = [];
		$terms = [];
		$term_ids = [];
		foreach ($taxonomies as $taxonomy) {
			$taxonomy = (string) $taxonomy;
			if ($taxonomy === '') {
				continue;
			}
			$meta_key = 'attribute_' . $taxonomy;
			$meta[$meta_key] = function_exists('get_post_meta') ? (string) get_post_meta((int) $variation_id, $meta_key, true) : '';
			$records = intersoccer_pv_facet_term_records((int) $variation_id, $taxonomy);
			$terms[$taxonomy] = $records['slugs'];
			$term_ids[$taxonomy] = $records['ids'];
		}
		ksort($meta);
		ksort($terms);
		ksort($term_ids);
		return ['meta' => $meta, 'terms' => $terms, 'term_ids' => $term_ids];
	}
}

if (!function_exists('intersoccer_pv_facet_side_effect_plan')) {
	/**
	 * Meta, terms, and translation twins Apply will write for one variation.
	 *
	 * @param int                  $variation_id Variation ID.
	 * @param array<string,string> $slugs        Taxonomy => slug. Empty slugs are not written.
	 * @return array{before:array<string,mixed>,after:array<string,mixed>}
	 */
	function intersoccer_pv_facet_side_effect_plan($variation_id, array $slugs) {
		$written = [];
		foreach ($slugs as $taxonomy => $slug) {
			$taxonomy = (string) $taxonomy;
			$slug = (string) $slug;
			if ($taxonomy === '' || $slug === '') {
				continue;
			}
			$written[$taxonomy] = $slug;
		}
		ksort($written);
		$taxes = array_keys($written);
		$before = intersoccer_pv_facet_side_snapshot((int) $variation_id, $taxes);
		$after = $before;
		foreach ($written as $taxonomy => $slug) {
			$after['meta']['attribute_' . $taxonomy] = $slug;
			$after['terms'][$taxonomy] = [$slug];
			$source_id = 0;
			if (function_exists('get_term_by')) {
				$source_term = get_term_by('slug', $slug, $taxonomy);
				if ($source_term && !(function_exists('is_wp_error') && is_wp_error($source_term)) && !empty($source_term->term_id)) {
					$source_id = (int) $source_term->term_id;
				}
			}
			$after['term_ids'][$taxonomy] = $source_id > 0 ? [$source_id] : [];
		}
		$before_twins = [];
		$after_twins = [];
		$needs_manual_rows = [];
		if (function_exists('intersoccer_foreach_translated_product_variations')) {
			foreach (intersoccer_foreach_translated_product_variations((int) $variation_id) as $tid) {
				$tid = (int) $tid;
				if ($tid <= 0) {
					continue;
				}
				$key = (string) $tid;
				$snap = intersoccer_pv_facet_side_snapshot($tid, $taxes);
				$raw_attrs = function_exists('intersoccer_pv_repair_raw_variation_attributes')
					? intersoccer_pv_repair_raw_variation_attributes($tid)
					: [];
				$attr_before = [];
				$attr_after = [];
				$after_meta = $snap['meta'];
				$after_terms = $snap['terms'];
				$after_term_ids = $snap['term_ids'] ?? [];
				$manual = [];
				foreach ($written as $taxonomy => $slug) {
					$attr_before[$taxonomy] = (string) ($raw_attrs[$taxonomy] ?? '');
					$mapped = $slug;
					$mapped_id = 0;
					if ($slug !== '' && function_exists('intersoccer_translated_taxonomy_term')) {
						$resolved = intersoccer_translated_taxonomy_term($slug, $taxonomy, $tid);
						if ($resolved === null) {
							$mapped = null;
						} else {
							$mapped = (string) $resolved['slug'];
							$mapped_id = (int) $resolved['term_id'];
						}
					}
					if ($mapped === null) {
						$manual[] = $taxonomy;
						$attr_after[$taxonomy] = $attr_before[$taxonomy];
						continue;
					}
					$attr_after[$taxonomy] = $mapped;
					$after_meta['attribute_' . $taxonomy] = $mapped;
					$after_terms[$taxonomy] = $mapped === '' ? [] : [$mapped];
					$after_term_ids[$taxonomy] = $mapped_id > 0 ? [$mapped_id] : [];
				}
				ksort($attr_before);
				ksort($attr_after);
				$before_twins[$key] = [
					'attributes' => $attr_before,
					'meta' => $snap['meta'],
					'terms' => $snap['terms'],
					'term_ids' => $snap['term_ids'] ?? [],
				];
				$after_twins[$key] = [
					'attributes' => $attr_after,
					'meta' => $after_meta,
					'terms' => $after_terms,
					'term_ids' => $after_term_ids,
				];
				foreach ($manual as $taxonomy) {
					$needs_manual_rows[] = [
						'tid' => $tid,
						'taxonomy' => $taxonomy,
						'current' => $attr_before[$taxonomy],
					];
				}
			}
		}
		ksort($before_twins);
		ksort($after_twins);
		return [
			'before' => [
				'meta' => $before['meta'],
				'terms' => $before['terms'],
				'term_ids' => $before['term_ids'] ?? [],
				'translations' => $before_twins,
			],
			'after' => [
				'meta' => $after['meta'],
				'terms' => $after['terms'],
				'term_ids' => $after['term_ids'] ?? [],
				'translations' => $after_twins,
			],
			'needs_manual' => $needs_manual_rows,
		];
	}
}

if (!function_exists('intersoccer_pv_facet_translation_state')) {
	/**
	 * Live translation twins for venue and camp-term comparison.
	 *
	 * @param int $variation_id Variation ID.
	 * @return array<string,array<string,mixed>>
	 */
	function intersoccer_pv_facet_translation_state($variation_id) {
		$taxes = ['pa_camp-terms', 'pa_intersoccer-venues'];
		$translations = [];
		if (!function_exists('intersoccer_foreach_translated_product_variations')) {
			return $translations;
		}
		foreach (intersoccer_foreach_translated_product_variations((int) $variation_id) as $tid) {
			$tid = (int) $tid;
			if ($tid <= 0) {
				continue;
			}
			$raw_attrs = function_exists('intersoccer_pv_repair_raw_variation_attributes')
				? intersoccer_pv_repair_raw_variation_attributes($tid)
				: [];
			$subset = [];
			foreach ($taxes as $taxonomy) {
				$subset[$taxonomy] = (string) ($raw_attrs[$taxonomy] ?? '');
			}
			$snap = intersoccer_pv_facet_side_snapshot($tid, $taxes);
			$translations[(string) $tid] = [
				'attributes' => $subset,
				'meta' => $snap['meta'],
				'terms' => $snap['terms'],
				'term_ids' => $snap['term_ids'] ?? [],
			];
		}
		ksort($translations);
		return $translations;
	}
}

if (!function_exists('intersoccer_pv_repair_read_value')) {
	/**
	 * @param mixed $id Row id.
	 * @return mixed
	 */
	function intersoccer_pv_repair_read_value($id) {
		if (isset($GLOBALS['intersoccer_pv_test_value_store']) && is_array($GLOBALS['intersoccer_pv_test_value_store'])
			&& array_key_exists($id, $GLOBALS['intersoccer_pv_test_value_store'])) {
			return $GLOBALS['intersoccer_pv_test_value_store'][$id];
		}
		$id = (string) $id;
		if (strpos($id, 'meta:') === 0 && function_exists('get_post_meta')) {
			$parts = explode(':', $id, 3);
			return ['value' => (string) get_post_meta((int) ($parts[1] ?? 0), (string) ($parts[2] ?? ''), true)];
		}
		if (strpos($id, 'order-item:') === 0) {
			$parts = explode(':', $id);
			$order = function_exists('wc_get_order') ? wc_get_order((int) ($parts[1] ?? 0)) : null;
			$item = ($order && method_exists($order, 'get_item')) ? $order->get_item((int) ($parts[2] ?? 0)) : null;
			return $item ? intersoccer_pv_item_meta_map($item) : [];
		}
		if (strpos($id, 'order:') === 0) {
			return intersoccer_pv_order_meta_snapshot((int) substr($id, 6));
		}
		if (strpos($id, 'camp-schedule:') === 0 && function_exists('intersoccer_get_camp_schedule_meta')) {
			$meta = intersoccer_get_camp_schedule_meta((int) substr($id, 14));
			return [
				'start' => (string) ($meta['start'] ?? ''),
				'end' => (string) ($meta['end'] ?? ''),
				'week' => (string) ($meta['week'] ?? ''),
			];
		}
		if (strpos($id, 'parent-attr:') === 0 && class_exists('InterSoccer_Program_Manager')) {
			$rest = substr($id, strlen('parent-attr:'));
			$parts = explode(':', $rest, 2);
			return InterSoccer_Program_Manager::parent_taxonomy_attribute_snapshot((int) ($parts[0] ?? 0), (string) ($parts[1] ?? ''));
		}
		if (strpos($id, 'attrs:') === 0) {
				$variation_id = (int) substr($id, 6);
				$taxes = ['pa_camp-terms', 'pa_intersoccer-venues'];
				$snap = intersoccer_pv_facet_side_snapshot($variation_id, $taxes);
				$attributes = function_exists('intersoccer_pv_repair_raw_variation_attributes')
					? intersoccer_pv_repair_raw_variation_attributes($variation_id)
					: [];
				if ($attributes === [] && function_exists('wc_get_product')) {
					$product = wc_get_product($variation_id);
					$attributes = ($product && method_exists($product, 'get_attributes')) ? $product->get_attributes() : [];
					if (!is_array($attributes)) {
						$attributes = [];
					}
				}
				return [
					'attributes' => $attributes,
					'meta' => $snap['meta'],
					'terms' => $snap['terms'],
					'term_ids' => $snap['term_ids'] ?? [],
					'translations' => intersoccer_pv_facet_translation_state($variation_id),
				];
			}
		return null;
	}
}

if (!function_exists('intersoccer_pv_repair_restore_value')) {
	/**
	 * @param mixed $id     Row id.
	 * @param mixed $before Previous value.
	 * @return void
	 */
	function intersoccer_pv_repair_restore_value($id, $before, $after = null) {
		if (isset($GLOBALS['intersoccer_pv_test_value_store']) && is_array($GLOBALS['intersoccer_pv_test_value_store'])
			&& array_key_exists($id, $GLOBALS['intersoccer_pv_test_value_store'])) {
			$GLOBALS['intersoccer_pv_test_value_store'][$id] = $before;
			return;
		}
		if (!is_array($before)) {
			return;
		}
		$id = (string) $id;
		if (strpos($id, 'order-item:') === 0 && is_array($before)) {
			$parts = explode(':', $id);
			$order = function_exists('wc_get_order') ? wc_get_order((int) ($parts[1] ?? 0)) : null;
			$item = ($order && method_exists($order, 'get_item')) ? $order->get_item((int) ($parts[2] ?? 0)) : null;
			if ($item) {
				intersoccer_pv_item_restore_meta_map($item, $before, is_array($after) ? $after : null);
				if (method_exists($item, 'save')) {
					$item->save();
				}
			}
			return;
		}
		if (strpos($id, 'parent-attr:') === 0) {
			$rest = substr($id, strlen('parent-attr:'));
			$parts = explode(':', $rest, 2);
			intersoccer_pv_repair_restore_parent_attr((int) ($parts[0] ?? 0), (string) ($parts[1] ?? ''), $before);
			return;
		}
		if (strpos($id, 'meta:') === 0 && function_exists('update_post_meta')) {
			$parts = explode(':', $id, 3);
			update_post_meta((int) ($parts[1] ?? 0), (string) ($parts[2] ?? ''), (string) ($before['value'] ?? ''));
			return;
		}
		if (strpos($id, 'camp-schedule:') === 0 && function_exists('intersoccer_update_camp_schedule')) {
			intersoccer_update_camp_schedule(
				(int) substr($id, 14),
				(string) ($before['start'] ?? ''),
				(string) ($before['end'] ?? ''),
				(string) ($before['week'] ?? ''),
				true
			);
			return;
		}
		if (strpos($id, 'attrs:') === 0 && function_exists('wc_get_product') && isset($before['attributes']) && is_array($before['attributes'])) {
			$variation_id = (int) substr($id, 6);
			$product = wc_get_product($variation_id);
			if ($product && method_exists($product, 'get_attributes') && method_exists($product, 'set_attributes')) {
				$current = $product->get_attributes();
				if (!is_array($current)) {
					$current = [];
				}
				foreach ($before['attributes'] as $key => $value) {
					$current[$key] = $value;
				}
				$product->set_attributes($current);
				if (method_exists($product, 'save')) {
					$product->save();
				}
			}
			intersoccer_pv_repair_restore_facet_side($variation_id, $before);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_restore_object_terms')) {
	/**
	 * Restore taxonomy relationships by term ID when the snapshot has IDs.
	 *
	 * @param int   $object_id Object ID.
	 * @param array $state     Snapshot with optional term_ids and terms.
	 * @return void
	 */
	function intersoccer_pv_repair_restore_object_terms($object_id, array $state) {
		if (!function_exists('wp_set_object_terms')) {
			return;
		}
		$object_id = (int) $object_id;
		if ($object_id <= 0) {
			return;
		}
		$ids = isset($state['term_ids']) && is_array($state['term_ids']) ? $state['term_ids'] : [];
		$slugs = isset($state['terms']) && is_array($state['terms']) ? $state['terms'] : [];
		$taxonomies = array_unique(array_merge(array_keys($ids), array_keys($slugs)));
		foreach ($taxonomies as $taxonomy) {
			$taxonomy = (string) $taxonomy;
			if ($taxonomy === '') {
				continue;
			}
			$id_list = isset($ids[$taxonomy]) && is_array($ids[$taxonomy]) ? array_values(array_map('intval', $ids[$taxonomy])) : [];
			$id_list = array_values(array_filter($id_list));
			if ($id_list !== []) {
				wp_set_object_terms($object_id, $id_list, $taxonomy);
				continue;
			}
			$slug_list = isset($slugs[$taxonomy]) && is_array($slugs[$taxonomy]) ? array_values($slugs[$taxonomy]) : [];
			wp_set_object_terms($object_id, $slug_list, $taxonomy);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_restore_facet_side')) {
	/**
	 * Restore post meta, terms, and translation twins captured with a variation attribute row.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param array $before       Previous side effects.
	 * @return void
	 */
	function intersoccer_pv_repair_restore_facet_side($variation_id, array $before) {
		$variation_id = (int) $variation_id;
		if (isset($before['meta']) && is_array($before['meta']) && function_exists('update_post_meta')) {
			foreach ($before['meta'] as $meta_key => $meta_value) {
				update_post_meta($variation_id, (string) $meta_key, $meta_value);
			}
		}
		intersoccer_pv_repair_restore_object_terms($variation_id, $before);
		if (!isset($before['translations']) || !is_array($before['translations'])) {
			return;
		}
		foreach ($before['translations'] as $tid => $twin) {
			if (!is_array($twin)) {
				continue;
			}
			$tid = (int) $tid;
			if ($tid <= 0) {
				continue;
			}
			if (isset($twin['attributes']) && is_array($twin['attributes']) && function_exists('wc_get_product')) {
				$twin_product = wc_get_product($tid);
				if ($twin_product && method_exists($twin_product, 'get_attributes') && method_exists($twin_product, 'set_attributes')) {
					$current = $twin_product->get_attributes();
					if (!is_array($current)) {
						$current = [];
					}
					foreach ($twin['attributes'] as $key => $value) {
						$current[$key] = $value;
					}
					$twin_product->set_attributes($current);
					if (method_exists($twin_product, 'save')) {
						$twin_product->save();
					}
				}
			}
			if (isset($twin['meta']) && is_array($twin['meta']) && function_exists('update_post_meta')) {
				foreach ($twin['meta'] as $meta_key => $meta_value) {
					update_post_meta($tid, (string) $meta_key, $meta_value);
				}
			}
			intersoccer_pv_repair_restore_object_terms($tid, $twin);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_restore_parent_attr')) {
	/**
	 * Restore one parent taxonomy attribute without dropping the others.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy.
	 * @param array  $before     Previous is_variation and slugs.
	 * @return void
	 */
	function intersoccer_pv_repair_restore_parent_attr($product_id, $taxonomy, array $before) {
		if ($product_id <= 0 || $taxonomy === '' || !function_exists('wc_get_product')) {
			return;
		}
		$product = wc_get_product($product_id);
		if (!$product || !method_exists($product, 'get_attributes') || !method_exists($product, 'set_attributes')) {
			return;
		}
		$attributes = $product->get_attributes();
		if (!is_array($attributes)) {
			$attributes = [];
		}
		if (isset($attributes[$taxonomy]) && is_object($attributes[$taxonomy]) && method_exists($attributes[$taxonomy], 'set_variation')) {
			$attributes[$taxonomy]->set_variation(!empty($before['is_variation']));
		}
		$product->set_attributes($attributes);
		if (method_exists($product, 'save')) {
			$product->save();
		}
		$slugs = isset($before['slugs']) && is_array($before['slugs']) ? array_values(array_map('strval', $before['slugs'])) : [];
		if ($slugs !== [] && function_exists('wp_set_object_terms')) {
			wp_set_object_terms($product_id, $slugs, $taxonomy);
		}
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_scope_label')) {
	/**
	 * @param array $scope Scope.
	 * @return string
	 */
	function intersoccer_pv_advanced_tools_scope_label(array $scope) {
		$parts = [];
		foreach ($scope as $key => $value) {
			if ($key === 'tool' || $value === '' || $value === '0') {
				continue;
			}
			$parts[] = $key . '=' . $value;
		}
		$label = implode(', ', $parts);
		if (strlen($label) > 180) {
			$label = substr($label, 0, 177) . '...';
		}
		return $label;
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_handle_request')) {
	/**
	 * @return array{type:string,message:string,result:?array}
	 */
	function intersoccer_pv_advanced_tools_handle_request() {
		$empty = ['type' => '', 'message' => '', 'result' => null];
		$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		if ($method !== 'POST' || empty($_POST['intersoccer_pv_repair_tool'])) {
			return $empty;
		}
		intersoccer_pv_repair_require_cap();
		if (!intersoccer_pv_repair_verify_nonce()) {
			intersoccer_pv_repair_stop(__('Security check failed.', 'intersoccer-product-variations'));
		}
		$tool = sanitize_key(wp_unslash((string) $_POST['intersoccer_pv_repair_tool']));
		$mode = isset($_POST['intersoccer_pv_repair_mode'])
			? sanitize_key(wp_unslash((string) $_POST['intersoccer_pv_repair_mode']))
			: '';
		$user_id = (int) get_current_user_id();
		if ($mode === 'rollback' || $tool === 'rollback') {
			return intersoccer_pv_advanced_tools_handle_rollback($user_id);
		}
		$definitions = intersoccer_pv_advanced_tools_definitions();
		if (!isset($definitions[$tool])) {
			return ['type' => 'error', 'message' => __('Unknown repair.', 'intersoccer-product-variations'), 'result' => null];
		}
		$offers_apply = !array_key_exists('offers_apply', $definitions[$tool]) || !empty($definitions[$tool]['offers_apply']);
		$scope = intersoccer_pv_advanced_tools_scope_from_post($tool);
		if ($mode === 'preview') {
			$result = intersoccer_pv_advanced_tools_execute($tool, true, $scope);
			intersoccer_pv_repair_store_preview($user_id, $tool, $scope, $result);
			intersoccer_pv_repair_log([
				'user_id' => $user_id,
				'tool' => $tool,
				'mode' => 'preview',
				'scope' => intersoccer_pv_advanced_tools_scope_label($scope),
				'counts' => $result['counts'],
				'backup' => '',
			]);
			$GLOBALS['intersoccer_pv_repair_last_result'] = $result;
			$message = __('Preview finished. Nothing was written.', 'intersoccer-product-variations');
			if ($result['error'] !== '' && (int) $result['counts']['changed'] === 0) {
				return ['type' => 'error', 'message' => $result['summary'] !== '' ? $result['summary'] : $result['error'], 'result' => $result];
			}
			if (count($result['rows']) > (int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP) {
				$message .= ' ' . sprintf(
					/* translators: %d: backup row cap */
					__('Apply is refused above %d changes.', 'intersoccer-product-variations'),
					(int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP
				);
			}
			return ['type' => 'info', 'message' => $message . ' ' . $result['summary'], 'result' => $result];
		}
		if ($mode !== 'apply') {
			return ['type' => 'error', 'message' => __('Unknown action.', 'intersoccer-product-variations'), 'result' => null];
		}
		if (!$offers_apply) {
			return [
				'type' => 'error',
				'message' => __('Apply is not offered for this tool. No rollback.', 'intersoccer-product-variations'),
				'result' => null,
			];
		}
		$gate = intersoccer_pv_repair_gate_apply(
			$user_id,
			$tool,
			$scope,
			intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_phrase'),
			intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_global'),
			intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_delete')
		);
		if (!$gate['ok']) {
			return ['type' => 'error', 'message' => $gate['error'], 'result' => null];
		}
		$again = intersoccer_pv_advanced_tools_execute($tool, true, $scope);
		$stored = intersoccer_pv_repair_get_preview($user_id, $tool);
		if ($stored === null || (string) ($stored['rows_fingerprint'] ?? '') !== intersoccer_pv_repair_rows_fingerprint($again['rows'])) {
			return [
				'type' => 'error',
				'message' => __('The change set no longer matches the Preview. Run Preview again.', 'intersoccer-product-variations'),
				'result' => null,
			];
		}
		if (count($again['rows']) > (int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP) {
			return [
				'type' => 'error',
				'message' => sprintf(
					/* translators: %d: backup row cap */
					__('Apply is refused above %d changes. Narrow the scope so the backup can cover every row.', 'intersoccer-product-variations'),
					(int) INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP
				),
				'result' => null,
			];
		}
		$backup = intersoccer_pv_repair_write_backup($tool, $user_id, $again['rows']);
		try {
			$applied = intersoccer_pv_advanced_tools_execute($tool, false, $scope);
		} catch (Throwable $e) {
			$reason = trim((string) $e->getMessage());
			$note = 'partial';
			if ($reason !== '') {
				$note .= ': ' . $reason;
			}
			intersoccer_pv_repair_clear_preview($user_id, $tool);
			intersoccer_pv_repair_log([
				'user_id' => $user_id,
				'tool' => $tool,
				'mode' => 'apply',
				'scope' => intersoccer_pv_advanced_tools_scope_label($scope),
				'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
				'backup' => $backup,
				'note' => $note,
			]);
			$message = __('Apply stopped early. The backup is the pre-Apply snapshot. Roll back rows that were written.', 'intersoccer-product-variations');
			if ($reason !== '') {
				$message .= ' ' . sprintf(
					/* translators: %s: error detail */
					__('Reason: %s', 'intersoccer-product-variations'),
					$reason
				);
			}
			return [
				'type' => 'error',
				'message' => $message,
				'result' => null,
			];
		}
		intersoccer_pv_repair_clear_preview($user_id, $tool);
		intersoccer_pv_repair_log([
			'user_id' => $user_id,
			'tool' => $tool,
			'mode' => 'apply',
			'scope' => intersoccer_pv_advanced_tools_scope_label($scope),
			'counts' => $applied['counts'],
			'backup' => $backup,
		]);
		$GLOBALS['intersoccer_pv_repair_last_result'] = $applied;
		$message = $applied['error'] !== ''
			? $applied['error']
			: __('Apply finished.', 'intersoccer-product-variations') . ' ' . $applied['summary'];
		return ['type' => $applied['error'] !== '' ? 'error' : 'success', 'message' => $message, 'result' => $applied];
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_handle_rollback')) {
	/**
	 * @param int $user_id User ID.
	 * @return array{type:string,message:string,result:?array}
	 */
	function intersoccer_pv_advanced_tools_handle_rollback($user_id) {
		$typed_apply = intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_phrase');
		if (trim($typed_apply) !== INTERSOCCER_PV_REPAIR_APPLY_PHRASE) {
			return ['type' => 'error', 'message' => __('Type APPLY to confirm.', 'intersoccer-product-variations'), 'result' => null];
		}
		$filename = isset($_POST['intersoccer_pv_repair_backup'])
			? basename(sanitize_text_field(wp_unslash((string) $_POST['intersoccer_pv_repair_backup'])))
			: '';
		$backup = intersoccer_pv_repair_read_backup($filename);
		if ($backup === null) {
			return ['type' => 'error', 'message' => __('Backup file was not found.', 'intersoccer-product-variations'), 'result' => null];
		}
		$outcome = intersoccer_pv_repair_rollback_rows(
			$backup,
			'intersoccer_pv_repair_read_value',
			'intersoccer_pv_repair_restore_value'
		);
		intersoccer_pv_repair_log([
			'user_id' => $user_id,
			'tool' => (string) ($backup['tool'] ?? ''),
			'mode' => 'rollback',
			'scope' => $filename,
			'counts' => ['changed' => $outcome['restored'], 'added' => 0, 'updated' => $outcome['restored'], 'deleted' => 0],
			'backup' => $filename,
		]);
		$GLOBALS['intersoccer_pv_repair_last_rollback'] = $outcome;
		$message = sprintf(
			/* translators: 1: restored count, 2: conflict count */
			__('Rollback restored %1$d rows. Conflicts left untouched: %2$d.', 'intersoccer-product-variations'),
			(int) $outcome['restored'],
			count($outcome['conflicts'])
		);
		return ['type' => 'success', 'message' => $message, 'result' => null];
	}
}

if (!function_exists('intersoccer_pv_repair_phrase_fields')) {
	/**
	 * @param bool $show_global Show the GLOBAL field.
	 * @return void
	 */
	function intersoccer_pv_repair_phrase_fields($show_global, $show_delete = false) {
		?>
		<p>
			<label>
				<?php esc_html_e('Type APPLY to confirm', 'intersoccer-product-variations'); ?>
				<input type="text" name="intersoccer_pv_repair_phrase" value="" autocomplete="off" class="regular-text" />
			</label>
		</p>
		<?php if ($show_global) : ?>
			<p>
				<label>
					<?php esc_html_e('Type GLOBAL for a shop-wide scope', 'intersoccer-product-variations'); ?>
					<input type="text" name="intersoccer_pv_repair_global" value="" autocomplete="off" class="regular-text" />
				</label>
			</p>
		<?php endif; ?>
		<?php if ($show_delete) : ?>
			<p>
				<label>
					<?php esc_html_e('Type DELETE when Preview removes metadata', 'intersoccer-product-variations'); ?>
					<input type="text" name="intersoccer_pv_repair_delete" value="" autocomplete="off" class="regular-text" />
				</label>
			</p>
		<?php endif; ?>
		<?php
	}
}

if (!function_exists('intersoccer_pv_scan_repair_scope_from_request')) {
	/**
	 * Scope shared by Scan preview and the selected-order update.
	 *
	 * @return array
	 */
	function intersoccer_pv_scan_repair_scope_from_request() {
		$statuses = isset($_REQUEST['order_statuses']) && is_array($_REQUEST['order_statuses'])
			? array_values(array_map('sanitize_text_field', wp_unslash($_REQUEST['order_statuses'])))
			: ['processing', 'completed'];
		sort($statuses);
		$limit = isset($_REQUEST['preview_limit']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['preview_limit'])) : '25';
		return [
			'tool' => 'scan_orders',
			'preview_limit' => $limit,
			'order_statuses' => implode(',', $statuses),
			'fix_activity_type_only' => (isset($_REQUEST['fix_activity_type_only']) && (string) $_REQUEST['fix_activity_type_only'] === '1') ? '1' : '0',
			'strip_deprecated_meta' => (isset($_REQUEST['strip_deprecated_meta']) && (string) $_REQUEST['strip_deprecated_meta'] === '1') ? '1' : '0',
			'prune_legacy_twins' => (isset($_REQUEST['prune_legacy_twins']) && (string) $_REQUEST['prune_legacy_twins'] === '1') ? '1' : '0',
		];
	}
}

if (!function_exists('intersoccer_pv_register_advanced_tools_menu')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_register_advanced_tools_menu() {
		if (!function_exists('add_submenu_page')) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__('InterSoccer Advanced tools: Products & orders', 'intersoccer-product-variations'),
			__('InterSoccer Advanced tools: Products & orders', 'intersoccer-product-variations'),
			INTERSOCCER_PV_REPAIR_CAP,
			intersoccer_pv_advanced_tools_page_slug(),
			'intersoccer_pv_render_advanced_tools_page',
			58
		);
	}
}

if (!function_exists('intersoccer_pv_advanced_tools_tabs')) {
	/**
	 * @return array<string,string>
	 */
	function intersoccer_pv_advanced_tools_tabs() {
		return [
			'scan' => __('Scan & preview', 'intersoccer-product-variations'),
			'batch' => __('Automated batch', 'intersoccer-product-variations'),
			'season-labels' => __('Season labels', 'intersoccer-product-variations'),
			'course-dates' => __('Course dates', 'intersoccer-product-variations'),
			'product-tools' => __('Product repairs', 'intersoccer-product-variations'),
			'late-pickup' => __('Late pick up', 'intersoccer-product-variations'),
		];
	}
}

if (!function_exists('intersoccer_pv_render_tool_form')) {
	/**
	 * @param string $tool Tool key.
	 * @param string $product_id Prefilled product ID.
	 * @return void
	 */
	function intersoccer_pv_render_tool_form($tool, $product_id = '') {
		$definitions = intersoccer_pv_advanced_tools_definitions();
		if (!isset($definitions[$tool])) {
			return;
		}
		$definition = $definitions[$tool];
		$shop_wide = intersoccer_pv_scope_is_shop_wide($tool, ['product_id' => $product_id, 'force' => '0']);
		?>
		<div class="card" style="max-width: 920px; padding: 12px 16px; margin: 16px 0;">
			<h2 style="margin-top:0;"><?php echo esc_html($definition['label']); ?></h2>
			<p><?php echo esc_html($definition['description']); ?></p>
			<form method="post">
				<?php wp_nonce_field(INTERSOCCER_PV_REPAIR_NONCE); ?>
				<input type="hidden" name="intersoccer_pv_repair_tool" value="<?php echo esc_attr($tool); ?>" />
				<table class="form-table" role="presentation">
					<?php foreach ($definition['fields'] as $field) : ?>
						<tr>
							<th scope="row"><label for="intersoccer-pv-<?php echo esc_attr($tool . '-' . $field['name']); ?>"><?php echo esc_html($field['label']); ?></label></th>
							<td>
								<?php if (($field['type'] ?? '') === 'checkbox') : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr($field['name']); ?>" id="intersoccer-pv-<?php echo esc_attr($tool . '-' . $field['name']); ?>" value="1" />
										<?php echo esc_html($field['label']); ?>
									</label>
								<?php else : ?>
									<input
										type="<?php echo ($field['type'] ?? '') === 'number' ? 'number' : 'text'; ?>"
										class="regular-text"
										name="<?php echo esc_attr($field['name']); ?>"
										id="intersoccer-pv-<?php echo esc_attr($tool . '-' . $field['name']); ?>"
										value="<?php echo esc_attr($field['name'] === 'product_id' ? $product_id : (($field['name'] === 'order_statuses') ? 'processing,completed' : (($field['name'] === 'scan_limit') ? '1000' : ''))); ?>"
										min="0"
									/>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p>
					<button type="submit" class="button button-secondary" name="intersoccer_pv_repair_mode" value="preview">
						<?php esc_html_e('Preview (dry run)', 'intersoccer-product-variations'); ?>
					</button>
				</p>
				<?php if (!empty($definition['offers_apply']) || !array_key_exists('offers_apply', $definition)) : ?>
				<?php intersoccer_pv_repair_phrase_fields(true, !empty($definition['asks_delete'])); ?>
				<p>
					<button type="submit" class="button button-primary" name="intersoccer_pv_repair_mode" value="apply">
						<?php esc_html_e('Apply', 'intersoccer-product-variations'); ?>
					</button>
				</p>
				<?php if (!$shop_wide) : ?>
					<p class="description"><?php esc_html_e('GLOBAL is required when the product ID is empty, and when parsed dates overwrite existing values.', 'intersoccer-product-variations'); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e('This tool is shop-wide. Type GLOBAL as well as APPLY.', 'intersoccer-product-variations'); ?></p>
				<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e('Apply is not offered. No rollback.', 'intersoccer-product-variations'); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}

if (!function_exists('intersoccer_pv_render_rosters_tools_link')) {
	/**
	 * Cross-link to Rosters & reports Advanced tools when that plugin is present.
	 *
	 * @return void
	 */
	function intersoccer_pv_render_rosters_tools_link() {
		if (!function_exists('intersoccer_pv_repair_reports_plugin_active') || !intersoccer_pv_repair_reports_plugin_active()) {
			return;
		}
		if (!function_exists('intersoccer_pv_user_can_run_repairs') || !intersoccer_pv_user_can_run_repairs()) {
			return;
		}
		$url = admin_url('admin.php?page=intersoccer-advanced-tools');
		echo '<p><a href="' . esc_url($url) . '">' . esc_html__('InterSoccer Advanced tools: Rosters & reports', 'intersoccer-product-variations') . '</a></p>';
	}
}

if (!function_exists('intersoccer_pv_render_advanced_tools_page')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_render_advanced_tools_page() {
		intersoccer_pv_repair_require_cap();
		$notice = intersoccer_pv_advanced_tools_handle_request();
		$tabs = intersoccer_pv_advanced_tools_tabs();
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'scan';
		if (!isset($tabs[$tab])) {
			$tab = 'scan';
		}
		$product_id = isset($_GET['product_id']) ? (string) absint(wp_unslash($_GET['product_id'])) : '';
		if ($product_id === '0') {
			$product_id = '';
		}
		$base = admin_url('admin.php?page=' . intersoccer_pv_advanced_tools_page_slug());
		$result = $GLOBALS['intersoccer_pv_repair_last_result'] ?? null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e('InterSoccer Advanced tools: Products & orders', 'intersoccer-product-variations'); ?></h1>
			<?php intersoccer_pv_render_rosters_tools_link(); ?>
			<p><?php esc_html_e('Repairs stay off everyday product screens. Preview lists the fields that would change and writes nothing. Apply asks you to type APPLY. A shop-wide run also asks you to type GLOBAL. Removals also ask you to type DELETE. Apply is refused above 200 changes. The backup is taken before Apply and can roll back rows that still match what Apply wrote, including a partial Apply. Tools marked no rollback are not in the backup list.', 'intersoccer-product-variations'); ?></p>
			<?php if (!empty($notice['message'])) : ?>
				<div class="notice notice-<?php echo $notice['type'] === 'error' ? 'error' : 'info'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
				<?php foreach ($tabs as $slug => $label) : ?>
					<a href="<?php echo esc_url(add_query_arg('tab', $slug, $base)); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ($tab === 'scan' && function_exists('intersoccer_render_update_orders_page')) {
				intersoccer_render_update_orders_page(true);
			} elseif ($tab === 'batch') {
				intersoccer_pv_render_tool_form('automated_batch');
			} elseif ($tab === 'season-labels' && function_exists('intersoccer_render_season_label_repair_page')) {
				intersoccer_render_season_label_repair_page(true);
			} elseif ($tab === 'course-dates' && function_exists('intersoccer_render_course_date_repair_page')) {
				intersoccer_render_course_date_repair_page(true);
			} elseif ($tab === 'late-pickup') {
				intersoccer_pv_render_tool_form('late_pickup', $product_id);
			} else {
				foreach (['recalculate_end_dates', 'refresh_attributes', 'repair_venue', 'migrate_camp_dates'] as $tool) {
					intersoccer_pv_render_tool_form($tool, $product_id);
				}
			}
			if (is_array($result) && !empty($result['rows'])) {
				echo '<h2>' . esc_html__('Preview rows', 'intersoccer-product-variations') . '</h2>';
				echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>' . esc_html__('ID', 'intersoccer-product-variations') . '</th><th>' . esc_html__('Before', 'intersoccer-product-variations') . '</th><th>' . esc_html__('After', 'intersoccer-product-variations') . '</th></tr></thead><tbody>';
				foreach (array_slice($result['rows'], 0, 100) as $row) {
					echo '<tr><td>' . esc_html((string) ($row['id'] ?? '')) . '</td><td><code>' . esc_html(intersoccer_pv_repair_json($row['before'] ?? '')) . '</code></td><td><code>' . esc_html(intersoccer_pv_repair_json($row['after'] ?? '')) . '</code></td></tr>';
				}
				echo '</tbody></table>';
			}
			intersoccer_pv_render_repair_log();
			intersoccer_pv_render_backup_rollback();
			?>
		</div>
		<?php
	}
}

if (!function_exists('intersoccer_pv_render_repair_log')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_render_repair_log() {
		$entries = array_reverse(intersoccer_pv_repair_log_entries());
		echo '<h2>' . esc_html__('Run log', 'intersoccer-product-variations') . '</h2>';
		if ($entries === []) {
			echo '<p>' . esc_html__('No repair runs yet.', 'intersoccer-product-variations') . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
		foreach ([__('When', 'intersoccer-product-variations'), __('User', 'intersoccer-product-variations'), __('Tool', 'intersoccer-product-variations'), __('Mode', 'intersoccer-product-variations'), __('Scope', 'intersoccer-product-variations'), __('Changed', 'intersoccer-product-variations'), __('Backup', 'intersoccer-product-variations'), __('Note', 'intersoccer-product-variations'), __('Peak memory (MB)', 'intersoccer-product-variations')] as $heading) {
			echo '<th>' . esc_html($heading) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach (array_slice($entries, 0, 30) as $entry) {
			$counts = is_array($entry['counts'] ?? null) ? $entry['counts'] : [];
			echo '<tr>';
			echo '<td>' . esc_html((string) ($entry['at'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) (int) ($entry['user_id'] ?? 0)) . '</td>';
			echo '<td>' . esc_html((string) ($entry['tool'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($entry['mode'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($entry['scope'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) (int) ($counts['changed'] ?? 0)) . '</td>';
			echo '<td>' . esc_html((string) ($entry['backup'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($entry['note'] ?? '')) . '</td>';
			echo '<td>' . esc_html((string) ($entry['peak_memory_mb'] ?? '')) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}

if (!function_exists('intersoccer_pv_render_backup_rollback')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_render_backup_rollback() {
		$index = intersoccer_pv_repair_state_get('intersoccer_pv_repair_backup_index', []);
		if (!is_array($index) || $index === []) {
			return;
		}
		echo '<h2>' . esc_html__('Backups', 'intersoccer-product-variations') . '</h2>';
		echo '<table class="widefat striped" style="max-width:920px;"><thead><tr><th>' . esc_html__('File', 'intersoccer-product-variations') . '</th><th>' . esc_html__('Tool', 'intersoccer-product-variations') . '</th><th></th></tr></thead><tbody>';
		foreach (array_reverse($index) as $row) {
			if (!is_array($row) || empty($row['file'])) {
				continue;
			}
			$tool_key = (string) ($row['tool'] ?? '');
			$definitions = intersoccer_pv_advanced_tools_definitions();
			if ($tool_key !== '' && !empty($definitions[$tool_key]['no_rollback'])) {
				continue;
			}
			echo '<tr><td><code>' . esc_html((string) $row['file']) . '</code></td><td>' . esc_html((string) ($row['tool'] ?? '')) . '</td><td>';
			echo '<form method="post" style="display:inline;">';
			wp_nonce_field(INTERSOCCER_PV_REPAIR_NONCE);
			echo '<input type="hidden" name="intersoccer_pv_repair_tool" value="rollback" />';
			echo '<input type="hidden" name="intersoccer_pv_repair_mode" value="rollback" />';
			echo '<input type="hidden" name="intersoccer_pv_repair_backup" value="' . esc_attr((string) $row['file']) . '" />';
			echo '<input type="text" name="intersoccer_pv_repair_phrase" value="" autocomplete="off" placeholder="' . esc_attr__('APPLY', 'intersoccer-product-variations') . '" /> ';
			echo '<button type="submit" class="button">' . esc_html__('Rollback', 'intersoccer-product-variations') . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}
}

if (function_exists('add_action')) {
	add_action('admin_menu', 'intersoccer_pv_register_advanced_tools_menu', 20);
}
