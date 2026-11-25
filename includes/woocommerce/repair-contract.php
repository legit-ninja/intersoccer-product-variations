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
if (!defined('INTERSOCCER_PV_REPAIR_DELETE_PHRASE')) {
	define('INTERSOCCER_PV_REPAIR_DELETE_PHRASE', 'DELETE');
}
if (!defined('INTERSOCCER_PV_REPAIR_PREVIEW_TTL')) {
	define('INTERSOCCER_PV_REPAIR_PREVIEW_TTL', 30 * 60);
}
if (!defined('INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP')) {
	define('INTERSOCCER_PV_REPAIR_BACKUP_ROW_CAP', 200);
}
if (!defined('INTERSOCCER_PV_REPAIR_BACKUP_SUFFIX_BYTES')) {
	define('INTERSOCCER_PV_REPAIR_BACKUP_SUFFIX_BYTES', 16);
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
		if (!$role || !method_exists($role, 'add_cap')) {
			return;
		}
		if (method_exists($role, 'has_cap') && $role->has_cap(INTERSOCCER_PV_REPAIR_CAP)) {
			return;
		}
		$role->add_cap(INTERSOCCER_PV_REPAIR_CAP, true);
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
			'deletes' => (int) ($counts['deleted'] ?? 0),
			'changed' => $changed,
			'created_at' => time(),
		]);
	}
}

