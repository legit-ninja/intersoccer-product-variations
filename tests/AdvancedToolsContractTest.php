<?php
/**
 * Advanced tools: capability, nonce, preview/apply gate, backup, rollback.
 */

use PHPUnit\Framework\TestCase;

if (!defined('INTERSOCCER_PV_REPAIR_TEST')) {
	define('INTERSOCCER_PV_REPAIR_TEST', true);
}

if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
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

if (!function_exists('get_role')) {
	function get_role($role) {
		$roles = $GLOBALS['intersoccer_pv_test_roles'] ?? [];
		return is_array($roles) && isset($roles[$role]) ? $roles[$role] : null;
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
		unset(
			$GLOBALS['intersoccer_pv_test_interrupt_after'],
			$GLOBALS['intersoccer_pv_test_redirect'],
			$GLOBALS['intersoccer_pv_test_roles'],
			$GLOBALS['intersoccer_test_products'],
			$GLOBALS['intersoccer_test_json_capture'],
			$GLOBALS['intersoccer_test_json']
		);
		MockMetaData::reset();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_pv_test_tool_runner'],
			$GLOBALS['intersoccer_pv_test_nonce_valid'],
			$GLOBALS['intersoccer_pv_test_value_store'],
			$GLOBALS['intersoccer_test_user_caps'],
			$GLOBALS['intersoccer_pv_test_interrupt_after'],
			$GLOBALS['intersoccer_pv_test_redirect'],
			$GLOBALS['intersoccer_pv_test_roles'],
			$GLOBALS['intersoccer_test_products'],
			$GLOBALS['intersoccer_test_json_capture'],
			$GLOBALS['intersoccer_test_json'],
			$GLOBALS['intersoccer_test_orders'],
			$GLOBALS['intersoccer_test_wc_get_orders'],
			$GLOBALS['intersoccer_pv_test_post_types'],
			$GLOBALS['intersoccer_test_wp_get_post_parent_id_map']
		);
		$_POST = [];
		$_GET = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		MockMetaData::reset();
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

	public function test_preview_expires_after_thirty_minutes(): void {
		intersoccer_pv_repair_store_preview(1, 'late_pickup', ['tool' => 'late_pickup', 'product_id' => '42'], [
			'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
			'rows' => [[
				'id' => 'meta:5:_intersoccer_enable_late_pickup',
				'before' => ['value' => ''],
				'after' => ['value' => 'yes'],
			]],
			'shop_wide' => false,
		]);
		$key = intersoccer_pv_repair_preview_key(1, 'late_pickup');
		$stored = intersoccer_pv_repair_state_get($key);
		$this->assertIsArray($stored);
		$stored['created_at'] = time() - (30 * 60);
		intersoccer_pv_repair_state_set($key, $stored);
		$this->assertNull(intersoccer_pv_repair_get_preview(1, 'late_pickup'));
		$refused = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertStringContainsString('Run Preview before Apply.', $refused['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_cap_is_granted_once_and_not_to_shop_manager(): void {
		$admin = new InterSoccer_Pv_Test_Role();
		$shop = new InterSoccer_Pv_Test_Role();
		$GLOBALS['intersoccer_pv_test_roles'] = [
			'administrator' => $admin,
			'shop_manager' => $shop,
		];
		intersoccer_pv_repair_grant_administrator_cap();
		intersoccer_pv_repair_grant_administrator_cap();
		$this->assertSame(1, $admin->add_calls);
		$this->assertTrue($admin->has_cap('intersoccer_run_repairs'));
		$this->assertSame(0, $shop->add_calls);
		$this->assertFalse($shop->has_cap('intersoccer_run_repairs'));
	}

	public function test_backup_dir_denies_web_and_uses_a_long_suffix(): void {
		$name = intersoccer_pv_repair_write_backup('late_pickup', 1, [[
			'id' => 'meta:5:_end_date',
			'before' => ['value' => 'a'],
			'after' => ['value' => 'b'],
		]]);
		$this->assertMatchesRegularExpression('/-[a-f0-9]{32}\.json$/', $name);
		$htaccess = (string) file_get_contents($this->backupDir . '/.htaccess');
		$this->assertStringContainsString('Require all denied', $htaccess);
		$this->assertStringContainsString('Deny from all', $htaccess);
		$this->assertFileExists($this->backupDir . '/index.php');
	}

	public function test_uninstall_removes_cap_and_repair_options_only(): void {
		update_option('intersoccer_pv_repair_run_log', [['tool' => 'late_pickup']]);
		update_option('intersoccer_discount_rules', ['keep']);
		$admin = new InterSoccer_Pv_Test_Role();
		$shop = new InterSoccer_Pv_Test_Role();
		$admin->add_cap('intersoccer_run_repairs', true);
		$shop->add_cap('intersoccer_run_repairs', true);
		$admin->add_calls = 0;
		$shop->add_calls = 0;
		$GLOBALS['intersoccer_pv_test_roles'] = [
			'administrator' => $admin,
			'shop_manager' => $shop,
		];
		intersoccer_pv_repair_uninstall();
		$this->assertFalse($admin->has_cap('intersoccer_run_repairs'));
		$this->assertFalse($shop->has_cap('intersoccer_run_repairs'));
		$this->assertFalse(array_key_exists('intersoccer_pv_repair_run_log', $GLOBALS['intersoccer_test_options'] ?? []));
		$this->assertSame(['keep'], get_option('intersoccer_discount_rules'));
	}

	public function test_delete_phrase_is_required_when_preview_removes_metadata(): void {
		$state = ['changed' => 1];
		$GLOBALS['intersoccer_pv_test_tool_runner'] = static function ($tool, $dry_run, $scope) use (&$state) {
			unset($tool, $scope);
			if (!$dry_run) {
				$GLOBALS['intersoccer_pv_test_writes']++;
				$state['changed'] = 0;
			}
			return [
				'summary' => 'remove',
				'counts' => ['changed' => (int) $state['changed'], 'added' => 0, 'updated' => 0, 'deleted' => (int) $state['changed']],
				'rows' => [[
					'id' => 5,
					'before' => ['Player Index' => '1'],
					'after' => [],
				]],
			];
		};
		$this->postTool('preview', ['intersoccer_pv_repair_tool' => 'automated_batch']);
		$missing = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
		]);
		$this->assertStringContainsString('Type DELETE', $missing['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
			'intersoccer_pv_repair_delete' => 'DELETE',
		]);
		$this->assertSame('success', $applied['type']);
		$this->assertSame(1, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_apply_refuses_when_rows_changed_since_preview(): void {
		$this->bindLatePickupProduct(42, [501]);
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = '';
		$this->postTool('preview');
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = 'yes';
		$refused = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertStringContainsString('no longer matches the Preview', $refused['message']);
		$this->assertSame('yes', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
	}

	public function test_backup_cap_refuses_apply_without_writing_a_prefix(): void {
		$children = range(1000, 1200);
		$this->bindLatePickupProduct(42, $children);
		$preview = $this->postTool('preview');
		$this->assertStringContainsString('Apply is refused above 200', $preview['message']);
		$this->assertGreaterThan(200, count($preview['result']['rows']));
		$refused = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertStringContainsString('Apply is refused above 200', $refused['message']);
		$this->assertSame([], glob($this->backupDir . '/*.json') ?: []);
		$this->assertArrayNotHasKey('_intersoccer_enable_late_pickup', MockMetaData::$data[1000] ?? []);
	}

	public function test_real_late_pickup_preview_apply_rollback_and_second_apply(): void {
		$this->bindLatePickupProduct(42, [501]);
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = '';
		$preview = $this->postTool('preview');
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		$this->assertSame('yes', $preview['result']['rows'][0]['after']['value']);
		$applied = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertSame('success', $applied['type']);
		$this->assertSame('yes', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$raw = json_decode((string) file_get_contents($this->backupDir . '/' . $filename), true);
		$this->assertSame('', $raw['rows'][0]['before']['value']);
		$this->assertSame('yes', $raw['rows'][0]['after']['value']);

		$again = $this->postTool('preview');
		$this->assertSame(0, (int) $again['result']['counts']['changed']);
		$empty = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		$this->assertStringContainsString('Preview has no changes', $empty['message']);

		$restored = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored 1', $restored['message']);
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
	}

	public function test_partial_apply_rolls_back_from_the_pre_apply_snapshot(): void {
		$this->bindLatePickupProduct(42, [501, 502]);
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = '';
		MockMetaData::$data[502]['_intersoccer_enable_late_pickup'] = '';
		$this->postTool('preview');
		$GLOBALS['intersoccer_pv_test_interrupt_after'] = 1;
		$partial = $this->postTool('apply', ['intersoccer_pv_repair_phrase' => 'APPLY']);
		unset($GLOBALS['intersoccer_pv_test_interrupt_after']);
		$this->assertStringContainsString('pre-Apply snapshot', $partial['message']);
		$this->assertSame('yes', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		$this->assertSame('', MockMetaData::$data[502]['_intersoccer_enable_late_pickup']);
		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$raw = json_decode((string) file_get_contents($this->backupDir . '/' . $filename), true);
		$this->assertCount(2, $raw['rows']);
		$rolled = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored 1', $rolled['message']);
		$this->assertStringContainsString('Conflicts left untouched: 1', $rolled['message']);
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		$this->assertSame('', MockMetaData::$data[502]['_intersoccer_enable_late_pickup']);
	}

	public function test_real_refresh_attributes_preview_matches_apply(): void {
		$variation = new class {
			public $attrs = ['pa_age-group' => 'full-day'];
			public function get_attributes() {
				return $this->attrs;
			}
			public function set_attributes($attributes) {
				$this->attrs = $attributes;
			}
			public function save() {
			}
			public function get_id() {
				return 77;
			}
		};
		$parent = new class($variation) {
			private $variation;
			public function __construct($variation) {
				$this->variation = $variation;
			}
			public function get_children() {
				return [77];
			}
			public function get_attributes() {
				return [
					'pa_age-group' => new class {
						public function get_variation() {
							return true;
						}
						public function get_name() {
							return 'pa_age-group';
						}
					},
				];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
		};
		$GLOBALS['intersoccer_test_products'][10] = $parent;
		$GLOBALS['intersoccer_test_products'][77] = $variation;
		MockMetaData::$data[10]['_intersoccer_product_type'] = 'course';
		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '10',
		]);
		$this->assertSame('full-day', $variation->attrs['pa_age-group']);
		$this->assertSame('', $preview['result']['rows'][0]['after']['attributes']['pa_age-group']);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '10',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type']);
		$this->assertSame('', $variation->attrs['pa_age-group']);
	}

	public function test_real_course_end_date_preview_apply_and_rollback(): void {
		$parent = new class {
			public function get_children() {
				return [88];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
		};
		$GLOBALS['intersoccer_test_products'][15] = $parent;
		MockMetaData::$data[88]['_course_start_date'] = '2026-06-01';
		MockMetaData::$data[88]['_course_total_weeks'] = 4;
		MockMetaData::$data[88]['attribute_pa_course-day'] = 'monday';
		MockMetaData::$data[88]['_end_date'] = '1999-01-01';
		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'recalculate_end_dates',
			'product_id' => '15',
		]);
		$this->assertNotEmpty($preview['result']['rows']);
		$this->assertSame('1999-01-01', $preview['result']['rows'][0]['before']['value']);
		$this->assertNotSame('1999-01-01', $preview['result']['rows'][0]['after']['value']);
		$this->assertSame('1999-01-01', MockMetaData::$data[88]['_end_date']);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'recalculate_end_dates',
			'product_id' => '15',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type']);
		$this->assertSame($preview['result']['rows'][0]['after']['value'], MockMetaData::$data[88]['_end_date']);
		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertSame('1999-01-01', MockMetaData::$data[88]['_end_date']);
	}

	public function test_course_holiday_fix_does_not_offer_apply(): void {
		$_GET['tab'] = 'product-tools';
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$html = ob_get_clean();
		$this->assertStringContainsString('Apply is not offered. No rollback.', $html);
		$result = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'course_holiday_fix',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
		]);
		$this->assertStringContainsString('Apply is not offered', $result['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_get_and_legacy_handlers_write_nothing(): void {
		$this->bindLatePickupProduct(42, [501]);
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = '';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['product_id'] = '42';
		$this->assertSame(['type' => '', 'message' => '', 'result' => null], intersoccer_pv_advanced_tools_handle_request());
		require_once dirname(__DIR__) . '/includes/admin-product-fields.php';
		intersoccer_handle_repair_late_pickup_admin_post();
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		$this->assertStringContainsString('tab=late-pickup', (string) $GLOBALS['intersoccer_pv_test_redirect']);

		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		$GLOBALS['intersoccer_test_json_capture'] = true;
		$GLOBALS['intersoccer_test_user_caps'] = ['intersoccer_run_repairs' => true, 'manage_woocommerce' => true];
		require_once dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		$handlers = [
			'intersoccer_update_processing_orders_callback',
			'intersoccer_refresh_variation_attributes_callback',
			'intersoccer_batch_update_orders_callback',
			'intersoccer_automated_batch_update_callback',
		];
		foreach ($handlers as $handler) {
			try {
				$handler();
				$this->fail($handler . ' should refuse.');
			} catch (Exception $e) {
				$this->assertSame('AJAX_EXIT', $e->getMessage());
			}
			$this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
		}
		foreach (['ajax_apply_parsed_camp_dates', 'ajax_repair_camp_facets'] as $method) {
			try {
				InterSoccer_Program_Manager::$method();
				$this->fail($method . ' should refuse.');
			} catch (Exception $e) {
				$this->assertSame('AJAX_EXIT', $e->getMessage());
			}
			$this->assertFalse($GLOBALS['intersoccer_test_json']['success']);
		}
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
		unset($GLOBALS['intersoccer_test_json_capture']);
	}

	public function test_shop_manager_real_preview_writes_nothing(): void {
		$this->bindLatePickupProduct(42, [501]);
		MockMetaData::$data[501]['_intersoccer_enable_late_pickup'] = '';
		$GLOBALS['intersoccer_test_user_caps'] = ['manage_woocommerce' => true];
		try {
			$this->postTool('preview');
			$this->fail('Shop manager preview should be rejected.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('permission', strtolower($e->getMessage()));
		}
		$this->assertSame('', MockMetaData::$data[501]['_intersoccer_enable_late_pickup']);
	}

	public function test_real_camp_date_migration_preview_matches_apply(): void {
		$this->loadCampDateStack();
		if (!function_exists('wc_delete_product_transients')) {
			function wc_delete_product_transients($product_id = 0) {
				return true;
			}
		}
		$variation_id = 71;
		$parent = new class($variation_id) {
			private $variation_id;
			public function __construct($variation_id) {
				$this->variation_id = $variation_id;
			}
			public function get_id() {
				return 70;
			}
			public function get_children() {
				return [$this->variation_id];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_attribute($name) {
				return '';
			}
			public function get_attributes() {
				return [];
			}
		};
		$variation = new class {
			public function get_id() {
				return 71;
			}
			public function get_attribute($name) {
				return '';
			}
			public function get_attributes() {
				return [];
			}
		};
		$GLOBALS['intersoccer_test_products'][70] = $parent;
		$GLOBALS['intersoccer_test_products'][$variation_id] = $variation;
		MockMetaData::$data[70]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[$variation_id]['attribute_pa_camp-terms'] = 'autumn-week-4-october-20-october-24-5-days';
		MockMetaData::$data[$variation_id]['attribute_pa_program-season'] = 'Autumn 2025';

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'migrate_camp_dates',
			'product_id' => '70',
		]);
		$this->assertNotEmpty($preview['result']['rows']);
		$this->assertSame('', $preview['result']['rows'][0]['before']['start']);
		$this->assertSame('2025-10-20', $preview['result']['rows'][0]['after']['start']);
		$this->assertSame('2025-10-24', $preview['result']['rows'][0]['after']['end']);
		$this->assertSame('', (string) get_post_meta($variation_id, '_camp_start_date', true));

		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'migrate_camp_dates',
			'product_id' => '70',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type']);
		$this->assertSame('2025-10-20', get_post_meta($variation_id, '_camp_start_date', true));
		$this->assertSame('2025-10-24', get_post_meta($variation_id, '_camp_end_date', true));

		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertSame('', (string) get_post_meta($variation_id, '_camp_start_date', true));
	}

	public function test_real_order_line_batch_preview_apply_and_rollback(): void {
		$this->loadOrderRepairStack();
		$item = $this->makeOrderItem();
		$order = $this->makeOrder($item);
		$GLOBALS['intersoccer_test_orders'][9001] = $order;
		$GLOBALS['intersoccer_test_wc_get_orders'] = static function () use ($order) {
			return [$order];
		};
		$GLOBALS['intersoccer_test_products'][55] = new class {
			public function get_id() {
				return 55;
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_children() {
				return [56];
			}
			public function get_attribute($name) {
				return '';
			}
			public function get_attributes() {
				return [];
			}
		};
		MockMetaData::$data[55]['_intersoccer_product_type'] = 'camp';
		$GLOBALS['intersoccer_pv_test_post_types'] = [55 => 'product', 56 => 'product_variation'];
		$GLOBALS['intersoccer_test_wp_get_post_parent_id_map'] = [56 => 55];

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'order_statuses' => 'processing,completed',
			'scan_limit' => '10',
		]);
		$this->assertNotEmpty($preview['result']['rows']);
		$this->assertSame('order-item:9001:31', $preview['result']['rows'][0]['id']);
		$this->assertArrayNotHasKey('Activity Type', $preview['result']['rows'][0]['before']);
		$this->assertNotSame('', (string) ($preview['result']['rows'][0]['after']['Activity Type'] ?? ''));
		$this->assertSame('', (string) $item->get_meta('Activity Type', true));

		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'automated_batch',
			'order_statuses' => 'processing,completed',
			'scan_limit' => '10',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
			'intersoccer_pv_repair_delete' => 'DELETE',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertNotSame('', (string) $item->get_meta('Activity Type', true));

		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$rolled = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored 1', $rolled['message']);
		$this->assertSame('', (string) $item->get_meta('Activity Type', true));
	}

	private function loadCampDateStack(): void {
		if (!defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
		if (!class_exists('InterSoccer_Product_Types', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
		}
		require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule-migrate.php';
		if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
			require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
		}
	}

	private function loadOrderRepairStack(): void {
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!function_exists('get_post_type')) {
			function get_post_type($post_id) {
				$map = $GLOBALS['intersoccer_pv_test_post_types'] ?? null;
				if (is_array($map) && array_key_exists((int) $post_id, $map)) {
					return $map[(int) $post_id];
				}
				return 'product_variation';
			}
		}
		if (!class_exists('InterSoccer_Product_Types', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
		}
		require_once dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';
		if (!class_exists('WC_Order', false)) {
			eval('class WC_Order { public function __construct($customer_id = 0) {} public function get_customer_id() { return 0; } }');
		}
		if (!class_exists('WC_Order_Item_Product', false)) {
			eval('class WC_Order_Item_Product { public function __construct(array $meta = []) {} }');
		}
	}

	private function makeOrderItem(): object {
		return new class extends WC_Order_Item_Product {
			public $meta = [];
			public function get_product_id() {
				return 55;
			}
			public function get_variation_id() {
				return 56;
			}
			public function get_order() {
				return null;
			}
			public function get_meta_data() {
				$rows = [];
				foreach ($this->meta as $key => $value) {
					$rows[] = (object) ['key' => $key, 'value' => $value];
				}
				return $rows;
			}
			public function get_meta($key, $single = true) {
				if (!$single) {
					return array_key_exists($key, $this->meta) ? [$this->meta[$key]] : [];
				}
				return $this->meta[$key] ?? '';
			}
			public function add_meta_data($key, $value, $unique = false) {
				$this->meta[$key] = $value;
			}
			public function update_meta_data($key, $value) {
				$this->meta[$key] = $value;
			}
			public function delete_meta_data($key) {
				unset($this->meta[$key]);
			}
			public function save() {
			}
		};
	}

	private function makeOrder(object $item): object {
		return new class($item) extends WC_Order {
			private $item;
			public function __construct($item) {
				$this->item = $item;
			}
			public function get_id() {
				return 9001;
			}
			public function get_items($type = null) {
				return [31 => $this->item];
			}
			public function get_item($item_id) {
				return $this->item;
			}
			public function save() {
			}
		};
	}

	private function bindLatePickupProduct(int $product_id, array $children): void {
		$GLOBALS['intersoccer_test_products'][$product_id] = new class($product_id, $children) {
			private $id;
			private $children;
			public function __construct($id, $children) {
				$this->id = $id;
				$this->children = $children;
			}
			public function get_id() {
				return $this->id;
			}
			public function get_children() {
				return $this->children;
			}
			public function is_type($type) {
				return $type === 'variable';
			}
		};
	}

	private function checkboxMarkup(string $html, string $name): string {
		if (!preg_match('/<input type="checkbox" name="' . preg_quote($name, '/') . '"[^>]*>/', $html, $match)) {
			return '';
		}
		return $match[0];
	}
}

class InterSoccer_Pv_Test_Role {
	public $caps = [];
	public $add_calls = 0;

	public function add_cap($cap, $grant = true) {
		$this->add_calls++;
		$this->caps[$cap] = (bool) $grant;
	}

	public function has_cap($cap) {
		return !empty($this->caps[$cap]);
	}

	public function remove_cap($cap) {
		unset($this->caps[$cap]);
	}
}
