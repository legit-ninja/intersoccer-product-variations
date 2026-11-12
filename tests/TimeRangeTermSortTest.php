<?php
/**
 * Camp and course time options sort by start time, including French labels.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/woocommerce/time-format.php';

class TimeRangeTermSortTest extends TestCase
{
    public function test_french_time_options_sort_by_start_time()
    {
        $names = [
            '16h15-17h15',
            '9h30-11h00',
            '13h00-14h00',
            '10h00-11h30',
            '11h15-12h45',
            '16h00-17h30',
        ];
        $terms = array_map(static function ($name) {
            return (object) ['name' => $name, 'slug' => $name];
        }, $names);

        $sorted = intersoccer_sort_time_range_product_terms($terms, 1, 'pa_course-times', ['orderby' => 'menu_order']);
        $got = array_map(static function ($term) {
            return $term->name;
        }, $sorted);

        $this->assertSame([
            '9h30-11h00',
            '10h00-11h30',
            '11h15-12h45',
            '13h00-14h00',
            '16h00-17h30',
            '16h15-17h15',
        ], $got);
    }

    public function test_compact_and_unparsed_terms_sort_by_start_then_last()
    {
        $terms = [
            (object) ['name' => 'Afternoon'],
            (object) ['name' => '0930-1100'],
            (object) ['name' => '16h15-17h15'],
        ];

        $sorted = intersoccer_sort_time_range_product_terms($terms, 1, 'pa_camp-times', []);
        $got = array_map(static function ($term) {
            return $term->name;
        }, $sorted);

        $this->assertSame(['0930-1100', '16h15-17h15', 'Afternoon'], $got);
    }

    public function test_other_taxonomies_keep_their_order()
    {
        $terms = ['16h00', '9h00'];
        $this->assertSame($terms, intersoccer_sort_time_range_product_terms($terms, 1, 'pa_venues', []));
    }
}
