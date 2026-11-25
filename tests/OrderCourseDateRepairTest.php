<?php
/**
 * Order-line course date repair (Order Meta Repair → Course dates).
 * Synthetic data only.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OrderSeasonLabelRepairTest.php'; // shared WP stubs + season repair (backup dir)

if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('wc_delete_order_item_meta')) {
	function wc_delete_order_item_meta($item_id, $key, $value = '', $delete_all = false) {
		$item_id = (int) $item_id;
		if (!isset($GLOBALS['intersoccer_test_order_item_meta'][$item_id][$key])) {
			return false;
		}
		unset($GLOBALS['intersoccer_test_order_item_meta'][$item_id][$key]);
		return true;
	}
}

if (!function_exists('intersoccer_reconcile_rosters')) {
	function intersoccer_reconcile_rosters($options = []) {
		$GLOBALS['intersoccer_test_course_date_reconcile_calls'][] = $options;
		return ['status' => 'success', 'synced' => count((array) ($options['order_ids'] ?? [])), 'deleted' => 0, 'message' => 'ok'];
	}
}

require_once dirname(__DIR__) . '/includes/woocommerce/order-course-date-repair.php';

/**
 * Minimal $wpdb for the queries the course date repair issues.
 */
class CourseDateRepairFakeWpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	/** @var array<int,array{order_id:int,type:string}> */
	public $items = [];
	/** @var array<int,array{status:string,type:string,created:string,lang:string}> */
	public $orders = [];
	private $pending = [];

	public function prepare($query, ...$args) {
		$token = 'Q' . count($this->pending) . '|' . $query;
		$this->pending[$token] = $args;
		return $token;
	}

	private function take($token) {
		$args = $this->pending[$token] ?? [];
		unset($this->pending[$token]);
		return $args;
	}

	public function get_col($token) {
		$args = $this->take($token);
		$meta = $GLOBALS['intersoccer_test_order_item_meta'];
		$out = [];
		if (strpos($token, "meta_key = '_variation_id'") !== false) {
			foreach ($meta as $iid => $m) {
				if (isset($m['_variation_id']) && in_array((string) $m['_variation_id'], $args, true)) {
					$out[] = (string) $iid;
				}
			}
		} elseif (strpos($token, "meta_key = 'Start Date'") !== false) {
			foreach ($meta as $iid => $m) {
				if (($m['Start Date'] ?? '') !== '') {
					$out[] = (string) $iid;
				}
			}
		}
		return $out;
	}

	public function get_results($token, $output = null) {
		$args = $this->take($token);
		$out = [];
		if (strpos($token, 'woocommerce_order_items WHERE') !== false) {
			foreach ($args as $iid) {
				if (isset($this->items[(int) $iid]) && $this->items[(int) $iid]['type'] === 'line_item') {
					$out[] = ['order_item_id' => (string) $iid, 'order_id' => (string) $this->items[(int) $iid]['order_id']];
				}
			}
		} elseif (strpos($token, 'meta_key, meta_value FROM') !== false) {
			$ids = [];
			$keys = [];
			foreach ($args as $a) {
				if (is_int($a)) {
					$ids[] = $a;
				} else {
					$keys[] = $a;
				}
			}
			foreach ($ids as $iid) {
				foreach ((array) ($GLOBALS['intersoccer_test_order_item_meta'][$iid] ?? []) as $k => $v) {
					if (in_array($k, $keys, true)) {
						$out[] = ['order_item_id' => (string) $iid, 'meta_key' => $k, 'meta_value' => (string) $v];
					}
				}
			}
		} elseif (strpos($token, 'post_status AS status') !== false) {
			foreach ($args as $oid) {
				if (isset($this->orders[(int) $oid])) {
					$o = $this->orders[(int) $oid];
					$out[] = ['oid' => (string) $oid, 'status' => 'wc-' . $o['status'], 'type' => $o['type'], 'created' => $o['created']];
				}
			}
		} elseif (strpos($token, "'wpml_language'") !== false) {
			foreach ($args as $oid) {
				if (isset($this->orders[(int) $oid])) {
					$out[] = ['oid' => (string) $oid, 'meta_value' => $this->orders[(int) $oid]['lang']];
				}
			}
		}
		return $out;
	}
}

