# Price Flicker Fix - Single-Day Booking with Late Pickup

## Issue Description
The display price was "flickering" and compounding when configuring single-day booking types with late pickup. Customers were seeing rapidly changing prices (e.g., CHF 110 → CHF 135 → CHF 160 → CHF 245) even though the cart and checkout prices were correct.

## Root Cause Analysis

### The Problem
The issue was in `includes/elementor-widgets.php` with how the base price was being stored and retrieved for late pickup calculations:

1. **Incorrect Base Price Clearing** (Line 1393 - OLD CODE):
   - Every `found_variation` event cleared the stored base price
   - This happened even when the same variation was re-selected (e.g., when day checkboxes changed)
   - The variation ID didn't actually change, but the base price was being reset

2. **Reading Displayed Price as Base** (Lines 968-972 - OLD CODE):
   ```javascript
   if (!basePrice || isNaN(basePrice)) {
       // BUG: This reads the DISPLAYED price which may already include late pickup!
       basePrice = parseFloat(priceMatch[0].replace(',', ''));
       $priceContainer.data('intersoccer-base-price', basePrice);
   }
   ```

### The Cascading Effect
When a user changed day selections:

1. Day checkbox changed → triggers WooCommerce `found_variation` event
2. Base price cleared (even though variation ID didn't change)
3. `updateMainPriceWithLatePickup()` called
4. Base price not found, so it reads **displayed price** (CHF 135 = 110 + 25 late pickup)
5. Stores CHF 135 as new "base price"
6. Adds late pickup again → CHF 160
7. Another event fires → reads CHF 160 as base → CHF 185
8. Continues compounding...

## The Solution

### Changes Made

#### 1. Only Reset Base Price on Actual Variation Change
**File:** `includes/elementor-widgets.php` (Lines 1389-1404)

```javascript
// Only clear and reset base price if variation ID actually changed
var $priceContainer = jQuery('.woocommerce-variation-price');
var previousVariationId = $priceContainer.data('intersoccer-variation-id');

if (previousVariationId != variation.variation_id) {
    // New variation - store its base price from the variation data
    var basePrice = parseFloat(variation.display_price) || 0;
    $priceContainer.data('intersoccer-base-price', basePrice);
    $priceContainer.data('intersoccer-variation-id', variation.variation_id);
    console.log('InterSoccer Late Pickup: New variation detected, storing base price from variation data:', basePrice);
} else {
    console.log('InterSoccer Late Pickup: Same variation, preserving stored base price');
}
```

**Key Points:**
- Tracks the variation ID to detect actual changes
- Only stores new base price when variation ID changes
- Uses `variation.display_price` (authoritative source from WooCommerce)
- Preserves base price for same variation

#### 2. Never Use Displayed Price as Base Fallback
**File:** `includes/elementor-widgets.php` (Lines 945-957)

```javascript
function updateMainPriceWithLatePickup(latePickupCost) {
    var $priceContainer = jQuery('.woocommerce-variation-price');
    if (!$priceContainer.length) {
        console.log('InterSoccer Late Pickup: Price container not found, skipping main price update');
        return;
    }
    
    // Get the base price (must be already stored from variation data)
    var basePrice = parseFloat($priceContainer.data('intersoccer-base-price'));
    if (!basePrice || isNaN(basePrice)) {
        console.log('InterSoccer Late Pickup: Base price not available yet, skipping update');
        return;
    }
    
    // Calculate new total price
    var totalPrice = basePrice + parseFloat(latePickupCost || 0);
    // ... rest of function
}
```

**Key Points:**
- Removed fallback that read displayed price
- Returns early if base price not available
- Prevents price compounding
- Base price must be set from variation data first

#### 3. Proper AJAX Base Price Update
**File:** `includes/elementor-widgets.php` (Lines 733-738)

```javascript
// Update stored base price with the new camp price (from AJAX response)
var $priceContainer = jQuery('.woocommerce-variation-price');
if (data && data.rawPrice) {
    var newBasePrice = parseFloat(data.rawPrice);
    $priceContainer.data('intersoccer-base-price', newBasePrice);
    console.log('InterSoccer Late Pickup: Updated base price from AJAX to:', newBasePrice);
}
```

**Key Points:**
- Uses AJAX response `rawPrice` (server-calculated camp price)
- Properly updates base price when days change
- Late pickup is then recalculated from correct base

## Testing Checklist

### Scenario 1: Single-Day Booking with Late Pickup
1. ✅ Select a camp product
2. ✅ Choose "single-days" booking type
3. ✅ Select a player
4. ✅ Select 1 day (e.g., Tuesday)
5. ✅ Verify price shows correctly (e.g., CHF 110)
6. ✅ Select late pickup "Single Days" option
7. ✅ Check one day for late pickup
8. ✅ **Verify price updates to CHF 135 (110 + 25) and STAYS STABLE**
9. ✅ Add another camp day (e.g., Wednesday)
10. ✅ **Verify price updates to CHF 220 for 2 days, NO FLICKERING**
11. ✅ **Verify late pickup cost properly adds to CHF 245 (220 + 25)**
12. ✅ Remove a camp day
13. ✅ **Verify price properly decreases, NO COMPOUNDING**

### Scenario 2: Changing Between Variations
1. ✅ Select different age group or time
2. ✅ Verify base price resets from new variation
3. ✅ Add late pickup
4. ✅ Verify calculation is correct
5. ✅ Switch back to original variation
6. ✅ Verify price resets correctly

### Scenario 3: Full Week Late Pickup
1. ✅ Select "Full Week" late pickup option
2. ✅ Verify price adds full week cost correctly
3. ✅ Change camp days
4. ✅ **Verify full week late pickup cost stays consistent**

### Console Log Verification
When testing, look for these console messages:

**On variation change:**
```
InterSoccer Late Pickup: New variation detected, storing base price from variation data: 110
```

**On day checkbox change (same variation):**
```
InterSoccer Late Pickup: Same variation, preserving stored base price
```

**On AJAX price update:**
```
InterSoccer Late Pickup: Updated base price from AJAX to: 220
InterSoccer Late Pickup: Camp price updated, recalculating with late pickup
InterSoccer Late Pickup: Updated main price - base: 220 late pickup: 25 total: 245
```

**Should NOT see:**
- ❌ "Stored base price: [progressively increasing number]"
- ❌ "Cleared stored base price for new variation" (when variation didn't change)
- ❌ Base price that matches a total with late pickup already included

## Expected Behavior After Fix

### Price Flow
1. **Variation Selected**: Base price = CHF 110 (from `variation.display_price`)
2. **1 Day Selected**: Base price = CHF 110 (per-day rate)
3. **Late Pickup Added**: Total = CHF 135 (110 + 25), base stays 110
4. **2 Days Selected**: AJAX returns new base = CHF 220, late pickup recalculated = CHF 245
5. **Late Pickup Removed**: Total = CHF 220 (base only)

### Key Principles
- **Base price is authoritative**: Always from WooCommerce variation data or AJAX response
- **Base price is stable**: Only changes when variation ID changes or AJAX updates it
- **No circular dependencies**: Displayed price never becomes input for calculations
- **Idempotent operations**: Running same calculation multiple times gives same result

## Files Modified
- `includes/elementor-widgets.php` - Lines 730-746, 945-957, 1384-1404

## Deployment Notes
1. Clear browser cache after deployment
2. Test on staging first with multiple scenarios
3. Monitor console logs for any unexpected base price changes
4. Verify cart and checkout prices remain accurate

## Related Issues
- This fixes the "day 2 of flickering price" issue reported by customer
- Console logs were captured in `debug.log` for analysis
- Cart and checkout prices were always correct (backend calculations not affected)

## Date
November 5, 2025

## Author
AI Assistant with Jeremy Lee

