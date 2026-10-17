<?php
/**
 * Camp add-to-cart validation must not log request or player fields when debug is captured.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/woocommerce/product-camp.php';

class CampValidationDebugLoggingTest extends TestCase
{
    private $product_id = 89100;
    private $variation_id = 89101;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $GLOBALS['intersoccer_captured_debug'] = [];
        $GLOBALS['intersoccer_capture_debug'] = true;
        update_post_meta($this->variation_id, 'attribute_pa_booking-type', 'single-days');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $GLOBALS['intersoccer_capture_debug'] = false;
        $GLOBALS['intersoccer_captured_debug'] = [];
        parent::tearDown();
    }

    public function testSingleDayValidationFailureDoesNotLogRequestValues(): void
    {
        $sample_name = 'CampDebugSampleChild';
        $sample_dob = '2015-06-01';
        $sample_medical = 'CampDebugSampleAsthma';

        $_POST['variation_id'] = (string) $this->variation_id;
        $_POST['camp_days'] = [];
        $_POST['assigned_attendee'] = $sample_name;
        $_POST['player_medical'] = $sample_medical;
        $_POST['attendee_dob'] = $sample_dob;

        $passed = InterSoccer_Camp::validate_single_day(true, $this->product_id, 1);

        $this->assertFalse($passed);
        $this->assertNotEmpty($GLOBALS['intersoccer_captured_debug'], 'Expected validation path to emit debug when capture is enabled');

        $log_blob = implode("\n", $GLOBALS['intersoccer_captured_debug']);
        $this->assertStringNotContainsString($sample_name, $log_blob);
        $this->assertStringNotContainsString($sample_dob, $log_blob);
        $this->assertStringNotContainsString($sample_medical, $log_blob);
        $this->assertStringNotContainsString('assigned_attendee', $log_blob);
        $this->assertStringNotContainsString('player_medical', $log_blob);
    }
}
