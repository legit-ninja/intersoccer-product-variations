<?php
/**
 * Preview / Apply / backup / rollback contract for Product Variations repairs.
 *
 * Office staff use Advanced tools. Preview writes nothing. Apply requires a
 * matching Preview, the typed phrase APPLY, and GLOBAL when the scope is
 * shop-wide.
 *
 * @package InterSoccer_Product_Variations
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('INTERSOCCER_PV_REPAIR_CAP')) {
	define('INTERSOCCER_PV_REPAIR_CAP', 'intersoccer_run_repairs');
}
if (!defined('INTERSOCCER_PV_ADVANCED_TOOLS_PAGE')) {
	define('INTERSOCCER_PV_ADVANCED_TOOLS_PAGE', 'intersoccer-pv-advanced-tools');
}
if (!defined('INTERSOCCER_PV_REPAIR_NONCE')) {
	define('INTERSOCCER_PV_REPAIR_NONCE', 'intersoccer_pv_advanced_tools');
}
if (!defined('INTERSOCCER_PV_REPAIR_APPLY_PHRASE')) {
	define('INTERSOCCER_PV_REPAIR_APPLY_PHRASE', 'APPLY');
}
if (!defined('INTERSOCCER_PV_REPAIR_GLOBAL_PHRASE')) {
	define('INTERSOCCER_PV_REPAIR_GLOBAL_PHRASE', 'GLOBAL');
}

if (!function_exists('intersoccer_pv_advanced_tools_page_slug')) {
	/**
	 * @return string
	 */
	function intersoccer_pv_advanced_tools_page_slug() {
		return INTERSOCCER_PV_ADVANCED_TOOLS_PAGE;
	}
}

if (!function_exists('intersoccer_pv_repair_grant_administrator_cap')) {
	/**
	 * Grant the repair capability to the Administrator role only.
	 */
	function intersoccer_pv_repair_grant_administrator_cap() {
		if (!function_exists('get_role')) {
			return;
		}
		$role = get_role('administrator');
		if ($role && method_exists($role, 'add_cap')) {
			$role->add_cap(INTERSOCCER_PV_REPAIR_CAP, true);
		}
	}
}

if (!function_exists('intersoccer_pv_user_can_run_repairs')) {
	/**
	 * @return bool
	 */
	function intersoccer_pv_user_can_run_repairs() {
		return function_exists('current_user_can') && current_user_can(INTERSOCCER_PV_REPAIR_CAP);
	}
}

if (!function_exists('intersoccer_pv_repair_stop')) {
	/**
	 * Stop a repair request. Tests receive an exception; admin uses wp_die.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	function intersoccer_pv_repair_stop($message) {
		$message = (string) $message;
		if (defined('INTERSOCCER_PV_REPAIR_TEST') && INTERSOCCER_PV_REPAIR_TEST) {
			throw new RuntimeException(function_exists('wp_strip_all_tags') ? wp_strip_all_tags($message) : $message);
		}
		if (function_exists('wp_die')) {
			wp_die(function_exists('esc_html') ? esc_html($message) : $message);
		}
		throw new RuntimeException($message);
	}
}

if (!function_exists('intersoccer_pv_repair_require_cap')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_repair_require_cap() {
		if (!intersoccer_pv_user_can_run_repairs()) {
			intersoccer_pv_repair_stop(__('You do not have permission to run repairs.', 'intersoccer-product-variations'));
		}
	}
}

if (!function_exists('intersoccer_pv_repair_state_get')) {
	/**
	 * @param string $key     Option key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function intersoccer_pv_repair_state_get($key, $default = null) {
		if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])
			&& array_key_exists($key, $GLOBALS['intersoccer_test_option_store'])) {
			$value = $GLOBALS['intersoccer_test_option_store'][$key];
			return $value === null ? $default : $value;
		}
		if (function_exists('get_option')) {
			$missing = new stdClass();
			$value = get_option($key, $missing);
			if ($value !== $missing && $value !== false && $value !== null) {
				return $value;
			}
		}
		if (isset($GLOBALS['intersoccer_pv_repair_memory']) && is_array($GLOBALS['intersoccer_pv_repair_memory'])
			&& array_key_exists($key, $GLOBALS['intersoccer_pv_repair_memory'])
			&& $GLOBALS['intersoccer_pv_repair_memory'][$key] !== null) {
			return $GLOBALS['intersoccer_pv_repair_memory'][$key];
		}
		return $default;
	}
}

if (!function_exists('intersoccer_pv_repair_state_set')) {
	/**
	 * @param string $key   Option key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	function intersoccer_pv_repair_state_set($key, $value) {
		if (!isset($GLOBALS['intersoccer_pv_repair_memory']) || !is_array($GLOBALS['intersoccer_pv_repair_memory'])) {
			$GLOBALS['intersoccer_pv_repair_memory'] = [];
		}
		$GLOBALS['intersoccer_pv_repair_memory'][$key] = $value;
		if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])) {
			$GLOBALS['intersoccer_test_option_store'][$key] = $value;
		}
		if (function_exists('update_option')) {
			update_option($key, $value, false);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_reset_state')) {
	/**
	 * @return void
	 */
	function intersoccer_pv_repair_reset_state() {
		$GLOBALS['intersoccer_pv_repair_memory'] = [];
		if (isset($GLOBALS['intersoccer_test_options']) && is_array($GLOBALS['intersoccer_test_options'])) {
			foreach (array_keys($GLOBALS['intersoccer_test_options']) as $key) {
				if (is_string($key) && strpos($key, 'intersoccer_pv_repair_') === 0) {
					unset($GLOBALS['intersoccer_test_options'][$key]);
				}
			}
		}
		if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])) {
			foreach (array_keys($GLOBALS['intersoccer_test_option_store']) as $key) {
				if (is_string($key) && strpos($key, 'intersoccer_pv_repair_') === 0) {
					unset($GLOBALS['intersoccer_test_option_store'][$key]);
				}
			}
		}
	}
}

