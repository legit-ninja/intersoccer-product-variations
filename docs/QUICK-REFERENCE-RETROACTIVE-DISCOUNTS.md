# Quick Reference: Retroactive Discounts

## What Was Implemented

### Courses - Retroactive Same-Season Discount
**Customer buys Saturday course, then later buys Sunday course in same season**
- Gets 50% discount on 2nd course
- Only if different day
- Same parent product + same child

### Camps - Progressive Week Discounts
**Customer buys multiple weeks of camp**
- Week 1: Full price (0%)
- Week 2: 10% off
- Week 3+: 20% off
- Works across multiple orders

### Tournaments - Multiple Days Discount
**Customer buys multiple tournament days for same child**
- Day 1: 30 CHF (full price)
- Day 2+: 20 CHF (33.33% off)
- 2 days for 50 CHF total
- Works across multiple orders

### Sibling / Multi-Child Discounts Across Orders (camps & courses only)
**Customer books Child A in a prior order, then Child B alone in a new cart**
- Camp and course sibling rates count prior children within lookback
- Camps: type-wide; courses: same season as cart items
- Tournament and birthday: sibling discounts apply in the current cart only (not across orders)
- Highest merged spend = 0%; 2nd/3rd+ rates apply to **current cart lines only**
- Camp stacking: `max(sibling%, progressive%)` on a line

## Admin Configuration

**Location**: WooCommerce > Marketing > InterSoccer Discounts

### Settings
- Enable Retroactive Course Discounts (default: ON)
- Enable Retroactive Camp Discounts (default: ON)
- Enable Retroactive Sibling Discounts (default: ON)
- Order Lookback Period: 6 months (range: 1-24)

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

Key option: `intersoccer_enable_retroactive_sibling_discounts`

## Test Coverage

- Sibling-across-orders cases in `tests/RetroactiveSiblingDiscountTest.php`
- Run: `php vendor/bin/phpunit tests/`

## Real-World Examples

### Example 1: Course
```
Previous Order: Saturday Course (600 CHF)
New Cart: Sunday Course (600 CHF)
Result: Sunday = 300 CHF (50% off)
Saved: 300 CHF
```

### Example 2: Camp
```
Previous Order: Week 1 Camp (500 CHF)
New Cart: Week 2 (500 CHF) + Week 3 (500 CHF)
Result: Week 2 = 450 CHF (10% off), Week 3 = 400 CHF (20% off)
Saved: 150 CHF
```

### Example 3: Tournament
```
Previous Order: Day 1 (30 CHF)
New Cart: Day 2 (30 CHF)
Result: Day 2 = 20 CHF (33.33% off)
Saved: 10 CHF
Total for 2 days: 50 CHF
```

### Example 4: Sibling across orders
```
Previous Order: Child A full-week camp (500 CHF)
New Cart: Child B full-week camp (450 CHF)
Result: Child B = 360 CHF (20% sibling)
```

## Deployment Checklist

- [ ] Export `intersoccer_discount_rules` from production
- [ ] Export `intersoccer_discount_messages` from production
- [ ] Export retroactive settings including `intersoccer_enable_retroactive_sibling_discounts`
- [ ] Deploy code to staging
- [ ] Import database options
- [ ] Visit Admin UI to verify
- [ ] Test with customer account that has previous orders
- [ ] Verify sibling discount with only one child in cart
- [ ] Run tests: `php vendor/bin/phpunit tests/`
- [ ] Deploy to production

## Troubleshooting

### Discounts Not Applying?
1. Check Admin UI: Are retroactive sibling discounts enabled?
2. Check lookback period: Does it include previous order date?
3. Check customer is logged in
4. Camps: prior item must be full-week
5. Courses: prior child must share a cart season
6. Enable WP_DEBUG and check logs

### Performance Issues?
1. Reduce lookback period to 3-6 months
2. Check WooCommerce order indexes
3. Clear WordPress transients
4. Monitor slow query log
