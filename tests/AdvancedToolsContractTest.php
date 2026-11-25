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
		require_once dirname(__DIR__) . '/includes/language-helpers.php';
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
			$GLOBALS['intersoccer_test_terms'],
			$GLOBALS['intersoccer_test_product_terms'],
			$GLOBALS['intersoccer_test_product_term_ids'],
			$GLOBALS['intersoccer_test_term_translations'],
			$GLOBALS['intersoccer_test_term_translation_ids'],
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
			$GLOBALS['intersoccer_test_wp_get_post_parent_id_map'],
			$GLOBALS['intersoccer_pv_test_rr_active'],
			$GLOBALS['intersoccer_pv_test_scan_queries'],
			$GLOBALS['intersoccer_pv_test_open_basedir'],
			$GLOBALS['intersoccer_pv_test_upload_basedir'],
			$GLOBALS['intersoccer_pv_test_active_plugins'],
			$GLOBALS['intersoccer_test_term_translations'],
			$GLOBALS['intersoccer_test_term_translation_ids'],
			$GLOBALS['intersoccer_test_terms'],
			$GLOBALS['intersoccer_test_product_terms'],
			$GLOBALS['intersoccer_test_product_term_ids']
		);
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
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
		$GLOBALS['intersoccer_pv_test_rr_active'] = true;
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$html = ob_get_clean();
		$this->assertStringContainsString('InterSoccer Advanced tools: Products &amp; orders', $html);
		$this->assertStringContainsString('InterSoccer Advanced tools: Rosters &amp; reports', $html);
		$this->assertStringContainsString('admin.php?page=intersoccer-advanced-tools', $html);
		unset($GLOBALS['intersoccer_pv_test_rr_active']);
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
		$ajax = substr($pm, (int) strpos($pm, 'function ajax_bulk_process_one'));
		$ajax = substr($ajax, 0, (int) strpos($ajax, 'function process_bulk_item'));
		$this->assertStringContainsString("=== 'refresh_attrs'", $ajax);
		$this->assertStringContainsString('wp_send_json_error', $ajax);
		$allowed = substr($pm, (int) strpos($pm, 'function process_bulk_item'));
		$allowed = substr($allowed, 0, (int) strpos($allowed, 'function ' , 20));
		$this->assertStringNotContainsString("'refresh_attrs'", $allowed);

		$js = (string) file_get_contents(dirname(__DIR__) . '/js/program-manager.js');
		$this->assertStringNotContainsString('refresh_attrs', $js);
		$this->assertStringNotContainsString('intersoccer_run_course_holiday_fix', $js);
		$this->assertStringNotContainsString('intersoccer-pm-course-holiday-fix-btn', $js);

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
		$index = intersoccer_pv_repair_state_get('intersoccer_pv_repair_backup_index', []);
		$this->assertIsArray($index);
		$this->assertSame(realpath($this->backupDir), $index[count($index) - 1]['dir']);
	}

	public function test_backup_read_uses_the_recorded_directory_after_the_preferred_dir_changes(): void {
		$dir_a = $this->backupDir;
		$name = intersoccer_pv_repair_write_backup('late_pickup', 1, [[
			'id' => 'meta:5:_end_date',
			'before' => ['value' => 'old'],
			'after' => ['value' => 'new'],
		]]);
		$dir_b = sys_get_temp_dir() . '/pv-repair-b-' . getmypid() . '-' . uniqid('', true);
		mkdir($dir_b, 0777, true);
		$GLOBALS['intersoccer_pv_test_backup_dir'] = $dir_b;
		$read = intersoccer_pv_repair_read_backup($name);
		$this->assertIsArray($read);
		$this->assertSame('old', $read['rows'][0]['before']['value']);
		$this->assertFileDoesNotExist($dir_b . '/' . $name);
		$this->assertFileExists($dir_a . '/' . $name);
		$GLOBALS['intersoccer_pv_test_value_store']['meta:5:_end_date'] = ['value' => 'new'];
		$rolled = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $name,
		]);
		$this->assertStringContainsString('restored 1', $rolled['message']);
		$this->assertSame(['value' => 'old'], $GLOBALS['intersoccer_pv_test_value_store']['meta:5:_end_date']);
		$this->backupDir = $dir_a;
		$GLOBALS['intersoccer_pv_test_backup_dir'] = $dir_a;
	}

	public function test_backup_dir_probe_skips_paths_outside_open_basedir(): void {
		$root = sys_get_temp_dir() . '/pv-open-' . getmypid() . '-' . uniqid('', true);
		mkdir($root, 0777, true);
		$GLOBALS['intersoccer_pv_test_open_basedir'] = $root;
		$outside = dirname($root) . '/pv-outside-' . uniqid('', true) . '/intersoccer-pv-repair-backups';
		$this->assertFalse(intersoccer_pv_repair_dir_creatable($outside));
		$this->assertDirectoryDoesNotExist(dirname($outside));
		$inside = $root . '/intersoccer-pv-repair-backups';
		$this->assertTrue(intersoccer_pv_repair_dir_creatable($inside));
		$this->assertDirectoryExists($inside);
		$this->assertTrue(intersoccer_pv_repair_dir_creatable($inside));
	}

	public function test_uninstall_deletes_backup_files_and_directories(): void {
		$name = intersoccer_pv_repair_write_backup('late_pickup', 1, [[
			'id' => 'meta:5:_end_date',
			'before' => ['value' => 'a'],
			'after' => ['value' => 'b'],
		]]);
		$uploads = sys_get_temp_dir() . '/pv-uploads-' . getmypid() . '-' . uniqid('', true);
		mkdir($uploads, 0777, true);
		file_put_contents($uploads . '/keep.txt', 'keep');
		$fallback = $uploads . '/intersoccer-pv-repair-backups';
		mkdir($fallback, 0777, true);
		file_put_contents($fallback . '/player.json', '{"player_first_name":"Ada"}');
		$GLOBALS['intersoccer_pv_test_upload_basedir'] = $uploads;
		intersoccer_pv_repair_uninstall();
		$this->assertFileDoesNotExist($this->backupDir . '/' . $name);
		$this->assertDirectoryDoesNotExist($this->backupDir);
		$this->assertFileDoesNotExist($fallback . '/player.json');
		$this->assertDirectoryDoesNotExist($fallback);
		$this->assertFileExists($uploads . '/keep.txt');
	}

	public function test_uninstall_removes_cap_and_repair_options_only(): void {
		update_option('intersoccer_pv_repair_run_log', [['tool' => 'late_pickup']]);
		update_option('intersoccer_rr_repair_run_log', ['keep-rr']);
		update_option('_transient_intersoccer_pv_repair_preview_1', 'drop-me');
		update_option('_transient_intersoccer_rr_repair_preview_1', 'keep-rr-transient');
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
		$this->assertFalse(array_key_exists('_transient_intersoccer_pv_repair_preview_1', $GLOBALS['intersoccer_test_options'] ?? []));
		$this->assertSame(['keep-rr'], get_option('intersoccer_rr_repair_run_log'));
		$this->assertSame('keep-rr-transient', get_option('_transient_intersoccer_rr_repair_preview_1'));
		$this->assertSame(['keep'], get_option('intersoccer_discount_rules'));
	}

	public function test_uninstall_leaves_the_shared_cap_while_rosters_is_active(): void {
		$admin = new InterSoccer_Pv_Test_Role();
		$admin->add_cap('intersoccer_run_repairs', true);
		$GLOBALS['intersoccer_pv_test_roles'] = ['administrator' => $admin];
		$GLOBALS['intersoccer_pv_test_rr_active'] = true;
		update_option('intersoccer_rr_repair_run_log', ['keep-rr']);
		update_option('intersoccer_pv_repair_run_log', ['drop']);
		intersoccer_pv_repair_uninstall();
		$this->assertTrue($admin->has_cap('intersoccer_run_repairs'));
		$this->assertSame(['keep-rr'], get_option('intersoccer_rr_repair_run_log'));
		$this->assertFalse(array_key_exists('intersoccer_pv_repair_run_log', $GLOBALS['intersoccer_test_options'] ?? []));
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
		$this->assertStringContainsString('was enabled', $applied['message']);
		$this->assertStringNotContainsString('would be enabled', $applied['message']);
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
		$this->assertStringContainsString('interrupted', $partial['message']);
		$log_partial = intersoccer_pv_repair_log_entries();
		$this->assertStringContainsString('interrupted', (string) ($log_partial[count($log_partial) - 1]['note'] ?? ''));
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

	public function test_real_refresh_attributes_keeps_existing_values_and_fills_empty_slots(): void {
		$this->loadProductTypes();
		$open = new class {
			public $attrs = [
				'pa_age-group' => 'full-day',
				'pa_course-day' => '',
			];
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
			public function is_type($type) {
				return $type === 'variation';
			}
		};
		$running = new class {
			public $attrs = ['pa_course-day' => ''];
			public function get_attributes() {
				return $this->attrs;
			}
			public function set_attributes($attributes) {
				$this->attrs = $attributes;
			}
			public function save() {
			}
			public function get_id() {
				return 78;
			}
			public function is_type($type) {
				return $type === 'variation';
			}
		};
		$GLOBALS['intersoccer_test_products'][10] = new class($open, $running) {
			private $open;
			private $running;
			public function __construct($open, $running) {
				$this->open = $open;
				$this->running = $running;
			}
			public function get_children() {
				return [77, 78];
			}
			public function get_attributes() {
				return [];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_id() {
				return 10;
			}
		};
		$GLOBALS['intersoccer_test_products'][77] = $open;
		$GLOBALS['intersoccer_test_products'][78] = $running;
		MockMetaData::$data[10]['_intersoccer_product_type'] = 'course';
		MockMetaData::$data[77]['_course_start_date'] = '2099-09-01';
		MockMetaData::$data[77]['_course_total_weeks'] = '';
		MockMetaData::$data[78]['_course_start_date'] = '2000-01-03';
		MockMetaData::$data[78]['_end_date'] = '2099-06-01';
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_10']);

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '10',
		]);
		$this->assertSame('full-day', $open->attrs['pa_age-group']);
		$this->assertSame('', $open->attrs['pa_course-day']);
		$this->assertSame('', (string) get_post_meta(77, '_course_total_weeks', true));
		$ids = array_map(static function ($row) {
			return (string) ($row['id'] ?? '');
		}, $preview['result']['rows']);
		$this->assertNotContains('attrs:78', $ids);
		$this->assertNotContains('meta:78:_course_total_weeks', $ids);
		$attr_row = null;
		foreach ($preview['result']['rows'] as $row) {
			if (($row['id'] ?? '') === 'attrs:77') {
				$attr_row = $row;
			}
		}
		$this->assertIsArray($attr_row);
		$this->assertSame('monday', $attr_row['after']['attributes']['pa_course-day']);
		$this->assertArrayNotHasKey('pa_age-group', $attr_row['after']['attributes']);
		$this->assertSame('2099-09-01', (string) get_post_meta(77, '_course_start_date', true));

		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '10',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type']);
		$this->assertSame(count($preview['result']['rows']), count($applied['result']['rows']));
		$this->assertSame('full-day', $open->attrs['pa_age-group']);
		$this->assertSame('monday', $open->attrs['pa_course-day']);
		$this->assertSame('', (string) get_post_meta(77, '_course_total_weeks', true));
		$this->assertSame('2099-09-01', (string) get_post_meta(77, '_course_start_date', true));
		$this->assertSame('', $running->attrs['pa_course-day']);
		$manual_ids = [];
		foreach ($applied['result']['rows'] as $row) {
			if (!empty($row['needs_manual'])) {
				$manual_ids[] = (string) ($row['id'] ?? '');
			}
		}
		$this->assertContains('manual:meta:77:_course_total_weeks', $manual_ids);
		$this->assertNotContains('manual:meta:77:_course_start_date', $manual_ids);
	}

	public function test_refresh_attributes_lists_missing_course_meta_and_any_attributes_as_needs_manual(): void {
		$this->loadProductTypes();
		$course = new class {
			public $attrs = [
				'pa_course-day' => 'monday',
				'pa_age-group' => 'full-day',
			];
			public function get_attributes() {
				return $this->attrs;
			}
			public function set_attributes($attributes) {
				$this->attrs = $attributes;
			}
			public function save() {
			}
			public function get_id() {
				return 79;
			}
			public function is_type($type) {
				return $type === 'variation';
			}
		};
		$camp = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '0900-1200',
				'pa_booking-type' => '',
				'pa_girls-only' => '',
			];
			public function get_attributes() {
				return $this->attrs;
			}
			public function set_attributes($attributes) {
				$this->attrs = $attributes;
			}
			public function save() {
			}
			public function get_id() {
				return 81;
			}
			public function is_type($type) {
				return $type === 'variation';
			}
		};
		$GLOBALS['intersoccer_test_products'][16] = new class($course) {
			private $course;
			public function __construct($course) {
				$this->course = $course;
			}
			public function get_children() {
				return [79];
			}
			public function get_attributes() {
				return [];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_id() {
				return 16;
			}
		};
		$GLOBALS['intersoccer_test_products'][18] = new class($camp) {
			private $camp;
			public function __construct($camp) {
				$this->camp = $camp;
			}
			public function get_children() {
				return [81];
			}
			public function get_attributes() {
				return [];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_id() {
				return 18;
			}
		};
		$GLOBALS['intersoccer_test_products'][79] = $course;
		$GLOBALS['intersoccer_test_products'][81] = $camp;
		MockMetaData::$data[16]['_intersoccer_product_type'] = 'course';
		MockMetaData::$data[18]['_intersoccer_product_type'] = 'camp';
		unset(
			$GLOBALS['intersoccer_test_transients']['intersoccer_type_16'],
			$GLOBALS['intersoccer_test_transients']['intersoccer_type_18']
		);

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '16',
		]);
		$ids = array_map(static function ($row) {
			return (string) ($row['id'] ?? '');
		}, $preview['result']['rows']);
		$this->assertContains('manual:meta:79:_course_start_date', $ids);
		$this->assertContains('manual:meta:79:_course_total_weeks', $ids);
		$this->assertContains('manual:meta:79:_course_holiday_dates', $ids);
		$this->assertNotContains('attrs:79', $ids);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '16',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertTrue(
			$applied['type'] === 'success' || strpos($applied['message'], 'no changes') !== false,
			$applied['message']
		);
		$this->assertSame('', (string) get_post_meta(79, '_course_start_date', true));
		$this->assertSame('', (string) get_post_meta(79, '_course_total_weeks', true));
		$this->assertSame('', (string) get_post_meta(79, '_course_holiday_dates', true));
		$this->assertSame('monday', $course->attrs['pa_course-day']);
		$this->assertSame('full-day', $course->attrs['pa_age-group']);

		$camp_preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '18',
		]);
		$camp_ids = array_map(static function ($row) {
			return (string) ($row['id'] ?? '');
		}, $camp_preview['result']['rows']);
		$this->assertContains('manual:attr:81:pa_girls-only', $camp_ids);
		$this->assertContains('attrs:81', $camp_ids);
		$this->assertSame('', $camp->attrs['pa_booking-type']);
		$this->assertSame('', $camp->attrs['pa_girls-only']);
		$this->assertSame('5-13y-full-day', $camp->attrs['pa_age-group']);
		$this->assertSame('0900-1200', $camp->attrs['pa_camp-times']);
		$camp_applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'refresh_attributes',
			'product_id' => '18',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $camp_applied['type'], $camp_applied['message']);
		$this->assertSame('full-week', $camp->attrs['pa_booking-type']);
		$this->assertSame('', $camp->attrs['pa_girls-only']);
		$this->assertSame('5-13y-full-day', $camp->attrs['pa_age-group']);
		$this->assertSame('0900-1200', $camp->attrs['pa_camp-times']);
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

	public function test_product_tools_has_no_course_holiday_fix_card(): void {
		$_GET['tab'] = 'product-tools';
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$html = ob_get_clean();
		$this->assertStringNotContainsString('Course Holiday Fix', $html);
		$this->assertStringNotContainsString('not available in this release', $html);
		$this->assertStringNotContainsString('value="course_holiday_fix"', $html);
		$this->assertStringNotContainsString('intersoccer-pm-course-holiday-fix-btn', $html);
		$this->assertStringContainsString('value="refresh_attributes"', $html);
		$result = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'course_holiday_fix',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_global' => 'GLOBAL',
		]);
		$this->assertStringContainsString('Unknown repair', $result['message']);
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_writes']);
	}

	public function test_scan_tab_get_does_not_preview(): void {
		if (!function_exists('checked')) {
			function checked($checked, $current = true, $display = true) {
				$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
				if ($display) {
					echo $result;
				}
				return $result;
			}
		}
		if (!function_exists('selected')) {
			function selected($selected, $current = true, $display = true) {
				$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
				if ($display) {
					echo $result;
				}
				return $result;
			}
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_render_update_orders_page')) {
			require_once dirname(__DIR__) . '/includes/woocommerce/admin-ui.php';
		}
		$GLOBALS['intersoccer_pv_test_scan_queries'] = 0;
		$GLOBALS['intersoccer_test_wc_get_orders'] = static function () {
			$GLOBALS['intersoccer_pv_test_scan_queries']++;
			return [];
		};
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET = ['tab' => 'scan', 'preview_updates' => '1', 'detailed_preview' => '1'];
		$_REQUEST = $_GET;
		ob_start();
		intersoccer_pv_render_advanced_tools_page();
		$html = ob_get_clean();
		$this->assertSame(0, $GLOBALS['intersoccer_pv_test_scan_queries']);
		$this->assertStringNotContainsString('<h2>Orders Missing Metadata</h2>', $html);
	}

	public function test_order_item_rollback_deletes_only_keys_apply_added(): void {
		$this->loadOrderRepairStack();
		$item = $this->makeOrderItem();
		$item->meta['Player Note'] = 'keep-me';
		$item->meta['Activity Type'] = 'Camp';
		$GLOBALS['intersoccer_test_orders'][9001] = $this->makeOrder($item);
		$filename = intersoccer_pv_repair_write_backup('automated_batch', 1, [[
			'id' => 'order-item:9001:31',
			'before' => [],
			'after' => ['Activity Type' => 'Camp'],
		]]);
		$restored = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored 1', $restored['message']);
		$this->assertSame('', (string) $item->get_meta('Activity Type', true));
		$this->assertSame('keep-me', (string) $item->get_meta('Player Note', true));
	}

	public function test_real_venue_repair_preview_matches_apply_and_rollback_keeps_other_attributes(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() {
				return $this->attrs;
			}
			public function set_attributes($attributes) {
				$this->attrs = $attributes;
			}
			public function save() {
			}
			public function get_id() {
				return 881;
			}
			public function is_type($type) {
				return $type === 'variation';
			}
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() {
				return 880;
			}
			public function get_children() {
				return [881];
			}
			public function is_type($type) {
				return $type === 'variable';
			}
			public function get_attributes() {
				return $this->attributes;
			}
			public function set_attributes($attributes) {
				$this->attributes = $attributes;
			}
			public function save() {
			}
		};
		$GLOBALS['intersoccer_test_products'][880] = $parent;
		$GLOBALS['intersoccer_test_products'][881] = $variation;
		$GLOBALS['intersoccer_test_terms'][8801] = [
			'name' => 'Nyon',
			'slug' => 'nyon',
			'taxonomy' => 'pa_intersoccer-venues',
		];
		$GLOBALS['intersoccer_test_terms'][8802] = [
			'name' => 'Autumn Week 1',
			'slug' => 'autumn-week-1',
			'taxonomy' => 'pa_camp-terms',
		];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_camp-terms'] = ['autumn-week-1'];
		MockMetaData::$data[880]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[881]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_880']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
	public $attrs = [];
	private $id;
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function set_attributes($attributes) { $this->attrs = $attributes; }
	public function save() { return $this->id; }
	public function is_type($type) { return $type === 'variation'; }
}
PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(882);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][882] = $twin;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			return $id === 882 ? 'fr' : 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($type, $return_original);
			if ((int) $id === 881 && $lang === 'fr') {
				return 882;
			}
			return 0;
		};

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
		]);
		$this->assertSame('', $variation->attrs['pa_intersoccer-venues']);
		$this->assertNotEmpty($preview['result']['rows']);
		$preview_ids = array_column($preview['result']['rows'], 'id');
		$this->assertContains('parent-attr:880:pa_intersoccer-venues', $preview_ids);
		$this->assertContains('attrs:881', $preview_ids);

		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertSame(count($preview['result']['rows']), count($applied['result']['rows']));
		$this->assertSame(
			intersoccer_pv_repair_rows_fingerprint($preview['result']['rows']),
			intersoccer_pv_repair_rows_fingerprint($applied['result']['rows'])
		);
		$this->assertSame('nyon', $variation->attrs['pa_intersoccer-venues']);
		$this->assertSame('autumn-week-1', $variation->attrs['pa_camp-terms']);
		$this->assertSame('5-13y-full-day', $variation->attrs['pa_age-group']);
		$this->assertSame('1000-1700', $variation->attrs['pa_camp-times']);
		$this->assertSame('nyon', (string) get_post_meta(881, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('autumn-week-1', (string) get_post_meta(881, 'attribute_pa_camp-terms', true));
		$this->assertContains('nyon', (array) ($GLOBALS['intersoccer_test_product_terms'][881]['pa_intersoccer-venues'] ?? []));
		$this->assertContains('autumn-week-1', (array) ($GLOBALS['intersoccer_test_product_terms'][881]['pa_camp-terms'] ?? []));
		$this->assertContains('manual:twin:882:pa_intersoccer-venues', $preview_ids);
		$this->assertContains('manual:twin:882:pa_camp-terms', $preview_ids);
		$this->assertSame('', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame('', $twin->attrs['pa_camp-terms']);
		$this->assertSame('5-8y', $twin->attrs['pa_age-group']);
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_camp-terms', true));
		$this->assertNotContains('nyon', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
		$this->assertNotContains('autumn-week-1', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_camp-terms'] ?? []));

		$log = intersoccer_pv_repair_log_entries();
		$filename = (string) $log[count($log) - 1]['backup'];
		$raw = json_decode((string) file_get_contents($this->backupDir . '/' . $filename), true);
		$backup_ids = array_column($raw['rows'], 'id');
		$this->assertContains('parent-attr:880:pa_intersoccer-venues', $backup_ids);
		$this->assertContains('attrs:881', $backup_ids);

		$rolled = $this->postTool('rollback', [
			'intersoccer_pv_repair_tool' => 'rollback',
			'intersoccer_pv_repair_phrase' => 'APPLY',
			'intersoccer_pv_repair_backup' => $filename,
		]);
		$this->assertStringContainsString('restored', $rolled['message']);
		$this->assertSame('5-13y-full-day', $variation->attrs['pa_age-group']);
		$this->assertSame('1000-1700', $variation->attrs['pa_camp-times']);
		$this->assertArrayHasKey('pa_age-group', $variation->attrs);
		$this->assertSame('', $variation->attrs['pa_intersoccer-venues']);
		$this->assertSame('', $variation->attrs['pa_camp-terms']);
		$this->assertSame('', (string) get_post_meta(881, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('', (string) get_post_meta(881, 'attribute_pa_camp-terms', true));
		$this->assertSame([], (array) ($GLOBALS['intersoccer_test_product_terms'][881]['pa_intersoccer-venues'] ?? []));
		$this->assertSame([], (array) ($GLOBALS['intersoccer_test_product_terms'][881]['pa_camp-terms'] ?? []));
		$this->assertSame('', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame('', $twin->attrs['pa_camp-terms']);
		$this->assertSame('5-8y', $twin->attrs['pa_age-group']);
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_camp-terms', true));
		$this->assertSame([], (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
		$this->assertSame([], (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_camp-terms'] ?? []));
	}


	public function test_venue_repair_writes_translated_terms_on_wpml_twins(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() { return $this->attrs; }
			public function set_attributes($attributes) { $this->attrs = $attributes; }
			public function save() {}
			public function get_id() { return 881; }
			public function is_type($type) { return $type === 'variation'; }
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() { return 880; }
			public function get_children() { return [881]; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_attributes() { return $this->attributes; }
			public function set_attributes($attributes) { $this->attributes = $attributes; }
			public function save() {}
		};
		$GLOBALS['intersoccer_test_products'][880] = $parent;
		$GLOBALS['intersoccer_test_products'][881] = $variation;
		$GLOBALS['intersoccer_test_terms'][8801] = ['name' => 'Nyon', 'slug' => 'nyon', 'taxonomy' => 'pa_intersoccer-venues'];
		$GLOBALS['intersoccer_test_terms'][8802] = ['name' => 'Autumn Week 1', 'slug' => 'autumn-week-1', 'taxonomy' => 'pa_camp-terms'];
		$GLOBALS['intersoccer_test_terms'][8803] = ['name' => 'Nyon FR', 'slug' => 'nyon-fr', 'taxonomy' => 'pa_intersoccer-venues'];
		$GLOBALS['intersoccer_test_terms'][8804] = ['name' => 'Automne semaine 1', 'slug' => 'automne-semaine-1', 'taxonomy' => 'pa_camp-terms'];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_camp-terms'] = ['autumn-week-1'];
		$GLOBALS['intersoccer_test_term_translations']['pa_intersoccer-venues']['nyon'] = ['en' => 'nyon', 'fr' => 'nyon-fr'];
		$GLOBALS['intersoccer_test_term_translations']['pa_camp-terms']['autumn-week-1'] = ['en' => 'autumn-week-1', 'fr' => 'automne-semaine-1'];
		MockMetaData::$data[880]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[881]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_880']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
	public $attrs = [];
	private $id;
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function set_attributes($attributes) { $this->attrs = $attributes; }
	public function save() { return $this->id; }
	public function is_type($type) { return $type === 'variation'; }
}
PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(882);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][882] = $twin;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			return $id === 882 ? 'fr' : 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($return_original);
			if ((int) $id === 881 && $lang === 'fr') {
				return 882;
			}
			if ((int) $id === 8801 && $lang === 'fr') {
				return 8803;
			}
			if ((int) $id === 8802 && $lang === 'fr') {
				return 8804;
			}
			return 0;
		};
		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
		]);
		$preview_ids = array_column($preview['result']['rows'], 'id');
		$this->assertContains('attrs:881', $preview_ids);
		$this->assertNotContains('manual:twin:882:pa_intersoccer-venues', $preview_ids);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertSame('nyon', $variation->attrs['pa_intersoccer-venues']);
		$this->assertSame('autumn-week-1', $variation->attrs['pa_camp-terms']);
		$this->assertSame('nyon-fr', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame('automne-semaine-1', $twin->attrs['pa_camp-terms']);
		$this->assertSame('5-8y', $twin->attrs['pa_age-group']);
		$this->assertSame('nyon-fr', (string) get_post_meta(882, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('automne-semaine-1', (string) get_post_meta(882, 'attribute_pa_camp-terms', true));
		$this->assertContains('nyon-fr', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
		$this->assertContains('automne-semaine-1', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_camp-terms'] ?? []));
		$this->assertNotContains('nyon', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
	}

	public function test_venue_repair_needs_manual_when_twin_language_is_unknown(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() { return $this->attrs; }
			public function set_attributes($attributes) { $this->attrs = $attributes; }
			public function save() {}
			public function get_id() { return 881; }
			public function is_type($type) { return $type === 'variation'; }
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() { return 880; }
			public function get_children() { return [881]; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_attributes() { return $this->attributes; }
			public function set_attributes($attributes) { $this->attributes = $attributes; }
			public function save() {}
		};
		$GLOBALS['intersoccer_test_products'][880] = $parent;
		$GLOBALS['intersoccer_test_products'][881] = $variation;
		$GLOBALS['intersoccer_test_terms'][8801] = ['name' => 'Nyon', 'slug' => 'nyon', 'taxonomy' => 'pa_intersoccer-venues'];
		$GLOBALS['intersoccer_test_terms'][8802] = ['name' => 'Autumn Week 1', 'slug' => 'autumn-week-1', 'taxonomy' => 'pa_camp-terms'];
		$GLOBALS['intersoccer_test_terms'][8803] = ['name' => 'Nyon FR', 'slug' => 'nyon-fr', 'taxonomy' => 'pa_intersoccer-venues'];
		$GLOBALS['intersoccer_test_terms'][8804] = ['name' => 'Automne semaine 1', 'slug' => 'automne-semaine-1', 'taxonomy' => 'pa_camp-terms'];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][880]['pa_camp-terms'] = ['autumn-week-1'];
		$GLOBALS['intersoccer_test_product_terms'][881] = [];
		$GLOBALS['intersoccer_test_product_terms'][882] = [];
		$GLOBALS['intersoccer_test_term_translations']['pa_intersoccer-venues']['nyon'] = ['en' => 'nyon', 'fr' => 'nyon-fr'];
		$GLOBALS['intersoccer_test_term_translations']['pa_camp-terms']['autumn-week-1'] = ['en' => 'autumn-week-1', 'fr' => 'automne-semaine-1'];
		MockMetaData::$data[880]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[881]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_880']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
	class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
		public $attrs = [];
		private $id;
		public function __construct($id) { $this->id = (int) $id; }
		public function get_id() { return $this->id; }
		public function get_attributes() { return $this->attrs; }
		public function set_attributes($attributes) { $this->attrs = $attributes; }
		public function save() { return $this->id; }
		public function is_type($type) { return $type === 'variation'; }
	}
	PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(882);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][882] = $twin;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			$type = is_array($args) ? (string) ($args['element_type'] ?? '') : '';
			if ($id === 882) {
				return null;
			}
			if (strpos($type, 'tax_') === 0) {
				return 'en';
			}
			return $id === 881 || $id === 880 ? 'en' : null;
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($return_original);
			if ((int) $id === 881 && $lang === 'fr') {
				return 882;
			}
			if ((int) $id === 8801 && $lang === 'fr') {
				return 8803;
			}
			if ((int) $id === 8802 && $lang === 'fr') {
				return 8804;
			}
			if ($lang === 'en' || $lang === null || $lang === '') {
				return (int) $id;
			}
			return 0;
		};
		$this->assertNull(intersoccer_translated_taxonomy_term_slug('nyon', 'pa_intersoccer-venues', 882));
		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
		]);
		$preview_ids = array_column($preview['result']['rows'] ?? [], 'id');
		$this->assertContains('manual:twin:882:pa_intersoccer-venues', $preview_ids);
		$this->assertContains('manual:twin:882:pa_camp-terms', $preview_ids);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '880',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertSame('nyon', $variation->attrs['pa_intersoccer-venues']);
		$this->assertSame('autumn-week-1', $variation->attrs['pa_camp-terms']);
		$this->assertSame('', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame('', $twin->attrs['pa_camp-terms']);
		$this->assertSame('5-8y', $twin->attrs['pa_age-group']);
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame('', (string) get_post_meta(882, 'attribute_pa_camp-terms', true));
		$this->assertNotContains('nyon', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
		$this->assertNotContains('nyon-fr', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_intersoccer-venues'] ?? []));
		$this->assertNotContains('autumn-week-1', (array) ($GLOBALS['intersoccer_test_product_terms'][882]['pa_camp-terms'] ?? []));
	}


	public function test_venue_repair_writes_shared_slug_camp_term_by_id_on_twins(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		$camp_slug = 'easter-week-2-march-30-april-2-4-days';
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() { return $this->attrs; }
			public function set_attributes($attributes) { $this->attrs = $attributes; }
			public function save() {}
			public function get_id() { return 981; }
			public function is_type($type) { return $type === 'variation'; }
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() { return 980; }
			public function get_children() { return [981]; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_attributes() { return $this->attributes; }
			public function set_attributes($attributes) { $this->attributes = $attributes; }
			public function save() {}
		};
		$GLOBALS['intersoccer_test_products'][980] = $parent;
		$GLOBALS['intersoccer_test_products'][981] = $variation;
		$GLOBALS['intersoccer_test_terms'][350] = ['name' => 'Nyon', 'slug' => 'nyon', 'taxonomy' => 'pa_intersoccer-venues', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][359] = ['name' => 'Nyon FR', 'slug' => 'nyon-fr', 'taxonomy' => 'pa_intersoccer-venues', 'wpml_lang' => 'fr'];
		$GLOBALS['intersoccer_test_terms'][997] = ['name' => 'Easter Week 2 EN', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][1022] = ['name' => 'Easter Week 2 FR', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'fr'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_camp-terms'] = [$camp_slug];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_intersoccer-venues'] = [350];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_camp-terms'] = [997];
		$GLOBALS['intersoccer_test_term_translations']['pa_intersoccer-venues']['nyon'] = ['en' => 'nyon', 'fr' => 'nyon-fr'];
		$GLOBALS['intersoccer_test_term_translations']['pa_camp-terms'][$camp_slug] = ['en' => $camp_slug, 'fr' => $camp_slug];
		$GLOBALS['intersoccer_test_term_translation_ids']['pa_intersoccer-venues']['nyon'] = ['en' => 350, 'fr' => 359];
		$GLOBALS['intersoccer_test_term_translation_ids']['pa_camp-terms'][$camp_slug] = ['en' => 997, 'fr' => 1022];
		MockMetaData::$data[980]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[981]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_980']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
	public $attrs = [];
	private $id;
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function set_attributes($attributes) { $this->attrs = $attributes; }
	public function save() { return $this->id; }
	public function is_type($type) { return $type === 'variation'; }
}
PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(982);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][982] = $twin;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			$map = [982 => 'fr', 359 => 'fr', 1022 => 'fr'];
			return $map[$id] ?? 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($type, $return_original);
			if ((int) $id === 981 && $lang === 'fr') {
				return 982;
			}
			if ((int) $id === 350 && $lang === 'fr') {
				return 359;
			}
			if ((int) $id === 997 && $lang === 'fr') {
				return 1022;
			}
			return 0;
		};

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '980',
		]);
		$preview_ids = array_column($preview['result']['rows'] ?? [], 'id');
		$this->assertContains('attrs:981', $preview_ids);
		$this->assertNotContains('manual:twin:982:pa_intersoccer-venues', $preview_ids);
		$this->assertNotContains('manual:twin:982:pa_camp-terms', $preview_ids);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '980',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertSame('nyon-fr', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame($camp_slug, $twin->attrs['pa_camp-terms']);
		$this->assertSame('nyon-fr', (string) get_post_meta(982, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame($camp_slug, (string) get_post_meta(982, 'attribute_pa_camp-terms', true));
		$this->assertSame([359], array_values(array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_intersoccer-venues'] ?? []))));
		$this->assertSame([1022], array_values(array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_camp-terms'] ?? []))));
		$this->assertNotContains(997, array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_camp-terms'] ?? [])));
		$this->assertSame([997], array_values(array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][981]['pa_camp-terms'] ?? []))));
	}

	public function test_venue_repair_shared_slug_camp_term_without_fr_venue_does_not_add_en_term(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		$camp_slug = 'easter-week-2-march-30-april-2-4-days';
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() { return $this->attrs; }
			public function set_attributes($attributes) { $this->attrs = $attributes; }
			public function save() {}
			public function get_id() { return 981; }
			public function is_type($type) { return $type === 'variation'; }
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() { return 980; }
			public function get_children() { return [981]; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_attributes() { return $this->attributes; }
			public function set_attributes($attributes) { $this->attributes = $attributes; }
			public function save() {}
		};
		$GLOBALS['intersoccer_test_products'][980] = $parent;
		$GLOBALS['intersoccer_test_products'][981] = $variation;
		$GLOBALS['intersoccer_test_terms'][350] = ['name' => 'Nyon', 'slug' => 'nyon', 'taxonomy' => 'pa_intersoccer-venues', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][997] = ['name' => 'Easter Week 2 EN', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][1022] = ['name' => 'Easter Week 2 FR', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'fr'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_camp-terms'] = [$camp_slug];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_intersoccer-venues'] = [350];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_camp-terms'] = [997];
		$GLOBALS['intersoccer_test_term_translations']['pa_intersoccer-venues']['nyon'] = ['en' => 'nyon'];
		$GLOBALS['intersoccer_test_term_translations']['pa_camp-terms'][$camp_slug] = ['en' => $camp_slug, 'fr' => $camp_slug];
		$GLOBALS['intersoccer_test_term_translation_ids']['pa_camp-terms'][$camp_slug] = ['en' => 997, 'fr' => 1022];
		MockMetaData::$data[980]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[981]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_980']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
	public $attrs = [];
	private $id;
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function set_attributes($attributes) { $this->attrs = $attributes; }
	public function save() { return $this->id; }
	public function is_type($type) { return $type === 'variation'; }
}
PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(982);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][982] = $twin;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			return $id === 982 || $id === 1022 ? 'fr' : 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($type, $return_original);
			if ((int) $id === 981 && $lang === 'fr') {
				return 982;
			}
			if ((int) $id === 997 && $lang === 'fr') {
				return 1022;
			}
			return 0;
		};

		$preview = $this->postTool('preview', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '980',
		]);
		$preview_ids = array_column($preview['result']['rows'] ?? [], 'id');
		$this->assertContains('manual:twin:982:pa_intersoccer-venues', $preview_ids);
		$this->assertNotContains('manual:twin:982:pa_camp-terms', $preview_ids);
		$applied = $this->postTool('apply', [
			'intersoccer_pv_repair_tool' => 'repair_venue',
			'product_id' => '980',
			'intersoccer_pv_repair_phrase' => 'APPLY',
		]);
		$this->assertSame('success', $applied['type'], $applied['message']);
		$this->assertSame('', $twin->attrs['pa_intersoccer-venues']);
		$this->assertSame('', (string) get_post_meta(982, 'attribute_pa_intersoccer-venues', true));
		$this->assertSame($camp_slug, $twin->attrs['pa_camp-terms']);
		$this->assertSame($camp_slug, (string) get_post_meta(982, 'attribute_pa_camp-terms', true));
		$this->assertSame([1022], array_values(array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_camp-terms'] ?? []))));
		$this->assertNotContains(997, array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_camp-terms'] ?? [])));
		$this->assertSame([], array_values(array_map('intval', (array) ($GLOBALS['intersoccer_test_product_term_ids'][982]['pa_intersoccer-venues'] ?? []))));
	}

	public function test_venue_rollback_restores_with_admin_language_bar_on_en_fr_and_all(): void {
		$this->loadProductTypes();
		$this->installVenueRepairStubs();
		if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
			define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
		}
		if (!function_exists('intersoccer_pm_infer_camp_term_slug_for_variation')) {
			require_once dirname(__DIR__) . '/includes/helpers.php';
		}
		if (!function_exists('intersoccer_translated_taxonomy_term_slug')) {
			require_once dirname(__DIR__) . '/includes/language-helpers.php';
		}
		if (!class_exists('WP_List_Table', false)) {
			eval('class WP_List_Table { public function __construct($args = []) {} }');
		}
		if (!class_exists('InterSoccer_Program_Manager', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/program-manager.php';
		}
		$camp_slug = 'easter-week-2-march-30-april-2-4-days';
		$variation = new class {
			public $attrs = [
				'pa_age-group' => '5-13y-full-day',
				'pa_camp-times' => '1000-1700',
				'pa_intersoccer-venues' => '',
				'pa_camp-terms' => '',
			];
			public function get_attributes() { return $this->attrs; }
			public function set_attributes($attributes) { $this->attrs = $attributes; }
			public function save() {}
			public function get_id() { return 981; }
			public function is_type($type) { return $type === 'variation'; }
		};
		$parent = new class {
			public $attributes = [];
			public function get_id() { return 980; }
			public function get_children() { return [981]; }
			public function is_type($type) { return $type === 'variable'; }
			public function get_attributes() { return $this->attributes; }
			public function set_attributes($attributes) { $this->attributes = $attributes; }
			public function save() {}
		};
		$GLOBALS['intersoccer_test_products'][980] = $parent;
		$GLOBALS['intersoccer_test_products'][981] = $variation;
		$GLOBALS['intersoccer_test_terms'][350] = ['name' => 'Nyon', 'slug' => 'nyon', 'taxonomy' => 'pa_intersoccer-venues', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][359] = ['name' => 'Nyon FR', 'slug' => 'nyon-fr', 'taxonomy' => 'pa_intersoccer-venues', 'wpml_lang' => 'fr'];
		$GLOBALS['intersoccer_test_terms'][997] = ['name' => 'Easter Week 2 EN', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'en'];
		$GLOBALS['intersoccer_test_terms'][1022] = ['name' => 'Easter Week 2 FR', 'slug' => $camp_slug, 'taxonomy' => 'pa_camp-terms', 'wpml_lang' => 'fr'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_intersoccer-venues'] = ['nyon'];
		$GLOBALS['intersoccer_test_product_terms'][980]['pa_camp-terms'] = [$camp_slug];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_intersoccer-venues'] = [350];
		$GLOBALS['intersoccer_test_product_term_ids'][980]['pa_camp-terms'] = [997];
		$GLOBALS['intersoccer_test_term_translations']['pa_intersoccer-venues']['nyon'] = ['en' => 'nyon', 'fr' => 'nyon-fr'];
		$GLOBALS['intersoccer_test_term_translations']['pa_camp-terms'][$camp_slug] = ['en' => $camp_slug, 'fr' => $camp_slug];
		$GLOBALS['intersoccer_test_term_translation_ids']['pa_intersoccer-venues']['nyon'] = ['en' => 350, 'fr' => 359];
		$GLOBALS['intersoccer_test_term_translation_ids']['pa_camp-terms'][$camp_slug] = ['en' => 997, 'fr' => 1022];
		MockMetaData::$data[980]['_intersoccer_product_type'] = 'camp';
		MockMetaData::$data[981]['_camp_week_index'] = 1;
		unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_980']);
		if (!class_exists('WC_Product_Variation', false)) {
			eval('class WC_Product_Variation { public function get_attributes() { return []; } public function set_attributes($attributes) {} public function save() { return 0; } }');
		}
		if (!class_exists('PvAdvancedToolsTwinVariation', false)) {
			eval(<<<'PHP'
class PvAdvancedToolsTwinVariation extends WC_Product_Variation {
	public $attrs = [];
	private $id;
	public function __construct($id) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_attributes() { return $this->attrs; }
	public function set_attributes($attributes) { $this->attrs = $attributes; }
	public function save() { return $this->id; }
	public function is_type($type) { return $type === 'variation'; }
}
PHP
			);
		}
		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', 'test');
		}
		$twin = new PvAdvancedToolsTwinVariation(982);
		$twin->attrs = [
			'pa_age-group' => '5-8y',
			'pa_intersoccer-venues' => '',
			'pa_camp-terms' => '',
		];
		$GLOBALS['intersoccer_test_products'][982] = $twin;
		$admin_lang = 'en';
		$GLOBALS['intersoccer_test_wpml_current_language'] = $admin_lang;
		MockFilters::$filters['wpml_active_languages'] = static function () {
			return ['en' => [], 'fr' => []];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($empty = null, $args = []) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			$map = [982 => 'fr', 359 => 'fr', 1022 => 'fr'];
			return $map[$id] ?? 'en';
		};
		MockFilters::$filters['wpml_current_language'] = static function () use (&$admin_lang) {
			return $admin_lang;
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) {
			unset($type, $return_original);
			if ((int) $id === 981 && $lang === 'fr') {
				return 982;
			}
			if ((int) $id === 350 && $lang === 'fr') {
				return 359;
			}
			if ((int) $id === 997 && $lang === 'fr') {
				return 1022;
			}
			return 0;
		};

		foreach (['en', 'fr', 'all'] as $lang) {
			$admin_lang = $lang;
			$GLOBALS['intersoccer_test_wpml_current_language'] = $lang;
			$parent->attributes = [];
			$variation->attrs['pa_intersoccer-venues'] = '';
			$variation->attrs['pa_camp-terms'] = '';
			$twin->attrs['pa_intersoccer-venues'] = '';
			$twin->attrs['pa_camp-terms'] = '';
			unset(MockMetaData::$data[981]['attribute_pa_intersoccer-venues'], MockMetaData::$data[981]['attribute_pa_camp-terms']);
			unset(MockMetaData::$data[982]['attribute_pa_intersoccer-venues'], MockMetaData::$data[982]['attribute_pa_camp-terms']);
			$GLOBALS['intersoccer_test_product_terms'][981] = [];
			$GLOBALS['intersoccer_test_product_terms'][982] = [];
			$GLOBALS['intersoccer_test_product_term_ids'][981] = [];
			$GLOBALS['intersoccer_test_product_term_ids'][982] = [];
			intersoccer_pv_repair_reset_state();

			$preview = $this->postTool('preview', [
				'intersoccer_pv_repair_tool' => 'repair_venue',
				'product_id' => '980',
			]);
			$this->assertSame('info', $preview['type'], $lang . ': ' . ($preview['message'] ?? ''));
			$applied = $this->postTool('apply', [
				'intersoccer_pv_repair_tool' => 'repair_venue',
				'product_id' => '980',
				'intersoccer_pv_repair_phrase' => 'APPLY',
			]);
			$this->assertSame('success', $applied['type'], $lang . ': ' . ($applied['message'] ?? ''));
			$this->assertSame('nyon-fr', (string) get_post_meta(982, 'attribute_pa_intersoccer-venues', true));
			// Simulate WCML filtering get_attributes() to the EN slug while raw meta stays FR.
			$twin->attrs['pa_intersoccer-venues'] = 'nyon';
			$twin->attrs['pa_camp-terms'] = $camp_slug;
			$log = intersoccer_pv_repair_log_entries();
			$filename = (string) $log[count($log) - 1]['backup'];
			$restored = $this->postTool('rollback', [
				'intersoccer_pv_repair_tool' => 'rollback',
				'intersoccer_pv_repair_phrase' => 'APPLY',
				'intersoccer_pv_repair_backup' => $filename,
			]);
			$this->assertSame('success', $restored['type'], $lang . ': ' . ($restored['message'] ?? ''));
			$this->assertMatchesRegularExpression('/restored [1-9]/', $restored['message'], $lang . ': ' . ($restored['message'] ?? ''));
			$this->assertStringContainsString('Conflicts left untouched: 0', $restored['message'], $lang . ': ' . ($restored['message'] ?? ''));
			$parent->attributes = [];
			$this->assertSame('', (string) get_post_meta(982, 'attribute_pa_intersoccer-venues', true), $lang);
			$this->assertSame('', $twin->attrs['pa_intersoccer-venues'], $lang);
		}
	}

	public function test_season_label_list_backups_does_not_create_the_directory(): void {
		if (!class_exists('InterSoccer_Order_Season_Label_Repair', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/order-season-label-repair.php';
		}
		$base = sys_get_temp_dir() . '/pv-season-get-' . getmypid() . '-' . uniqid('', true);
		$GLOBALS['intersoccer_test_season_label_backup_dir'] = $base . '/intersoccer-backups';
		$this->assertDirectoryDoesNotExist($GLOBALS['intersoccer_test_season_label_backup_dir']);
		$list = InterSoccer_Order_Season_Label_Repair::list_backups();
		$this->assertSame([], $list);
		$this->assertDirectoryDoesNotExist($GLOBALS['intersoccer_test_season_label_backup_dir']);
		unset($GLOBALS['intersoccer_test_season_label_backup_dir']);
		@rmdir($base);
	}

	public function test_course_date_list_backups_does_not_create_the_directory(): void {
		if (!class_exists('InterSoccer_Order_Season_Label_Repair', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/order-season-label-repair.php';
		}
		if (!class_exists('InterSoccer_Order_Course_Date_Repair', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/order-course-date-repair.php';
		}
		$base = sys_get_temp_dir() . '/pv-course-get-' . getmypid() . '-' . uniqid('', true);
		$GLOBALS['intersoccer_test_season_label_backup_dir'] = $base . '/intersoccer-backups';
		$this->assertDirectoryDoesNotExist($GLOBALS['intersoccer_test_season_label_backup_dir']);
		$list = InterSoccer_Order_Course_Date_Repair::list_backups();
		$this->assertSame([], $list);
		$this->assertDirectoryDoesNotExist($GLOBALS['intersoccer_test_season_label_backup_dir']);
		unset($GLOBALS['intersoccer_test_season_label_backup_dir']);
		@rmdir($base);
	}


	public function test_uninstall_detects_reports_plugin_by_main_file_not_folder(): void {
		unset($GLOBALS['intersoccer_pv_test_rr_active']);
		$GLOBALS['intersoccer_pv_test_active_plugins'] = [
			'reports-rosters-2-10-15/intersoccer-reports-rosters.php',
		];
		$this->assertTrue(intersoccer_pv_repair_reports_plugin_active());
		$GLOBALS['intersoccer_pv_test_active_plugins'] = [
			'unrelated/unrelated.php',
		];
		$this->assertFalse(intersoccer_pv_repair_reports_plugin_active());
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

	private function loadProductTypes(): void {
		if (!defined('HOUR_IN_SECONDS')) {
			define('HOUR_IN_SECONDS', 3600);
		}
		if (!class_exists('InterSoccer_Product_Types', false)) {
			require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
		}
	}

	private function installVenueRepairStubs(): void {
		if (!function_exists('wc_attribute_taxonomy_id_by_name')) {
			function wc_attribute_taxonomy_id_by_name($name) {
				unset($name);
				return 1;
			}
		}
		if (!function_exists('wc_delete_product_transients')) {
			function wc_delete_product_transients($product_id = 0) {
				unset($product_id);
				return true;
			}
		}
		if (!function_exists('wp_set_object_terms')) {
			function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) {
				unset($append);
				$stored = [];
				$ids = [];
				foreach ((array) $terms as $term) {
					if (is_numeric($term) && isset($GLOBALS['intersoccer_test_terms'][(int) $term])) {
						$row = $GLOBALS['intersoccer_test_terms'][(int) $term];
						$stored[] = (string) ($row['slug'] ?? $row['name'] ?? $term);
						$ids[] = (int) $term;
					} else {
						$stored[] = (string) $term;
						if (isset($GLOBALS['intersoccer_test_terms']) && is_array($GLOBALS['intersoccer_test_terms'])) {
							foreach ($GLOBALS['intersoccer_test_terms'] as $term_id => $row) {
								if (($row['taxonomy'] ?? '') === $taxonomy && (($row['slug'] ?? '') === (string) $term || ($row['name'] ?? '') === (string) $term)) {
									$ids[] = (int) $term_id;
									break;
								}
							}
						}
					}
				}
				$GLOBALS['intersoccer_test_product_terms'][(int) $object_id][$taxonomy] = $stored;
				$GLOBALS['intersoccer_test_product_term_ids'][(int) $object_id][$taxonomy] = $ids;
				return true;
			}
		}
		if (!function_exists('wp_get_object_terms')) {
			function wp_get_object_terms($object_id, $taxonomy, $args = []) {
				$fields = $args['fields'] ?? 'all';
				$ids = $GLOBALS['intersoccer_test_product_term_ids'][(int) $object_id][$taxonomy] ?? [];
				if ($fields === 'ids') {
					return array_values(array_map('intval', (array) $ids));
				}
				if ($fields === 'slugs') {
					return $GLOBALS['intersoccer_test_product_terms'][(int) $object_id][$taxonomy] ?? [];
				}
				$out = [];
				foreach ((array) $ids as $term_id) {
					$row = $GLOBALS['intersoccer_test_terms'][(int) $term_id] ?? null;
					if ($row) {
						$out[] = (object) [
							'term_id' => (int) $term_id,
							'slug' => (string) ($row['slug'] ?? ''),
							'name' => (string) ($row['name'] ?? ''),
							'taxonomy' => (string) ($row['taxonomy'] ?? $taxonomy),
						];
					}
				}
				if ($out === [] && !empty($GLOBALS['intersoccer_test_product_terms'][(int) $object_id][$taxonomy])) {
					foreach ((array) $GLOBALS['intersoccer_test_product_terms'][(int) $object_id][$taxonomy] as $slug) {
						$out[] = (object) [
							'term_id' => 0,
							'slug' => (string) $slug,
							'name' => (string) $slug,
							'taxonomy' => (string) $taxonomy,
						];
					}
				}
				return $out;
			}
		}
		if (!class_exists('WC_Product_Attribute', false)) {
			eval(<<<'PHP'
class WC_Product_Attribute {
	private $name;
	private $options = [];
	private $id = 0;
	private $visible = true;
	private $variation = false;
	public function get_name() { return $this->name; }
	public function set_name($name) { $this->name = $name; return $this; }
	public function get_options() { return $this->options; }
	public function set_options($options) { $this->options = $options; return $this; }
	public function get_id() { return $this->id; }
	public function set_id($id) { $this->id = $id; return $this; }
	public function set_visible($visible) { $this->visible = (bool) $visible; return $this; }
	public function get_visible() { return $this->visible; }
	public function set_variation($variation) { $this->variation = (bool) $variation; return $this; }
	public function get_variation() { return $this->variation; }
}
PHP
			);
		}
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
