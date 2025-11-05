# Test Coverage Analysis
## InterSoccer Product Variations Plugin

**Date:** November 5, 2025  
**Purpose:** Assess current test coverage and identify gaps to prevent regressions

---

## Executive Summary

### Current State
- ✅ **Good:** Regression tests for past bugs
- ✅ **Good:** Basic unit tests for course pricing
- ✅ **Good:** Cart/order metadata display tests
- ⚠️  **Missing:** Late pickup functionality tests
- ⚠️  **Missing:** Camp pricing AJAX tests
- ⚠️  **Missing:** Price flicker regression test (new fix)
- ⚠️  **Missing:** Integration tests with WooCommerce
- ⚠️  **Missing:** Discount calculation tests

### Test Files Analysis

| Test File | Lines | Status | Coverage Area | Needs Work |
|-----------|-------|--------|---------------|------------|
| `SimpleTest.php` | 11 | ✅ Pass | Basic sanity | None - keep as template |
| `CoursePriceCalculationTest.php` | 188 | ⚠️ Mocked | Course prorating | Need real WC integration |
| `CartDisplayTest.php` | 242 | ✅ Good | Cart metadata | Add late pickup tests |
| `OrderMetadataTest.php` | 255 | ✅ Good | Order metadata | Complete |
| `EmojiTranslationTest.php` | 177 | ✅ Good | WPML UTF8 compliance | Complete |
| `RegressionTest.php` | 254 | ✅ Good | Past bug prevention | Add price flicker test |

### Critical Missing Tests

1. **Late Pickup Calculations** (HIGH PRIORITY)
   - Single day calculations
   - Full week calculations
   - Price compounding prevention
   - Base price storage/retrieval

2. **Camp Day Pricing** (HIGH PRIORITY)
   - Single-day camp pricing
   - Multi-day camp pricing
   - AJAX price updates
   - Price display synchronization

3. **Price Flicker Prevention** (CRITICAL - NEW FIX)
   - Base price should not compound
   - Variation ID tracking
   - Late pickup + camp days interaction

4. **Discount Logic** (MEDIUM PRIORITY)
   - Multi-week discounts
   - Sibling discounts
   - Discount stacking rules

---

## Detailed Analysis

### 1. Existing Tests - What Works

#### ✅ RegressionTest.php (254 lines)
**Purpose:** Prevent return of previously fixed bugs

**Tests:**
- Undefined `$product_type` variable (cart-calculations.php:317)
- Camp days missing from cart display
- Emojis in translatable strings (WPML UTF8 issue)
- Compiled translation files (.mo) exist
- Undefined `$variation` variable

**Strengths:**
- Real-world bug prevention
- Static code analysis
- File-level validation
- Good documentation of past issues

**Gaps:**
- No runtime behavior testing
- Doesn't test calculations
- Doesn't test user flows

#### ✅ CoursePriceCalculationTest.php (188 lines)
**Purpose:** Test course prorating logic

**Tests:**
- Full price for courses that haven't started
- Prorated price for in-progress courses
- Session rate pricing (per-week rate)
- Holiday date handling (extends duration)

**Strengths:**
- Comprehensive course pricing scenarios
- Clear test data setup
- Good edge case coverage