if (!function_exists('intersoccer_pv_repair_json')) {
	/**
	 * @param mixed $data Data.
	 * @return string
	 */
	function intersoccer_pv_repair_json($data) {
		if (function_exists('wp_json_encode')) {
			$encoded = wp_json_encode($data);
			return is_string($encoded) ? $encoded : '';
		}
		$encoded = json_encode($data);
		return is_string($encoded) ? $encoded : '';
	}
}

if (!function_exists('intersoccer_pv_repair_normalize_scope')) {
	/**
	 * @param array $scope Scope.
	 * @return array
	 */
	function intersoccer_pv_repair_normalize_scope(array $scope) {
		ksort($scope);
		foreach ($scope as $key => $value) {
			if (is_array($value)) {
				$flat = array_map('strval', $value);
				sort($flat);
				$scope[$key] = $flat;
			} elseif (is_bool($value)) {
				$scope[$key] = $value ? '1' : '0';
			} else {
				$scope[$key] = (string) $value;
			}
		}
		return $scope;
	}
}

if (!function_exists('intersoccer_pv_repair_fingerprint')) {
	/**
	 * @param array $scope Scope.
	 * @return string
	 */
	function intersoccer_pv_repair_fingerprint(array $scope) {
		return hash('sha256', intersoccer_pv_repair_json(intersoccer_pv_repair_normalize_scope($scope)));
	}
}

if (!function_exists('intersoccer_pv_repair_rows_fingerprint')) {
	/**
	 * @param array $rows Rows.
	 * @return string
	 */
	function intersoccer_pv_repair_rows_fingerprint(array $rows) {
		return hash('sha256', intersoccer_pv_repair_json(array_values($rows)));
	}
}

if (!function_exists('intersoccer_pv_repair_preview_key')) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $tool    Tool key.
	 * @return string
	 */
	function intersoccer_pv_repair_preview_key($user_id, $tool) {
		$tool = function_exists('sanitize_key') ? sanitize_key($tool) : preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $tool));
		return 'intersoccer_pv_repair_preview_' . (int) $user_id . '_' . $tool;
	}
}

if (!function_exists('intersoccer_pv_repair_store_preview')) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $tool    Tool key.
	 * @param array  $scope   Scope.
	 * @param array  $result  Dry-run result.
	 * @return void
	 */
	function intersoccer_pv_repair_store_preview($user_id, $tool, array $scope, array $result) {
		$counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
		$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
		$changed = (int) ($counts['changed'] ?? 0);
		if ($changed === 0) {
			$changed = (int) ($counts['added'] ?? 0) + (int) ($counts['updated'] ?? 0) + (int) ($counts['deleted'] ?? 0);
			$counts['changed'] = $changed;
		}
		$tool = function_exists('sanitize_key') ? sanitize_key($tool) : (string) $tool;
		intersoccer_pv_repair_state_set(intersoccer_pv_repair_preview_key($user_id, $tool), [
			'user_id' => (int) $user_id,
			'tool' => $tool,
			'scope' => intersoccer_pv_repair_normalize_scope($scope),
			'fingerprint' => intersoccer_pv_repair_fingerprint($scope),
			'rows_fingerprint' => intersoccer_pv_repair_rows_fingerprint($rows),
			'counts' => $counts,
			'rows' => $rows,
			'summary' => (string) ($result['summary'] ?? ''),
			'shop_wide' => !empty($result['shop_wide']),
			'changed' => $changed,
			'created_at' => time(),
		]);
	}
}

