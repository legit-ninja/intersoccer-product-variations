# Retroactive Discounts Implementation Summary

## Overview
Extended the InterSoccer Product Variations discount system to apply discounts based on customer's previous orders, allowing parents to receive discounts even when purchasing additional events separately.

## Features Implemented

### 1. Retroactive Course Discounts
**Scenario**: Customer previously purchased a Saturday course, now purchasing a Sunday course in the same season.

**Logic**:
- Checks previous orders for courses with same parent product ID and same assigned player
- Applies 50% same-season discount if previous course has a different day
- Only applies when days are different (e.g., Saturday → Sunday/Wednesday)

**Configuration**:
- Enable/disable via Admin UI: `intersoccer_enable_retroactive_course_discounts`
- Default: Enabled

### 2. Progressive Camp Week Discounts
**Scenario**: Customer previously purchased Week 1 camp, now purchasing Week 2 and Week 3.

**Logic**:
- Tracks all camp weeks purchased (previous + current cart) by parent product and assigned player
- Applies progressive discounts based on total weeks:
  - Week 1: Full price (0%)
  - Week 2: 10% discount
  - Week 3+: 20% discount
- Parses week number from `pa_camp-terms` attribute (e.g., "summer-week-2-june-24-june-28-5-days")

**Configuration**:
- Enable/disable via Admin UI: `intersoccer_enable_retroactive_camp_discounts`
- Week 2 rate: `camp-progressive-week-2` (default: 10%)
- Week 3+ rate: `camp-progressive-week-3-plus` (default: 20%)
- Default: Enabled

### 3. Tournament Same-Child Multiple Days Discount
**Scenario**: Customer previously purchased Tournament Day 1, now purchasing Day 2.

**Logic**:
- Tracks all tournament days purchased (previous + current cart) by parent product and assigned player
- Applies 33.33% discount to 2nd+ days for the same child
- Pricing: Day 1 = 30 CHF, Day 2+ = 20 CHF (2 days for 50 CHF total)
- Works alongside existing sibling discounts (different children)

**Configuration**:
- Discount rule: `tournament-same-child-multiple-days` (default: 33.33%)
- Always enabled (no separate toggle)
- Configurable via Admin UI discount rules

### 4. Performance Optimization
**Lookback Period**:
- Configurable via Admin UI: `intersoccer_retroactive_discount_lookback_months`
- Default: 6 months
- Range: 1-24 months
- Limits database queries to recent orders only

**Caching**:
- Static caching in helper functions to avoid repeated queries
- Cache keys: `{type}_{customer_id}_{parent_product_id}_{assigned_player}_{lookback_months}`
- Cache cleared on cart changes

## Database Requirements

### WordPress Options (wp_options table)
The following options must be exported/imported between environments:

1. **`intersoccer_discount_rules`** - All discount rule configurations
2. **`intersoccer_discount_messages`** - Custom discount messages (multilingual)
3. **`intersoccer_disable_sibling_discount_with_coupons`** - Coupon interaction setting
4. **`intersoccer_enable_retroactive_course_discounts`** - Enable retroactive courses (default: true)
5. **`intersoccer_enable_retroactive_camp_discounts`** - Enable retroactive camps (default: true)
6. **`intersoccer_retroactive_discount_lookback_months`** - Lookback period (default: 6)

### Export SQL Query
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
Visit **WooCommerce > Marketing > InterSoccer Discounts** to auto-initialize all settings with defaults.

## Admin UI Configuration

### Location
**WooCommerce > Marketing > InterSoccer Discounts**

### Settings Available

#### Discount Settings Section
1. **Disable Sibling Discounts with Coupons** (checkbox)
   - Disables all sibling/same-season discounts when WooCommerce coupons are applied

2. **Enable Retroactive Course Discounts** (checkbox)
   - Apply same-season course discounts based on previous orders
   - Default: Enabled

3. **Enable Retroactive Camp Discounts** (checkbox)
   - Apply progressive camp discounts based on previous orders
   - Default: Enabled

