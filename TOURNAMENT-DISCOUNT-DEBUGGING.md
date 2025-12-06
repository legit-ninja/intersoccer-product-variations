# Tournament Discount Debugging Guide

## Issue
Tournament same-child multiple days discount (33.33%) is not applying in cart.

## Debug Checklist

### 1. Check Discount Rule is Active
**Admin UI**: WooCommerce > Marketing > InterSoccer Discounts

Look for:
- **Rule Name**: "Tournament Same Child Multiple Days Discount"
- **Type**: Tournament
- **Condition**: `same_child_multiple_days`
- **Rate**: 33.33%
- **Active**: ✅ Checkbox must be checked

### 2. Enable Debug Logging
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### 3. Check Debug Log
Location: `wp-content/debug.log`

Look for these log entries when adding tournaments to cart:
```
InterSoccer Tournament: Checking same-child multiple days discount
InterSoccer Tournament: Rate from settings: 33.33%
InterSoccer Tournament: Customer ID: [number]
InterSoccer Tournament: Tournaments in context: [number]
InterSoccer Tournament: Grouped into X groups by player/parent
InterSoccer Tournament: Processing group - Parent: X, Player: Y, Cart items: Z
InterSoccer Tournament: Found N previous tournaments
InterSoccer Tournament: Total days (previous + cart): M
InterSoccer Tournament: Applying 33.33% discount
```

### 4. Common Issues & Solutions

#### Issue: "Rate from settings: NULL/DISABLED"
**Solution**: Discount rule not active
- Go to Admin UI
- Find "Tournament Same Child Multiple Days Discount"
- Check the "Active" checkbox
- Click "Save All Rules"

#### Issue: "Tournaments in context: 0"
**Solution**: Tournaments not being detected
- Check that products have `pa_activity-type` = "tournament"
- Verify assigned player/attendee is set in cart
- Check cart items have `assigned_attendee` or `assigned_player`

#### Issue: "Grouped into 0 groups"
**Solution**: Missing parent_product_id or assigned_player
- Verify tournament products are variable products (not simple)
- Check variations have parent product
- Ensure player is assigned at add-to-cart

#### Issue: "Total days < 2"
**Solution**: Only 1 tournament in cart and no previous orders
- Add 2+ tournament days to cart for same child, OR
- Make sure customer has previous tournament order
- Check parent product IDs match (must be same tournament series)

#### Issue: "Found 0 previous tournaments"
**Solution**: Previous order query not finding tournaments
- Check customer is logged in (guest checkouts limited)
- Verify previous order is within lookback period (default: 6 months)
- Confirm previous order has status: completed or processing
- Check assigned_player IDs match exactly

### 5. Manual Verification Steps

1. **Add Tournament to Cart**:
   - Select Tournament Day 1
   - Assign to a child
   - Add to cart
   - Note the price (should be 30 CHF)

2. **Check Cart Context**:
   - Look in debug.log for: "Built cart context with..."
   - Should show: "X children with tournaments"

3. **Add Second Tournament**:
   - Select Tournament Day 2 (or same day, different variation)
   - Assign to **same child**
   - Add to cart
   - Price should be 20 CHF (33.33% discount)

4. **Verify Discount Applied**:
   - Check cart total
   - Look for discount message
   - Debug log should show: "Applied tournament same-child discount"

### 6. SQL Query to Check Discount Rule

```sql
SELECT * FROM wp_options 
WHERE option_name = 'intersoccer_discount_rules';
```

Look for this rule in the serialized data:
```php
[tournament-same-child-multiple-days] => Array
(
    [id] => tournament-same-child-multiple-days
    [name] => Tournament Same Child Multiple Days Discount
    [type] => tournament
    [condition] => same_child_multiple_days
    [rate] => 33.33
    [active] => 1  // MUST BE 1
)
```

### 7. Test Scenarios

#### Scenario A: Two tournaments in cart
```
Cart:
- Tournament Day 1, Child: Alice → 30 CHF
- Tournament Day 2, Child: Alice → Should be 20 CHF

Expected: Day 2 gets 33.33% discount
```

#### Scenario B: One tournament, one previous order
```
Previous Order (completed, < 6 months ago):
- Tournament Day 1, Child: Bob → 30 CHF (paid)

Current Cart:
- Tournament Day 2, Child: Bob → Should be 20 CHF

Expected: Day 2 gets 33.33% discount
```

### 8. Check Database Options

```sql
-- Check if retroactive settings exist
SELECT option_name, option_value 
FROM wp_options 
WHERE option_name IN (
    'intersoccer_enable_retroactive_course_discounts',
    'intersoccer_enable_retroactive_camp_discounts',
    'intersoccer_retroactive_discount_lookback_months'
);
```

If missing, visit Admin UI to initialize them.

### 9. Force Rule Initialization

Visit: **WooCommerce > Marketing > InterSoccer Discounts**

This will:
- Auto-create missing discount rules
- Initialize settings with defaults
- Save to database

### 10. Debug Output Example

**Working correctly** should show:
```
InterSoccer Tournament: Checking same-child multiple days discount
InterSoccer Tournament: Rate from settings: 33.33%
InterSoccer Tournament: Customer ID: 42
InterSoccer Tournament: Lookback months: 6
InterSoccer Tournament: Tournaments in context: 1
InterSoccer Tournament: Grouped into 1 groups by player/parent
InterSoccer Tournament: Processing group - Parent: 1234, Player: player-567, Cart items: 2
InterSoccer Tournament: Found 0 previous tournaments
InterSoccer Tournament: Total days (previous + cart): 2
InterSoccer Tournament: Item 0 - Position: 1, Price: 30
InterSoccer Tournament: Day position 1 < 2, no discount applied
InterSoccer Tournament: Item 1 - Position: 2, Price: 30
InterSoccer Tournament: Applying 33.33% discount - Base: 30, Discounted: 20
InterSoccer: Applied tournament same-child discount 33.33% to day 2 for item 1234 for attendee player-567
```

## Next Steps

1. Enable WP_DEBUG
2. Add 2 tournaments to cart for same child
3. Check debug.log
4. Share the log entries starting with "InterSoccer Tournament:"
5. I can identify the exact issue from the logs


