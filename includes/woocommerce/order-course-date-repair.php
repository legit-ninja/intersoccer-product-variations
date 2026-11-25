<?php
/**
 * Order-line course date repair (Order Meta Repair → Course dates tab).
 *
 * When the office corrects a course variation's schedule after bookings were
 * taken, lines booked before the correction keep the old Start Date, End Date,
 * Holidays and "N Weeks Remaining" note. This tool compares each scoped course
 * line with its CURRENT variation and rewrites only those schedule fields.
 *
 * - Dry run (Preview) is the default and writes nothing.
 * - Apply needs the typed phrase APPLY (and GLOBAL for all course lines), refuses when the change set differs from the
 *   Preview, writes a JSON backup first, then re-syncs the touched orders' roster
 *   rows through Reports & Rosters (scoped, never deletes).
 * - Rollback restores each value only while it still holds what Apply wrote.
 * - Prices, totals, coupons and discounts are never written. Price differences
 *   between what was paid and the current variation price are reported as data.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

class InterSoccer_Order_Course_Date_Repair {

	const PREVIEW_TRANSIENT = 'intersoccer_pv_course_date_repair_preview';
	const BACKUP_PREFIX     = 'course-date-repair-';
	const ACTION_REPAIRED   = 'intersoccer_pv_order_course_dates_repaired';
	const MAX_IDS           = 500;
	const BATCH_SIZE        = 200;

	/** Line meta keys this tool may write. */
	const KEY_START    = 'Start Date';
	const KEY_END      = 'End Date';
	const KEY_HOLIDAYS = 'Holidays';
	const KEY_WEEKS    = 'Booking details';

	/**
	 * Order statuses whose lines are repaired (others are listed as skipped).
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return ['completed', 'processing', 'on-hold', 'pending'];
	}

	/**
	 * Parse a free-text id list ("1, 2 3\n4") into unique positive ints (capped).
	 *
	 * @param string|array $raw Raw input.
	 * @return int[]
	 */
	public static function parse_id_list($raw) {
		$parts = is_array($raw) ? $raw : preg_split('/[^0-9]+/', (string) $raw);
		$ids = [];
		foreach ((array) $parts as $part) {
			$id = (int) $part;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);
		sort($ids);
		return array_slice($ids, 0, self::MAX_IDS);
	}

	/**
	 * Normalize a scope array.
	 *
	 * @param array $scope item_ids, variation_ids, all_course_lines.
	 * @return array{item_ids:int[],variation_ids:int[],all_course_lines:bool}
	 */
	public static function normalize_scope(array $scope) {
		return [
			'item_ids'         => self::parse_id_list($scope['item_ids'] ?? []),
			'variation_ids'    => self::parse_id_list($scope['variation_ids'] ?? []),
			'all_course_lines' => !empty($scope['all_course_lines']),
		];
	}

	/**
	 * @param array $scope Normalized scope.
	 * @return bool
	 */
	public static function scope_is_empty(array $scope) {
		return empty($scope['item_ids']) && empty($scope['variation_ids']) && empty($scope['all_course_lines']);
	}

	/**
	 * Any date string to Y-m-d (or '' when unparseable/empty).
	 *
	 * @param string $value Date text.
	 * @return string
	 */
	public static function to_ymd($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value;
		}
		// d/m/Y or d/m/y (legacy line format).
		if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $value, $m)) {
			$year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
			return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
		}
		$ts = strtotime($value);
		return $ts === false ? '' : date('Y-m-d', $ts);
	}

	/**
	 * English storage format used by checkout for course line dates.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function format_for_storage($ymd) {
		if (function_exists('intersoccer_pv_format_order_line_date_for_storage')) {
			return intersoccer_pv_format_order_line_date_for_storage($ymd);
		}
		$ts = strtotime((string) $ymd);
		return $ts === false ? (string) $ymd : date('F j, Y', $ts);
	}

	/**
	 * Holidays line value → sorted list of Y-m-d.
	 *
	 * @param string $value e.g. "February 10, 2027, February 17, 2027".
	 * @return string[]
	 */
	public static function parse_holidays_value($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return [];
		}
		$out = [];
		if (preg_match_all('/[A-Za-z]+ \d{1,2}, \d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4}/', $value, $m)) {
			foreach ($m[0] as $token) {
				$ymd = self::to_ymd($token);
				if ($ymd !== '') {
					$out[$ymd] = $ymd;
				}
			}
		}
		$out = array_values($out);
		sort($out);
		return $out;
	}

	/**
	 * ISO weekday (1=Mon … 7=Sun) from a course-day slug/label in EN, FR or DE.
	 *
	 * @param string $day Slug or label.
	 * @return int 0 when unknown.
	 */
	public static function weekday_number($day) {
		$day = strtolower(trim((string) $day));
		$map = [
			'monday' => 1, 'lundi' => 1, 'montag' => 1,
			'tuesday' => 2, 'mardi' => 2, 'dienstag' => 2,
			'wednesday' => 3, 'mercredi' => 3, 'mittwoch' => 3,
			'thursday' => 4, 'jeudi' => 4, 'donnerstag' => 4,
			'friday' => 5, 'vendredi' => 5, 'freitag' => 5,
			'saturday' => 6, 'samedi' => 6, 'samstag' => 6,
			'sunday' => 7, 'dimanche' => 7, 'sonntag' => 7,
		];
		foreach ($map as $name => $n) {
			if ($day === $name || strpos($day, $name) === 0) {
				return $n;
			}
		}
		return 0;
	}

	/**
	 * Weeks remaining for a booking made on $booked_ymd (same rule as the cart note:
	 * full term before the start, otherwise course days left skipping holidays).
	 *
	 * @param array  $schedule start, end, holidays, total_weeks, weekday.
	 * @param string $booked_ymd Order date Y-m-d.
	 * @return int
	 */
	public static function weeks_remaining_at(array $schedule, $booked_ymd) {
		$total = (int) ($schedule['total_weeks'] ?? 0);
		if ($total <= 0) {
			return 0;
		}
		$start = (string) ($schedule['start'] ?? '');
		$end   = (string) ($schedule['end'] ?? '');
		$wd    = (int) ($schedule['weekday'] ?? 0);
		if ($start === '' || $booked_ymd === '' || $booked_ymd < $start || $wd === 0 || $end === '') {
			return $total;
		}
		if ($booked_ymd > $end) {
			return 0;
		}
		$holidays = array_flip((array) ($schedule['holidays'] ?? []));
		$count = 0;
		$ts = strtotime($booked_ymd . ' 12:00:00');
		$end_ts = strtotime($end . ' 12:00:00');
		while ($ts <= $end_ts) {
			$ymd = date('Y-m-d', $ts);
			if ((int) date('N', $ts) === $wd && !isset($holidays[$ymd])) {
				$count++;
			}
			$ts += 86400;
		}
		return max(0, min($count, $total));
	}

	/**
	 * Current schedule of a variation (WPML falls back to the original variation).
	 *
	 * @param int $variation_id Variation id.
	 * @return array|null start,end,holidays,total_weeks,weekday,day_slug,price; null when not a course.
	 */
	public static function variation_schedule($variation_id) {
		$variation_id = (int) $variation_id;
		if ($variation_id <= 0) {
			return null;
		}
		$get = static function ($key, $default = '') use ($variation_id) {
			if (function_exists('intersoccer_get_course_meta')) {
				return intersoccer_get_course_meta($variation_id, $key, $default);
			}
			return function_exists('get_post_meta') ? get_post_meta($variation_id, $key, true) : $default;
		};
		$start = self::to_ymd((string) $get('_course_start_date', ''));
		if ($start === '') {
			return null;
		}
		$holidays_raw = $get('_course_holiday_dates', []);
		if (is_string($holidays_raw) && function_exists('maybe_unserialize')) {
			$holidays_raw = maybe_unserialize($holidays_raw);
		}
		$holidays = [];
		foreach ((array) $holidays_raw as $h) {
			$ymd = self::to_ymd((string) $h);
			if ($ymd !== '') {
				$holidays[$ymd] = $ymd;
			}
		}
		$holidays = array_values($holidays);
		sort($holidays);
		$day_slug = (string) $get('attribute_pa_course-day', '');
		$price = function_exists('get_post_meta') ? get_post_meta($variation_id, '_price', true) : '';
		if ($price === '' || $price === null) {
			$price = $get('_price', '');
		}
		return [
			'start'       => $start,
			'end'         => self::to_ymd((string) $get('_end_date', '')),
			'holidays'    => $holidays,
			'total_weeks' => (int) $get('_course_total_weeks', 0),
			'day_slug'    => $day_slug,
			'weekday'     => self::weekday_number($day_slug),
			'price'       => $price === '' || $price === null ? null : (float) $price,
		];
	}

	/**
	 * Compare one line with its variation schedule (pure; no I/O).
	 *
	 * @param array      $line item_id, order_id, variation_id, status, order_date (Y-m-d), language, meta[].
	 * @param array|null $schedule From variation_schedule().
	 * @return array{changes:array,skipped:array,price:array|null}
	 */
	public static function evaluate_line(array $line, $schedule) {
		$out = ['changes' => [], 'skipped' => [], 'price' => null];
		$item_id  = (int) ($line['item_id'] ?? 0);
		$order_id = (int) ($line['order_id'] ?? 0);
		$meta     = (array) ($line['meta'] ?? []);
		$base = [
			'item_id'      => $item_id,
			'order_id'     => $order_id,
			'variation_id' => (int) ($line['variation_id'] ?? 0),
			'status'       => (string) ($line['status'] ?? ''),
			'language'     => (string) ($line['language'] ?? 'en'),
		];
		$skip = static function ($reason, $detail = '') use ($base) {
			return $base + ['reason' => $reason, 'detail' => (string) $detail];
		};

		if (!in_array($base['status'], self::active_statuses(), true)) {
			$out['skipped'][] = $skip('order_status_not_active', $base['status']);
			return $out;
		}
		if (!is_array($schedule)) {
			$out['skipped'][] = $skip('not_a_course_variation');
			return $out;
		}
		$line_day = (string) ($meta['pa_course-day'] ?? '');
		$line_wd = self::weekday_number($line_day !== '' ? $line_day : (string) ($meta['Course Day'] ?? ''));
		if ($schedule['weekday'] > 0 && $line_wd > 0 && $line_wd !== $schedule['weekday']) {
			$out['skipped'][] = $skip('course_day_differs', $line_day . ' vs ' . $schedule['day_slug']);
			return $out;
		}
		if ($schedule['weekday'] > 0 && (int) date('N', strtotime($schedule['start'] . ' 12:00:00')) !== $schedule['weekday']) {
			$out['skipped'][] = $skip('variation_start_not_on_course_day', $schedule['start'] . ' ' . $schedule['day_slug']);
			return $out;
		}

		$add = static function ($key, $old, $new, $op) use ($base, &$out) {
			$out['changes'][] = $base + ['meta_key' => $key, 'old' => (string) $old, 'new' => (string) $new, 'op' => $op];
		};

		$cur_start = (string) ($meta[self::KEY_START] ?? '');
		if (self::to_ymd($cur_start) !== $schedule['start']) {
			$add(self::KEY_START, $cur_start, self::format_for_storage($schedule['start']), $cur_start === '' ? 'add' : 'update');
		}
		$cur_end = (string) ($meta[self::KEY_END] ?? '');
		if ($schedule['end'] !== '' && self::to_ymd($cur_end) !== $schedule['end']) {
			$add(self::KEY_END, $cur_end, self::format_for_storage($schedule['end']), $cur_end === '' ? 'add' : 'update');
		}
		$cur_hol = (string) ($meta[self::KEY_HOLIDAYS] ?? '');
		if (self::parse_holidays_value($cur_hol) !== $schedule['holidays']) {
			$new_hol = implode(', ', array_map([__CLASS__, 'format_for_storage'], $schedule['holidays']));
			if ($new_hol === '') {
				$add(self::KEY_HOLIDAYS, $cur_hol, '', 'delete');
			} else {
				$add(self::KEY_HOLIDAYS, $cur_hol, $new_hol, $cur_hol === '' ? 'add' : 'update');
			}
		}

		// The weeks note is derived from the schedule; only touch it when dates changed.
		if ($out['changes'] !== []) {
			$cur_note = (string) ($meta[self::KEY_WEEKS] ?? '');
			if (preg_match('/^(\d+)(\s.*)$/u', $cur_note, $m)) {
				$weeks = self::weeks_remaining_at($schedule, (string) ($line['order_date'] ?? ''));
				if ($weeks > 0 && $weeks !== (int) $m[1]) {
					$add(self::KEY_WEEKS, $cur_note, $weeks . $m[2], 'update');
				}
			}
		}

		if ($out['changes'] !== []) {
			$qty = max(1, (int) ($meta['_qty'] ?? 1));
			$paid_sub = isset($meta['_line_subtotal']) ? (float) $meta['_line_subtotal'] : null;
			$current = $schedule['price'] !== null ? round($schedule['price'] * $qty, 2) : null;
			$out['price'] = $base + [
				'qty'               => $qty,
				'paid_subtotal'     => $paid_sub,
				'paid_total'        => isset($meta['_line_total']) ? (float) $meta['_line_total'] : null,
				'current_price'     => $current,
				'difference'        => ($current !== null && $paid_sub !== null) ? round($current - $paid_sub, 2) : null,
			];
		}
		return $out;
	}

	/**
	 * Stable fingerprint of a change set (Apply must match the Preview).
	 *
	 * @param array $changes Change rows.
	 * @return string
	 */
	public static function fingerprint(array $changes) {
		$keys = [];
		foreach ($changes as $c) {
			$keys[] = implode("\x1f", [(int) $c['item_id'], $c['meta_key'], $c['op'], $c['old'], $c['new']]);
		}
		sort($keys);
		return sha1(implode("\x1e", $keys));
	}

	/**
	 * Candidate line item ids for a scope.
	 *
	 * @param array $scope Normalized scope.
	 * @return int[]
	 */
	public static function candidate_item_ids(array $scope) {
		global $wpdb;
		$ids = [];
		foreach ($scope['item_ids'] as $id) {
			$ids[$id] = $id;
		}
		if (!isset($wpdb) || !is_object($wpdb)) {
			return array_values($ids);
		}
		$meta_t = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if (!empty($scope['variation_ids'])) {
			$ph = implode(',', array_fill(0, count($scope['variation_ids']), '%s'));
			$rows = $wpdb->get_col($wpdb->prepare(
				"SELECT order_item_id FROM {$meta_t} WHERE meta_key = '_variation_id' AND meta_value IN ({$ph})",
				...array_map('strval', $scope['variation_ids'])
			));
			foreach ((array) $rows as $id) {
				$ids[(int) $id] = (int) $id;
			}
		}
		if (!empty($scope['all_course_lines'])) {
			$rows = $wpdb->get_col(
				"SELECT DISTINCT order_item_id FROM {$meta_t} WHERE meta_key = 'Start Date' AND meta_value <> ''"
			);
			foreach ((array) $rows as $id) {
				$ids[(int) $id] = (int) $id;
			}
		}
		$ids = array_values(array_filter($ids));
		sort($ids);
		return $ids;
	}

	/**
	 * Load line rows (items + meta + order status/date/language) by SQL.
	 *
	 * @param int[] $item_ids Item ids.
	 * @return array<int,array> Keyed by item id (line_item rows only).
	 */
	public static function load_lines(array $item_ids) {
		global $wpdb;
		$item_ids = array_values(array_filter(array_map('intval', $item_ids)));
		if ($item_ids === [] || !isset($wpdb) || !is_object($wpdb)) {
			return [];
		}
		$items_t = $wpdb->prefix . 'woocommerce_order_items';
		$meta_t  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$ph = implode(',', array_fill(0, count($item_ids), '%d'));
		$items = $wpdb->get_results($wpdb->prepare(
			"SELECT order_item_id, order_id FROM {$items_t} WHERE order_item_type = 'line_item' AND order_item_id IN ({$ph})",
			...$item_ids
		), ARRAY_A);
		$lines = [];
		$order_ids = [];
		foreach ((array) $items as $row) {
			$iid = (int) $row['order_item_id'];
			$lines[$iid] = ['item_id' => $iid, 'order_id' => (int) $row['order_id'], 'meta' => []];
			$order_ids[(int) $row['order_id']] = true;
		}
		if ($lines === []) {
			return [];
		}
		$keys = ['_product_id', '_variation_id', '_qty', '_line_subtotal', '_line_total', 'pa_course-day', 'Course Day',
			self::KEY_START, self::KEY_END, self::KEY_HOLIDAYS, self::KEY_WEEKS];
		$kph = implode(',', array_fill(0, count($keys), '%s'));
		$ids = array_keys($lines);
		$iph = implode(',', array_fill(0, count($ids), '%d'));
		$meta_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT order_item_id, meta_key, meta_value FROM {$meta_t} WHERE order_item_id IN ({$iph}) AND meta_key IN ({$kph})",
			...array_merge($ids, $keys)
		), ARRAY_A);
		foreach ((array) $meta_rows as $mr) {
			$iid = (int) $mr['order_item_id'];
			if (isset($lines[$iid]) && !isset($lines[$iid]['meta'][$mr['meta_key']])) {
				$lines[$iid]['meta'][(string) $mr['meta_key']] = (string) $mr['meta_value'];
			}
		}
		$orders = self::order_rows(array_keys($order_ids));
		foreach ($lines as $iid => $line) {
			$o = $orders[$line['order_id']] ?? ['status' => 'missing', 'type' => '', 'date' => '', 'lang' => 'en'];
			$lines[$iid]['variation_id'] = (int) ($line['meta']['_variation_id'] ?? 0);
			$lines[$iid]['status'] = $o['type'] === 'shop_order' ? $o['status'] : 'not_an_order';
			$lines[$iid]['order_date'] = $o['date'];
			$lines[$iid]['language'] = $o['lang'];
		}
		return $lines;
	}

	/**
	 * Order status / type / created date (site-local Y-m-d) / language.
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int,array{status:string,type:string,date:string,lang:string}>
	 */
	public static function order_rows(array $order_ids) {
		global $wpdb;
		$order_ids = array_values(array_filter(array_map('intval', $order_ids)));
		if ($order_ids === []) {
			return [];
		}
		$ph = implode(',', array_fill(0, count($order_ids), '%d'));
		$hpos = class_exists('InterSoccer_Order_Season_Label_Repair') && InterSoccer_Order_Season_Label_Repair::hpos_enabled();
		if ($hpos) {
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id AS oid, status, type, date_created_gmt AS created FROM {$wpdb->prefix}wc_orders WHERE id IN ({$ph})",
				...$order_ids
			), ARRAY_A);
			$langs = $wpdb->get_results($wpdb->prepare(
				"SELECT order_id AS oid, meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = 'wpml_language' AND order_id IN ({$ph})",
				...$order_ids
			), ARRAY_A);
		} else {
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT ID AS oid, post_status AS status, post_type AS type, post_date_gmt AS created FROM {$wpdb->posts} WHERE ID IN ({$ph})",
				...$order_ids
			), ARRAY_A);
			$langs = $wpdb->get_results($wpdb->prepare(
				"SELECT post_id AS oid, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'wpml_language' AND post_id IN ({$ph})",
				...$order_ids
			), ARRAY_A);
		}
		$out = [];
		foreach ((array) $rows as $r) {
			$status = (string) $r['status'];
			if (strpos($status, 'wc-') === 0) {
				$status = substr($status, 3);
			}
			$created = (string) ($r['created'] ?? '');
			$date = '';
			if ($created !== '' && strpos($created, '0000') !== 0) {
				$date = function_exists('get_date_from_gmt') ? get_date_from_gmt($created, 'Y-m-d') : substr($created, 0, 10);
			}
			$out[(int) $r['oid']] = ['status' => $status, 'type' => (string) $r['type'], 'date' => $date, 'lang' => 'en'];
		}
		foreach ((array) $langs as $l) {
			$oid = (int) $l['oid'];
			$lang = strtolower(trim((string) $l['meta_value']));
			if (isset($out[$oid]) && $lang !== '') {
				$out[$oid]['lang'] = $lang;
			}
		}
		return $out;
	}

	/**
	 * Dry run for a scope.
	 *
	 * @param array $scope Scope.
	 * @return array|WP_Error
	 */
	public static function run_dry(array $scope) {
		return self::run($scope, false);
	}

	/**
	 * Apply for a scope; refuses when the change set no longer matches $expected_fingerprint.
	 *
	 * @param array  $scope Scope.
	 * @param string $expected_fingerprint Fingerprint from the Preview.
	 * @return array|WP_Error
	 */
	public static function run_apply(array $scope, $expected_fingerprint) {
		return self::run($scope, true, (string) $expected_fingerprint);
	}

	/**
	 * @param array  $scope Scope.
	 * @param bool   $apply Write.
	 * @param string $expected_fingerprint Required for apply.
	 * @return array|WP_Error
	 */
	public static function run(array $scope, $apply = false, $expected_fingerprint = '') {
		$scope = self::normalize_scope($scope);
		if (self::scope_is_empty($scope)) {
			return new WP_Error('scope_empty', __('Enter order item IDs or variation IDs (or tick "all course lines") before Preview.', 'intersoccer-product-variations'));
		}
		$report = [
			'mode'        => $apply ? 'apply' : 'dry_run',
			'scope'       => $scope,
			'examined'    => 0,
			'rows'        => [],
			'skipped'     => [],
			'prices'      => [],
			'lines'       => 0,
			'orders'      => 0,
			'fingerprint' => '',
			'updated'     => 0,
			'backup_file' => null,
			'rosters'     => null,
			'order_ids'   => [],
		];
		$ids = self::candidate_item_ids($scope);
		$schedules = [];
		foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
			$lines = self::load_lines($chunk);
			foreach ($chunk as $iid) {
				if (!isset($lines[$iid])) {
					if (in_array($iid, $scope['item_ids'], true)) {
						$report['skipped'][] = ['item_id' => $iid, 'order_id' => 0, 'variation_id' => 0, 'status' => '', 'language' => '', 'reason' => 'line_item_not_found', 'detail' => ''];
					}
					continue;
				}
				$line = $lines[$iid];
				$report['examined']++;
				$vid = (int) $line['variation_id'];
				if (!array_key_exists($vid, $schedules)) {
					$schedules[$vid] = self::variation_schedule($vid);
				}
				$res = self::evaluate_line($line, $schedules[$vid]);
				// "All course lines" mode lists only lines on course variations.
				foreach ($res['skipped'] as $s) {
					if (!empty($scope['all_course_lines']) && $s['reason'] === 'not_a_course_variation' && !in_array($iid, $scope['item_ids'], true)) {
						continue;
					}
					$report['skipped'][] = $s;
				}
				foreach ($res['changes'] as $c) {
					$report['rows'][] = $c;
				}
				if ($res['price'] !== null) {
					$report['prices'][] = $res['price'];
				}
			}
			unset($lines);
			if (class_exists('InterSoccer_Order_Season_Label_Repair')) {
				InterSoccer_Order_Season_Label_Repair::flush_runtime_caches();
			}
		}
		$report['lines'] = count(array_unique(array_column($report['rows'], 'item_id')));
		$report['order_ids'] = array_values(array_unique(array_map('intval', array_column($report['rows'], 'order_id'))));
		$report['orders'] = count($report['order_ids']);
		$report['fingerprint'] = self::fingerprint($report['rows']);

		if (!$apply || $report['rows'] === []) {
			return $report;
		}
		if ($expected_fingerprint === '' || !hash_equals($expected_fingerprint, $report['fingerprint'])) {
			return new WP_Error('preview_mismatch', __('The changes no longer match the last Preview. Run Preview again, check it, then Apply.', 'intersoccer-product-variations'));
		}

		$backup = self::write_backup($report['rows']);
		if (is_wp_error($backup)) {
			return $backup;
		}
		$report['backup_file'] = $backup;
		$touched = [];
		foreach ($report['rows'] as $change) {
			if (self::write_change($change['item_id'], $change['meta_key'], $change['op'], $change['new'])) {
				$report['updated']++;
				$touched[(int) $change['order_id']] = true;
			}
		}
		$report['order_ids'] = array_keys($touched);
		$report['rosters'] = self::resync_rosters($report['order_ids']);
		if ($report['order_ids'] !== [] && function_exists('do_action')) {
			do_action(self::ACTION_REPAIRED, $report['order_ids']);
		}
		return $report;
	}

	/**
	 * Write one value (update/add) or delete it.
	 *
	 * @param int    $item_id Item id.
	 * @param string $key Meta key (only the four schedule keys are allowed).
	 * @param string $op update|add|delete.
	 * @param string $value Value for update/add.
	 * @return bool
	 */
	public static function write_change($item_id, $key, $op, $value) {
		$item_id = (int) $item_id;
		if ($item_id <= 0 || !in_array($key, [self::KEY_START, self::KEY_END, self::KEY_HOLIDAYS, self::KEY_WEEKS], true)) {
			return false;
		}
		if ($op === 'delete') {
			return function_exists('wc_delete_order_item_meta') ? (bool) wc_delete_order_item_meta($item_id, $key) : false;
		}
		if (function_exists('wc_update_order_item_meta')) {
			wc_update_order_item_meta($item_id, $key, (string) $value);
			return true;
		}
		return false;
	}

	/**
	 * Current single value ('' when missing).
	 *
	 * @param int    $item_id Item id.
	 * @param string $key Key.
	 * @return string
	 */
	public static function read_value($item_id, $key) {
		if (function_exists('wc_get_order_item_meta')) {
			$v = wc_get_order_item_meta((int) $item_id, $key, true);
			return is_scalar($v) ? (string) $v : '';
		}
		return '';
	}

	/**
	 * Scoped roster re-sync for the touched orders (no deletes, no product siblings).
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array{status:string,order_ids:int[],message:string}
	 */
	public static function resync_rosters(array $order_ids) {
		$order_ids = array_values(array_filter(array_map('intval', $order_ids)));
		if ($order_ids === []) {
			return ['status' => 'none', 'order_ids' => [], 'message' => ''];
		}
		$available = function_exists('intersoccer_rr_load_reconcile')
			? intersoccer_rr_load_reconcile()
			: function_exists('intersoccer_reconcile_rosters');
		if (!$available || !function_exists('intersoccer_reconcile_rosters')) {
			return [
				'status'    => 'unavailable',
				'order_ids' => $order_ids,
				'message'   => 'ROSTERS NOT UPDATED: Reports & Rosters reconcile is not available here. Re-sync orders ' . implode(',', $order_ids) . ' from Reports & Rosters before relying on roster dates.',
			];
		}
		$result = intersoccer_reconcile_rosters([
			'order_ids'                => $order_ids,
			'include_product_siblings' => false,
			'delete_obsolete'          => false,
		]);
		$status = is_array($result) && ($result['status'] ?? '') === 'error' ? 'error' : 'synced';
		return [
			'status'    => $status,
			'order_ids' => $order_ids,
			'message'   => is_array($result) ? (string) ($result['message'] ?? '') : '',
			'synced'    => is_array($result) ? (int) ($result['synced'] ?? 0) : 0,
			'deleted'   => is_array($result) ? (int) ($result['deleted'] ?? 0) : 0,
		];
	}

	/**
	 * Backup directory (shared with the Season labels tool).
	 *
	 * @param bool $create Create the directory and deny-web files when missing.
	 * @return string|WP_Error
	 */
	public static function backup_dir($create = true) {
		if (class_exists('InterSoccer_Order_Season_Label_Repair')) {
			return InterSoccer_Order_Season_Label_Repair::backup_dir($create);
		}
		return new WP_Error('backup_dir', __('Backup directory unavailable.', 'intersoccer-product-variations'));
	}

	/**
	 * Streamed JSON backup of every change (old value, op) before Apply.
	 *
	 * @param array $changes Change rows.
	 * @return string|WP_Error Path.
	 */
	public static function write_backup(array $changes) {
		$dir = self::backup_dir();
		if (is_wp_error($dir)) {
			return $dir;
		}
		$path = rtrim((string) $dir, '/\\') . '/' . self::BACKUP_PREFIX . gmdate('Ymd-His') . '.json';
		$fh = @fopen($path, 'xb');
		if ($fh === false) {
			return new WP_Error('backup_write', __('Could not write backup file (try again in a second).', 'intersoccer-product-variations'));
		}
		$ok = fwrite($fh, '{"created_at":' . json_encode(gmdate('c')) . ',"tool":"order-course-date-repair","entries":[' . "\n") !== false;
		$first = true;
		foreach ($changes as $c) {
			$entry = json_encode([
				'item_id'  => (int) $c['item_id'],
				'order_id' => (int) $c['order_id'],
				'meta_key' => (string) $c['meta_key'],
				'op'       => (string) $c['op'],
				'old'      => (string) $c['old'],
				'new'      => (string) $c['new'],
			], JSON_UNESCAPED_UNICODE);
			if ($entry === false) {
				fclose($fh);
				@unlink($path);
				return new WP_Error('backup_encode', __('Could not encode backup JSON.', 'intersoccer-product-variations'));
			}
			$ok = $ok && fwrite($fh, ($first ? '' : ",\n") . $entry) !== false;
			$first = false;
		}
		$ok = $ok && fwrite($fh, "\n]}\n") !== false;
		$ok = fclose($fh) && $ok;
		if (!$ok) {
			@unlink($path);
			return new WP_Error('backup_write', __('Could not write backup file.', 'intersoccer-product-variations'));
		}
		return $path;
	}

	/**
	 * Backups for this tool, newest first.
	 *
	 * @return array<int,array{path:string,basename:string,mtime:int}>
	 */
	public static function list_backups() {
		$dir = self::backup_dir(false);
		if (is_wp_error($dir) || !is_dir($dir)) {
			return [];
		}
		$out = [];
		foreach (glob(rtrim((string) $dir, '/\\') . '/' . self::BACKUP_PREFIX . '*.json') ?: [] as $path) {
			$out[] = ['path' => $path, 'basename' => basename($path), 'mtime' => (int) filemtime($path)];
		}
		usort($out, static function ($a, $b) {
			return strcmp($b['basename'], $a['basename']);
		});
		return $out;
	}

	/**
	 * Restore from a backup. Each value is restored only while it still equals what
	 * Apply wrote (or, for a delete, while it is still absent); others are conflicts.
	 * Then the restored orders' roster rows are re-synced.
	 *
	 * @param string $basename Backup file name (inside the backup directory).
	 * @return array|WP_Error
	 */
	public static function rollback($basename) {
		$basename = basename((string) $basename);
		if (strpos($basename, self::BACKUP_PREFIX) !== 0) {
			return new WP_Error('rollback_name', __('Not a course date repair backup.', 'intersoccer-product-variations'));
		}
		$dir = self::backup_dir();
		if (is_wp_error($dir)) {
			return $dir;
		}
		$path = rtrim((string) $dir, '/\\') . '/' . $basename;
		if (!is_readable($path)) {
			return new WP_Error('rollback_missing', __('Backup file not found.', 'intersoccer-product-variations'));
		}
		$data = json_decode((string) file_get_contents($path), true);
		if (!is_array($data) || ($data['tool'] ?? '') !== 'order-course-date-repair' || !is_array($data['entries'] ?? null)) {
			return new WP_Error('rollback_invalid', __('Backup file is invalid.', 'intersoccer-product-variations'));
		}
		$result = ['restored' => 0, 'conflicts' => [], 'order_ids' => [], 'rosters' => null];
		$orders = [];
		foreach ($data['entries'] as $e) {
			$item_id = (int) ($e['item_id'] ?? 0);
			$key = (string) ($e['meta_key'] ?? '');
			$op = (string) ($e['op'] ?? 'update');
			$old = (string) ($e['old'] ?? '');
			$new = (string) ($e['new'] ?? '');
			$current = self::read_value($item_id, $key);
			$expected_now = $op === 'delete' ? '' : $new;
			if ($current !== $expected_now) {
				$result['conflicts'][] = ['item_id' => $item_id, 'meta_key' => $key, 'expected_now' => $expected_now, 'current' => $current, 'would_restore' => $old];
				continue;
			}
			if ($op === 'add') {
				$ok = self::write_change($item_id, $key, 'delete', '');
			} else {
				$ok = self::write_change($item_id, $key, 'update', $old);
			}
			if ($ok) {
				$result['restored']++;
				$orders[(int) ($e['order_id'] ?? 0)] = true;
			}
		}
		unset($orders[0]);
		$result['order_ids'] = array_keys($orders);
		$result['rosters'] = self::resync_rosters($result['order_ids']);
		return $result;
	}
}