class OrderCourseDateRepairTest extends TestCase {

	/** @var string */
	private $backup_dir;
	private $prev_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->backup_dir = sys_get_temp_dir() . '/pv-course-date-' . uniqid('', true);
		mkdir($this->backup_dir, 0755, true);
		$GLOBALS['intersoccer_test_season_label_backup_dir'] = $this->backup_dir;
		$GLOBALS['intersoccer_test_season_label_hpos'] = false;
		$GLOBALS['intersoccer_test_order_items'] = [];
		$GLOBALS['intersoccer_test_order_item_meta'] = [];
		$GLOBALS['intersoccer_test_do_action'] = [];
		$GLOBALS['intersoccer_test_course_date_reconcile_calls'] = [];
		$this->prev_wpdb = $GLOBALS['wpdb'] ?? null;

		MockMetaData::$data = [];
		// Saturday course (corrected): 7 weeks, two holidays.
		$this->variation(9001, '2027-01-02', '2027-02-27', ['2027-02-06', '2027-02-13'], 7, 'saturday', 210);
		// Monday course (corrected): 6 weeks.
		$this->variation(9002, '2027-01-04', '2027-02-22', ['2027-02-08', '2027-02-15'], 6, 'monday', 165);
		// Sunday spring course (corrected): 16 weeks.
		$this->variation(9003, '2027-03-07', '2027-07-04', ['2027-03-28', '2027-04-04'], 16, 'sunday', 480);
		// A camp variation (no course dates).
		MockMetaData::$data[9004] = ['_price' => '300'];