if (!function_exists('intersoccer_pv_repair_preview_is_fresh')) {
	/**
	 * A Preview older than 30 minutes is missing.
	 *
	 * @param int $created_at Unix timestamp.
	 * @return bool
	 */
	function intersoccer_pv_repair_preview_is_fresh($created_at) {
		$created_at = (int) $created_at;
		$ttl = defined('INTERSOCCER_PV_REPAIR_PREVIEW_TTL') ? (int) INTERSOCCER_PV_REPAIR_PREVIEW_TTL : 1800;
		return $created_at > 0 && (time() - $created_at) < $ttl;
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
		if (!intersoccer_pv_repair_preview_is_fresh($stored['created_at'] ?? 0)) {
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
	 * @param string $typed_delete Typed DELETE phrase.
	 * @return array{ok:bool,error:string,code:string}
	 */
	function intersoccer_pv_repair_gate_apply($user_id, $tool, array $scope, $typed_apply, $typed_global, $typed_delete = '') {
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
		$deletes = (int) ($preview['deletes'] ?? 0);
		if ($deletes === 0 && is_array($preview['counts'] ?? null)) {
			$deletes = (int) ($preview['counts']['deleted'] ?? 0);
		}
		if ($deletes > 0 && trim((string) $typed_delete) !== INTERSOCCER_PV_REPAIR_DELETE_PHRASE) {
			return [
				'ok' => false,
				'error' => __('Preview removes metadata. Type DELETE to confirm those removals.', 'intersoccer-product-variations'),
				'code' => 'delete_phrase',
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
	 * Directory for repair JSON backups.
	 *
	 * Backups hold player names, so the files must not be web-readable.
	 * Preferred path: a directory beside the WordPress root (dirname of ABSPATH),
	 * which is outside the web root when ABSPATH is the document root.
	 * If that parent is not writable, fall back to uploads/intersoccer-pv-repair-backups
	 * and deny web access with .htaccess plus index.php. Filenames stay long and random.
	 *
	 * @return string
	 */
	function intersoccer_pv_repair_backup_dir() {
		if (!empty($GLOBALS['intersoccer_pv_test_backup_dir'])) {
			$dir = (string) $GLOBALS['intersoccer_pv_test_backup_dir'];
		} else {
			$dir = intersoccer_pv_repair_backup_dir_resolve();
		}
		intersoccer_pv_repair_backup_dir_protect($dir);
		return $dir;
	}
}

if (!function_exists('intersoccer_pv_repair_backup_outside_candidates')) {
	/**
	 * Paths above the web root, tried before the uploads fallback.
	 *
	 * @return string[]
	 */
	function intersoccer_pv_repair_backup_outside_candidates() {
		$candidates = [];
		if (defined('ABSPATH')) {
			$root = rtrim((string) ABSPATH, '/\\');
			$parent = dirname($root);
			if ($parent !== '' && $parent !== $root && $parent !== '.' && $parent !== DIRECTORY_SEPARATOR) {
				$candidates[] = $parent . '/intersoccer-pv-repair-backups';
			}
		}
		return array_values(array_unique($candidates));
	}
}

if (!function_exists('intersoccer_pv_repair_open_basedir_roots')) {
	/**
	 * open_basedir roots, or null when the host does not restrict paths.
	 *
	 * @return string[]|null
	 */
	function intersoccer_pv_repair_open_basedir_roots() {
		if (array_key_exists('intersoccer_pv_test_open_basedir', $GLOBALS)) {
			$open = (string) $GLOBALS['intersoccer_pv_test_open_basedir'];
		} else {
			$open = ini_get('open_basedir');
			$open = is_string($open) ? $open : '';
		}
		if ($open === '') {
			return null;
		}
		$roots = [];
		foreach (explode(PATH_SEPARATOR, $open) as $root) {
			$root = rtrim(str_replace('\\', '/', $root), '/');
			if ($root !== '') {
				$roots[] = $root;
			}
		}
		return $roots;
	}
}

if (!function_exists('intersoccer_pv_repair_path_allowed')) {
	/**
	 * False when $path sits outside open_basedir. Those probes warn on restricted hosts.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	function intersoccer_pv_repair_path_allowed($path) {
		$roots = intersoccer_pv_repair_open_basedir_roots();
		if ($roots === null) {
			return true;
		}
		$path = rtrim(str_replace('\\', '/', (string) $path), '/');
		if ($path === '') {
			return false;
		}
		foreach ($roots as $root) {
			if ($path === $root || strpos($path, $root . '/') === 0) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('intersoccer_pv_repair_dir_creatable')) {
	/**
	 * @param string $dir Directory.
	 * @return bool
	 */
	function intersoccer_pv_repair_dir_creatable($dir) {
		$dir = (string) $dir;
		if ($dir === '' || !intersoccer_pv_repair_path_allowed($dir)) {
			return false;
		}
		$parent = dirname($dir);
		if (!intersoccer_pv_repair_path_allowed($parent)) {
			return false;
		}
		if (@is_dir($dir)) {
			return @is_writable($dir);
		}
		if ($parent === '' || $parent === '.' || !@is_dir($parent) || !@is_writable($parent)) {
			return false;
		}
		$made = function_exists('wp_mkdir_p') ? @wp_mkdir_p($dir) : @mkdir($dir, 0755, true);
		return (bool) $made && @is_dir($dir) && @is_writable($dir);
	}
}

if (!function_exists('intersoccer_pv_repair_backup_uploads_dir')) {
	/**
	 * Uploads fallback for repair JSON backups.
	 *
	 * @return string
	 */
	function intersoccer_pv_repair_backup_uploads_dir() {
		if (!empty($GLOBALS['intersoccer_pv_test_upload_basedir'])) {
			$base = (string) $GLOBALS['intersoccer_pv_test_upload_basedir'];
		} else {
			$uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
			$base = isset($uploads['basedir']) && is_string($uploads['basedir']) && $uploads['basedir'] !== ''
				? $uploads['basedir']
				: sys_get_temp_dir();
		}
		return rtrim(str_replace('\\', '/', $base), '/') . '/intersoccer-pv-repair-backups';
	}
}

if (!function_exists('intersoccer_pv_repair_backup_normalize_dir')) {
	/**
	 * @param string $dir Directory.
	 * @return string
	 */
	function intersoccer_pv_repair_backup_normalize_dir($dir) {
		$dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
		if ($dir !== '' && @is_dir($dir)) {
			$real = @realpath($dir);
			if (is_string($real) && $real !== '') {
				return rtrim(str_replace('\\', '/', $real), '/');
			}
		}
		return $dir;
	}
}

if (!function_exists('intersoccer_pv_repair_backup_recorded_dir')) {
	/**
	 * Absolute directory stored when the backup was written.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	function intersoccer_pv_repair_backup_recorded_dir($filename) {
		$index = intersoccer_pv_repair_state_get('intersoccer_pv_repair_backup_index', []);
		if (!is_array($index)) {
			return '';
		}
		$found = '';
		foreach ($index as $entry) {
			if (!is_array($entry) || (string) ($entry['file'] ?? '') !== $filename) {
				continue;
			}
			if (!empty($entry['dir']) && is_string($entry['dir'])) {
				$found = $entry['dir'];
			}
		}
		return $found;
	}
}

if (!function_exists('intersoccer_pv_repair_backup_lookup_dirs')) {
	/**
	 * Recorded directory first, then both known locations, then the active directory.
	 *
	 * @param string $filename Filename.
	 * @return string[]
	 */
	function intersoccer_pv_repair_backup_lookup_dirs($filename) {
		$dirs = [];
		$recorded = intersoccer_pv_repair_backup_recorded_dir($filename);
		if ($recorded !== '') {
			$dirs[] = $recorded;
		}
		foreach (intersoccer_pv_repair_backup_outside_candidates() as $candidate) {
			$dirs[] = $candidate;
		}
		$dirs[] = intersoccer_pv_repair_backup_uploads_dir();
		if (!empty($GLOBALS['intersoccer_pv_test_backup_dir'])) {
			$dirs[] = (string) $GLOBALS['intersoccer_pv_test_backup_dir'];
		}
		$unique = [];
		foreach ($dirs as $dir) {
			$dir = intersoccer_pv_repair_backup_normalize_dir($dir);
			if ($dir === '' || isset($unique[$dir])) {
				continue;
			}
			$unique[$dir] = $dir;
		}
		return array_values($unique);
	}
}

if (!function_exists('intersoccer_pv_repair_backup_locate')) {
	/**
	 * @param string $filename Filename.
	 * @return string|null Absolute file path.
	 */
	function intersoccer_pv_repair_backup_locate($filename) {
		$filename = basename((string) $filename);
		if (!preg_match('/^[a-z0-9._-]+\.json$/', $filename)) {
			return null;
		}
		foreach (intersoccer_pv_repair_backup_lookup_dirs($filename) as $dir) {
			if (!intersoccer_pv_repair_path_allowed($dir)) {
				continue;
			}
			$path = $dir . '/' . $filename;
			if (@is_readable($path)) {
				return $path;
			}
		}
		return null;
	}
}

if (!function_exists('intersoccer_pv_repair_backup_dir_resolve')) {
	/**
	 * @return string
	 */
	function intersoccer_pv_repair_backup_dir_resolve() {
		foreach (intersoccer_pv_repair_backup_outside_candidates() as $candidate) {
			if (intersoccer_pv_repair_dir_creatable($candidate)) {
				return $candidate;
			}
		}
		return intersoccer_pv_repair_backup_uploads_dir();
	}
}

if (!function_exists('intersoccer_pv_repair_backup_dir_protect')) {
	/**
	 * @param string $dir Directory.
	 * @return void
	 */
	function intersoccer_pv_repair_backup_dir_protect($dir) {
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
		$htaccess = $dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			file_put_contents($htaccess, $rules);
		}
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
		$bytes = defined('INTERSOCCER_PV_REPAIR_BACKUP_SUFFIX_BYTES') ? (int) INTERSOCCER_PV_REPAIR_BACKUP_SUFFIX_BYTES : 16;
		if ($bytes < 16) {
			$bytes = 16;
		}
		$name = $tool_key . '-' . gmdate('Ymd-His') . '-' . (int) $user_id . '-' . bin2hex(random_bytes($bytes)) . '.json';
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
			'dir' => intersoccer_pv_repair_backup_normalize_dir($dir),
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
		$path = intersoccer_pv_repair_backup_locate($filename);
		if ($path === null) {
			return;
		}
		$data = json_decode((string) file_get_contents($path), true);
		if (!is_array($data)) {
			return;
		}
		$data['rows'] = $rows;
		file_put_contents($path, intersoccer_pv_repair_json($data));
	}
}

if (!function_exists('intersoccer_pv_repair_read_backup')) {
	/**
	 * @param string $filename Filename.
	 * @return array|null
	 */
	function intersoccer_pv_repair_read_backup($filename) {
		$path = intersoccer_pv_repair_backup_locate($filename);
		if ($path === null) {
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
			if (!empty($row['needs_manual'])) {
				continue;
			}
			$current = call_user_func($read_current, $row['id']);
			if (intersoccer_pv_repair_values_equal($current, $row['after'] ?? null)) {
				call_user_func($restore, $row['id'], $row['before'] ?? null, $row['after'] ?? null);
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

if (!function_exists('intersoccer_pv_repair_option_is_repair_state')) {
	/**
	 * Options created for repair previews, backups, and run logs.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function intersoccer_pv_repair_option_is_repair_state($name) {
		$name = (string) $name;
		$bare = preg_replace('/^(_site_transient_timeout_|_site_transient_|_transient_timeout_|_transient_)/', '', $name);
		return is_string($bare) && (bool) preg_match('/^intersoccer_pv_repair_/i', $bare);
	}
}

if (!function_exists('intersoccer_pv_repair_active_plugin_has_basename')) {
	/**
	 * True when an active plugin main file matches $basename, regardless of folder.
	 *
	 * @param string $basename Main PHP file, e.g. intersoccer-reports-rosters.php.
	 * @return bool
	 */
	function intersoccer_pv_repair_active_plugin_has_basename($basename) {
		$basename = basename((string) $basename);
		if ($basename === '') {
			return false;
		}
		$plugins = [];
		if (isset($GLOBALS['intersoccer_pv_test_active_plugins']) && is_array($GLOBALS['intersoccer_pv_test_active_plugins'])) {
			$plugins = $GLOBALS['intersoccer_pv_test_active_plugins'];
		} else {
			if (function_exists('get_option')) {
				$plugins = (array) get_option('active_plugins', []);
			}
			if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
				$plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
			}
		}
		foreach ($plugins as $plugin) {
			if (basename((string) $plugin) === $basename) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('intersoccer_pv_repair_reports_plugin_active')) {
	/**
	 * Reports & Rosters still owns the shared repair capability when it is active.
	 *
	 * @return bool
	 */
	function intersoccer_pv_repair_reports_plugin_active() {
		if (array_key_exists('intersoccer_pv_test_rr_active', $GLOBALS)) {
			return (bool) $GLOBALS['intersoccer_pv_test_rr_active'];
		}
		if (array_key_exists('intersoccer_pv_test_active_plugins', $GLOBALS)) {
			return intersoccer_pv_repair_active_plugin_has_basename('intersoccer-reports-rosters.php');
		}
		if (function_exists('intersoccer_rr_user_can_run_repairs')) {
			return true;
		}
		if (defined('INTERSOCCER_REPORTS_ROSTERS_LOADED') || defined('INTERSOCCER_RR_ADVANCED_TOOLS_PAGE')) {
			return true;
		}
		return intersoccer_pv_repair_active_plugin_has_basename('intersoccer-reports-rosters.php');
	}
}

if (!function_exists('intersoccer_pv_repair_remove_cap_from_roles')) {
	/**
	 * Remove the repair capability from every role that has it.
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_remove_cap_from_roles() {
		$names = ['administrator', 'shop_manager'];
		if (function_exists('wp_roles')) {
			$wp_roles = wp_roles();
			if (is_object($wp_roles) && isset($wp_roles->roles) && is_array($wp_roles->roles)) {
				$names = array_keys($wp_roles->roles);
			}
		}
		if (!function_exists('get_role')) {
			return;
		}
		foreach ($names as $name) {
			$role = get_role($name);
			if (!$role || !method_exists($role, 'remove_cap')) {
				continue;
			}
			if (method_exists($role, 'has_cap') && !$role->has_cap(INTERSOCCER_PV_REPAIR_CAP)) {
				continue;
			}
			$role->remove_cap(INTERSOCCER_PV_REPAIR_CAP);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_delete_repair_options')) {
	/**
	 * Delete intersoccer_pv_repair_* options and their transients.
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_delete_repair_options() {
		$names = [];
		foreach (['intersoccer_test_options', 'intersoccer_test_option_store', 'intersoccer_pv_repair_memory'] as $bucket) {
			if (isset($GLOBALS[$bucket]) && is_array($GLOBALS[$bucket])) {
				$names = array_merge($names, array_keys($GLOBALS[$bucket]));
			}
		}
		if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_col') && isset($GLOBALS['wpdb']->options)) {
			$like = method_exists($GLOBALS['wpdb'], 'esc_like') ? $GLOBALS['wpdb']->esc_like('intersoccer_pv_repair_') . '%' : 'intersoccer_pv_repair_%';
			$sql = $GLOBALS['wpdb']->prepare(
				"SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like,
				'_transient_' . $like,
				'_transient_timeout_' . $like,
				'_site_transient_' . $like,
				'_site_transient_timeout_' . $like
			);
			$found = $GLOBALS['wpdb']->get_col($sql);
			if (is_array($found)) {
				$names = array_merge($names, $found);
			}
		}
		foreach (array_unique($names) as $name) {
			if (!is_string($name) || !intersoccer_pv_repair_option_is_repair_state($name)) {
				continue;
			}
			if (function_exists('delete_option')) {
				delete_option($name);
			}
			foreach (['intersoccer_test_options', 'intersoccer_test_option_store', 'intersoccer_pv_repair_memory'] as $bucket) {
				if (isset($GLOBALS[$bucket]) && is_array($GLOBALS[$bucket])) {
					unset($GLOBALS[$bucket][$name]);
				}
			}
		}
	}
}

if (!function_exists('intersoccer_pv_repair_backup_dir_is_removable')) {
	/**
	 * Only this plugin's backup directories, plus the active test directory.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	function intersoccer_pv_repair_backup_dir_is_removable($dir) {
		$dir = intersoccer_pv_repair_backup_normalize_dir($dir);
		if ($dir === '' || $dir === '/' || $dir === '.' || $dir === dirname($dir)) {
			return false;
		}
		if (basename($dir) === 'intersoccer-pv-repair-backups') {
			return true;
		}
		if (!empty($GLOBALS['intersoccer_pv_test_backup_dir'])) {
			$test = intersoccer_pv_repair_backup_normalize_dir((string) $GLOBALS['intersoccer_pv_test_backup_dir']);
			if ($test !== '' && $dir === $test) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('intersoccer_pv_repair_delete_backup_tree')) {
	/**
	 * @param string $dir Directory.
	 * @return void
	 */
	function intersoccer_pv_repair_delete_backup_tree($dir) {
		if (!intersoccer_pv_repair_path_allowed($dir) || !@is_dir($dir)) {
			return;
		}
		$items = @scandir($dir);
		if (!is_array($items)) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			if (@is_dir($path) && !@is_link($path)) {
				intersoccer_pv_repair_delete_backup_tree($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}
}

if (!function_exists('intersoccer_pv_repair_delete_backup_dirs')) {
	/**
	 * Delete backup files and this plugin's backup directories.
	 *
	 * Recorded directories are removed first. Preferred (outside the web root)
	 * and the uploads fallback are removed when they are this plugin's backup dirs.
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_delete_backup_dirs() {
		$dirs = [];
		$index = intersoccer_pv_repair_state_get('intersoccer_pv_repair_backup_index', []);
		if (is_array($index)) {
			foreach ($index as $entry) {
				if (is_array($entry) && !empty($entry['dir']) && is_string($entry['dir'])) {
					$dirs[] = $entry['dir'];
				}
			}
		}
		if (!empty($GLOBALS['intersoccer_pv_test_backup_dir'])) {
			$dirs[] = (string) $GLOBALS['intersoccer_pv_test_backup_dir'];
		}
		foreach (intersoccer_pv_repair_backup_outside_candidates() as $candidate) {
			$dirs[] = $candidate;
		}
		$dirs[] = intersoccer_pv_repair_backup_uploads_dir();
		$seen = [];
		foreach ($dirs as $dir) {
			$normalized = intersoccer_pv_repair_backup_normalize_dir($dir);
			if ($normalized === '' || isset($seen[$normalized]) || !intersoccer_pv_repair_backup_dir_is_removable($normalized)) {
				continue;
			}
			$seen[$normalized] = true;
			if (!@is_dir($normalized)) {
				continue;
			}
			intersoccer_pv_repair_delete_backup_tree($normalized);
		}
	}
}

if (!function_exists('intersoccer_pv_repair_uninstall')) {
	/**
	 * Uninstall hook: drop backup files, then PV repair options. Leave the shared cap while Reports & Rosters is active.
	 *
	 * @return void
	 */
	function intersoccer_pv_repair_uninstall() {
		intersoccer_pv_repair_delete_backup_dirs();
		if (!intersoccer_pv_repair_reports_plugin_active()) {
			intersoccer_pv_repair_remove_cap_from_roles();
		}
		intersoccer_pv_repair_delete_repair_options();
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