if (!function_exists('intersoccer_pv_repair_get_preview')) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $tool    Tool key.
	 * @return array|null
	 */
	function intersoccer_pv_repair_get_preview($user_id, $tool) {
		$stored = intersoccer_pv_repair_state_get(intersoccer_pv_repair_preview_key($user_id, $tool), null);
		if (!is_array($stored) || empty($stored['fingerprint']) || !empty($stored['consumed'])) {
			return null;
		}
		return $stored;
	}
}

if (!function_exists('intersoccer_pv_repair_clear_preview')) {
	/**
	 * @param int    $user_id User ID.
	 * @param string $tool    Tool key.
	 * @return void
	 */
	function intersoccer_pv_repair_clear_preview($user_id, $tool) {
		intersoccer_pv_repair_state_set(intersoccer_pv_repair_preview_key($user_id, $tool), ['consumed' => true]);
	}
}

if (!function_exists('intersoccer_pv_repair_verify_nonce')) {
	/**
	 * @param string $action Nonce action.
	 * @return bool
	 */
	function intersoccer_pv_repair_verify_nonce($action = INTERSOCCER_PV_REPAIR_NONCE) {
		if (array_key_exists('intersoccer_pv_test_nonce_valid', $GLOBALS)) {
			return (bool) $GLOBALS['intersoccer_pv_test_nonce_valid'];
		}
		$nonce = '';
		if (isset($_POST['_wpnonce'])) {
			$nonce = function_exists('sanitize_text_field')
				? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']))
				: (string) $_POST['_wpnonce'];
		} elseif (isset($_POST['intersoccer_pv_repair_nonce'])) {
			$nonce = function_exists('sanitize_text_field')
				? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_pv_repair_nonce']))
				: (string) $_POST['intersoccer_pv_repair_nonce'];
		}
		if ($nonce === '' || !function_exists('wp_verify_nonce')) {
			return false;
		}
		return (bool) wp_verify_nonce($nonce, $action);
	}
}

if (!function_exists('intersoccer_pv_repair_posted_phrase')) {
	/**
	 * @param string $field POST field.
	 * @return string
	 */
	function intersoccer_pv_repair_posted_phrase($field) {
		if (!isset($_POST[$field])) {
			return '';
		}
		$raw = function_exists('wp_unslash') ? wp_unslash($_POST[$field]) : $_POST[$field];
		if (is_array($raw)) {
			return '';
		}
		$raw = (string) $raw;
		return function_exists('sanitize_text_field') ? sanitize_text_field($raw) : trim($raw);
	}
}

if (!function_exists('intersoccer_pv_repair_gate_apply')) {
	/**
	 * @param int    $user_id      User ID.
	 * @param string $tool         Tool key.
	 * @param array  $scope        Scope.
	 * @param string $typed_apply  Typed APPLY phrase.
	 * @param string $typed_global Typed GLOBAL phrase.
	 * @return array{ok:bool,error:string,code:string}
	 */
	function intersoccer_pv_repair_gate_apply($user_id, $tool, array $scope, $typed_apply, $typed_global) {
		$preview = intersoccer_pv_repair_get_preview($user_id, $tool);
		if ($preview === null) {
			return [
				'ok' => false,
				'error' => __('Run Preview before Apply.', 'intersoccer-product-variations'),
				'code' => 'no_preview',
			];
		}
		if ((string) ($preview['fingerprint'] ?? '') !== intersoccer_pv_repair_fingerprint($scope)) {
			return [
				'ok' => false,
				'error' => __('The change set no longer matches the Preview. Run Preview again.', 'intersoccer-product-variations'),
				'code' => 'mismatch',
			];
		}
		if (trim((string) $typed_apply) !== INTERSOCCER_PV_REPAIR_APPLY_PHRASE) {
			return [
				'ok' => false,
				'error' => __('Type APPLY to confirm.', 'intersoccer-product-variations'),
				'code' => 'apply_phrase',
			];
		}
		if (!empty($preview['shop_wide']) && trim((string) $typed_global) !== INTERSOCCER_PV_REPAIR_GLOBAL_PHRASE) {
			return [
				'ok' => false,
				'error' => __('This scope is shop-wide. Type GLOBAL as well as APPLY.', 'intersoccer-product-variations'),
				'code' => 'global_phrase',
			];
		}
		if ((int) ($preview['changed'] ?? 0) === 0) {
			return [
				'ok' => false,
				'error' => __('Preview has no changes. Apply was not run.', 'intersoccer-product-variations'),
				'code' => 'empty',
			];
		}
		return ['ok' => true, 'error' => '', 'code' => 'ok'];
	}
}