/**
 * Admin tab slug.
 *
 * @return string
 */
function intersoccer_course_date_repair_tab_slug() {
	return 'course-dates';
}

/**
 * Store / read the Preview for the Apply gate (per user, 1 hour).
 *
 * @param array|null $report Report to store; null to read.
 * @return array|null
 */
function intersoccer_course_date_repair_preview($report = null) {
	$key = InterSoccer_Order_Course_Date_Repair::PREVIEW_TRANSIENT . '_' . (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
	if ($report !== null) {
		$payload = ['at' => time(), 'report' => $report];
		$ttl = defined('INTERSOCCER_PV_REPAIR_PREVIEW_TTL') ? (int) INTERSOCCER_PV_REPAIR_PREVIEW_TTL : 1800;
		if (function_exists('set_transient')) {
			set_transient($key, $payload, $ttl);
		} else {
			$GLOBALS[$key] = $payload;
		}
		return $payload;
	}
	$data = function_exists('get_transient') ? get_transient($key) : ($GLOBALS[$key] ?? null);
	if (!is_array($data)) {
		return null;
	}
	$fresh = function_exists('intersoccer_pv_repair_preview_is_fresh')
		? intersoccer_pv_repair_preview_is_fresh($data['at'] ?? 0)
		: ((int) ($data['at'] ?? 0) > 0 && (time() - (int) $data['at']) < 1800);
	return $fresh ? $data : null;
}

/**
 * Render the Course dates tab (English admin copy).
 *
 * @param bool $embedded Inside the Order Meta Repair page.
 */
function intersoccer_render_course_date_repair_page($embedded = true) {
	if (!function_exists('intersoccer_pv_user_can_run_repairs') || !intersoccer_pv_user_can_run_repairs()) {
		wp_die(esc_html__('You do not have permission to run repairs.', 'intersoccer-product-variations'));
	}
	$notice = '';
	$report = null;
	$rollback = null;
	$form_items = '';
	$form_vars = '';
	$form_all = false;

	if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['intersoccer_course_date_repair_action'])) {
		check_admin_referer('intersoccer_course_date_repair');
		$action = sanitize_key(wp_unslash((string) $_POST['intersoccer_course_date_repair_action']));
		$form_items = sanitize_textarea_field(wp_unslash((string) ($_POST['item_ids'] ?? '')));
		$form_vars = sanitize_textarea_field(wp_unslash((string) ($_POST['variation_ids'] ?? '')));
		$form_all = !empty($_POST['all_course_lines']);

		if ($action === 'preview') {
			$report = InterSoccer_Order_Course_Date_Repair::run_dry([
				'item_ids' => $form_items, 'variation_ids' => $form_vars, 'all_course_lines' => $form_all,
			]);
			if (is_wp_error($report)) {
				$notice = $report->get_error_message();
				$report = null;
			} else {
				intersoccer_course_date_repair_preview($report);
				if (function_exists('intersoccer_pv_repair_log')) {
					intersoccer_pv_repair_log([
						'user_id' => (int) get_current_user_id(),
						'tool' => 'course_dates',
						'mode' => 'preview',
						'scope' => $form_all ? 'all_course_lines' : 'scoped',
						'counts' => ['changed' => count((array) ($report['rows'] ?? []))],
						'backup' => '',
					]);
				}
				$notice = __('Preview (dry run) completed. Nothing was written.', 'intersoccer-product-variations');
			}
		} elseif ($action === 'apply') {
			$stored = intersoccer_course_date_repair_preview();
			$phrase = function_exists('intersoccer_pv_repair_posted_phrase')
				? intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_phrase')
				: '';
			$global = function_exists('intersoccer_pv_repair_posted_phrase')
				? intersoccer_pv_repair_posted_phrase('intersoccer_pv_repair_global')
				: '';
			$shop_wide = is_array($stored) && !empty($stored['report']['scope']['all_course_lines']);
			$change_count = (is_array($stored) && is_array($stored['report']['rows'] ?? null)) ? count($stored['report']['rows']) : 0;
			if (trim($phrase) !== 'APPLY') {
				$notice = __('Type APPLY to confirm.', 'intersoccer-product-variations');
			} elseif ($shop_wide && trim($global) !== 'GLOBAL') {
				$notice = __('This scope is shop-wide. Type GLOBAL as well as APPLY.', 'intersoccer-product-variations');
			} elseif (!$stored || empty($stored['report']) || ($stored['report']['mode'] ?? '') !== 'dry_run') {
				$notice = __('Run Preview before Apply.', 'intersoccer-product-variations');
			} elseif ($change_count === 0) {
				$notice = __('Preview has no changes. Apply was not run.', 'intersoccer-product-variations');
			} else {
				$prev = $stored['report'];
				$report = InterSoccer_Order_Course_Date_Repair::run_apply((array) $prev['scope'], (string) $prev['fingerprint']);
				if (is_wp_error($report)) {
					$notice = $report->get_error_message();
					$report = null;
				} else {
					$notice = sprintf(
						/* translators: 1: values written, 2: backup file */
						__('Updated %1$d value(s). Backup: %2$s', 'intersoccer-product-variations'),
						(int) $report['updated'],
						$report['backup_file'] ? basename((string) $report['backup_file']) : '—'
					);
					intersoccer_course_date_repair_preview($report);
					if (function_exists('intersoccer_pv_repair_log')) {
						intersoccer_pv_repair_log([
							'user_id' => (int) get_current_user_id(),
							'tool' => 'course_dates',
							'mode' => 'apply',
							'scope' => $shop_wide ? 'all_course_lines' : 'scoped',
							'counts' => ['changed' => (int) ($report['updated'] ?? 0)],
							'backup' => !empty($report['backup_file']) ? basename((string) $report['backup_file']) : '',
						]);
					}
				}
			}
		} elseif ($action === 'rollback') {
			$file = sanitize_file_name(wp_unslash((string) ($_POST['backup_file'] ?? '')));
			$rollback = InterSoccer_Order_Course_Date_Repair::rollback($file);
			if (is_wp_error($rollback)) {
				$notice = $rollback->get_error_message();
				$rollback = null;
			} else {
				$notice = sprintf(
					/* translators: 1: restored, 2: conflicts */
					__('Rollback restored %1$d value(s); %2$d conflict(s).', 'intersoccer-product-variations'),
					(int) $rollback['restored'],
					count($rollback['conflicts'])
				);
			}
		}
	}
	if ($report === null) {
		$stored = intersoccer_course_date_repair_preview();
		if ($stored && is_array($stored['report'] ?? null)) {
			$report = $stored['report'];
		}
	}
	if (is_array($report) && $form_items === '' && $form_vars === '' && !$form_all) {
		$form_items = implode(', ', (array) ($report['scope']['item_ids'] ?? []));
		$form_vars = implode(', ', (array) ($report['scope']['variation_ids'] ?? []));
		$form_all = !empty($report['scope']['all_course_lines']);
	}
	$backups = InterSoccer_Order_Course_Date_Repair::list_backups();
	$money = static function ($v) {
		return $v === null ? '—' : number_format((float) $v, 2, '.', "'");
	};
	?>
	<h2><?php esc_html_e('Course date repair', 'intersoccer-product-variations'); ?></h2>
	<p><?php esc_html_e('Bring course order lines in line with their current variation: Start Date, End Date, Holidays and the "Weeks Remaining" note. Only the lines you scope are examined. Prices and totals are never changed; price differences are listed for information. Apply writes a JSON backup first, then re-syncs the roster rows of the changed orders only (nothing is deleted).', 'intersoccer-product-variations'); ?></p>
	<?php if ($notice !== '') : ?>
		<div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div>
	<?php endif; ?>
	<?php if (is_array($report) && is_array($report['rosters'] ?? null) && ($report['rosters']['status'] ?? '') === 'unavailable') : ?>
		<div class="notice notice-error"><p><?php echo esc_html((string) $report['rosters']['message']); ?></p></div>
	<?php endif; ?>

	<form method="post" style="margin-bottom:1.5em;">
		<?php wp_nonce_field('intersoccer_course_date_repair'); ?>
		<p><label><strong><?php esc_html_e('Order item IDs', 'intersoccer-product-variations'); ?></strong><br />
			<textarea name="item_ids" rows="2" cols="80"><?php echo esc_textarea($form_items); ?></textarea></label></p>
		<p><label><strong><?php esc_html_e('Variation IDs (every line on these variations is checked)', 'intersoccer-product-variations'); ?></strong><br />
			<textarea name="variation_ids" rows="1" cols="80"><?php echo esc_textarea($form_vars); ?></textarea></label></p>
		<p><label><input type="checkbox" name="all_course_lines" value="1" <?php checked($form_all); ?> />
			<?php esc_html_e('All course lines (detect mismatches across the shop; review carefully before Apply)', 'intersoccer-product-variations'); ?></label></p>
		<button type="submit" class="button button-secondary" name="intersoccer_course_date_repair_action" value="preview"><?php esc_html_e('Preview (dry run)', 'intersoccer-product-variations'); ?></button>
	</form>

	<form method="post" style="margin-bottom:2em;">
		<?php wp_nonce_field('intersoccer_course_date_repair'); ?>
		<?php
		if (function_exists('intersoccer_pv_repair_phrase_fields')) {
			intersoccer_pv_repair_phrase_fields(true);
		}
		?>
		<p class="description"><?php esc_html_e('Type APPLY. Type GLOBAL as well when the Preview used all course lines.', 'intersoccer-product-variations'); ?></p>
		<p><button type="submit" class="button button-primary" name="intersoccer_course_date_repair_action" value="apply"><?php esc_html_e('Apply', 'intersoccer-product-variations'); ?></button></p>
	</form>

	<?php if (is_array($report)) : ?>
		<h3><?php esc_html_e('Summary', 'intersoccer-product-variations'); ?></h3>
		<p><?php echo esc_html(sprintf(
			/* translators: 1 mode 2 examined 3 lines 4 orders 5 values 6 skipped */
			__('Mode: %1$s | Lines examined: %2$d | Lines to change: %3$d | Orders: %4$d | Values: %5$d | Skipped: %6$d', 'intersoccer-product-variations'),
			(string) $report['mode'], (int) $report['examined'], (int) $report['lines'], (int) $report['orders'], count($report['rows']), count($report['skipped'])
		)); ?></p>
		<?php if (is_array($report['rosters'] ?? null) && $report['rosters']['status'] !== 'none') : ?>
			<p><?php echo esc_html(sprintf(
				/* translators: 1 status 2 order count 3 message */
				__('Rosters: %1$s for %2$d order(s). %3$s', 'intersoccer-product-variations'),
				(string) $report['rosters']['status'], count((array) $report['rosters']['order_ids']), (string) $report['rosters']['message']
			)); ?></p>
		<?php endif; ?>

		<h3><?php esc_html_e('Changes (old → new)', 'intersoccer-product-variations'); ?></h3>
		<table class="widefat striped" style="max-width:1200px;margin-bottom:1.5em;">
			<thead><tr>
				<th><?php esc_html_e('Order', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('Item', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('Variation', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('Field', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('Old', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('New', 'intersoccer-product-variations'); ?></th>
				<th><?php esc_html_e('Op', 'intersoccer-product-variations'); ?></th>
			</tr></thead>
			<tbody>
			<?php if (empty($report['rows'])) : ?>
				<tr><td colspan="7"><?php esc_html_e('No lines need a change.', 'intersoccer-product-variations'); ?></td></tr>
			<?php else : foreach ($report['rows'] as $r) : ?>
				<tr>
					<td><?php echo esc_html((string) (int) $r['order_id']); ?></td>
					<td><?php echo esc_html((string) (int) $r['item_id']); ?></td>
					<td><?php echo esc_html((string) (int) $r['variation_id']); ?></td>
					<td><?php echo esc_html((string) $r['meta_key']); ?></td>
					<td><?php echo esc_html($r['old'] === '' ? '(none)' : (string) $r['old']); ?></td>
					<td><?php echo esc_html($r['new'] === '' ? '(removed)' : (string) $r['new']); ?></td>
					<td><?php echo esc_html((string) $r['op']); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<?php if (!empty($report['prices'])) : ?>
			<h3><?php esc_html_e('Price check (information only, nothing is changed)', 'intersoccer-product-variations'); ?></h3>
			<table class="widefat striped" style="max-width:1000px;margin-bottom:1.5em;">
				<thead><tr>
					<th><?php esc_html_e('Order', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Item', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Variation', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Paid list price (subtotal)', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Paid after discounts', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Current variation price', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Difference (current − paid list)', 'intersoccer-product-variations'); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($report['prices'] as $p) : ?>
					<tr>
						<td><?php echo esc_html((string) (int) $p['order_id']); ?></td>
						<td><?php echo esc_html((string) (int) $p['item_id']); ?></td>
						<td><?php echo esc_html((string) (int) $p['variation_id']); ?></td>
						<td><?php echo esc_html($money($p['paid_subtotal'])); ?></td>
						<td><?php echo esc_html($money($p['paid_total'])); ?></td>
						<td><?php echo esc_html($money($p['current_price'])); ?></td>
						<td><?php echo esc_html($money($p['difference'])); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if (!empty($report['skipped'])) : ?>
			<h3><?php esc_html_e('Skipped', 'intersoccer-product-variations'); ?></h3>
			<table class="widefat striped" style="max-width:1000px;margin-bottom:1.5em;">
				<thead><tr>
					<th><?php esc_html_e('Order', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Item', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Reason', 'intersoccer-product-variations'); ?></th>
					<th><?php esc_html_e('Detail', 'intersoccer-product-variations'); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ($report['skipped'] as $s) : ?>
					<tr>
						<td><?php echo esc_html((string) (int) $s['order_id']); ?></td>
						<td><?php echo esc_html((string) (int) $s['item_id']); ?></td>
						<td><?php echo esc_html((string) $s['reason']); ?></td>
						<td><?php echo esc_html((string) $s['detail']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h3><?php esc_html_e('Backups', 'intersoccer-product-variations'); ?></h3>
	<?php if (empty($backups)) : ?>
		<p><?php esc_html_e('No course date backups yet.', 'intersoccer-product-variations'); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:720px;">
			<thead><tr><th><?php esc_html_e('File', 'intersoccer-product-variations'); ?></th><th><?php esc_html_e('Actions', 'intersoccer-product-variations'); ?></th></tr></thead>
			<tbody>
			<?php foreach ($backups as $b) : ?>
				<tr>
					<td><code><?php echo esc_html($b['basename']); ?></code></td>
					<td><form method="post" style="display:inline;">
						<?php wp_nonce_field('intersoccer_course_date_repair'); ?>
						<input type="hidden" name="backup_file" value="<?php echo esc_attr($b['basename']); ?>" />
						<button type="submit" class="button" name="intersoccer_course_date_repair_action" value="rollback"><?php esc_html_e('Rollback', 'intersoccer-product-variations'); ?></button>
					</form></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php if (is_array($rollback) && !empty($rollback['conflicts'])) : ?>
		<h3><?php esc_html_e('Rollback conflicts (left as they are)', 'intersoccer-product-variations'); ?></h3>
		<pre><?php echo esc_html((string) json_encode($rollback['conflicts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
	<?php endif; ?>
	<?php
}
