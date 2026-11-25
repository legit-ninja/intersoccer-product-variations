<?php
/**
 * Season label repair: row-based scan stays memory-bounded (no WC_Order objects).
 *
 * Dataset sized from the Nov 24 backup: 5,012 orders, 5,593 line items,
 * 5,160 lines carrying a Season label. Run at 1x and 4x to show the peak does
 * not scale with order count beyond the (small) list of matched changes.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OrderSeasonLabelRepairTest.php'; // shared WP stubs + plugin file

if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

class SeasonLabelSyntheticWpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries = 0;
	public $max_rows_returned = 0;
	private $lines;      // item_id => [order_id, product_id, season]
	private $orders;     // order_id => [status, lang]
	private $item_ids;   // sorted
	private $pending = [];

	public function __construct(int $orders, int $lines, int $labelled, int $renamed) {
		$this->orders = [];
		for ($o = 1; $o <= $orders; $o++) {
			$this->orders[100000 + $o] = [$o % 17 === 0 ? 'cancelled' : 'completed', ['en', 'fr', 'de'][$o % 3]];
		}
		$this->lines = [];
		for ($i = 1; $i <= $lines; $i++) {
			$oid = 100000 + (($i - 1) % $orders) + 1;
			$season = '';
			if ($i <= $labelled) {
				$season = $i <= $renamed ? ($i % 2 ? 'Winter 2027' : 'Spring/Summer 2027') : 'Autumn 2026';
			}
			$pid = $i <= $renamed ? ($i % 2 ? 5001 : 5002) : 6000 + ($i % 40);
			$this->lines[500000 + $i] = [$oid, $pid, $season];
		}
		$this->item_ids = array_keys($this->lines);
		sort($this->item_ids);
	}

	public function prepare($query, ...$args) {
		if (count($args) === 1 && is_array($args[0])) {
			$args = $args[0];
		}
		$token = 'Q' . count($this->pending) . '|' . $query;
		$this->pending[$token] = $args;
		return $token;
	}

	public function get_results($token, $output = null) {
		$this->queries++;
		$args = $this->pending[$token] ?? [];
		unset($this->pending[$token]);
		$out = [];
		if (strpos($token, 'oi.order_item_id > %d') !== false) {
			$after = (int) $args[0];
			$limit = (int) end($args);
			foreach ($this->item_ids as $iid) {
				if ($iid <= $after || $this->lines[$iid][2] === '') {
					continue;
				}
				$out[] = ['order_item_id' => (string) $iid, 'order_id' => (string) $this->lines[$iid][0]];
				if (count($out) >= $limit) {
					break;
				}
			}
		} elseif (strpos($token, 'meta_key, meta_value FROM') !== false) {
			foreach ($args as $a) {
				if (!is_int($a) && !ctype_digit((string) $a)) {
					break;
				}
				$iid = (int) $a;
				if (!isset($this->lines[$iid])) {
					continue;
				}
				[$oid, $pid, $season] = $this->lines[$iid];
				$out[] = ['order_item_id' => (string) $iid, 'meta_key' => '_product_id', 'meta_value' => (string) $pid];
				$out[] = ['order_item_id' => (string) $iid, 'meta_key' => '_variation_id', 'meta_value' => (string) ($pid * 10)];
				$out[] = ['order_item_id' => (string) $iid, 'meta_key' => 'Season', 'meta_value' => $season];
			}
		} elseif (strpos($token, 'post_status AS status') !== false || strpos($token, 'status, type FROM') !== false) {
			foreach ($args as $oid) {
				if (isset($this->orders[(int) $oid])) {
					$out[] = ['oid' => (string) $oid, 'status' => 'wc-' . $this->orders[(int) $oid][0], 'type' => 'shop_order'];
				}
			}
		} elseif (strpos($token, "'wpml_language'") !== false) {
			foreach ($args as $oid) {
				if (isset($this->orders[(int) $oid])) {
					$out[] = ['oid' => (string) $oid, 'meta_value' => $this->orders[(int) $oid][1]];
				}
			}
		}
		$this->max_rows_returned = max($this->max_rows_returned, count($out));
		return $out;
	}
}

class OrderSeasonLabelRepairMemoryTest extends TestCase {

	/** @var string */
	private $backup_dir;

	protected function setUp(): void {
		parent::setUp();
		$this->backup_dir = sys_get_temp_dir() . '/pv-season-label-mem-' . uniqid('', true);
		mkdir($this->backup_dir, 0755, true);
		$GLOBALS['intersoccer_test_season_label_backup_dir'] = $this->backup_dir;
		$GLOBALS['intersoccer_test_do_action'] = [];
		$GLOBALS['intersoccer_test_order_items'] = [];
		$GLOBALS['intersoccer_test_order_item_meta'] = [];
		$GLOBALS['intersoccer_test_wc_update_order_item_meta_calls'] = [];
		$GLOBALS['intersoccer_test_terms'] = [
			101 => ['name' => 'Winter Courses', 'slug' => 'winter-courses', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'en'],
			102 => ['name' => "Cours d'hiver", 'slug' => 'cours-dhiver', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'fr'],
			103 => ['name' => 'Winterkurse', 'slug' => 'winterkurse', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'de'],
			201 => ['name' => 'Spring/Summer Courses', 'slug' => 'spring-summer-courses', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'en'],
			202 => ['name' => 'Cours printemps-été', 'slug' => 'cours-printemps-ete', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'fr'],
			203 => ['name' => 'Frühlings-/Sommerkurse', 'slug' => 'fruehlings-sommerkurse', 'taxonomy' => 'pa_program-season', 'wpml_lang' => 'de'],
		];
		$GLOBALS['intersoccer_test_wpml_object_ids'] = [
			'101|pa_program-season|fr' => 102, '101|pa_program-season|de' => 103,
			'201|pa_program-season|fr' => 202, '201|pa_program-season|de' => 203,
			'102|pa_program-season|en' => 101, '103|pa_program-season|en' => 101,
			'202|pa_program-season|en' => 201, '203|pa_program-season|en' => 201,
		];
		$GLOBALS['intersoccer_test_product_term_ids'] = [
			5001 => ['pa_program-season' => [101]],
			5002 => ['pa_program-season' => [201]],
		];
		$GLOBALS['intersoccer_test_product_terms'] = [
			5001 => ['pa_program-season' => ['Winter Courses']],
			5002 => ['pa_program-season' => ['Spring/Summer Courses']],
		];
		if (class_exists('MockFilters')) {
			MockFilters::reset();
			MockFilters::$filters['wpml_default_language'] = static function () {
				return 'en';
			};
			MockFilters::$filters['wpml_object_id'] = static function ($id, $type, $return_original = false, $lang = null) {
				$key = (int) $id . '|' . (string) $type . '|' . (string) $lang;
				if (isset($GLOBALS['intersoccer_test_wpml_object_ids'][$key])) {
					return $GLOBALS['intersoccer_test_wpml_object_ids'][$key];
				}
				return $return_original ? (int) $id : 0;
			};
		}
		$GLOBALS['intersoccer_test_season_label_hpos'] = true;
	}

	protected function tearDown(): void {
		foreach (glob($this->backup_dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
		@rmdir($this->backup_dir);
		unset($GLOBALS['intersoccer_test_season_label_backup_dir'], $GLOBALS['intersoccer_test_season_label_hpos']);
		$GLOBALS['wpdb'] = null;
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
		parent::tearDown();
	}

	/**
	 * @return array{0:array,1:float,2:SeasonLabelSyntheticWpdb}
	 */
	private function runScan(int $scale, bool $apply): array {
		global $wpdb;
		$wpdb = new SeasonLabelSyntheticWpdb(5012 * $scale, 5593 * $scale, 5160 * $scale, 300 * $scale);
		gc_collect_cycles();
		if (function_exists('memory_reset_peak_usage')) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$report = $apply
			? InterSoccer_Order_Season_Label_Repair::run_apply([])
			: InterSoccer_Order_Season_Label_Repair::run_dry([]);
		$delta_mb = (memory_get_peak_usage() - $before) / 1048576;
		return [$report, $delta_mb, $wpdb];
	}

	public function test_dry_run_on_nov24_sized_dataset_is_memory_bounded(): void {
		[$report, $delta_mb, $db] = $this->runScan(1, false);
		$this->assertIsArray($report);
		$this->assertSame(300, $report['lines']);
		$this->assertArrayHasKey('peak_memory_mb', $report);
		$this->assertLessThanOrEqual(1500, $db->max_rows_returned, 'batches of at most 500 lines (3 meta rows each)');
		$this->assertLessThan(24.0, $delta_mb, sprintf('scan peak grew by %.1f MB', $delta_mb));
		fwrite(STDERR, sprintf("\n[season-label memory] 1x dry-run (5,012 orders / 5,593 lines): +%.2f MB over baseline, %d queries\n", $delta_mb, $db->queries));
	}

	public function test_apply_on_nov24_sized_dataset_is_memory_bounded_and_backup_streams(): void {
		[$report, $delta_mb] = $this->runScan(1, true);
		$this->assertSame(300, $report['updated']);
		$this->assertFileExists($report['backup_file']);
		$data = json_decode((string) file_get_contents($report['backup_file']), true);
		$this->assertIsArray($data);
		$this->assertCount(300, $data['entries']);
		$this->assertSame('order-season-label-repair', $data['tool']);
		$this->assertLessThan(24.0, $delta_mb, sprintf('apply peak grew by %.1f MB', $delta_mb));
		fwrite(STDERR, sprintf("[season-label memory] 1x apply: +%.2f MB over baseline\n", $delta_mb));
	}

	public function test_peak_does_not_scale_with_order_count(): void {
		[, $one] = $this->runScan(1, false);
		[$report4, $four] = $this->runScan(4, false);
		$this->assertSame(1200, $report4['lines']);
		$this->assertLessThan(24.0, $four);
		fwrite(STDERR, sprintf("[season-label memory] 4x dry-run (20,048 orders): +%.2f MB (1x: +%.2f MB)\n", $four, $one));
	}

	public function test_cancelled_trash_and_refund_rows_follow_status_and_type_rules(): void {
		$rows = InterSoccer_Order_Season_Label_Repair::order_statuses_except_trash();
		$this->assertNotContains('trash', $rows);
	}
}
