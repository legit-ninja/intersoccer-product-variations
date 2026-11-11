<?php
/**
 * New camp-term labels/slugs, with legacy season-week slugs unchanged.
 */

use PHPUnit\Framework\TestCase;

class CampTermFormatTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (class_exists('MockMetaData')) {
            MockMetaData::reset();
        }
        require_once dirname(__DIR__) . '/includes/woocommerce/attribute-registry.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/age-group-verification.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
        if (!function_exists('intersoccer_parse_camp_week_from_terms')) {
            require_once dirname(__DIR__) . '/includes/woocommerce/discounts.php';
        }
    }

    public function test_new_human_form_any_case_with_and_without_trailing_word() {
        $plain = intersoccer_parse_camp_term_schedule('Week 1: March 22-25 (4 days)', 'Sample Season 2026');
        $this->assertSame(1, $plain['week']);
        $this->assertSame('2026-03-22', $plain['start']);
        $this->assertSame('2026-03-25', $plain['end']);
        $this->assertSame(4, $plain['days']);

        $lower = intersoccer_parse_camp_term_schedule('week 1: march 22-25 (4 days)', 'Sample Season 2026');
        $this->assertSame($plain, $lower);

        $trailing = intersoccer_parse_camp_term_schedule('WEEK 1: MARCH 22-25 (4 DAYS CAMPS)', 'Sample Season 2026');
        $this->assertSame(1, $trailing['week']);
        $this->assertSame('2026-03-22', $trailing['start']);
        $this->assertSame('2026-03-25', $trailing['end']);
        $this->assertSame(4, $trailing['days']);

        $cross = intersoccer_parse_camp_term_schedule('Week 2: June 29-July 3 (5 days)', 'Sample Season 2026');
        $this->assertSame(2, $cross['week']);
        $this->assertSame('2026-06-29', $cross['start']);
        $this->assertSame('2026-07-03', $cross['end']);
        $this->assertSame(5, $cross['days']);
    }

    public function test_new_slug_forms() {
        foreach (array('week-1-march-22-25-4-days', 'week-1-march-22-25-4-days-camps') as $slug) {
            $parts = intersoccer_parse_camp_term_schedule($slug, 'Sample Season 2026');
            $this->assertSame(1, $parts['week'], $slug);
            $this->assertSame('2026-03-22', $parts['start'], $slug);
            $this->assertSame('2026-03-25', $parts['end'], $slug);
            $this->assertSame(4, $parts['days'], $slug);
            $this->assertSame(1, intersoccer_parse_camp_week_from_terms($slug), $slug);
        }

        $upper = intersoccer_parse_camp_term_schedule('WEEK-1-MARCH-22-25-4-DAYS', 'Sample Season 2026');
        $this->assertSame('2026-03-22', $upper['start']);
        $this->assertSame('2026-03-25', $upper['end']);
    }

    public function test_old_slugs_keep_the_same_start_dates_and_weeks() {
        $this->assertSame(
            '2025-10-20',
            intersoccer_parse_camp_start_date_from_terms('autumn-week-4-october-20-october-24-5-days', 'Autumn 2025')
        );
        $this->assertSame(
            '2025-06-24',
            intersoccer_parse_camp_start_date_from_terms('summer-week-2-june-24-june-28-5-days', '2025')
        );
        $this->assertSame(
            '2025-06-24',
            intersoccer_parse_camp_start_date_from_terms('summer-week-2-june-24-28-5-days', '2025')
        );
        $this->assertSame(
            '2025-10-19',
            intersoccer_parse_camp_start_date_from_terms('autumn-week-4-october-19-23-5-days', 'Autumn 2025')
        );
        $this->assertSame(
            '2026-06-29',
            intersoccer_parse_camp_start_date_from_terms('sommer-woche-2-juni-29-juli-3-5-tage', '2026')
        );
        $this->assertSame(
            '2026-06-29',
            intersoccer_parse_camp_start_date_from_terms('été-semaine-2-juin-29-juillet-3-5-jours', '2026')
        );

        $old = intersoccer_camp_schedule_from_terms_deprecated(
            'autumn-week-4-october-20-october-24-5-days',
            'Autumn 2025'
        );
        $this->assertSame('2025-10-20', $old['start']);
        $this->assertSame(4, $old['week']);
        $this->assertSame('2025-10-24', $old['end']);

        $german = intersoccer_camp_schedule_from_terms_deprecated(
            'sommer-woche-2-juni-29-juli-3-5-tage',
            '2026'
        );
        $this->assertSame('2026-06-29', $german['start']);
        $this->assertSame('2026-07-03', $german['end']);
        $this->assertNull(intersoccer_parse_camp_week_from_terms('sommer-woche-2-juni-29-juli-3-5-tage'));
        $this->assertNull($german['week']);

        $this->assertSame(2, intersoccer_parse_camp_week_from_terms('Summer Week 2: July 6-10 (5 days)'));
        $legacy_label = intersoccer_parse_camp_term_schedule('Summer Week 2: July 6-10 (5 days)', 'Sample Season 2026');
        $this->assertSame(2, $legacy_label['week']);
        $this->assertSame('2026-07-06', $legacy_label['start']);
        $this->assertSame('2026-07-10', $legacy_label['end']);
        $this->assertSame(5, $legacy_label['days']);
    }

    public function test_short_month_day_labels_still_parse() {
        $this->assertSame(
            '2026-03-22',
            intersoccer_parse_camp_start_date_from_terms('Sample: March 22', 'Sample Season 2026')
        );
        $this->assertSame(
            '2026-03-22',
            intersoccer_parse_camp_start_date_from_terms('March 22', 'Sample Season 2026')
        );
    }

    public function test_season_field_wins_and_term_text_is_only_a_fallback() {
        $old_slug = 'summer-week-2-june-24-june-28-5-days';
        $new_slug = 'week-1-march-22-25-4-days';

        $from_field = intersoccer_resolve_camp_season_for_week_identity(
            array('', 'Sample Season 2026'),
            $old_slug
        );
        $this->assertSame('Sample Season 2026', $from_field);
        $this->assertSame(
            'sample season 2026|2',
            intersoccer_discount_camp_week_identity($from_field, intersoccer_parse_camp_week_from_terms($old_slug))
        );

        $from_order = intersoccer_resolve_camp_season_for_week_identity(
            array('', '', 'Order Season 2026'),
            $old_slug
        );
        $this->assertSame('Order Season 2026', $from_order);

        $this->assertSame(
            'summer',
            intersoccer_resolve_camp_season_for_week_identity(array('', ''), $old_slug)
        );
        $this->assertSame('summer', intersoccer_legacy_season_token_from_camp_terms($old_slug));
        $this->assertSame('Summer', intersoccer_legacy_season_token_from_camp_terms('Summer Week 2: July 6-10 (5 days)'));
        $this->assertSame('sommer', intersoccer_legacy_season_token_from_camp_terms('sommer-woche-2-juni-29-juli-3-5-tage'));

        $this->assertSame('', intersoccer_legacy_season_token_from_camp_terms('Week 1: March 22-25 (4 days camps)'));
        $this->assertSame('', intersoccer_legacy_season_token_from_camp_terms($new_slug));
        $this->assertSame('', intersoccer_legacy_season_token_from_camp_terms('week-1-march-22-25-4-days-camps'));
        $this->assertSame(
            '',
            intersoccer_resolve_camp_season_for_week_identity(array('', ''), 'Week 1: March 22-25 (4 days)')
        );
    }

    public function test_discount_week_identity_uses_season_plus_week_for_new_terms() {
        $week = intersoccer_parse_camp_week_from_terms('week-1-march-22-25-4-days');
        $label_week = intersoccer_parse_camp_week_from_terms('Week 1: March 22-25 (4 days camps)');
        $this->assertSame(1, $week);
        $this->assertSame($week, $label_week);

        $sample = intersoccer_discount_camp_week_identity('Sample Season 2026', $week);
        $same = intersoccer_discount_camp_week_identity(
            intersoccer_resolve_camp_season_for_week_identity('Sample Season 2026', 'week-1-march-22-25-4-days-camps'),
            $label_week
        );
        $other = intersoccer_discount_camp_week_identity('Other Season 2026', $week);

        $this->assertSame('sample season 2026|1', $sample);
        $this->assertSame($sample, $same);
        $this->assertNotSame($sample, $other);
        $this->assertSame('', intersoccer_discount_camp_week_identity('Sample Season 2026', null));
    }

    public function test_date_meta_wins_over_a_parseable_new_term() {
        $vid = 55120;
        intersoccer_update_camp_schedule($vid, '2026-01-05', '2026-01-08', 9, true);
        MockMetaData::$data[$vid]['attribute_pa_camp-terms'] = 'week-1-march-22-25-4-days';
        MockMetaData::$data[$vid]['attribute_pa_program-season'] = 'Sample Season 2026';

        $schedule = intersoccer_get_camp_schedule($vid, true);
        $this->assertSame('2026-01-05', $schedule['start']);
        $this->assertSame('2026-01-08', $schedule['end']);
        $this->assertSame(9, $schedule['week']);
        $this->assertSame('meta', $schedule['source']);
    }

    public function test_missing_dates_flag() {
        $bare = 55130;
        $this->assertSame('Missing dates', intersoccer_camp_variation_missing_dates_flag($bare));

        $dated = 55131;
        intersoccer_update_camp_schedule($dated, '2026-03-22', '2026-03-25', 1, true);
        MockMetaData::$data[$dated]['attribute_pa_camp-terms'] = 'not-a-term';
        $this->assertSame('', intersoccer_camp_variation_missing_dates_flag($dated));

        $parsed_new = 55132;
        MockMetaData::$data[$parsed_new]['attribute_pa_camp-terms'] = 'week-1-march-22-25-4-days-camps';
        MockMetaData::$data[$parsed_new]['attribute_pa_program-season'] = 'Sample Season 2026';
        $this->assertSame('', intersoccer_camp_variation_missing_dates_flag($parsed_new));

        $parsed_old = 55133;
        MockMetaData::$data[$parsed_old]['attribute_pa_camp-terms'] = 'sommer-woche-2-juni-29-juli-3-5-tage';
        MockMetaData::$data[$parsed_old]['attribute_pa_program-season'] = '2026';
        $this->assertSame('', intersoccer_camp_variation_missing_dates_flag($parsed_old));
    }

    public function test_migrate_accepts_new_format_and_keeps_old_start_dates() {
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
        $saved_filters = class_exists('MockFilters') ? MockFilters::$filters : null;
        require_once dirname(__DIR__) . '/includes/woocommerce/product-types.php';
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule-migrate.php';
        if ($saved_filters !== null) {
            MockFilters::$filters = $saved_filters;
        }

        $parent = 55101;
        $new_var = 55111;
        $old_var = 55112;
        unset($GLOBALS['intersoccer_test_transients']['intersoccer_type_' . $parent]);
        MockMetaData::$data[$parent]['_intersoccer_product_type'] = 'camp';
        MockMetaData::$data[$new_var]['attribute_pa_camp-terms'] = 'week-1-march-22-25-4-days-camps';
        MockMetaData::$data[$new_var]['attribute_pa_program-season'] = 'Sample Season 2026';
        MockMetaData::$data[$old_var]['attribute_pa_camp-terms'] = 'autumn-week-4-october-20-october-24-5-days';
        MockMetaData::$data[$old_var]['attribute_pa_program-season'] = 'Autumn 2025';

        $GLOBALS['intersoccer_test_products'][$parent] = new class($parent, array($new_var, $old_var)) {
            private $id;
            private $children;
            public function __construct($id, array $children) {
                $this->id = (int) $id;
                $this->children = $children;
            }
            public function get_id() {
                return $this->id;
            }
            public function is_type($type) {
                return $type === 'variable';
            }
            public function get_children() {
                return $this->children;
            }
            public function get_attributes() {
                return array();
            }
            public function get_attribute($name) {
                return '';
            }
            public function get_parent_id() {
                return 0;
            }
        };

        $result = intersoccer_migrate_camp_dates_for_product($parent, array('dry_run' => true));
        $this->assertIsArray($result);
        $by_id = array();
        foreach ($result['rows'] as $row) {
            $by_id[(int) $row['variation_id']] = $row;
        }

        $this->assertSame('would_update', $by_id[$new_var]['status']);
        $this->assertSame('2026-03-22', $by_id[$new_var]['schedule']['start']);
        $this->assertSame('2026-03-25', $by_id[$new_var]['schedule']['end']);
        $this->assertSame(1, $by_id[$new_var]['schedule']['week']);

        $this->assertSame('would_update', $by_id[$old_var]['status']);
        $this->assertSame('2025-10-20', $by_id[$old_var]['schedule']['start']);
        $this->assertSame(4, $by_id[$old_var]['schedule']['week']);

        unset($GLOBALS['intersoccer_test_products'][$parent]);
    }
}