		$db = new CourseDateRepairFakeWpdb();
		$GLOBALS['wpdb'] = $db;
		// Lines booked before the office correction (swapped / wrong dates).
		$this->line($db, 701, 801, 9001, 'saturday', 'January 4, 2027', 'February 13, 2027', '', '6 Weeks Remaining', 165, 165);
		$this->line($db, 702, 802, 9002, 'monday', 'January 2, 2027', 'February 15, 2027', '', '7 Weeks Remaining', 210, 157.5);
		$this->line($db, 703, 803, 9003, 'sunday', 'January 6, 2027', 'April 25, 2027', 'February 10, 2027, February 17, 2027', '16 Weeks Remaining', 480, 360);
		// Already correct.
		$this->line($db, 704, 804, 9001, 'saturday', 'January 2, 2027', 'February 27, 2027', 'February 6, 2027, February 13, 2027', '7 Weeks Remaining', 210, 210);
		// Cancelled order.
		$this->line($db, 705, 805, 9001, 'saturday', 'January 4, 2027', 'February 13, 2027', '', '6 Weeks Remaining', 165, 165, 'cancelled');
		// Line day differs from its variation.
		$this->line($db, 706, 806, 9002, 'tuesday', 'January 5, 2027', 'February 16, 2027', '', '6 Weeks Remaining', 165, 165);
		// Camp line.
		$this->line($db, 707, 807, 9004, '', '', '', '', '', 300, 300);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->prev_wpdb;
		unset($GLOBALS['intersoccer_test_season_label_hpos']);
		MockMetaData::$data = [];
		foreach (glob($this->backup_dir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		foreach (glob($this->backup_dir . '/.*') ?: [] as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
		@rmdir($this->backup_dir);
		parent::tearDown();
	}

	private function variation($id, $start, $end, array $holidays, $weeks, $day, $price) {
		MockMetaData::$data[$id] = [
			'_course_start_date' => $start,
			'_end_date' => $end,
			'_course_holiday_dates' => $holidays,
			'_course_total_weeks' => (string) $weeks,
			'attribute_pa_course-day' => $day,
			'_price' => (string) $price,
		];
	}

	private function line($db, $item_id, $order_id, $vid, $day, $start, $end, $holidays, $weeks, $subtotal, $total, $status = 'completed') {
		$db->items[$item_id] = ['order_id' => $order_id, 'type' => 'line_item'];
		$db->orders[$order_id] = ['status' => $status, 'type' => 'shop_order', 'created' => '2026-11-10 09:00:00', 'lang' => 'en'];
		$meta = ['_product_id' => '9000', '_variation_id' => (string) $vid, '_qty' => '1',
			'_line_subtotal' => (string) $subtotal, '_line_total' => (string) $total];
		foreach (['pa_course-day' => $day, 'Start Date' => $start, 'End Date' => $end, 'Holidays' => $holidays, 'Booking details' => $weeks] as $k => $v) {
			if ($v !== '') {
				$meta[$k] = $v;
			}
		}
		$GLOBALS['intersoccer_test_order_item_meta'][$item_id] = $meta;
	}

	private function rowsByItem(array $report) {
		$out = [];
		foreach ($report['rows'] as $r) {
			$out[$r['item_id']][$r['meta_key']] = [$r['old'], $r['new'], $r['op']];
		}
		return $out;
	}

	public function test_weekday_number_handles_en_fr_de() {
		$this->assertSame(6, InterSoccer_Order_Course_Date_Repair::weekday_number('saturday'));
		$this->assertSame(7, InterSoccer_Order_Course_Date_Repair::weekday_number('Dimanche'));
		$this->assertSame(3, InterSoccer_Order_Course_Date_Repair::weekday_number('Mittwoch'));
		$this->assertSame(0, InterSoccer_Order_Course_Date_Repair::weekday_number(''));
	}

	public function test_weeks_remaining_full_term_before_start_and_counts_after() {
		$s = ['start' => '2027-01-02', 'end' => '2027-02-27', 'holidays' => ['2027-02-06', '2027-02-13'], 'total_weeks' => 7, 'weekday' => 6];
		$this->assertSame(7, InterSoccer_Order_Course_Date_Repair::weeks_remaining_at($s, '2026-11-10'));
		// Booked on Feb 1: Feb 20 and 27 left (6th and 13th are holidays).
		$this->assertSame(2, InterSoccer_Order_Course_Date_Repair::weeks_remaining_at($s, '2027-02-01'));
		$this->assertSame(0, InterSoccer_Order_Course_Date_Repair::weeks_remaining_at($s, '2027-03-01'));
	}

	public function test_parse_holidays_and_dates() {
		$this->assertSame(['2027-02-10', '2027-02-17'], InterSoccer_Order_Course_Date_Repair::parse_holidays_value('February 10, 2027, February 17, 2027'));
		$this->assertSame('2027-01-04', InterSoccer_Order_Course_Date_Repair::to_ymd('04/01/27'));
		$this->assertSame('2027-01-04', InterSoccer_Order_Course_Date_Repair::to_ymd('January 4, 2027'));
	}

	public function test_empty_scope_is_refused() {
		$r = InterSoccer_Order_Course_Date_Repair::run_dry([]);
		$this->assertInstanceOf(WP_Error::class, $r);
	}

	public function test_preview_by_item_ids_lists_old_to_new_and_writes_nothing() {
		$r = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => '701, 702 703 704 705 706 707 999']);
		$this->assertSame('dry_run', $r['mode']);
		$rows = $this->rowsByItem($r);
		$this->assertSame(['January 4, 2027', 'January 2, 2027', 'update'], $rows[701]['Start Date']);
		$this->assertSame(['February 13, 2027', 'February 27, 2027', 'update'], $rows[701]['End Date']);
		$this->assertSame(['', 'February 6, 2027, February 13, 2027', 'add'], $rows[701]['Holidays']);
		$this->assertSame(['6 Weeks Remaining', '7 Weeks Remaining', 'update'], $rows[701]['Booking details']);
		$this->assertSame(['January 2, 2027', 'January 4, 2027', 'update'], $rows[702]['Start Date']);
		$this->assertSame(['7 Weeks Remaining', '6 Weeks Remaining', 'update'], $rows[702]['Booking details']);
		$this->assertSame(['January 6, 2027', 'March 7, 2027', 'update'], $rows[703]['Start Date']);
		$this->assertSame(['April 25, 2027', 'July 4, 2027', 'update'], $rows[703]['End Date']);
		$this->assertSame(['February 10, 2027, February 17, 2027', 'March 28, 2027, April 4, 2027', 'update'], $rows[703]['Holidays']);
		$this->assertArrayNotHasKey('Booking details', $rows[703], '16 weeks is unchanged');
		$this->assertArrayNotHasKey(704, $rows, 'correct line untouched');
		$this->assertSame(3, $r['lines']);
		$this->assertSame(3, $r['orders']);

		$reasons = [];
		foreach ($r['skipped'] as $s) {
			$reasons[$s['item_id']] = $s['reason'];
		}
		$this->assertSame('order_status_not_active', $reasons[705]);
		$this->assertSame('course_day_differs', $reasons[706]);
		$this->assertSame('not_a_course_variation', $reasons[707]);
		$this->assertSame('line_item_not_found', $reasons[999]);

		// Nothing written.
		$this->assertSame('January 4, 2027', $GLOBALS['intersoccer_test_order_item_meta'][701]['Start Date']);
		$this->assertSame([], glob($this->backup_dir . '/course-date-repair-*.json'));
		$this->assertSame([], $GLOBALS['intersoccer_test_course_date_reconcile_calls']);
	}

