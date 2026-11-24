<?php
use PHPUnit\Framework\TestCase;

class CourseHolidayDatesSaveTest extends TestCase
{
    private $variation_id = 9301;
    private $loop = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['intersoccer_admin_date_errors'] = [];
        require_once dirname(__DIR__) . "/includes/admin-product-fields.php";
        update_post_meta($this->variation_id, "_course_holiday_dates", ["2026-01-14", "2026-02-11"]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function testMissingHolidayInputsKeepStoredDates()
    {
        $kept = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2026-01-14", "2026-02-11"], $kept);
        $this->assertSame(["2026-01-14", "2026-02-11"], get_post_meta($this->variation_id, "_course_holiday_dates", true));
    }

    public function testPresentFlagWithoutDatesClearsStoredHolidays()
    {
        $_POST["intersoccer_holiday_dates_present"] = [$this->loop => "1"];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame([], $saved);
        $this->assertSame([], get_post_meta($this->variation_id, "_course_holiday_dates", true));
    }

    public function testPostedEmptyDateValuesClearStoredHolidays()
    {
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["", ""]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame([], $saved);
        $this->assertSame([], get_post_meta($this->variation_id, "_course_holiday_dates", true));
    }

    public function testPostedHolidayDatesReplaceStoredOnes()
    {
        $_POST["intersoccer_holiday_dates_present"] = [$this->loop => "1"];
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["2026-03-18", "2026-03-18", "bad"]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2026-03-18"], $saved);
        $this->assertSame(["2026-03-18"], get_post_meta($this->variation_id, "_course_holiday_dates", true));
    }

    public function testInvalidEditOnExistingHolidayKeepsPreviousValue()
    {
        update_post_meta($this->variation_id, "_course_holiday_dates", ["2027-03-06", "2027-03-27", "2027-04-02"]);
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["06/03/2027", "31/02/2027", "02/04/2027"]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2027-03-06", "2027-03-27", "2027-04-02"], $saved);
        $this->assertSame(["2027-03-06", "2027-03-27", "2027-04-02"], get_post_meta($this->variation_id, "_course_holiday_dates", true));
        $errors = intersoccer_admin_date_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("31/02/2027", $errors[0]);
        $this->assertStringContainsString("not a valid", strtolower($errors[0]));
    }

    public function testMixOfValidEditAndInvalidEditOnExistingHolidays()
    {
        // All three stored dates are Saturdays, so dropping one would change the end date.
        update_post_meta($this->variation_id, "_course_holiday_dates", ["2027-03-27", "2027-04-10", "2027-05-15"]);
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["03.04.2027", "31/02/2027", "15/05/2027"]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2027-04-03", "2027-04-10", "2027-05-15"], $saved);
        $this->assertSame(["2027-04-03", "2027-04-10", "2027-05-15"], get_post_meta($this->variation_id, "_course_holiday_dates", true));
        $errors = intersoccer_admin_date_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("31/02/2027", $errors[0]);

        update_post_meta($this->variation_id, "attribute_pa_course-day", "saturday");
        $start = "2027-03-06";
        $end_with_kept = intersoccer_end_date_for_course_variation($this->variation_id, 9300, $start, 15, $saved);
        $end_if_dropped = intersoccer_end_date_for_course_variation($this->variation_id, 9300, $start, 15, ["2027-04-03", "2027-05-15"]);
        $this->assertSame(
            $end_with_kept,
            intersoccer_end_date_for_course_variation($this->variation_id, 9300, $start, 15, ["2027-04-03", "2027-04-10", "2027-05-15"])
        );
        $this->assertNotSame($end_if_dropped, $end_with_kept, "End date must not be recalculated from a shortened holiday list");
    }

    public function testInvalidEditOnTheOnlyHolidayKeepsPreviousValue()
    {
        update_post_meta($this->variation_id, "_course_holiday_dates", ["2027-03-27"]);
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["31/02/2027"]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2027-03-27"], $saved);
        $this->assertSame(["2027-03-27"], get_post_meta($this->variation_id, "_course_holiday_dates", true));
        $errors = intersoccer_admin_date_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("31/02/2027", $errors[0]);
    }

    public function testNewInvalidHolidayRowIsStillOmitted()
    {
        update_post_meta($this->variation_id, "_course_holiday_dates", ["2027-03-27"]);
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["27/03/2027", "31/02/2027"]];
        $saved = intersoccer_save_course_holiday_dates($this->variation_id, $this->loop);
        $this->assertSame(["2027-03-27"], $saved);
        $errors = intersoccer_admin_date_errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("31/02/2027", $errors[0]);
    }
}
