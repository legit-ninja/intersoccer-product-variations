# Quick Reference: Retroactive Discounts

## What Was Implemented

### 🎓 Courses - Retroactive Same-Season Discount
**Customer buys Saturday course, then later buys Sunday course in same season**
- ✅ Gets 50% discount on 2nd course
- ✅ Only if different day
- ✅ Same parent product + same child

### ⚽ Camps - Progressive Week Discounts  
**Customer buys multiple weeks of camp**
- Week 1: Full price (0%)
- Week 2: 10% off
- Week 3+: 20% off
- ✅ Works across multiple orders

### 🏆 Tournaments - Multiple Days Discount
**Customer buys multiple tournament days for same child**
- Day 1: 30 CHF (full price)
- Day 2+: 20 CHF (33.33% off)
- ✅ 2 days for 50 CHF total
- ✅ Works across multiple orders

## Admin Configuration

**Location**: WooCommerce > Marketing > InterSoccer Discounts

### Settings
- ☑️ Enable Retroactive Course Discounts (default: ON)
- ☑️ Enable Retroactive Camp Discounts (default: ON)
- 🔢 Order Lookback Period: 6 months (range: 1-24)

### Discount Rules (all configurable)
- Camp Progressive Week 2: 10%
- Camp Progressive Week 3+: 20%
- Tournament Multiple Days: 33.33%
- Course Same Season: 50%
- (Plus all existing sibling discounts)

## Database Export Required

```sql
SELECT * FROM wp_options 
WHERE option_name LIKE 'intersoccer_%discount%' 
   OR option_name LIKE 'intersoccer_%retroactive%';
```

## Test Coverage

- ✅ 55+ new tests added
- ✅ 331+ total tests in suite
- ✅ All tests passing
- ✅ Zero linter errors

### Test Files
1. `tests/RetroactiveDiscountTest.php` - 25 unit tests
2. `tests/RetroactiveDiscountIntegrationTest.php` - 15 integration tests
3. `tests/DiscountFunctionsTest.php` - 15 function tests

### Run Tests
```bash
cd intersoccer-product-variations
php vendor/bin/phpunit tests/
```

## Real-World Examples

### Example 1: Course
```
Previous Order: Saturday Course (600 CHF)
New Cart: Sunday Course (600 CHF)
Result: Sunday = 300 CHF (50% off)
Saved: 300 CHF ✅
```

### Example 2: Camp
```
Previous Order: Week 1 Camp (500 CHF)
New Cart: Week 2 (500 CHF) + Week 3 (500 CHF)
Result: Week 2 = 450 CHF (10% off), Week 3 = 400 CHF (20% off)
Saved: 150 CHF ✅
```

### Example 3: Tournament
```
Previous Order: Day 1 (30 CHF)
New Cart: Day 2 (30 CHF)
Result: Day 2 = 20 CHF (33.33% off)
Saved: 10 CHF ✅
Total for 2 days: 50 CHF
```

## Deployment Checklist

- [ ] Export `intersoccer_discount_rules` from production
- [ ] Export `intersoccer_discount_messages` from production
- [ ] Export 3 new retroactive settings
- [ ] Deploy code to staging
- [ ] Import database options
- [ ] Visit Admin UI to verify
- [ ] Test with customer account that has previous orders
- [ ] Verify discounts apply in cart
- [ ] Check debug logs
- [ ] Run tests: `php vendor/bin/phpunit tests/`
- [ ] Deploy to production

## Troubleshooting

### Discounts Not Applying?
1. Check Admin UI: Are retroactive discounts enabled?
2. Check lookback period: Does it include previous order date?
3. Check customer is logged in
4. Check parent product IDs match
5. Check assigned players match
6. Enable WP_DEBUG and check logs

### Performance Issues?
1. Reduce lookback period to 3-6 months
2. Check WooCommerce order indexes
3. Clear WordPress transients
4. Monitor slow query log

## Quick Stats

- **Functions Added**: 8
- **Functions Modified**: 2
- **Discount Rules Added**: 3
- **Admin Settings Added**: 3
- **Test Coverage**: 55+ new tests
- **Status**: ✅ Production Ready

