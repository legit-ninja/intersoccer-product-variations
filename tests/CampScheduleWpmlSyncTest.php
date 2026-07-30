<?php
/**
 * WPML sync for camp schedule meta (FR/DE siblings + default-lang read fallback).
 */

use PHPUnit\Framework\TestCase;

class CampScheduleWpmlSyncTest extends TestCase {
	private int $en_id = 9101;
	private int $fr_id = 9102;
	private int $de_id = 9103;

	protected function setUp(): void {
		parent::setUp();
		if (class_exists('MockMetaData')) {
			MockMetaData::reset();
		}
		if (class_exists('MockFilters')) {
			MockFilters::reset();
		}

		if (!defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.6-test');
		}

		require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
		require_once dirname(__DIR__) . '/includes/language-helpers.php';
		require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';

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
		MockFilters::$filters['wpml_default_language'] = static function () {
			return 'en';
		};
		MockFilters::$filters['wpml_object_id'] = static function ($id, $type = null, $return_original = null, $lang = null) use ($en, $fr, $de) {
			$id = (int) $id;
			if ($type !== 'product_variation') {
				return $id;
			}
			$map = [
				$en => ['en' => $en, 'fr' => $fr, 'de' => $de],
				$fr => ['en' => $en, 'fr' => $fr, 'de' => $de],
				$de => ['en' => $en, 'fr' => $fr, 'de' => $de],
			];
			if (!isset($map[$id])) {
				return $id;
			}
			$lang = $lang ?: 'en';
			return $map[$id][$lang] ?? $id;
		};
	}

	public function test_update_camp_schedule_fans_out_to_fr_de_siblings(): void {
		intersoccer_update_camp_schedule($this->en_id, '2026-07-06', '2026-07-10', 2, true);

		$this->assertSame('2026-07-06', get_post_meta($this->fr_id, '_camp_start_date', true));
		$this->assertSame('2026-07-10', get_post_meta($this->fr_id, '_camp_end_date', true));
		$this->assertSame(2, (int) get_post_meta($this->fr_id, '_camp_week_index', true));

		$this->assertSame('2026-07-06', get_post_meta($this->de_id, '_camp_start_date', true));
		$this->assertSame('2026-07-10', get_post_meta($this->de_id, '_camp_end_date', true));
		$this->assertSame(2, (int) get_post_meta($this->de_id, '_camp_week_index', true));
	}

	public function test_direct_sync_uses_post_meta_without_update_camp_schedule(): void {
		update_post_meta($this->en_id, '_camp_start_date', '2026-08-03');
		update_post_meta($this->en_id, '_camp_end_date', '2026-08-07');
		update_post_meta($this->en_id, '_camp_week_index', 1);

		// Fan-out must not call intersoccer_update_camp_schedule (would re-enter sync).
		intersoccer_sync_camp_schedule_to_translations($this->en_id);

		$this->assertSame('2026-08-03', get_post_meta($this->fr_id, '_camp_start_date', true));
		$this->assertSame('2026-08-07', get_post_meta($this->de_id, '_camp_end_date', true));
		// Source untouched by a recursive rewrite.
		$this->assertSame('2026-08-03', get_post_meta($this->en_id, '_camp_start_date', true));
	}

	public function test_get_camp_schedule_falls_back_to_default_language_meta(): void {
		update_post_meta($this->en_id, '_camp_start_date', '2026-09-07');
		update_post_meta($this->en_id, '_camp_end_date', '2026-09-11');
		update_post_meta($this->en_id, '_camp_week_index', 4);
		// FR empty locally.
		delete_post_meta($this->fr_id, '_camp_start_date');
		delete_post_meta($this->fr_id, '_camp_end_date');
		delete_post_meta($this->fr_id, '_camp_week_index');

		$raw = intersoccer_get_camp_schedule_meta($this->fr_id);
		$this->assertSame('', $raw['start']);

		$schedule = intersoccer_get_camp_schedule($this->fr_id, true);
		$this->assertSame('2026-09-07', $schedule['start']);
		$this->assertSame('2026-09-11', $schedule['end']);
		$this->assertSame(4, $schedule['week']);
		$this->assertSame('wpml_default', $schedule['source']);
	}

	public function test_foreach_translated_product_variations_excludes_source(): void {
		$siblings = intersoccer_foreach_translated_product_variations($this->en_id);
		$this->assertEqualsCanonicalizing([$this->fr_id, $this->de_id], $siblings);
		$this->assertNotContains($this->en_id, $siblings);
	}

	public function test_translation_complete_copies_schedule_from_default(): void {
		update_post_meta($this->en_id, '_camp_start_date', '2026-10-05');
		update_post_meta($this->en_id, '_camp_end_date', '2026-10-09');
		update_post_meta($this->en_id, '_camp_week_index', 5);

		if (!function_exists('intersoccer_is_camp')) {
			function intersoccer_is_camp($product_id) {
				return true;
			}
		}
		if (!function_exists('get_post_type')) {
			function get_post_type($post_id) {
				return 'product_variation';
			}
		}
		if (!function_exists('wp_get_post_parent_id')) {
			function wp_get_post_parent_id($post_id) {
				return 9999;
			}
		}

		intersoccer_sync_camp_schedule_on_translation_complete($this->fr_id);

		$this->assertSame('2026-10-05', get_post_meta($this->fr_id, '_camp_start_date', true));
		$this->assertSame('2026-10-09', get_post_meta($this->fr_id, '_camp_end_date', true));
		$this->assertSame(5, (int) get_post_meta($this->fr_id, '_camp_week_index', true));
	}
}
