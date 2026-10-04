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
        $_POST["intersoccer_holiday_dates"] = [$this->loop => ["", "not-a-date"]];
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
}
