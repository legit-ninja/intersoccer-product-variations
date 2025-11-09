# Course Info AJAX Handler - Unit Tests

## Purpose
Prevent regression of the missing holidays and end_date bug in the course information display.

## Test File
`tests/CourseInfoAjaxTest.php`

## What's Tested

### Critical Regression Tests
1. **testRegressionAllFieldsAlwaysPresent** - The most important test
   - Ensures ALL required fields are always present in the AJAX response
   - Tests multiple scenarios (full data, minimal data, no holidays)
   - **This test would have caught the original bug**

### Field Validation Tests
2. **testAjaxHandlerReturnsAllFieldsWithHolidays**
   - Verifies all 6 required fields: `is_course`, `start_date`, `end_date`, `total_weeks`, `remaining_sessions`, `holidays`
   - Validates holiday formatting (Y-m-d → F j, Y)
   - Confirms correct array count

3. **testAjaxHandlerReturnsEmptyHolidaysArray**
   - Ensures `holidays` key exists even when no holidays are configured
   - Must return empty array, not null or undefined

4. **testAjaxHandlerHandlesMissingHolidaysMeta**
   - Tests graceful handling when `_course_holiday_dates` meta doesn't exist
   - Should return empty array instead of error

### Date Calculation Tests
5. **testAjaxHandlerCalculatesEndDate**
   - Validates end_date calculation logic
   - Tests: Start (2025-01-06) + 10 weeks = End (2025-03-17)

6. **testAjaxHandlerEndDateEmptyWithoutStartDate**
   - Ensures end_date is empty string when start_date is missing
   - Prevents null/undefined issues

### Edge Cases
7. **testAjaxHandlerHandlesInvalidHolidayDates**
   - Tests handling of malformed date strings
   - Should process valid dates, handle invalid gracefully

## Required Response Structure

```json
{
  "success": true,
  "data": {
    "is_course": true,
    "start_date": "January 6, 2025",       // Formatted
    "end_date": "March 17, 2025",          // Formatted (NEW - was missing)
    "total_weeks": 12,
    "remaining_sessions": 6,
    "holidays": [                          // Array (NEW - was missing)
      "February 17, 2025",
      "March 24, 2025",
      "April 21, 2025"
    ]
  }
}
```

## Running the Tests

```bash
# Run all course info tests
./vendor/bin/phpunit tests/CourseInfoAjaxTest.php

# Run specific test
./vendor/bin/phpunit tests/CourseInfoAjaxTest.php --filter testRegressionAllFieldsAlwaysPresent

# Run with detailed output
./vendor/bin/phpunit tests/CourseInfoAjaxTest.php --testdox
```

## Integration with CI/CD

These tests are automatically run by:
1. `deploy.sh` - Before every deployment
2. Git pre-commit hooks (if configured)
3. CI/CD pipeline (if configured)

## What Was Fixed

### Before (Bug)
```php
$response = [
    'is_course' => true,
    'start_date' => $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '',
    'total_weeks' => $total_weeks,
    'remaining_sessions' => $remaining_sessions
    // ❌ Missing: end_date
    // ❌ Missing: holidays
];
```

### After (Fixed)
```php
$response = [
    'is_course' => true,
    'start_date' => $start_date ? date_i18n('F j, Y', strtotime($start_date)) : '',
    'end_date' => $end_date ? date_i18n('F j, Y', strtotime($end_date)) : '', // ✅ Added
    'total_weeks' => $total_weeks,
    'remaining_sessions' => $remaining_sessions,
    'holidays' => array_map(function($holiday) {  // ✅ Added
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $holiday)) {
            return date_i18n('F j, Y', strtotime($holiday));
        }
        return $holiday;
    }, $holidays)
];
```

## Coverage

- **AJAX Handler**: `includes/ajax-handlers.php` → `intersoccer_get_course_info()`
- **JavaScript Display**: `js/variation-details.js` → `updateCourseInfo()`
- **PHP Rendering**: `includes/woocommerce/product-course.php` → `intersoccer_render_course_info()`

## Maintenance

When modifying the course info AJAX handler:
1. Run these tests FIRST to ensure they pass
2. Make your changes
3. Run tests AGAIN to ensure no regression
4. Add new tests if adding new fields

## Test Status

✅ All tests passing (as of deployment)
✅ Exit code 0 confirmed
✅ Regression protection in place