if (!function_exists('intersoccer_pv_repair_backup_dir')) {
	/**
	 * @return string
	 */
	function intersoccer_pv_repair_backup_dir() {
		if (!empty($GLOBALS['intersoccer_pv_test_backup_dir'])) {
			$dir = (string) $GLOBALS['intersoccer_pv_test_backup_dir'];
		} else {
			$uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
			$base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : sys_get_temp_dir();
			$dir = rtrim($base, '/') . '/intersoccer-pv-repair-backups';
		}
		if (!is_dir($dir)) {
			if (function_exists('wp_mkdir_p')) {
				wp_mkdir_p($dir);
			} else {
				mkdir($dir, 0755, true);
			}
		}
		$index = $dir . '/index.php';
		if (!file_exists($index)) {
			file_put_contents($index, "<?php\n// Silence is golden.\n");
		}
		return $dir;
	}
}

if (!function_exists('intersoccer_pv_repair_write_backup')) {
	/**
	 * @param string $tool    Tool key.
	 * @param int    $user_id User ID.
	 * @param array  $rows    Changed rows.
	 * @return string Filename.
	 */
	function intersoccer_pv_repair_write_backup($tool, $user_id, array $rows) {
		$dir = intersoccer_pv_repair_backup_dir();
		$tool_key = function_exists('sanitize_key') ? sanitize_key($tool) : preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $tool));
		$name = $tool_key . '-' . gmdate('Ymd-His') . '-' . (int) $user_id . '-' . bin2hex(random_bytes(3)) . '.json';
		$payload = [
			'tool' => $tool_key,
			'user_id' => (int) $user_id,
			'created_at' => gmdate('c'),
			'rows' => $rows,
		];
		file_put_contents($dir . '/' . $name, intersoccer_pv_repair_json($payload));
		$index = intersoccer_pv_repair_state_get('intersoccer_pv_repair_backup_index', []);
		if (!is_array($index)) {
			$index = [];
		}
		$index[] = [
			'file' => $name,
			'tool' => $tool_key,
			'user_id' => (int) $user_id,
			'created_at' => $payload['created_at'],
		];
		intersoccer_pv_repair_state_set('intersoccer_pv_repair_backup_index', $index);
		return $name;
	}
}

if (!function_exists('intersoccer_pv_repair_replace_backup_rows')) {
	/**
	 * @param string $filename Filename.
	 * @param array  $rows     Rows.
	 * @return void
	 */
	function intersoccer_pv_repair_replace_backup_rows($filename, array $rows) {
		$filename = basename((string) $filename);
		if (!preg_match('/^[a-z0-9._-]+\.json$/', $filename)) {
			return;
		}
		$data = intersoccer_pv_repair_read_backup($filename);
		if ($data === null) {
			return;
		}
		$data['rows'] = $rows;
		file_put_contents(intersoccer_pv_repair_backup_dir() . '/' . $filename, intersoccer_pv_repair_json($data));
	}
}

if (!function_exists('intersoccer_pv_repair_read_backup')) {
	/**
	 * @param string $filename Filename.
	 * @return array|null
	 */
	function intersoccer_pv_repair_read_backup($filename) {
		$filename = basename((string) $filename);
		if (!preg_match('/^[a-z0-9._-]+\.json$/', $filename)) {
			return null;
		}
		$path = intersoccer_pv_repair_backup_dir() . '/' . $filename;
		if (!is_readable($path)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($path), true);
		return is_array($data) ? $data : null;
	}
}

