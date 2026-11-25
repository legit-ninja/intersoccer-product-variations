<?php
/**
 * Swiss admin date parsing and formatting (DD/MM/YYYY in, Y-m-d stored).
 *
 * Run: vendor/bin/phpunit tests/AdminDateFormatTest.php
 */

use PHPUnit\Framework\TestCase;

class AdminDateFormatTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__) . '/includes/woocommerce/admin-date-format.php';
        $GLOBALS['intersoccer_admin_date_errors'] = [];
    }

    public function testSwissDotDateIsSixthOfMarchNotThirdOfJune() {
        $this->assertSame('2027-03-06', intersoccer_admin_parse_date('06.03.2027'));
        $this->assertSame('2027-03-06', intersoccer_admin_parse_date('06/03/2027'));
        $this->assertSame('2027-03-06', intersoccer_admin_parse_date('6/3/2027'));
        $this->assertSame('2027-03-06', intersoccer_admin_parse_date('6.3.2027'));
    }

    public function testIsoDateIsNotSwapped() {
        $this->assertSame('2027-03-06', intersoccer_admin_parse_date('2027-03-06'));
        $this->assertSame('2027-06-03', intersoccer_admin_parse_date('2027-06-03'));
        $this->assertSame('2027-06-03', intersoccer_admin_parse_date('2027/06/03'));
    }

    public function testDayOverTwelveIsMarchNotRejectedAsUsMonth() {
        $this->assertSame('2026-01-13', intersoccer_admin_parse_date('13/01/2026'));
        $this->assertSame('2026-01-13', intersoccer_admin_parse_date('13.01.2026'));
    }

    public function testThirdOfJuneSwissIsJune() {
        $this->assertSame('2027-06-03', intersoccer_admin_parse_date('03/06/2027'));
        $this->assertSame('2027-06-03', intersoccer_admin_parse_date('03.06.2027'));
    }

    public function testImpossibleDatesAreRejectedNotRolledOrSwapped() {
        $this->assertSame('', intersoccer_admin_parse_date('31/02/2027'));
        $this->assertSame('', intersoccer_admin_parse_date('02/31/2027'));
        $this->assertSame('', intersoccer_admin_parse_date('29/02/2027'));
        $this->assertSame('2028-02-29', intersoccer_admin_parse_date('29/02/2028'));
        $this->assertSame('', intersoccer_admin_parse_date('00/03/2027'));
        $this->assertSame('', intersoccer_admin_parse_date('32/01/2027'));
        $this->assertSame('', intersoccer_admin_parse_date('15/13/2027'));
        $this->assertTrue(intersoccer_admin_parse_date_result('31/02/2027')['invalid']);
    }

    public function testBlankAndGarbage() {
        $this->assertSame('', intersoccer_admin_parse_date(''));
        $this->assertSame('', intersoccer_admin_parse_date('   '));
        $this->assertSame('', intersoccer_admin_parse_date('not-a-date'));
        $this->assertSame('', intersoccer_admin_parse_date('03-06-2027'));
        $this->assertSame('', intersoccer_admin_parse_date('06.03.27'));
        $this->assertTrue(intersoccer_admin_parse_date_result('')['empty']);
        $this->assertTrue(intersoccer_admin_parse_date_result('not-a-date')['invalid']);
        $this->assertFalse(intersoccer_admin_parse_date_result('')['invalid']);
    }

    public function testFormatStoredIsoAsDayMonthYear() {
        $this->assertSame('06/03/2027', intersoccer_admin_format_date('2027-03-06'));
        $this->assertSame('03/06/2027', intersoccer_admin_format_date('2027-06-03'));
        $this->assertSame('06/03/2027', intersoccer_admin_format_date('06.03.2027'));
        $this->assertSame('', intersoccer_admin_format_date(''));
        $this->assertSame('', intersoccer_admin_format_date('31/02/2027'));
    }

    public function testValidatedCourseStartDateAcceptsSwissAndIso() {
        require_once dirname(__DIR__) . '/includes/admin-product-fields.php';
        $this->assertSame('2027-03-06', intersoccer_validated_course_start_date('06.03.2027'));
        $this->assertSame('2027-03-06', intersoccer_validated_course_start_date('06/03/2027'));
        $this->assertSame('2027-03-06', intersoccer_validated_course_start_date('2027-03-06'));
        $this->assertSame('', intersoccer_validated_course_start_date('31/02/2027'));
        $this->assertSame('', intersoccer_validated_course_start_date('not-a-date'));
    }

    public function testPostedSwissHolidayDatesAreStoredAsYmd() {
        require_once dirname(__DIR__) . '/includes/admin-product-fields.php';
        $_POST = [];
        $variation_id = 9401;
        $loop = 0;
        update_post_meta($variation_id, '_course_holiday_dates', []);
        $_POST['intersoccer_holiday_dates_present'] = [$loop => '1'];
        $_POST['intersoccer_holiday_dates'] = [$loop => ['02.04.2027', '16/05/2027', '2027-04-18']];
        $saved = intersoccer_save_course_holiday_dates($variation_id, $loop);
        $this->assertSame(['2027-04-02', '2027-05-16', '2027-04-18'], $saved);
        $this->assertSame([], intersoccer_admin_date_errors());
        $_POST = [];
    }

    public function testImpossibleHolidayIsFlaggedAndOmitted() {
        require_once dirname(__DIR__) . '/includes/admin-product-fields.php';
        $_POST = [];
        $variation_id = 9402;
        $loop = 0;
        update_post_meta($variation_id, '_course_holiday_dates', ['2027-01-01']);
        $_POST['intersoccer_holiday_dates'] = [$loop => ['06.03.2027', '31/02/2027']];
        $saved = intersoccer_save_course_holiday_dates($variation_id, $loop);
        $this->assertSame(['2027-03-06'], $saved);
        $errors = intersoccer_admin_date_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('31/02/2027', $errors[0]);
        $_POST = [];
    }

    public function testCampScheduleNormalizerAcceptsSwissWeekStart() {
        require_once dirname(__DIR__) . '/includes/woocommerce/camp-schedule.php';
        $this->assertSame('2027-03-06', intersoccer_camp_schedule_normalize_ymd('06.03.2027'));
        $this->assertSame('2027-03-06', intersoccer_camp_schedule_normalize_ymd('2027-03-06'));
        $this->assertSame('', intersoccer_camp_schedule_normalize_ymd('31/02/2027'));
        $proposed = intersoccer_camp_schedule_propose_from_week1('06.03.2027', 1, 5);
        $this->assertSame('2027-03-06', $proposed['start']);
        $this->assertSame('2027-03-10', $proposed['end']);
    }
}
