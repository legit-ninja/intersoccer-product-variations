# Course Future Start Date Pricing Fix

## Problems Fixed

### Problem 1: Frontend Display Price
Course pro-rated pricing was being applied to courses with start dates in the future, causing customers to see incorrect prices when booking early. For example, if a course had a `base_price` of 550 CHF but a `session_rate` of 50 CHF with 12 weeks, customers would see 600 CHF (50 × 12) instead of the correct 550 CHF base price.

### Problem 2: Admin Auto-Calculation
When editing course variations in the admin panel, the system would automatically overwrite the Regular Price with the calculated price (session_rate × total_weeks) upon save. This violated the principle that Regular Price should be a static, manually-set value. For example, if an admin set a Regular Price of 375 CHF, it would be automatically changed to 378 CHF (27 × 14) upon save, preventing manual price control.

## Root Causes

### Root Cause 1: Frontend Calculation Logic
The `InterSoccer_Course::calculate_price()` method was applying session-rate or pro-rated calculations regardless of whether the course had started. Even when `remaining_sessions` equaled `total_weeks` (indicating the course hadn't started), it would still calculate `session_rate * remaining_sessions`, which could differ from the base price.

### Root Cause 2: Admin Save Hook
The `intersoccer_save_course_variation_fields()` function (hooked to `woocommerce_save_product_variation`) would automatically overwrite the Regular Price with `session_rate × total_weeks` for ANY course with a session rate set. This violated the core principle that Regular Price is a static, manually-controlled value.

## Solutions

### Solution 1: Frontend Display
Added an early return check in `InterSoccer_Course::calculate_price()` to detect if the course start date is in the future. If the course hasn't started yet, the method now returns the base price immediately without any pro-rating calculations.

### Solution 2: Admin Auto-Calculation Removed
Completely removed the automatic price calculation from the admin save function. The Regular Price is now ONLY set manually by admins and is never auto-calculated. The session rate is used exclusively for frontend calculations after the course start date.

## Implementation

### Fix 1: Frontend Price Calculation

**File:** `includes/woocommerce/product-course.php`
**Method:** `InterSoccer_Course::calculate_price()`
**Lines:** 414-425

Added early return logic:
```php
// CRITICAL FIX: Don't apply pro-rated pricing to courses with future start dates
// Customers booking early should pay full base price, not a calculated session price
$start_date = intersoccer_get_course_meta($variation_id ?: $product_id, '_course_start_date', '');
if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start = new DateTime($start_date);
    $current = new DateTime(current_time('Y-m-d'));
    
    if ($current < $start) {
        error_log('InterSoccer: Course has not started yet (start: ' . $start_date . ') - returning full base price: ' . $base_price);
        return $base_price;
    }
}
```

### Fix 2: Admin Save Auto-Calculation

**File:** `includes/admin-product-fields.php`
**Function:** `intersoccer_save_course_variation_fields()`
**Lines:** 230-251

**Before (problematic code):**
```php
// Update variation price to full course price if session rate is set
if ($weekly_discount > 0) {
    $full_price = $weekly_discount * $total_weeks;
    update_post_meta($variation_id, '_price', $full_price);
    update_post_meta($variation_id, '_regular_price', $full_price);
    wc_delete_product_transients($variation_id);
}
```

**After (fixed code):**
```php
// IMPORTANT: We do NOT automatically set the Regular Price here
// The Regular Price is a static price manually set by the admin
// The session rate is ONLY used for frontend calculation after the course starts
// Clear transients to ensure changes take effect
wc_delete_product_transients($variation_id);
```

**Key Change:** Completely removed all automatic price calculation logic. The Regular Price remains under full manual control.

**Also Fixed:** WPML translation sync function (lines 600-603) with identical removal of auto-calculation.

### Test Coverage

**File:** `tests/CoursePriceCalculationTest.php`

1. **Enhanced `testFullPriceCourse()`**
   - Verifies future courses return base_price
   - Added regression test to ensure session_rate is not applied to future courses

2. **New `testFutureCourseWithSessionRate()`**
   - Dedicated test for future courses with session_rate set
   - Verifies base_price (550 CHF) is returned, not session_rate × total_weeks (600 CHF)
   - Prevents regression of the original bug

## Verified Price Injection Points

All price calculation points now benefit from this fix because they all call `InterSoccer_Course::calculate_price()`:

### 1. Cart Item Data (Line 73)
**File:** `includes/woocommerce/cart-calculations.php`
```php
$cart_item_data['base_price'] = InterSoccer_Course::calculate_price($product_id, $variation_id);
```
**Impact:** Future courses added to cart now show correct base price.

### 2. Variation Data Filter (Line 399)
**File:** `includes/woocommerce/cart-calculations.php`
**Filter:** `woocommerce_available_variation`
```php
$prorated_price = InterSoccer_Course::calculate_price($product_id, $variation_id);
```
**Impact:** Frontend variation selector displays correct base price for future courses.

### 3. Price Calculation Function (Line 261)
**File:** `includes/woocommerce/cart-calculations.php`
**Function:** `intersoccer_calculate_price()`
```php
$price = InterSoccer_Course::calculate_price($product_id, $variation_id, $remaining_weeks);
```
**Impact:** All generic price calculations use correct base price for future courses.

### 4. AJAX Dynamic Price Handler (Line 175)
**File:** `includes/ajax-handlers.php`
**Action:** `intersoccer_calculate_dynamic_price`
```php
$calculated_price = InterSoccer_Course::calculate_price($product_id, $variation_id, $remaining_weeks);
```
**Impact:** AJAX price updates on the frontend show correct base price for future courses.

### 5. Variation Price Filters (Lines 305, 357, 376)
**File:** `includes/woocommerce/cart-calculations.php`
- Hash calculation for price caching
- `woocommerce_product_variation_get_price` filter
- `woocommerce_variation_prices_price_html` filter

**Impact:** All price display mechanisms use correct base price for future courses.

### 6. Discount Calculations (Lines 345, 438)
**File:** `includes/woocommerce/discount-messages.php`
- Multi-child course discounts
- Same-season course discounts

**Impact:** Discount calculations start from correct base price for future courses.

## Expected Behavior

### Frontend Display
**Before Fix:**
- **Future Course (base: 550 CHF, session_rate: 50 CHF, weeks: 12)**
  - ❌ Displayed: 600 CHF (50 × 12)
  - ❌ Customer confusion: "Why is the price higher if I book early?"

**After Fix:**
- **Future Course (base: 550 CHF, session_rate: 50 CHF, weeks: 12)**
  - ✅ Displayed: 550 CHF (base price)
  - ✅ Customer expectation: Full price for full course

- **Started Course (same config, 7 weeks remaining)**
  - ✅ Displayed: 350 CHF (50 × 7)
  - ✅ Pro-rated correctly for mid-course enrollment

### Admin Panel
**Before Fix:**
- Admin sets Regular Price to 375 CHF (session_rate: 27 CHF, 14 weeks configured)
- ❌ On save: Price automatically changed to 378 CHF (27 × 14) - INCORRECT
- Admin cannot manually control pricing

**After Fix:**
- Admin sets Regular Price to 375 CHF (session_rate: 27 CHF, 14 weeks configured)
- ✅ On save: Price remains 375 CHF - CORRECT
- ✅ Regular Price is ALWAYS under manual control
- ✅ Session rate is ONLY used for frontend calculation after start date

## Course Pricing Model (Clarified)

### Regular Price (Static)
- **Set by:** Admin manually
- **Never changes:** Remains constant regardless of course status
- **Purpose:** Base price for full course before start date

### Session Rate (Dynamic Calculator)
- **Set by:** Admin manually
- **Used for:** Frontend calculation ONLY after start date
- **Purpose:** Calculate prorated price: `session_rate × remaining_sessions`
- **Does NOT modify:** Regular Price in database

### Pricing Logic by Status

#### Before Start Date:
- **Customer sees:** Regular Price (e.g., 375 CHF)
- **Calculation:** None - uses stored Regular Price
- **Discounts:** Sibling and same-season only

#### After Start Date:
- **Customer sees:** Prorated price (e.g., 27 CHF × 7 sessions = 189 CHF)
- **Calculation:** `session_rate × remaining_sessions`
- **Regular Price:** Ignored in calculation
- **Discounts:** Sibling and same-season only

## Testing Results

✅ All course price calculation tests pass
✅ All plugin tests pass (full test suite)
✅ No regressions detected
✅ Manual price control fully restored
✅ Auto-calculation completely removed

## Files Modified
1. `includes/woocommerce/product-course.php` - Added future date check in `calculate_price()`
2. `includes/admin-product-fields.php` - Removed automatic Regular Price calculation (2 locations)
3. `tests/CoursePriceCalculationTest.php` - Enhanced test coverage
4. `docs/COURSE-FUTURE-PRICING-FIX.md` - Comprehensive documentation with pricing model clarification

## Files Verified (No Changes Needed)
1. `includes/woocommerce/cart-calculations.php` - All call sites verified
2. `includes/ajax-handlers.php` - AJAX handler verified
3. `includes/woocommerce/discount-messages.php` - Discount calculations verified

## Deployment Notes
- **Impact:** Medium - Affects all future course pricing
- **Risk:** Low - Early return prevents calculation errors
- **Testing:** All existing tests pass
- **Backward Compatibility:** ✅ Maintained - Started courses still use pro-rating
- **Version:** Implemented in version 1.4.63+

## Related Documentation
- [Price Flicker Resolved](./PRICE-FLICKER-RESOLVED.md) - Camp price display fixes
- [Course Info Tests](./COURSE-INFO-TESTS.md) - Course information unit tests