if (!function_exists('intersoccer_pv_repair_values_equal')) {
	/**
	 * @param mixed $left  Current value.
	 * @param mixed $right Expected value.
	 * @return bool
	 */
	function intersoccer_pv_repair_values_equal($left, $right) {
		if (is_array($right)) {
			if (!is_array($left)) {
				return false;
			}
			foreach ($right as $key => $value) {
				if (!array_key_exists($key, $left) || !intersoccer_pv_repair_values_equal($left[$key], $value)) {
					return false;
				}
			}
			return true;
		}
		if (is_array($left)) {
			return false;
		}
		return (string) $left === (string) $right;
	}
}

if (!function_exists('intersoccer_pv_repair_rollback_rows')) {
	/**
	 * Restore rows whose current value still equals what Apply wrote.
	 *
	 * @param array    $backup       Backup payload.
	 * @param callable $read_current function ($id): current value.
	 * @param callable $restore      function ($id, $before): void.
	 * @return array{restored:int,conflicts:array<int,mixed>}
	 */
	function intersoccer_pv_repair_rollback_rows(array $backup, $read_current, $restore) {
		$restored = 0;
		$conflicts = [];
		$rows = is_array($backup['rows'] ?? null) ? $backup['rows'] : [];
		foreach ($rows as $row) {
			if (!is_array($row) || !array_key_exists('id', $row)) {
				continue;
			}
			$current = call_user_func($read_current, $row['id']);
			if (intersoccer_pv_repair_values_equal($current, $row['after'] ?? null)) {
				call_user_func($restore, $row['id'], $row['before'] ?? null);
				$restored++;
			} else {
				$conflicts[] = $row['id'];
			}
		}
		return ['restored' => $restored, 'conflicts' => $conflicts];
	}
}

if (!function_exists('intersoccer_pv_repair_log')) {
	/**
	 * @param array $entry Log entry.
	 * @return void
	 */
	function intersoccer_pv_repair_log(array $entry) {
		$log = intersoccer_pv_repair_state_get('intersoccer_pv_repair_run_log', []);
		if (!is_array($log)) {
			$log = [];
		}
		unset($entry['rows'], $entry['customer'], $entry['email']);
		$entry['user_id'] = (int) ($entry['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0));
		$entry['at'] = gmdate('c');
		$entry['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
		$log[] = $entry;
		if (count($log) > 100) {
			$log = array_slice($log, -100);
		}
		intersoccer_pv_repair_state_set('intersoccer_pv_repair_run_log', $log);
	}
}

if (!function_exists('intersoccer_pv_repair_log_entries')) {
	/**
	 * @return array
	 */
	function intersoccer_pv_repair_log_entries() {
		$log = intersoccer_pv_repair_state_get('intersoccer_pv_repair_run_log', []);
		return is_array($log) ? $log : [];
	}
}

if (!function_exists('intersoccer_pv_repair_reject_legacy_ajax')) {
	/**
	 * Everyday admin-ajax repair actions no longer write.
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_reject_legacy_ajax() {
		$message = intersoccer_pv_user_can_run_repairs()
			? __('This repair runs only from Advanced tools.', 'intersoccer-product-variations')
			: __('You do not have permission to run repairs.', 'intersoccer-product-variations');
		if (function_exists('wp_send_json_error')) {
			$encoded = wp_send_json_error(['message' => $message], 403);
			if (is_string($encoded)) {
				echo $encoded;
			}
			return;
		}
		intersoccer_pv_repair_stop($message);
	}
}

if (!function_exists('intersoccer_pv_scope_is_shop_wide')) {
	/**
	 * @param string $tool  Tool key.
	 * @param array  $scope Scope.
	 * @return bool
	 */
	function intersoccer_pv_scope_is_shop_wide($tool, array $scope) {
		$blank = static function ($value) {
			return trim((string) $value) === '' || (string) $value === '0';
		};
		switch ($tool) {
			case 'automated_batch':
			case 'course_holiday_fix':
			case 'season_labels':
				return true;
			case 'recalculate_end_dates':
			case 'refresh_attributes':
			case 'repair_venue':
				return $blank($scope['product_id'] ?? '');
			case 'migrate_camp_dates':
				return $blank($scope['product_id'] ?? '') || (string) ($scope['force'] ?? '') === '1';
			case 'scan_orders':
				return (string) ($scope['preview_limit'] ?? '') === '-1';
			default:
				return false;
		}
	}
}

if (function_exists('add_action')) {
	add_action('init', 'intersoccer_pv_repair_grant_administrator_cap', 20);
}
