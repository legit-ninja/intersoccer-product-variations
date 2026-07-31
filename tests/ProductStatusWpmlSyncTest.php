<?php
/**
 * WPML fan-out for parent product post_status.
 */

use PHPUnit\Framework\TestCase;

class ProductStatusWpmlSyncTest extends TestCase {
	private int $en_id = 9201;
	private int $fr_id = 9202;
	private int $de_id = 9203;

	/** @var array<int,string> */
	public static $statuses = [];

	protected function setUp(): void {
		parent::setUp();
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}
		self::$statuses = [
			$this->en_id => 'publish',
			$this->fr_id => 'publish',
			$this->de_id => 'publish',
		];

		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.6-test');
		}

		require_once dirname(__DIR__) . '/includes/helpers.php';
		require_once dirname(__DIR__) . '/includes/language-helpers.php';

		if (!function_exists('wp_update_post')) {
			function wp_update_post($data, $wp_error = false) {
				$id = isset($data['ID']) ? (int) $data['ID'] : 0;
				if ($id <= 0) {
					return $wp_error && class_exists('WP_Error') ? new WP_Error('invalid', 'invalid') : 0;
				}
				if (isset($data['post_status'])) {
					ProductStatusWpmlSyncTest::$statuses[$id] = sanitize_key((string) $data['post_status']);
				}
				return $id;
			}
		}

		$en = $this->en_id;
		$fr = $this->fr_id;
		$de = $this->de_id;

		MockFilters::$filters['wpml_active_languages'] = static function () {
			return [
				'en' => ['code' => 'en'],
				'fr' => ['code' => 'fr'],
				'de' => ['code' => 'de'],
			];
		};
		MockFilters::$filters['wpml_element_language_code'] = static function ($value, $args = null) use ($en) {
			$id = is_array($args) ? (int) ($args['element_id'] ?? 0) : 0;
			return $id === $en ? 'en' : $value;
		};
		MockFilters::$filters['wpml_current_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en, $fr, $de) {
			$id = (int) $id;
			if ($type !== 'product' && $type !== 'post_product') {
				return $id;
			}
			$map = [
				$en => ['en' => $en, 'fr' => $fr, 'de' => $de],
				$fr => ['en' => $en, 'fr' => $fr, 'de' => $de],
				$de => ['en' => $en, 'fr' => $fr, 'de' => $de],
			];
			if (!isset($map[$id])) {
				return 0;
			}
			$lang = $lang ?: 'en';
			return $map[$id][$lang] ?? 0;
		};
	}

	public function test_foreach_translated_products_returns_fr_de(): void {
		$ids = intersoccer_foreach_translated_products($this->en_id);
		sort($ids);
		$this->assertSame([$this->fr_id, $this->de_id], $ids);
	}

	public function test_sync_status_fans_out_private_to_siblings(): void {
		$updated = intersoccer_sync_product_status_to_translations($this->en_id, 'private');
		sort($updated);
		$this->assertSame([$this->fr_id, $this->de_id], $updated);
		$this->assertSame('private', self::$statuses[$this->fr_id]);
		$this->assertSame('private', self::$statuses[$this->de_id]);
		// Source is not rewritten by fan-out.
		$this->assertSame('publish', self::$statuses[$this->en_id]);
	}

	public function test_sync_skips_missing_translation(): void {
		$en = $this->en_id;
		$fr = $this->fr_id;
		$de = $this->de_id;
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en, $fr) {
			$id = (int) $id;
			if ($id !== $en) {
				return $id;
			}
			if (($type === 'product' || $type === 'post_product') && $lang === 'fr') {
				return $fr;
			}
			if (($type === 'product' || $type === 'post_product') && $lang === 'de') {
				return 0; // missing DE
			}
			return $id;
		};

		$updated = intersoccer_sync_product_status_to_translations($this->en_id, 'draft');
		$this->assertSame([$this->fr_id], $updated);
		$this->assertSame('draft', self::$statuses[$this->fr_id]);
		$this->assertSame('publish', self::$statuses[$this->de_id]);
	}

	public function test_sync_rejects_invalid_status(): void {
		$updated = intersoccer_sync_product_status_to_translations($this->en_id, 'trash');
		$this->assertSame([], $updated);
	}
}
