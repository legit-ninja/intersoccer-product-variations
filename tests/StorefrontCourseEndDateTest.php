<?php
/**
 * Storefront end-date fallback must use the variation course day.
 */

use PHPUnit\Framework\TestCase;

class StorefrontCourseEndDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/admin-product-fields.php';
    }

    public function testMissingEndDateUsesTheVariationCourseDay()
    {
        $parent_id = 9200;
        $wednesday_variation = 9201;
        update_post_meta($wednesday_variation, 'attribute_pa_course-day', 'wednesday');

        $end = intersoccer_storefront_course_end_date(
            $wednesday_variation,
            $parent_id,
            '',
            '2026-01-05',
            4,
            []
        );

        $this->assertSame('2026-01-28', $end, 'Four Wednesday sessions, not the parent Monday date 2026-01-26');
        $this->assertSame('', get_post_meta($wednesday_variation, '_end_date', true), 'The fallback must not write the variation row');

        $with_holiday = intersoccer_storefront_course_end_date(
            $wednesday_variation,
            $parent_id,
            '',
            '2026-01-05',
            4,
            ['2026-01-14']
        );
        $this->assertSame('2026-02-04', $with_holiday, 'A holiday on the variation course day still extends the end date');
    }

    public function testInvalidStoredEndDateUsesTheVariationCourseDay()
    {
        $variation_id = 9202;
        update_post_meta($variation_id, 'attribute_pa_course-day', 'friday');
        update_post_meta($variation_id, '_end_date', 'not-a-date');

        $end = intersoccer_storefront_course_end_date(
            $variation_id,
            9200,
            'not-a-date',
            '2026-01-05',
            2,
            []
        );

        $this->assertSame('2026-01-16', $end);
        $this->assertSame('not-a-date', get_post_meta($variation_id, '_end_date', true), 'Stored meta is left alone');
    }

    public function testValidStoredEndDateIsShownUnchanged()
    {
        $variation_id = 9203;
        update_post_meta($variation_id, 'attribute_pa_course-day', 'wednesday');

        $end = intersoccer_storefront_course_end_date(
            $variation_id,
            9200,
            '2026-06-01',
            '2026-01-05',
            4,
            []
        );

        $this->assertSame('2026-06-01', $end);
    }

    public function testMalformedStartDateIsNotCalculated()
    {
        $variation_id = 9204;
        update_post_meta($variation_id, 'attribute_pa_course-day', 'wednesday');

        $this->assertSame(
            '',
            intersoccer_storefront_course_end_date($variation_id, 9200, '', '13/01/2026', 4, [])
        );
    }
}
