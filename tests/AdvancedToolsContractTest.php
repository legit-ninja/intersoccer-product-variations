<?php
/**
 * Advanced tools: capability, nonce, preview/apply gate, backup, rollback.
 */

use PHPUnit\Framework\TestCase;

if (!defined('INTERSOCCER_PV_REPAIR_TEST')) {
	define('INTERSOCCER_PV_REPAIR_TEST', true);
}

if (!function_exists('esc_html_e')) {
	function esc_html_e($text, $domain = 'default') {
		echo esc_html($text);
	}
}

if (!function_exists('esc_attr_e')) {
	function esc_attr_e($text, $domain = 'default') {
		echo esc_attr($text);
	}
}

if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default') {
		return esc_html(__($text, $domain));
	}
}

if (!function_exists('esc_attr__')) {
	function esc_attr__($text, $domain = 'default') {
		return esc_attr(__($text, $domain));
	}
}

if (!function_exists('wp_nonce_field')) {
	function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
		$html = '<input type="hidden" name="' . esc_attr((string) $name) . '" value="test-nonce" />';
		if ($echo) {
			echo $html;
		}
		return $html;
	}
}

if (!function_exists('admin_url')) {
	function admin_url($path = '') {
		return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
	}
}

if (!function_exists('add_query_arg')) {
	function add_query_arg($key, $value = null, $url = null) {
		if (is_array($key)) {
			$args = $key;
			$url = $value;
		} else {
			$args = [$key => $value];
		}
		$url = $url ?: '';
		$sep = strpos((string) $url, '?') === false ? '?' : '&';
		return $url . $sep . http_build_query($args);
	}
}

class AdvancedToolsContractTest extends TestCase {

