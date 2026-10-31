<?php
/**
 * Camp/course time ranges: fixed per-language formats on the product page only.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

if (!function_exists('wp_is_json_request')) {
    function wp_is_json_request() {
        return !empty($GLOBALS['intersoccer_test_is_json_request']);
    }
}

require_once dirname(__DIR__) . '/tests/bootstrap.php';
require_once dirname(__DIR__) . '/includes/woocommerce/time-format.php';

class TimeRangeFormatTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedGet = [];

    protected function setUp(): void
    {
        parent::setUp();
        MockFilters::reset();
        MockFilters::$filters['wpml_current_language'] = static fn () => 'en';
    }

    protected function tearDown(): void
    {
        MockFilters::reset();
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_doing_ajax'],
            $GLOBALS['intersoccer_test_is_json_request']
        );
        if ($this->savedServer !== []) {
            $_SERVER = $this->savedServer;
            $this->savedServer = [];
        }
        if ($this->savedGet !== []) {
            $_GET = $this->savedGet;
            $this->savedGet = [];
        }
        parent::tearDown();
    }

    private function snapshotRequestGlobals(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
    }

    /**
     * Real pa_camp-times term name styles (EN/FR/DE catalogue + #134 QA).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function campTimeFixtures(): array
    {
        $en = static fn (string $start, string $end) => $start . "\u{2013}" . $end;
        $fr = static fn (string $start, string $end) => $start . "\u{2013}" . $end;

        $rows = [
            ['08:00-12:00', '08:00', '12:00'],
            ['08:00-16:00', '08:00', '16:00'],
            ['08:30-12:00', '08:30', '12:00'],
            ['08:30-16:00', '08:30', '16:00'],
            ['09:00-16:00', '09:00', '16:00'],
            ['09:30-12:00', '09:30', '12:00'],
            ['09:30-16:30', '09:30', '16:30'],
            ['0945-1215', '09:45', '12:15'],
            ['0945-1645', '09:45', '16:45'],
            ['09h00-16h00', '09:00', '16:00'],
            ['09h30-12h00', '09:30', '12:00'],
            ['1000-1130', '10:00', '11:30'],
            ['1000-1230', '10:00', '12:30'],
            ['1000-1500', '10:00', '15:00'],
            ['1000-1700', '10:00', '17:00'],
            ['10h00-11h30', '10:00', '11:30'],
            ['10h00-15h00', '10:00', '15:00'],
            ['1100-1230', '11:00', '12:30'],
            ['1130-1230', '11:30', '12:30'],
            ['11h00-12h30', '11:00', '12:30'],
            ['12:00-16:00', '12:00', '16:00'],
            ['12:30-16:00', '12:30', '16:00'],
            ['12h30-16h00', '12:30', '16:00'],
            ['8h30-12h00', '08:30', '12:00'],
            ['8h30-16h00', '08:30', '16:00'],
            ['0830-1530', '08:30', '15:30'],
            ['1200-1530', '12:00', '15:30'],
            ['08.30-15.30', '08:30', '15:30'],
        ];

        $out = [];
        foreach ($rows as [$raw, $start, $end]) {
            $fr_start = str_replace(':', 'h', $start);
            $fr_end   = str_replace(':', 'h', $end);
            $out[$raw] = [
                $raw,
                $en($start, $end),
                $fr($fr_start, $fr_end),
            ];
        }

        return $out;
    }

    #[DataProvider('campTimeFixtures')]
    public function testCampTimeFixturesEnglish(string $raw, string $expectedEn, string $_expectedFr): void
    {
        MockFilters::$filters['wpml_current_language'] = static fn () => 'en';
        $this->assertSame($expectedEn, intersoccer_format_time_range($raw));
    }

    #[DataProvider('campTimeFixtures')]
    public function testCampTimeFixturesGermanMatchesEnglish(string $raw, string $expectedEn, string $_expectedFr): void
    {
        MockFilters::$filters['wpml_current_language'] = static fn () => 'de';
        $this->assertSame($expectedEn, intersoccer_format_time_range($raw));
    }

    #[DataProvider('campTimeFixtures')]
    public function testCampTimeFixturesFrench(string $raw, string $_expectedEn, string $expectedFr): void
    {
        MockFilters::$filters['wpml_current_language'] = static fn () => 'fr';
        $this->assertSame($expectedFr, intersoccer_format_time_range($raw));
    }

    public static function unchangedValues(): array
    {
        return [
            'half day suffix' => ['1000-1230 (Half Day)'],
            'full day label'  => ['Full Day'],
            'week label'      => ['Autumn Week 4: October 19 - 23 (5 days)'],
            'empty'           => [''],
            'end before start compact' => ['1230-1000'],
            'end before start h'       => ['16h00-8h30'],
            'equal times compact'      => ['1000-1000'],
            'out of range hours'       => ['2500-2600'],
            'out of range minutes'     => ['1060-1230'],
        ];
    }

    #[DataProvider('unchangedValues')]
    public function testInvalidOrNonRangeUnchanged(string $raw): void
    {
        $this->assertSame($raw, intersoccer_format_time_range($raw));
    }

    public function testFallbackValidatorRejectsEndBeforeStart(): void
    {
        $this->assertFalse(function_exists('intersoccer_attr_is_valid_camp_time_range'));
        $this->assertSame('1230-1000', intersoccer_format_time_range('1230-1000'));
    }

    public function testTimeRangeFormatFilterReceivesDefaultAndLanguage(): void
    {
        MockFilters::$filters['wpml_current_language'] = static fn () => 'fr';
        $seen = [];
        MockFilters::$filters['intersoccer_time_range_format'] = static function ($default, $language) use (&$seen) {
            $seen = [$default, $language];

            return 'H:i';
        };
        $this->assertSame("10:00\u{2013}12:30", intersoccer_format_time_range('1000-1230'));
        $this->assertSame(['H\hi', 'fr'], $seen);
    }

    public function testSeparatorFilter(): void
    {
        MockFilters::$filters['intersoccer_time_range_separator'] = static fn () => ' - ';
        $this->assertSame('10:00 - 12:30', intersoccer_format_time_range('1000-1230'));
    }

    public function testUnknownLanguageUsesHiFormat(): void
    {
        MockFilters::$filters['wpml_current_language'] = static fn () => 'it';
        $this->assertSame('H:i', intersoccer_time_range_format('it'));
        $this->assertSame("10:00\u{2013}12:30", intersoccer_format_time_range('1000-1230'));
    }

    public function testTaxonomyScoping(): void
    {
        $camp  = (object) ['taxonomy' => 'pa_camp-times'];
        $course = (object) ['taxonomy' => 'pa_course-times'];
        $age   = (object) ['taxonomy' => 'pa_age-group'];

        $this->assertSame("10:00\u{2013}12:30", intersoccer_variation_option_time_range('1000-1230', $camp, 'pa_camp-times', null));
        $this->assertSame("10:00\u{2013}11:30", intersoccer_variation_option_time_range('10h00-11h30', $course, 'pa_course-times', null));
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $age, 'pa_age-group', null));
        $this->assertSame("10:00\u{2013}11:30", intersoccer_variation_option_time_range('1000-1130', null, 'pa_course-times', null));
        $this->assertSame('1000-1130', intersoccer_variation_option_time_range('1000-1130', null, 'pa_camp-terms', null));
    }

    public function testAdminContextLeavesNameUnchanged(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }

    public function testAdminAjaxContextLeavesNameUnchanged(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_doing_ajax'] = true;
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }

    public function testStoreApiRequestUriLeavesNameUnchanged(): void
    {
        $this->snapshotRequestGlobals();
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }

    public function testStoreApiRestRouteLeavesNameUnchanged(): void
    {
        $this->snapshotRequestGlobals();
        $_GET['rest_route'] = '/wc/store/v1/cart';
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }

    public function testJsonRequestLeavesNameUnchanged(): void
    {
        $GLOBALS['intersoccer_test_is_json_request'] = true;
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestRequestConstantLeavesNameUnchanged(): void
    {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $term = (object) ['taxonomy' => 'pa_camp-times'];
        $this->assertSame('1000-1230', intersoccer_variation_option_time_range('1000-1230', $term, 'pa_camp-times', null));
    }
}