4. **Order Lookback Period** (number input)
   - Months to look back when checking previous orders
   - Range: 1-24 months
   - Default: 6 months

#### Discount Rules Section
All discount rules are configurable:
- Camp Sibling Discount (2nd Child): 20%
- Camp Sibling Discount (3rd+ Child): 25%
- Camp Progressive Discount (Week 2): 10%
- Camp Progressive Discount (Week 3+): 20%
- Course Sibling Discount (2nd Child): 20%
- Course Sibling Discount (3rd+ Child): 30%
- Course Same Season Discount: 50%
- Tournament Sibling Discount (2nd Child): 20%
- Tournament Sibling Discount (3rd+ Child): 30%
- **Tournament Same Child Multiple Days: 33.33%** (NEW)

## Files Modified

### Core Discount Logic
- **`includes/woocommerce/discounts.php`** (1,900+ lines)
  - Added 6 new helper functions
  - Extended `intersoccer_build_cart_context()` with previous order data
  - Modified course same-season discount logic for retroactive support
  - Added progressive camp discount logic
  - Added tournament same-child discount logic
  - Added 2 new default discount rules

### Discount Messages
- **`includes/woocommerce/discount-messages.php`** (1,043 lines)
  - Added messages for `camp_progressive_week_2`
  - Added messages for `camp_progressive_week_3_plus`
  - Added messages for `tournament_same_child_multiple_days`
  - Updated rule ID mapping

### Admin UI
- **`includes/woocommerce/admin-ui.php`** (3,790 lines)
  - Added 3 new settings fields
  - Registered 3 new WordPress options
  - Updated settings save handler
  - Added validation for lookback period (1-24 months)

## Test Coverage

### Test Files Created
1. **`tests/RetroactiveDiscountTest.php`** - 25 unit tests
2. **`tests/RetroactiveDiscountIntegrationTest.php`** - 15 integration tests
3. **`tests/RETROACTIVE-DISCOUNT-TEST-COVERAGE.md`** - Test documentation

### Total Test Statistics
- **Test Files**: 22 files
- **Test Methods**: 331+ test methods across all files
- **New Tests Added**: 40+ tests for retroactive discounts
- **All Tests**: ✅ Passing

### Test Coverage Areas
- ✅ Tournament same-child multiple days discount (33.33%)
- ✅ Camp progressive discounts (Week 2: 10%, Week 3+: 20%)
- ✅ Retroactive course same-season discounts
- ✅ Week parsing from camp-terms attribute
- ✅ Parent product ID matching
- ✅ Assigned player matching
- ✅ Lookback period validation
- ✅ Cache key generation
- ✅ Discount sorting logic
- ✅ Edge cases (null values, invalid formats, etc.)

## Key Functions Added

### Previous Order Query Functions
```php
intersoccer_get_customer_previous_orders($customer_id, $customer_email, $lookback_months)
intersoccer_extract_course_items_from_order($order)
intersoccer_extract_camp_items_from_order($order)
intersoccer_extract_tournament_items_from_order($order)
intersoccer_parse_camp_week_from_terms($camp_terms)
intersoccer_get_previous_courses_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months)
intersoccer_get_previous_camps_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months)
intersoccer_get_previous_tournaments_by_parent($customer_id, $parent_product_id, $assigned_player, $lookback_months)
```

### Modified Functions
```php
intersoccer_build_cart_context($cart_items) // Extended with previous order data
intersoccer_apply_combo_discounts_to_items($cart) // Added retroactive discount logic
```

## Usage Examples

### Example 1: Course Discount
```
Customer Order History:
- Order #1001 (2 months ago): Saturday Course - Spring/Summer - Child: Alice - 600 CHF

Current Cart:
- Sunday Course - Spring/Summer - Child: Alice - 600 CHF

Result:
- Sunday Course gets 50% discount = 300 CHF
- Total: 300 CHF (saved 300 CHF)
```