	public function test_price_check_reports_differences_without_writing_prices() {
		$r = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [701, 702, 703]]);
		$by = [];
		foreach ($r['prices'] as $p) {
			$by[$p['item_id']] = $p;
		}
		$this->assertSame(45.0, $by[701]['difference']);
		$this->assertSame(-45.0, $by[702]['difference']);
		$this->assertSame(157.5, $by[702]['paid_total']);
		$this->assertSame(0.0, $by[703]['difference']);
		foreach ($r['rows'] as $row) {
			$this->assertContains($row['meta_key'], ['Start Date', 'End Date', 'Holidays', 'Booking details']);
		}
	}

	public function test_variation_scope_detects_only_mismatched_lines() {
		$r = InterSoccer_Order_Course_Date_Repair::run_dry(['variation_ids' => '9001']);
		$items = array_values(array_unique(array_column($r['rows'], 'item_id')));
		$this->assertSame([701], $items);
	}

	public function test_all_course_lines_mode_hides_non_course_lines() {
		$r = InterSoccer_Order_Course_Date_Repair::run_dry(['all_course_lines' => true]);
		$items = array_values(array_unique(array_column($r['rows'], 'item_id')));
		sort($items);
		$this->assertSame([701, 702, 703], $items);
		foreach ($r['skipped'] as $s) {
			$this->assertNotSame(707, $s['item_id']);
		}
	}

	public function test_apply_requires_matching_preview_fingerprint() {
		$preview = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [701]]);
		$bad = InterSoccer_Order_Course_Date_Repair::run_apply($preview['scope'], 'not-the-preview');
		$this->assertInstanceOf(WP_Error::class, $bad);
		$this->assertSame('January 4, 2027', $GLOBALS['intersoccer_test_order_item_meta'][701]['Start Date']);

		// Data changed after the Preview → refused.
		$GLOBALS['intersoccer_test_order_item_meta'][701]['Start Date'] = 'January 3, 2027';
		$stale = InterSoccer_Order_Course_Date_Repair::run_apply($preview['scope'], $preview['fingerprint']);
		$this->assertInstanceOf(WP_Error::class, $stale);
	}

	public function test_apply_backup_resync_and_rollback_round_trip() {
		$before = $GLOBALS['intersoccer_test_order_item_meta'];
		$preview = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [701, 702, 703, 704]]);
		$r = InterSoccer_Order_Course_Date_Repair::run_apply($preview['scope'], $preview['fingerprint']);
		$this->assertIsArray($r);
		$this->assertSame(count($preview['rows']), $r['updated']);
		$this->assertFileExists($r['backup_file']);
		$m = $GLOBALS['intersoccer_test_order_item_meta'];
		$this->assertSame('January 2, 2027', $m[701]['Start Date']);
		$this->assertSame('February 6, 2027, February 13, 2027', $m[701]['Holidays']);
		$this->assertSame('7 Weeks Remaining', $m[701]['Booking details']);
		$this->assertSame('March 7, 2027', $m[703]['Start Date']);
		// Money untouched.
		$this->assertSame('165', $m[701]['_line_subtotal']);
		$this->assertSame('157.5', $m[702]['_line_total']);
		$this->assertSame($before[704], $m[704]);

		// Scoped roster re-sync: only the changed orders, no siblings, no deletes.
		$calls = $GLOBALS['intersoccer_test_course_date_reconcile_calls'];
		$this->assertCount(1, $calls);
		$ids = $calls[0]['order_ids'];
		sort($ids);
		$this->assertSame([801, 802, 803], $ids);
		$this->assertFalse($calls[0]['include_product_siblings']);
		$this->assertFalse($calls[0]['delete_obsolete']);
		$this->assertSame('synced', $r['rosters']['status']);

		// Second preview is clean.
		$again = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [701, 702, 703, 704]]);
		$this->assertSame([], $again['rows']);

		// Rollback restores exactly (added Holidays removed again).
		$rb = InterSoccer_Order_Course_Date_Repair::rollback(basename($r['backup_file']));
		$this->assertIsArray($rb);
		$this->assertSame([], $rb['conflicts']);
		$this->assertSame(count($preview['rows']), $rb['restored']);
		$this->assertSame($before, $GLOBALS['intersoccer_test_order_item_meta']);
		$this->assertCount(2, $GLOBALS['intersoccer_test_course_date_reconcile_calls']);
	}

	public function test_rollback_leaves_values_edited_after_apply() {
		$preview = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [701]]);
		$r = InterSoccer_Order_Course_Date_Repair::run_apply($preview['scope'], $preview['fingerprint']);
		$GLOBALS['intersoccer_test_order_item_meta'][701]['Start Date'] = 'January 9, 2027';
		$rb = InterSoccer_Order_Course_Date_Repair::rollback(basename($r['backup_file']));
		$this->assertCount(1, $rb['conflicts']);
		$this->assertSame('January 9, 2027', $GLOBALS['intersoccer_test_order_item_meta'][701]['Start Date']);
		$this->assertSame('February 13, 2027', $GLOBALS['intersoccer_test_order_item_meta'][701]['End Date']);
	}

	public function test_rollback_rejects_other_files() {
		$this->assertInstanceOf(WP_Error::class, InterSoccer_Order_Course_Date_Repair::rollback('season-label-repair-20270101-000000.json'));
		$this->assertInstanceOf(WP_Error::class, InterSoccer_Order_Course_Date_Repair::rollback('../course-date-repair-x.json'));
	}

	public function test_holidays_removed_when_variation_has_none() {
		MockMetaData::$data[9001]['_course_holiday_dates'] = [];
		MockMetaData::$data[9001]['_end_date'] = '2027-02-13';
		MockMetaData::$data[9001]['_course_total_weeks'] = '7';
		$preview = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [704]]);
		$rows = $this->rowsByItem($preview);
		$this->assertSame(['February 6, 2027, February 13, 2027', '', 'delete'], $rows[704]['Holidays']);
		$r = InterSoccer_Order_Course_Date_Repair::run_apply($preview['scope'], $preview['fingerprint']);
		$this->assertArrayNotHasKey('Holidays', $GLOBALS['intersoccer_test_order_item_meta'][704]);
		InterSoccer_Order_Course_Date_Repair::rollback(basename($r['backup_file']));
		$this->assertSame('February 6, 2027, February 13, 2027', $GLOBALS['intersoccer_test_order_item_meta'][704]['Holidays']);
	}

	public function test_variation_start_not_on_course_day_is_skipped() {
		MockMetaData::$data[9002]['_course_start_date'] = '2027-01-05'; // Tuesday on a Monday course
		$r = InterSoccer_Order_Course_Date_Repair::run_dry(['item_ids' => [702]]);
		$this->assertSame([], $r['rows']);
		$this->assertSame('variation_start_not_on_course_day', $r['skipped'][0]['reason']);
	}

	public function test_order_meta_repair_page_exposes_course_dates_tab() {
		$src = file_get_contents(dirname(__DIR__) . '/includes/woocommerce/advanced-tools-admin.php');
		$this->assertStringContainsString("'course-dates'", $src);
		$this->assertStringContainsString('intersoccer_render_course_date_repair_page', $src);
		$main = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
		$this->assertStringContainsString('includes/woocommerce/order-course-date-repair.php', $main);
	}
}
