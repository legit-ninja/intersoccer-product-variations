# Release Notes - v1.12.5

**Release Date**: December 5, 2025

## Major Features

### Retroactive Discount System
Extended the discount system to check customer's previous orders and apply discounts automatically, encouraging repeat purchases and rewarding loyal customers.

#### 1. Retroactive Course Discounts
**Feature**: Same-season discount applies even when courses are purchased separately.

**Example**:
- Customer previously purchased: Saturday Course (Spring/Summer) - 600 CHF
- Customer now adds: Sunday Course (Spring/Summer) - 600 CHF
- **Result**: Sunday course gets 50% discount = 300 CHF
- **Saved**: 300 CHF

**Requirements**:
- Same parent product (same season)
- Same assigned child
- Different course day

#### 2. Progressive Camp Week Discounts
**Feature**: Progressive discounts based on total weeks purchased across all orders.

**Pricing**:
- Week 1: Full price (0% discount)
- Week 2: 10% discount
- Week 3+: 20% discount

**Example**:
- Week 1 (previous order): 500 CHF
- Week 2 (new cart): 450 CHF (10% off)
- Week 3 (later order): 400 CHF (20% off)
- **Total saved**: 150 CHF across orders

**Requirements**:
- Same parent product (same season)
- Same assigned child
- Week number parsed from product attributes

#### 3. Tournament Multiple Days Discount
**Feature**: Discount for same child enrolling in multiple tournament days.

**Pricing**:
- Day 1: 30 CHF (full price)
- Day 2+: 20 CHF (33.33% discount = 10 CHF off)
- **2 days for 50 CHF total**

**Example**:
- Day 1 (previous order): 30 CHF
- Day 2 (new cart): 20 CHF (33.33% off)
- **Saved**: 10 CHF

**Requirements**:
- Same parent product
- Same assigned child
- Works alongside sibling discounts

## Admin UI Enhancements

### New Settings (WooCommerce > Marketing > InterSoccer Discounts)

1. **Enable Retroactive Course Discounts** (checkbox)
   - Default: Enabled
   - Controls course same-season discount across orders

2. **Enable Retroactive Camp Discounts** (checkbox)
   - Default: Enabled
   - Controls progressive camp week discounts

3. **Order Lookback Period** (number input)
   - Default: 6 months
   - Range: 1-24 months
   - Limits how far back to check previous orders
   - Balance between discount eligibility and performance

### New Discount Rules (all configurable)
- Camp Progressive Week 2: 10%
- Camp Progressive Week 3+: 20%
- Tournament Same Child Multiple Days: 33.33%

## Technical Implementation

### New Functions (8)
- `intersoccer_get_customer_previous_orders()` - Query previous orders
- `intersoccer_extract_course_items_from_order()` - Extract course data
- `intersoccer_extract_camp_items_from_order()` - Extract camp data
- `intersoccer_extract_tournament_items_from_order()` - Extract tournament data
- `intersoccer_parse_camp_week_from_terms()` - Parse week numbers
- `intersoccer_get_previous_courses_by_parent()` - Query previous courses
- `intersoccer_get_previous_camps_by_parent()` - Query previous camps
- `intersoccer_get_previous_tournaments_by_parent()` - Query previous tournaments

### Enhanced Functions (2)
- `intersoccer_build_cart_context()` - Includes previous order data
- `intersoccer_apply_combo_discounts_to_items()` - Applies retroactive discounts

### Performance Optimization
- Static caching prevents repeated database queries
- Configurable lookback period limits query scope
- Efficient WooCommerce order query API
- Cache cleared on cart changes

## Test Coverage

### New Test Files
- `tests/RetroactiveDiscountTest.php` - 25 unit tests
- `tests/RetroactiveDiscountIntegrationTest.php` - 15 integration tests
- `tests/DiscountFunctionsTest.php` - 15 function tests

### Test Coverage
- Tournament discount calculations
- Camp progressive discount logic
- Course retroactive discount logic
- Week parsing and position calculation
- Parent product matching
- Assigned player matching
- Configuration validation
- Edge cases and error handling

**Total**: 55+ new tests, all passing ✅

## Repository Organization

### Structure Improvements
- Moved documentation to `docs/` folder (34 files)
- Moved debug utilities to `utils/` folder (5 scripts)
- Moved integration tests to `tests/integration/`
- Cleaned root directory (4 essential files only)
- Updated `.gitignore` for better file management

## Database Migration

### Required WordPress Options
Before deploying, export these options from production:

```sql
SELECT * FROM wp_options 
WHERE option_name IN (
    'intersoccer_discount_rules',
    'intersoccer_discount_messages',
    'intersoccer_disable_sibling_discount_with_coupons',
    'intersoccer_enable_retroactive_course_discounts',
    'intersoccer_enable_retroactive_camp_discounts',
    'intersoccer_retroactive_discount_lookback_months'
);
```

### Quick Initialization
Alternatively, visit **WooCommerce > Marketing > InterSoccer Discounts** to auto-initialize with defaults.

## Deployment Checklist

- [ ] Run tests: `php vendor/bin/phpunit tests/`
- [ ] Export database options from production
- [ ] Deploy code to staging
- [ ] Import database options to staging
- [ ] Test with customer accounts that have previous orders
- [ ] Verify discounts apply correctly in cart
- [ ] Check debug logs (enable WP_DEBUG)
- [ ] Test all three discount types (courses, camps, tournaments)
- [ ] Verify admin UI settings work
- [ ] Deploy to production

## Breaking Changes
None. All changes are backward compatible.

## Upgrade Notes
- Existing discount rules remain unchanged
- New discount rules are added automatically
- Default settings: All retroactive discounts enabled
- Lookback period defaults to 6 months
- No customer-facing changes required

## Support
- Documentation: `docs/RETROACTIVE-DISCOUNTS-IMPLEMENTATION.md`
- Quick Reference: `docs/QUICK-REFERENCE-RETROACTIVE-DISCOUNTS.md`
- Test Summary: `docs/TEST-SUMMARY.md`

---

**Contributors**: Jeremy Lee
**Status**: ✅ Production Ready