### Example 2: Camp Progressive Discount
```
Customer Order History:
- Order #1002 (1 month ago): Summer Week 1 Camp - Child: Bob - 500 CHF

Current Cart:
- Summer Week 2 Camp - Child: Bob - 500 CHF
- Summer Week 3 Camp - Child: Bob - 500 CHF

Result:
- Week 2 gets 10% discount = 450 CHF
- Week 3 gets 20% discount = 400 CHF
- Total: 850 CHF (saved 150 CHF)
```

### Example 3: Tournament Multiple Days
```
Customer Order History:
- Order #1003 (2 weeks ago): Tournament Day 1 - Child: Charlie - 30 CHF

Current Cart:
- Tournament Day 2 - Child: Charlie - 30 CHF

Result:
- Day 2 gets 33.33% discount = 20 CHF
- Total: 20 CHF (saved 10 CHF)
- Note: "2 days for 50 CHF"
```

### Example 4: Multiple Discounts Coexist
```
Current Cart:
- Tournament Day 1 - Child: Alice - 30 CHF (full price)
- Tournament Day 1 - Child: Bob - 30 CHF (20% sibling discount = 24 CHF)
- Tournament Day 2 - Child: Alice - 30 CHF (33.33% same-child discount = 20 CHF)

Total: 30 + 24 + 20 = 74 CHF
```

## Debugging

### Enable Debug Logging
Set `WP_DEBUG` to `true` in `wp-config.php` to see detailed discount calculation logs:
- Previous order queries
- Discount application decisions
- Week/day matching logic
- Cache hits/misses

### Debug Log Examples
```
InterSoccer: Applied retroactive 50% same-season discount to item 1234 for attendee player-123 (previous order found)
InterSoccer: Applied progressive camp discount 10% to week 2 (position 2) for item 5678 for attendee player-456
InterSoccer: Applied tournament same-child discount 33.33% to day 2 for item 9012 for attendee player-789
```

## Deployment Checklist

- [ ] Export discount rules from production (`intersoccer_discount_rules`)
- [ ] Export discount messages (`intersoccer_discount_messages`)
- [ ] Export all retroactive settings (3 new options)
- [ ] Deploy code to staging
- [ ] Visit Admin UI to verify settings load correctly
- [ ] Test with real customer accounts that have previous orders
- [ ] Verify discounts apply correctly in cart
- [ ] Check debug logs for any errors
- [ ] Run all unit tests: `php vendor/bin/phpunit tests/`
- [ ] Deploy to production

## Performance Notes

### Query Optimization
- Previous order queries limited to 6 months by default (configurable)
- Static caching prevents repeated queries during cart calculations
- Only queries orders with status: completed, processing
- Efficient WooCommerce order query API used

### Expected Performance Impact
- **First cart calculation**: ~50-100ms additional (order query + processing)
- **Subsequent calculations**: <5ms (cached)
- **Memory**: Minimal (~1-2KB per customer session)

### Recommendations
- Keep lookback period at 6 months for optimal performance
- Increase to 12 months only if needed for seasonal products
- Monitor slow query log if using 24 months lookback

## Support & Troubleshooting

### Common Issues

**Issue**: Discounts not applying
- **Check**: Visit Admin UI, ensure retroactive discounts are enabled
- **Check**: Verify lookback period includes the previous order date
- **Check**: Confirm customer is logged in (guest checkouts may not work)

**Issue**: Wrong discount amount
- **Check**: Verify discount rates in Admin UI
- **Check**: Check if multiple discounts are conflicting
- **Check**: Review debug logs for calculation details

**Issue**: Performance slow
- **Check**: Reduce lookback period to 3-6 months
- **Check**: Ensure WooCommerce order indexes are optimized
- **Check**: Clear WordPress transients

## Version Information
- **Plugin**: InterSoccer Product Variations
- **Feature Version**: 1.12.0
- **Implementation Date**: December 2025
- **Test Coverage**: 40+ new tests, 331+ total tests
- **Status**: ✅ Production Ready