**Gaps:**
- Heavily mocked (doesn't use real WooCommerce)
- Missing player discount logic
- Missing multi-week discount tests
- No integration with actual product data

#### ✅ CartDisplayTest.php (242 lines)
**Purpose:** Ensure cart metadata displays correctly

**Tests:**
- Camp days display in cart
- Assigned attendee display
- Late pickup details display
- Full week vs single day late pickup
- HTML escaping security
- Product type filtering (camps vs courses)

**Strengths:**
- Security-conscious (XSS prevention)
- Clear assertions
- Covers both camp types
- Good documentation

**Gaps:**
- Doesn't test late pickup **calculations**
- No test for late pickup **cost** display
- Missing cart item price modification tests

#### ✅ OrderMetadataTest.php (255 lines)
**Purpose:** Verify order metadata is saved correctly

**Tests:**
- Days selected added to order
- Assigned attendee metadata
- Late pickup type saved
- Late pickup days saved
- Late pickup cost formatted
- Empty/null value handling
- Input sanitization

**Strengths:**
- Comprehensive metadata coverage
- Security testing (sanitization)
- Null safety
- Good assertions

**Gaps:**
- Doesn't test actual order creation
- No test for metadata persistence
- Missing roster generation impact tests

#### ✅ EmojiTranslationTest.php (177 lines)
**Purpose:** Prevent 4-byte emojis in WPML strings

**Tests:**
- No emojis in `_e()` calls
- No emojis in `__()` calls
- No emojis in `_n()` calls
- No emojis in `_x()` calls

**Strengths:**
- Automated prevention of WPML UTF8 errors
- Comprehensive function coverage
- Helpful error messages with alternatives
- Real file scanning

**Gaps:**
- None - this test is complete and excellent

---

### 2. Critical Gaps - What's Missing

#### ❌ CRITICAL: Late Pickup Calculation Tests

**Why Critical:**
- Complex pricing logic
- Multiple calculation paths (full week, single day, 5-day edge case)
- Interaction with camp day pricing
- **Recent price flicker bug** shows this is fragile

**What Needs Testing:**

```php
// Test file needed: tests/LatePickupCalculationTest.php

1. testSingleDayLatePickupCost()
   - 1 day selected = 1 × per_day_cost
   - 2 days selected = 2 × per_day_cost
   - Verify cost calculation accuracy

2. testFullWeekLatePickupCost()
   - 5 days selected = full_week_cost (not 5 × per_day_cost)
   - Edge case: 5 single days = full week price

3. testLatePickupDoesNotCompound()
   - ⚠️ PRICE FLICKER FIX REGRESSION TEST
   - Base price stored from variation.display_price
   - Base price not read from displayed HTML
   - Multiple late pickup selections don't stack

4. testBasePricePreservation()
   - Base price set on variation change
   - Base price preserved on same variation events
   - Base price updates from AJAX response

5. testLatePickupWithCampDays()
   - Camp base price: CHF 110 (1 day)
   - Add late pickup: CHF 135 (110 + 25)
   - Add 2nd camp day: CHF 220 (camp)
   - Late pickup recalculated: CHF 245 (220 + 25)
   - Remove camp day: CHF 135 (110 + 25)

6. testLatePickupNoneOption()
   - "None" selected = no cost added
   - Switching from option to "None" removes cost
   - Base price remains unchanged

7. testLatePickupAvailableDays()
   - Only days in available_late_pickup_days are selectable
   - Respects admin day availability settings
```

**Priority:** 🔥 **CRITICAL**  
**Estimated Test Lines:** ~350 lines  
**Prevents:** Price calculation errors, compounding bugs

---

#### ❌ CRITICAL: Camp Pricing Tests

**Why Critical:**
- Single-day camps have complex pricing (per-day × number of days)
- AJAX updates modify prices dynamically
- Interaction with late pickup pricing
- **Price flicker bug was in this area**

**What Needs Testing:**

```php
// Test file needed: tests/CampPriceCalculationTest.php

1. testSingleDayCampPricing()
   - Base per-day price: CHF 110
   - 1 day selected = CHF 110
   - 2 days selected = CHF 220
   - 5 days selected = CHF 550

2. testFullWeekCampPricing()
   - Full week booking type
   - Fixed price (not per-day calculation)

3. testAjaxPriceUpdate()
   - AJAX request with days array
   - Server calculates correct total
   - Response includes rawPrice
   - Frontend updates display

4. testPriceDisplaySynchronization()
   - WooCommerce variation price
   - Custom price display
   - Cart price
   - All should match

5. testCampDayAvailability()
   - Only enabled days are priced
   - Respects variation-level day settings
   - Disabled days don't affect price

6. testCampPriceWithVariations()
   - Different age groups = different prices
   - Different times = different prices
   - Price changes when variation changes
```

**Priority:** 🔥 **CRITICAL**  
**Estimated Test Lines:** ~280 lines  
**Prevents:** Incorrect pricing, customer disputes

---

#### ❌ HIGH: Price Flicker Regression Test

**Why Critical:**
- **NEW FIX** (November 5, 2025) needs protection
- Complex bug involving multiple systems
- Easy to regress with future changes
- Affects customer experience significantly

**What Needs Testing:**

```php
// Test file needed: tests/PriceFlickerRegressionTest.php

1. testBasePriceNotClearedOnSameVariation()
   - Variation 35317 selected
   - Base price stored: CHF 110
   - Day checkbox changed (same variation)
   - Variation ID still 35317
   - Base price should still be CHF 110 (not cleared)

2. testBasePriceFromVariationData()
   - New variation selected
   - Base price = variation.display_price (CHF 110)
   - Base price NOT from displayed HTML

3. testBasePriceNeverFromDisplayedPrice()
   - Displayed price: CHF 135 (includes late pickup)
   - Base price should be CHF 110 (from data)
   - updateMainPriceWithLatePickup() should fail if no base price
   - Should not fall back to reading displayed price

4. testBasePriceUpdateFromAjax()
   - AJAX response: rawPrice = CHF 220
   - Base price updated to CHF 220
   - Late pickup recalculated on new base

5. testMultipleDayChangesNoCompounding()
   - Start: 1 day, CHF 110, late pickup CHF 25 = CHF 135
   - Add day: 2 days, CHF 220, late pickup CHF 25 = CHF 245
   - Remove day: 1 day, CHF 110, late pickup CHF 25 = CHF 135
   - Each step should be accurate (no CHF 160, 185, 210, etc.)

6. testVariationIdTracking()
   - Store variation_id with base price
   - Only reset when variation_id changes
   - Preserve across same-variation events

7. testConsoleLogVerification()
   - "New variation detected" when ID changes
   - "Same variation, preserving" when ID unchanged
   - "Updated base price from AJAX" on AJAX success
   - Never "Stored base price: [increasing number]"
```

**Priority:** 🔥 **CRITICAL**  
**Estimated Test Lines:** ~400 lines  
**Prevents:** Price flicker bug regression  
**Links To:** elementor-widgets.php lines 730-746, 945-957, 1384-1404

---

#### ❌ MEDIUM: Discount Calculation Tests

**Why Important:**
- Money-sensitive calculations
- Complex interaction between discount types
- Important for pricing accuracy

**What Needs Testing:**

```php
// Test file needed: tests/DiscountCalculationTest.php

1. testMultiWeekDiscount()
   - 5% off for 2-week booking
   - 10% off for 4-week booking
   - Applied to correct price base

2. testSiblingDiscount()
   - 10% off for 2nd sibling
   - Applied after multi-week discount
   - Not applied if other discounts conflict

3. testDiscountStacking()
   - Which discounts can combine
   - Maximum discount cap
   - Order of application

4. testNoNegativePrices()
   - Discounts never create negative price
   - Minimum price threshold respected
```

**Priority:** 🟡 **MEDIUM**  
**Estimated Test Lines:** ~200 lines

---

#### ❌ MEDIUM: AJAX Handler Tests

**Why Important:**
- Frontend/backend communication
- Security (nonce verification)
- Data validation

**What Needs Testing:**

```php
// Test file needed: tests/AjaxHandlerTest.php

1. testUpdateCampPrice()
   - Valid request with days array
   - Nonce verification
   - Correct price calculation
   - Proper JSON response

2. testAjaxSecurityChecks()
   - Invalid nonce rejected
   - Missing parameters handled
   - SQL injection prevention

3. testAjaxErrorHandling()
   - Invalid product ID
   - Invalid variation ID
   - Missing required data
   - Proper error responses
```

**Priority:** 🟡 **MEDIUM**  
**Estimated Test Lines:** ~180 lines

---

## 3. Test Infrastructure Improvements

### Current Bootstrap (tests/bootstrap.php)
**Issues:**
- Minimal WordPress function mocking
- No WooCommerce function mocking
- Shared mock data not well structured

**Recommendations:**

```php
// Improved bootstrap.php

1. Create MockWordPress class
   - get_post_meta()
   - update_post_meta()
   - wp_cache_*()
   - sanitize_*()
   - esc_html()
   - __(), _e()

2. Create MockWooCommerce class
   - WC_Product
   - WC_Product_Variation
   - wc_get_product()
   - wc_price()
   - WC()->cart
   - WC()->session

3. Create TestDataFactory
   - createCampProduct()
   - createCourseProduct()
   - createVariation()
   - createCartItem()
   - createOrder()

4. Create AssertionHelpers
   - assertPriceEquals()
   - assertMetadataExists()
   - assertArrayHasKeys()
```

### PHPUnit Configuration
**Issues:**
- Basic configuration
- No code coverage reports
- No test groups

**Recommendations:**

```xml
<!-- Enhanced phpunit.xml -->

<phpunit bootstrap="tests/bootstrap.php"
         colors="true"
         testdox="true">
    
    <testsuites>
        <testsuite name="Critical">
            <directory>tests/Critical/</directory>
        </testsuite>
        <testsuite name="Regression">
            <directory>tests/Regression/</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit/</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration/</directory>
        </testsuite>
    </testsuites>
    
    <coverage>
        <report>
            <html outputDirectory="tests/coverage"/>
            <text outputFile="php://stdout" showOnlySummary="true"/>
        </report>
    </coverage>
    
    <php>
        <env name="WP_TESTS_DIR" value="/tmp/wordpress-tests-lib"/>
        <env name="WP_CORE_DIR" value="/tmp/wordpress/"/>
    </php>
</phpunit>
```

---

## 4. Recommended Test Structure

```
tests/
├── bootstrap.php                      # Test initialization
├── Helpers/
│   ├── MockWordPress.php             # WordPress function mocks
│   ├── MockWooCommerce.php           # WooCommerce mocks
│   ├── TestDataFactory.php           # Test data creation
│   └── CustomAssertions.php          # Custom assertions
│
├── Unit/                              # Unit tests (isolated)
│   ├── CoursePriceCalculationTest.php
│   ├── CampPriceCalculationTest.php  # NEW
│   ├── LatePickupCalculationTest.php # NEW - CRITICAL
│   ├── DiscountCalculationTest.php   # NEW
│   └── MetadataFormattingTest.php
│
├── Integration/                       # Integration tests
│   ├── CartIntegrationTest.php       # NEW
│   ├── CheckoutIntegrationTest.php   # NEW
│   └── AjaxHandlerTest.php           # NEW
│
├── Regression/                        # Prevent past bugs
│   ├── PriceFlickerRegressionTest.php # NEW - CRITICAL
│   ├── UndefinedVariableTest.php
│   ├── CartDisplayRegressionTest.php
│   └── TranslationRegressionTest.php
│
└── Compliance/                        # Code quality
    ├── EmojiTranslationTest.php
    ├── SecurityTest.php              # NEW
    └── PerformanceTest.php           # NEW (future)
```

---

## 5. Priority Action Plan

### Phase 1: Critical (Week 1) 🔥
**Prevent regressions on recent fixes**

1. **Create `PriceFlickerRegressionTest.php`** (~400 lines)
   - Test base price storage from variation data
   - Test variation ID tracking
   - Test no compounding on multiple day changes
   - Test AJAX base price updates
   - **Directly tests November 5, 2025 fix**

2. **Create `LatePickupCalculationTest.php`** (~350 lines)
   - Single day calculations
   - Full week calculations
   - Base price preservation
   - Interaction with camp pricing

3. **Create `CampPriceCalculationTest.php`** (~280 lines)
   - Single-day camp pricing
   - Multi-day calculations
   - AJAX price updates

**Estimated Effort:** 3-4 days  
**Lines of Code:** ~1,030 lines  
**Protection:** Price calculation accuracy, recent bug fixes

### Phase 2: Important (Week 2) 🟡
**Improve overall coverage**

4. **Create `DiscountCalculationTest.php`** (~200 lines)
   - Multi-week discounts
   - Sibling discounts
   - Discount stacking

5. **Create `AjaxHandlerTest.php`** (~180 lines)
   - Security testing
   - Request/response validation
   - Error handling

6. **Improve Test Infrastructure**
   - Enhanced bootstrap.php
   - Test data factory
   - Custom assertions

**Estimated Effort:** 3-4 days  
**Lines of Code:** ~380 lines + infrastructure

### Phase 3: Nice to Have (Week 3+) 🟢
**Full integration testing**

7. **Create Integration Tests**
   - Real WooCommerce cart tests
   - Checkout flow tests
   - Order creation tests

8. **Create Compliance Tests**
   - Security scanning
   - Performance benchmarks
   - Code quality metrics

**Estimated Effort:** 1-2 weeks  
**Lines of Code:** ~500+ lines

---

## 6. Running Tests

### Current Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/SimpleTest.php

# Run with test documentation
./vendor/bin/phpunit --testdox

# Generate coverage report (requires xdebug)
./vendor/bin/phpunit --coverage-html tests/coverage
```

### Test Groups (After restructure)

```bash
# Run only critical tests
./vendor/bin/phpunit --testsuite Critical

# Run only regression tests
./vendor/bin/phpunit --testsuite Regression

# Run fast tests (exclude integration)
./vendor/bin/phpunit --exclude-group integration
```

---

## 7. Coverage Metrics

### Current Coverage (Estimated)

| Component | Tested | Coverage | Priority |
|-----------|--------|----------|----------|
| Course Pricing | ✅ Yes | ~70% | Medium |
| Camp Pricing | ❌ No | 0% | 🔥 Critical |
| Late Pickup | ❌ No | 0% | 🔥 Critical |
| Cart Display | ✅ Yes | ~80% | Low |
| Order Metadata | ✅ Yes | ~80% | Low |
| Discounts | ❌ No | 0% | 🟡 Medium |
| AJAX Handlers | ❌ No | 0% | 🟡 Medium |
| Price Flicker Fix | ❌ No | 0% | 🔥 CRITICAL |

### Target Coverage (Phase 1 Complete)

| Component | Target | Impact |
|-----------|--------|--------|
| Course Pricing | 85% | High confidence |
| Camp Pricing | 80% | High confidence |
| Late Pickup | 90% | Critical feature |
| Price Flicker | 100% | Bug prevention |
| Cart/Order | 85% | Already good |
| Discounts | 70% | Good coverage |
| AJAX | 75% | Security + functionality |

---

## 8. Success Criteria

### Definition of "Good Test Coverage"

1. **All Critical Paths Tested**
   - ✅ Price calculations (camp, course, late pickup)
   - ✅ Cart and checkout flow
   - ✅ Order metadata persistence

2. **All Recent Bugs Have Regression Tests**
   - ✅ Price flicker (November 5, 2025)
   - ✅ Undefined variables (November 2025)
   - ✅ WPML emoji errors (November 2025)

3. **Security Covered**
   - ✅ Input sanitization tested
   - ✅ XSS prevention verified
   - ✅ Nonce verification tested

4. **Fast Test Execution**
   - ⏱️ All tests run in < 30 seconds
   - ⏱️ Critical suite runs in < 10 seconds

5. **Clear Documentation**
   - 📝 Each test explains what it prevents
   - 📝 Test names are descriptive
   - 📝 Failure messages are actionable

---

## 9. Continuous Integration

### Recommended CI Setup

```yaml
# .github/workflows/tests.yml

name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.1'
        extensions: mbstring, intl
    
    - name: Install dependencies
      run: composer install
    
    - name: Run critical tests
      run: ./vendor/bin/phpunit --testsuite Critical
    
    - name: Run regression tests
      run: ./vendor/bin/phpunit --testsuite Regression
    
    - name: Run all tests
      run: ./vendor/bin/phpunit
```

### Pre-Deployment Checks

```bash
# Run before every deployment
./scripts/pre-deploy-tests.sh

# Contents:
#!/bin/bash
echo "Running critical tests..."
./vendor/bin/phpunit --testsuite Critical
if [ $? -ne 0 ]; then
    echo "❌ Critical tests failed! Deployment blocked."
    exit 1
fi

echo "Running regression tests..."
./vendor/bin/phpunit --testsuite Regression
if [ $? -ne 0 ]; then
    echo "❌ Regression tests failed! Deployment blocked."
    exit 1
fi

echo "✅ All tests passed! Safe to deploy."
```

---

## 10. Conclusion

### Summary

**Current State:**
- Good foundation with 6 test files
- Strong regression prevention
- Missing critical calculation tests

**Immediate Action Required:**
1. Create `PriceFlickerRegressionTest.php` (protect recent fix)
2. Create `LatePickupCalculationTest.php` (critical feature)
3. Create `CampPriceCalculationTest.php` (critical feature)

**Expected Outcome:**
- Prevent price calculation bugs
- Catch regressions before production
- Faster, safer deployments
- Higher confidence in changes

### Timeline

- **This Week:** Price flicker regression test
- **Week 1-2:** Critical calculation tests (late pickup, camp pricing)
- **Week 3-4:** Discount and AJAX tests
- **Month 2:** Integration and compliance tests

### Maintenance

- Run tests before every deployment
- Add regression test for every bug fix
- Update tests when features change
- Review coverage quarterly

---

**Document Version:** 1.0  
**Last Updated:** November 5, 2025  
**Next Review:** December 5, 2025

