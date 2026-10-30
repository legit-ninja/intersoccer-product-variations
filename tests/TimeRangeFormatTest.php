<?php
/**
 * Camp/course time ranges show in one format (the site time_format) in variation selects.
 * Stored term names are not changed.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/time-format.php';

class TimeRangeFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MockFilters::reset();
        $GLOBALS['intersoccer_test_options']['time_format'] = 'H:i';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['intersoccer_test_options']['time_format']);
        MockFilters::reset();
        parent::tearDown();
    }

    public static function mixedStyles(): array
    {
        return [
            'compact camp'      => ['1000-1230', "10:00\u{2013}12:30"],
            'compact course'    => ['1000-1130', "10:00\u{2013}11:30"],
            'afternoon'         => ['1615-1715', "16:15\u{2013}17:15"],
            'french h'          => ['10h00-11h30', "10:00\u{2013}11:30"],
            'colon with spaces' => ['10:00 - 12:30', "10:00\u{2013}12:30"],
            'dots'              => ['9.30-12.30', "09:30\u{2013}12:30"],
            'three digits'      => ['930-1230', "09:30\u{2013}12:30"],
            'en dash'           => ["10:00\u{2013}17:00", "10:00\u{2013}17:00"],
        ];
    }

    #[DataProvider('mixedStyles')]
    public function testMixedStylesBecomeOneFormat(string $raw, string $expected): void
    {
        $this->assertSame($expected, intersoccer_format_time_range($raw));
    }

    public function testUsesSiteTimeFormat(): void
    {
        $GLOBALS['intersoccer_test_options']['time_format'] = 'G\hi';
        $this->assertSame("10h00\u{2013}12h30", intersoccer_format_time_range('1000-1230'));
        $this->assertSame("10h00\u{2013}12h30", intersoccer_format_time_range('10h00-12h30'));
    }

    public function testEmptyTimeFormatFallsBackTo24h(): void
    {
        $GLOBALS['intersoccer_test_options']['time_format'] = '';
        $this->assertSame("10:00\u{2013}12:30", intersoccer_format_time_range('1000-1230'));
    }

    public static function notTimeRanges(): array
    {
        return [
            ['Full Day'],
            ['Autumn Week 4: October 19 - 23 (5 days)'],
            ['1000-1230 (Half Day)'],
            ['2500-2600'],
            ['1060-1230'],
            [''],
        ];
    }

    #[DataProvider('notTimeRanges')]
    public function testNonRangesAreUnchanged(string $raw): void
    {
        $this->assertSame($raw, intersoccer_format_time_range($raw));
    }

    public function testOptionFilterOnlyTouchesTimeTaxonomies(): void
    {
        $camp_time = (object) ['taxonomy' => 'pa_camp-times'];
        $course_time = (object) ['taxonomy' => 'pa_course-times'];
        $age = (object) ['taxonomy' => 'pa_age-group'];

        $this->assertSame("10:00\u{2013}12:30", intersoccer_variation_option_time_range('1000-1230', $camp_time, 'pa_camp-times', null));
        $this->assertSame("10:00\u{2013}11:30", intersoccer_variation_option_time_range('10h00-11h30', $course_time, 'pa_course-times', null));
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $age, 'pa_age-group', null));
        // Without a term object, the attribute name decides.
        $this->assertSame("10:00\u{2013}11:30", intersoccer_variation_option_time_range('1000-1130', null, 'pa_course-times', null));
        $this->assertSame('1000-1130', intersoccer_variation_option_time_range('1000-1130', null, 'pa_camp-terms', null));
    }

    public function testFiltersCanOverrideFormatAndSeparator(): void
    {
        MockFilters::$filters['intersoccer_time_of_day_format'] = static fn ($f) => 'H.i';
        $this->assertSame("10.00\u{2013}12.30", intersoccer_format_time_range('1000-1230'));
        MockFilters::$filters['intersoccer_time_range_separator'] = static fn ($s) => ' - ';
        $this->assertSame('10.00 - 12.30', intersoccer_format_time_range('1000-1230'));
    }
}
