# Testing Tournament Discounts - Step by Step

## Issue
Debug log shows no cart calculation messages, which means the discount logic hasn't run yet.

## Steps to Trigger Discount Calculation

### 1. Clear All Caches
```bash
# Via WP-CLI
wp cache flush
wp transient delete --all

# Or via WordPress Admin
# Tools > Site Health > Clear Cache
# Or use a caching plugin's clear cache button
```

### 2. Add Tournaments to Cart
- Add Tournament Product #1 (assign to Child A)
- Add Tournament Product #2 (assign to same Child A)
- **Important**: Make sure both tournaments:
  - Have the same parent product (same tournament series)
  - Are assigned to the SAME child/player

### 3. View Cart Page
**Critical**: You must visit the cart page to trigger discount calculations

- Go to: `/cart/` or click "View Cart"
- This triggers `woocommerce_before_calculate_totals` hook
- Discount logic runs at this point

### 4. Check Debug Log
Look for these specific messages:

```
InterSoccer Precise: Built cart context with X items...
InterSoccer Tournament: Checking same-child multiple days discount
InterSoccer Tournament: Rate from settings: 33.33%
InterSoccer Tournament: Customer ID: X
InterSoccer Tournament: Tournaments in context: Y
```

### 5. If No Debug Messages Appear

**Problem**: Cart calculation not running

**Solutions**:
a) Clear browser cache and cookies
b) Try in incognito/private browsing mode
c) Disable any cart caching plugins
d) Check if WP_DEBUG is actually enabled:
   ```php
   // In wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

### 6. Expected Debug Output

When working correctly, you should see:
```
[timestamp] InterSoccer Precise: Built cart context with 2 items, 0 children with camps, 0 children with courses, 1 children with tournaments...
[timestamp] InterSoccer Tournament: Checking same-child multiple days discount
[timestamp] InterSoccer Tournament: Rate from settings: 33.33%
[timestamp] InterSoccer Tournament: Customer ID: 42
[timestamp] InterSoccer Tournament: Lookback months: 6
[timestamp] InterSoccer Tournament: Tournaments in context: 1
[timestamp] InterSoccer Tournament: Grouped into 1 groups by player/parent
[timestamp] InterSoccer Tournament: Processing group - Parent: 1234, Player: player-567, Cart items: 2
[timestamp] InterSoccer Tournament: Found 0 previous tournaments
[timestamp] InterSoccer Tournament: Total days (previous + cart): 2
[timestamp] InterSoccer Tournament: Total days >= 2, applying discount logic
[timestamp] InterSoccer Tournament: Item 0 - Position: 1, Price: 30
[timestamp] InterSoccer Tournament: Day position 1 < 2, no discount applied
[timestamp] InterSoccer Tournament: Item 1 - Position: 2, Price: 30
[timestamp] InterSoccer Tournament: Applying 33.33% discount - Base: 30, Discounted: 20
[timestamp] InterSoccer: Applied tournament same-child discount 33.33% to day 2 for item 1234 for attendee player-567
```

### 7. Check Discount Rule in Database

**Via WP-CLI**:
```bash
wp option get intersoccer_discount_rules --format=json | grep -A10 tournament-same-child
```

**Via SQL**:
```sql
SELECT option_value FROM wp_options WHERE option_name = 'intersoccer_discount_rules';
```

Look for:
```
tournament-same-child-multiple-days => Array
(
    [id] => tournament-same-child-multiple-days
    [type] => tournament  ← Must be 'tournament', not 'general'
    [condition] => same_child_multiple_days  ← Must be this, not 'none'
    [rate] => 33.33
    [active] => 1
)
```

### 8. Re-Initialize Discount Rules

If the rule is corrupted:
1. Go to: WooCommerce > Marketing > InterSoccer Discounts
2. Find "Tournament Same Child Multiple Days Discount"
3. Verify:
   - Type: Tournament
   - Condition: same_child_multiple_days
   - Rate: 33.33
   - Active: ✅ Checked
4. Click "Save All Rules"
5. Try adding tournaments to cart again

### 9. Quick Test Checklist

- [ ] WP_DEBUG enabled in wp-config.php
- [ ] Caches cleared (WordPress, browser, plugins)
- [ ] Latest code deployed to server
- [ ] Two tournaments added to cart
- [ ] SAME child assigned to both
- [ ] Cart page viewed (not just added to cart)
- [ ] Debug.log checked for "InterSoccer Tournament:" messages
- [ ] Discount rule verified in Admin UI
- [ ] Rule type = 'tournament' (not 'general')
- [ ] Rule condition = 'same_child_multiple_days' (not 'none')

### 10. Common Mistakes

❌ **Only adding to cart**: Must visit cart/checkout page
❌ **Different children**: Must be same child for both tournaments
❌ **Different parent products**: Tournaments must be from same series
❌ **Guest checkout**: May not work without customer account
❌ **Cached page**: Browser/plugin caching prevents discount logic from running
❌ **Code not deployed**: Old code still running on server

## Next Action

After following steps 1-3 above, check the debug.log again. If you still don't see "InterSoccer Tournament:" messages, there's a deeper issue (code not deployed, hook not firing, etc.).


