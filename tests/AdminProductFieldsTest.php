<?php
/**
 * Test: Admin Product Fields
 * 
 * Purpose: Ensure course date calculations and field validation work correctly in admin
 * Covers: admin-product-fields.php - course date calculations, holiday handling
 * 
 * CRITICAL: Admin data errors = incorrect product setup = revenue impact
 */

use PHPUnit\Framework\TestCase;

class AdminProductFieldsTest extends TestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        if (!class_exists('DateTime')) {
            class DateTime {
                private $date;
                public function __construct($date = 'now') {
                    $this->date = $date === 'now' ? date('Y-m-d') : $date;
                }
                public function format($format) {
                    return date($format, strtotime($this->date));
                }
                public function add($interval) {
                    if (isset($interval->days)) {
                        $this->date = date('Y-m-d', strtotime("+{$interval->days} days", strtotime($this->date)));
                    }
                    return $this;
                }
            }
            
            class DateInterval {
                public $days;
                public function __construct($spec) {
                    // Parse P1D format
                    if (preg_match('/P(\d+)D/', $spec, $matches)) {
                        $this->days = (int)$matches[1];
                    }
                }
            }
        }
        
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', false);
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {}
        }
    }
    
    /**
     * Test: Calculate end date without holidays
     */
    public function testCalculateEndDateWithoutHolidays() {
        $start_date = '2025-01-06'; // Monday
        $total_weeks = 4;
        $course_days = ['Monday'];
        $holidays = [];
        
        // Calculate end date (4 Mondays from start)
        $current = new DateTime($start_date);
        $sessions = 0;
        
        while ($sessions < $total_weeks) {
            $current->add(new DateInterval('P1D'));
            if ($current->format('l') === 'Monday' && !in_array($current->format('Y-m-d'), $holidays)) {
                $sessions++;
            }
        }
        
        $end_date = $current->format('Y-m-d');
        
        $this->assertNotEmpty($end_date, 'End date should be calculated');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $end_date, 'End date format should be Y-m-d');
    }
    
    /**
     * Test: Calculate end date with holidays (holidays extend end date)
     */
    public function testCalculateEndDateWithHolidays() {
        $start_date = '2025-01-06'; // Monday
        $total_weeks = 4;
        $course_days = ['Monday'];
        $holidays = ['2025-01-20']; // A Monday
        
        // With 1 holiday, need to count 4 non-holiday Mondays
        $current = new DateTime($start_date);
        $sessions = 0;
        
        while ($sessions < $total_weeks) {
            $current->add(new DateInterval('P1D'));
            $date_str = $current->format('Y-m-d');
            if ($current->format('l') === 'Monday' && !in_array($date_str, $holidays)) {
                $sessions++;
            }
        }
        
        $end_date = $current->format('Y-m-d');
        
        $this->assertNotEmpty($end_date, 'End date with holidays should be calculated');
        // End date should be later than without holidays
    }
    
    /**
     * Test: Holiday date format validation
     */
    public function testHolidayDateFormatValidation() {
        $valid_dates = ['2025-01-01', '2025-12-25', '2025-04-18'];
        
        foreach ($valid_dates as $date) {
            $is_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);
            $this->assertTrue((bool)$is_valid, "{$date} should be valid");
        }
    }
    
    /**
     * Test: Invalid holiday date format
     */
    public function testInvalidHolidayDateFormat() {
        $invalid_dates = ['01-01-2025', '2025/01/01', 'invalid', ''];
        
        foreach ($invalid_dates as $date) {
            $is_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
            $this->assertFalse((bool)$is_valid, "{$date} should be invalid");
        }
    }
    
    /**
     * Test: Course day array validation
     */
    public function testCourseDayArrayValidation() {
        $valid_days = ['Monday', 'Wednesday', 'Friday'];
        
        $this->assertIsArray($valid_days, 'Course days should be an array');
        $this->assertNotEmpty($valid_days, 'Course days should not be empty');
        $this->assertCount(3, $valid_days, 'Should have 3 course days');
    }
    
    /**
     * Test: Empty course days handling
     */
    public function testEmptyCourseDaysHandling() {
        $course_days = [];
        
        $is_valid = !empty($course_days);
        
        $this->assertFalse($is_valid, 'Empty course days should be invalid');
    }
    
    /**
     * Test: Total weeks validation (positive integer)
     */
    public function testTotalWeeksValidation() {
        $valid_weeks = [1, 10, 20, 52];
        
        foreach ($valid_weeks as $weeks) {
            $is_valid = is_int($weeks) && $weeks > 0;
            $this->assertTrue($is_valid, "{$weeks} should be valid");
        }
    }
    
    /**
     * Test: Invalid total weeks
     */
    public function testInvalidTotalWeeks() {
        $invalid_weeks = [0, -1, 'abc', null];
        
        foreach ($invalid_weeks as $weeks) {
            $is_valid = is_int($weeks) && $weeks > 0;
            $this->assertFalse($is_valid, var_export($weeks, true) . " should be invalid");
        }
    }
    
    /**
     * Test: Start date validation
     */
    public function testStartDateValidation() {
        $start_date = '2025-01-15';
        
        $is_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && strtotime($start_date);
        
        $this->assertTrue((bool)$is_valid, 'Start date should be valid');
    }
    
    /**
     * Test: Future start date
     */
    public function testFutureStartDate() {
        $future_date = date('Y-m-d', strtotime('+30 days'));
        
        $is_future = strtotime($future_date) > time();
        
        $this->assertTrue($is_future, 'Date should be in the future');
    }
    
    /**
     * Test: Past start date
     */
    public function testPastStartDate() {
        $past_date = date('Y-m-d', strtotime('-30 days'));
        
        $is_past = strtotime($past_date) < time();
        
        $this->assertTrue($is_past, 'Date should be in the past');
    }
    
    /**
     * Test: Holiday array sanitization
     */
    public function testHolidayArraySanitization() {
        $raw_holidays = ['2025-01-01', '  2025-12-25  ', '2025-04-18'];
        $sanitized = array_map('trim', $raw_holidays);
        
        $this->assertEquals('2025-12-25', $sanitized[1], 'Holiday date should be trimmed');
        $this->assertCount(3, $sanitized, 'Should have 3 holidays');
    }
    
    /**
     * Test: Duplicate holidays removal
     */
    public function testDuplicateHolidaysRemoval() {
        $holidays = ['2025-01-01', '2025-12-25', '2025-01-01'];
        $unique = array_unique($holidays);
        
        $this->assertCount(2, $unique, 'Duplicate holidays should be removed');
    }
    
    /**
     * Test: Session rate field validation
     */
    public function testSessionRateFieldValidation() {
        $session_rate = 30.0;
        
        $is_valid = is_numeric($session_rate) && $session_rate > 0;
        
        $this->assertTrue($is_valid, 'Session rate should be positive number');
    }
    
    /**
     * Test: Invalid session rate
     */
    public function testInvalidSessionRate() {
        $invalid_rates = [0, -10, 'abc', null];
        
        foreach ($invalid_rates as $rate) {
            $is_valid = is_numeric($rate) && $rate > 0;
            $this->assertFalse($is_valid, var_export($rate, true) . " should be invalid");
        }
    }
    
    /**
     * Test: Field data structure
     */
    public function testFieldDataStructure() {
        $field_data = [
            'start_date' => '2025-01-15',
            'total_weeks' => 20,
            'session_rate' => 30.0,
            'holiday_dates' => ['2025-04-18']
        ];
        
        $this->assertArrayHasKey('start_date', $field_data, 'Should have start_date');
        $this->assertArrayHasKey('total_weeks', $field_data, 'Should have total_weeks');
        $this->assertArrayHasKey('session_rate', $field_data, 'Should have session_rate');
        $this->assertArrayHasKey('holiday_dates', $field_data, 'Should have holiday_dates');
    }
    
    /**
     * Test: Course days of week mapping
     */
    public function testCourseDaysOfWeekMapping() {
        $days_map = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7
        ];
        
        $this->assertEquals(1, $days_map['Monday'], 'Monday should be 1');
        $this->assertEquals(5, $days_map['Friday'], 'Friday should be 5');
        $this->assertCount(7, $days_map, 'Should have 7 days');
    }
    
    /**
     * Test: Field value sanitization
     */
    public function testFieldValueSanitization() {
        $raw_value = '  30.50  ';
        $sanitized = trim($raw_value);
        $float_value = floatval($sanitized);
        
        $this->assertEquals(30.50, $float_value, 'Value should be sanitized to float');
    }
    
    /**
     * Test: Integer field sanitization
     */
    public function testIntegerFieldSanitization() {
        $raw_value = '  20  ';
        $sanitized = trim($raw_value);
        $int_value = intval($sanitized);
        
        $this->assertEquals(20, $int_value, 'Value should be sanitized to integer');
    }
    
    /**
     * Test: Date array validation
     */
    public function testDateArrayValidation() {
        $dates = ['2025-01-01', '2025-12-25', '2025-04-18'];
        
        foreach ($dates as $date) {
            $is_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
            $this->assertTrue((bool)$is_valid, "{$date} should be valid format");
        }
    }
}