	private $backupDir;

	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__) . '/includes/woocommerce/advanced-tools-admin.php';
	}

	protected function setUp(): void {
		intersoccer_pv_repair_reset_state();
		$this->backupDir = sys_get_temp_dir() . '/pv-repair-' . getmypid() . '-' . uniqid('', true);
		$GLOBALS['intersoccer_pv_test_backup_dir'] = $this->backupDir;
		$GLOBALS['intersoccer_pv_test_writes'] = 0;
		unset(
			$GLOBALS['intersoccer_pv_test_nonce_valid'],
			$GLOBALS['intersoccer_pv_test_tool_runner'],
			$GLOBALS['intersoccer_pv_test_value_store'],
			$GLOBALS['intersoccer_pv_repair_last_result'],
			$GLOBALS['intersoccer_test_user_caps']
		);
		$_POST = [];
		$_GET = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$GLOBALS['intersoccer_test_user_caps'] = ['intersoccer_run_repairs' => true];
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_pv_test_tool_runner'],
			$GLOBALS['intersoccer_pv_test_nonce_valid'],
			$GLOBALS['intersoccer_pv_test_value_store'],
			$GLOBALS['intersoccer_test_user_caps']
		);
		$_POST = [];
		$_GET = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	private function runner(array &$state): void {
		$GLOBALS['intersoccer_pv_test_tool_runner'] = static function ($tool, $dry_run, $scope) use (&$state) {
			if (!$dry_run) {
				$GLOBALS['intersoccer_pv_test_writes']++;
				$state['changed'] = 0;
			}
			$changed = $dry_run ? (int) $state['changed'] : 1;
			return [
				'summary' => 'rows ' . $changed,
				'counts' => ['changed' => $changed, 'added' => 0, 'updated' => $changed, 'deleted' => 0],
				'rows' => [[
					'id' => 5,
					'before' => ['season' => 'A'],
					'after' => ['season' => 'B'],
				]],
				'wrote' => !$dry_run,
			];
		};
	}

	private function postTool(string $mode, array $extra = []): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array_merge([
			'intersoccer_pv_repair_tool' => 'late_pickup',
			'intersoccer_pv_repair_mode' => $mode,
			'_wpnonce' => 'test-nonce',
			'product_id' => '42',
			'intersoccer_pv_repair_phrase' => '',
			'intersoccer_pv_repair_global' => '',
		], $extra);
		return intersoccer_pv_advanced_tools_handle_request();
	}

	public function test_administrator_can_render_and_shop_manager_cannot(): void {
		$_GET['tab'] = 'product-tools';
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$html = ob_get_clean();
		$this->assertStringContainsString('Advanced tools', $html);
		$this->assertStringContainsString('Preview (dry run)', $html);
		$this->assertStringContainsString('Recalculate Course End Dates', $html);

		$_GET['tab'] = 'batch';
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$batch = ob_get_clean();
		$this->assertStringNotContainsString('checked', $this->checkboxMarkup($batch, 'strip_deprecated'));
		$this->assertStringNotContainsString('checked', $this->checkboxMarkup($batch, 'prune_legacy'));
		$this->assertNotSame('', $this->checkboxMarkup($batch, 'strip_deprecated'));

		$GLOBALS['intersoccer_test_user_caps'] = ['manage_woocommerce' => true];
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('permission');
		intersoccer_pv_render_advanced_tools_page();
	}

	public function test_shop_manager_cannot_preview_or_apply(): void {
		$state = ['changed' => 1];
		$this->runner($state);
		$GLOBALS['intersoccer_test_user_caps'] = ['manage_woocommerce' => true];
		try {
			$this->postTool('apply', [
				'intersoccer_pv_repair_phrase' => 'APPLY',
			]);
			$this->fail('Shop manager apply should be rejected.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('permission', strtolower($e->getMessage()));
		}
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_missing_and_bad_nonce_reject_without_writes(): void {
		$state = ['changed' => 1];
		$this->runner($state);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'intersoccer_pv_repair_tool' => 'late_pickup',
			'intersoccer_pv_repair_mode' => 'preview',
			'product_id' => '42',
		];
		try {
			intersoccer_pv_advanced_tools_handle_request();
			$this->fail('Missing nonce should be rejected.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Security check failed', $e->getMessage());
		}

		$GLOBALS['intersoccer_pv_test_nonce_valid'] = false;
		try {
			$this->postTool('preview');
			$this->fail('Bad nonce should be rejected.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Security check failed', $e->getMessage());
		}
		unset($GLOBALS['intersoccer_pv_test_nonce_valid']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_preview_writes_nothing_and_apply_requires_preview_phrase_and_global(): void {
		$state = ['changed' => 1];
		$this->runner($state);

		$preview = $this->postTool('preview', ['intersoccer_pv_repair_tool' => 'automated_batch']);
		$this->assertSame('info', $preview['type']);
		$this->assertStringContainsString('Nothing was written', $preview['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);

		intersoccer_pv_repair_reset_state();
		$noPreview = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
		]);
		$this->assertStringContainsString('Run Preview before Apply.', $noPreview['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);

		$this->postTool('preview', ['intersoccer_pv_repair_tool' => 'automated_batch']);
		$noPhrase = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'intersoccer_pv_repair_global' => 'GLOBAL',
		]);
		$this->assertStringContainsString('Type APPLY to confirm.', $noPhrase['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);

		$noGlobal = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertStringContainsString('Type GLOBAL as well as APPLY.', $noGlobal['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_scoped_apply_creates_backup_and_second_empty_apply_is_refused(): void {
		$state = ['changed' => 1];
		$this->runner($state);
		$this->postTool('preview');
		$applied = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertSame('success', $applied['type']);
		$this->assertSame(1, $GLOBALS['intersoccer_pv_test_writes']);
		$log = intersoccer_pv_repair_log_entries();
		$apply = $log[count($log) - 1];
		$this->assertSame('apply', $apply['mode']);
		$this->assertNotSame('', $apply['backup']);
		$this->assertFileExists($this->backupDir . '/' . $apply['backup']);
		$raw = json_decode((string) file_get_contents($this->backupDir . '/' . $apply['backup']), true);
		$this->assertSame('B', $raw['rows'][0]['after']['season']);

		$this->postTool('preview');
		$again = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertStringContainsString('Preview has no changes', $again['message']);
		$this->assertSame(1, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_rollback_restores_matching_rows_and_reports_conflicts(): void {
		$state = ['changed' => 1];
		$this->runner($state);
		$this->postTool('preview');
		$this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$log = intersoccer_pv_repair_log_entries();
		$apply = $log[count($log) - 1];
		$filename = (string) $apply['backup'];

		$GLOBALS['intersoccer_pv_test_value_store'] = [5 => ['season' => 'B']];
		$restored = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored 1', $restored['message']);
		$this->assertSame('A', $GLOBALS['intersoccer_pv_test_value_store'][5]['season']);

		$GLOBALS['intersoccer_pv_test_value_store'] = [5 => ['season' => 'C']];
		$conflict = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('Conflicts left untouched: 1', $conflict['message']);
		$this->assertSame('C', $GLOBALS['intersoccer_pv_test_value_store'][5]['season']);
	}

	public function test_everyday_screens_do_not_keep_repair_write_buttons(): void {
		$pm = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/program-manager.php');
		$this->assertStringNotContainsString('name="intersoccer_recalc_end_dates"', $pm);
		$this->assertStringNotContainsString('intersoccer-pm-course-holiday-fix-btn', $pm);
		$this->assertStringNotContainsString('intersoccer-pm-refresh-attrs-btn', $pm);
		$this->assertStringNotContainsString('intersoccer-pm-apply-parsed-dates-btn', $pm);
		$this->assertStringNotContainsString('intersoccer-pm-repair-facets-btn', $pm);
		$this->assertStringNotContainsString("'refresh_attrs'", substr($pm, (int) strpos($pm, 'function get_bulk_actions')));

		$fields = (string) file_get_contents(dirname(__DIR__) . '/includes/admin-product-fields.php');
		$handler = substr($fields, (int) strpos($fields, 'function intersoccer_handle_repair_late_pickup_admin_post'));
		$handler = substr($handler, 0, (int) strpos($handler, 'function intersoccer_get_late_pickup_repair_url'));
		$this->assertStringNotContainsString('intersoccer_enable_late_pickup_for_all_camp_variations', $handler);
		$this->assertStringNotContainsString('admin-post.php?action=intersoccer_repair_late_pickup', $fields);
		$this->assertStringNotContainsString('Re-enable late pick up on all variations', $fields);

		$admin = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/admin-ui.php');
		$this->assertDoesNotMatchRegularExpression('/id="batch-strip-deprecated"[^>]*checked/', $admin);
		$this->assertDoesNotMatchRegularExpression('/id="batch-prune-legacy-twins"[^>]*checked/', $admin);
		$this->assertStringNotContainsString("add_submenu_page", substr($admin, (int) strpos($admin, 'Order Meta Repair submenu removed')));
	}

	public function test_repair_cap_is_granted_to_administrator_only(): void {
		$src = (string) file_get_contents(dirname(__DIR__) . '/includes/woocommerce/repair-contract.php');
		$this->assertStringContainsString("define('INTERSOCCER_PV_REPAIR_CAP', 'intersoccer_run_repairs')", $src);
		$this->assertStringContainsString("get_role('administrator')", $src);
		$grant = substr($src, (int) strpos($src, 'function intersoccer_pv_repair_grant_administrator_cap'));
		$grant = substr($grant, 0, (int) strpos($grant, 'function intersoccer_pv_user_can_run_repairs'));
		$this->assertStringNotContainsString('shop_manager', $grant);
		$this->assertStringNotContainsString('manage_woocommerce', $grant);
	}

	private function checkboxMarkup(string $html, string $name): string {
		if (!preg_match('/<input type="checkbox" name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $match)) {
			return '';
		}
		return $match[0];
	}
}
