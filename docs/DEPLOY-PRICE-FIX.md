# Deployment Checklist: Price Flicker Fix

## Quick Summary
Fixed the price flickering issue when configuring single-day camps with late pickup. The problem was that the base price was being cleared and recalculated from the displayed price (which already included late pickup), causing a compounding effect.

## What Changed
**File Modified:** `includes/elementor-widgets.php`

**3 Key Changes:**
1. **Lines 1389-1404**: Only reset base price when variation ID actually changes (not on every event)
2. **Lines 945-957**: Never use displayed price as fallback for base price
3. **Lines 733-738**: Properly update base price from AJAX response

## Pre-Deployment
- [x] Code changes made
- [x] No linter errors
- [x] Documentation created (PRICE-FLICKER-FIX.md)

## Deployment Steps

### 1. Backup
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
cp includes/elementor-widgets.php includes/elementor-widgets.php.backup-$(date +%Y%m%d-%H%M%S)
```

### 2. Deploy to Staging (if available)
```bash
# Use your normal deployment process
./deploy.sh staging
```

### 3. Test on Staging
Open browser console (F12) and test:

**Test Case 1: Basic Single-Day with Late Pickup**
1. Go to a camp product page
2. Select "single-days" booking type
3. Select a player
4. Select 1 day (e.g., Tuesday) → **Check price: CHF 110**
5. Select late pickup "Single Days" → **Check price: CHF 135**
6. Check console for: `"Same variation, preserving stored base price"`
7. **VERIFY: Price stays at CHF 135 (no flickering to 160, 185, etc.)**

**Test Case 2: Multiple Days**
1. Add another day (Wednesday) → **Check price: CHF 220**
2. With late pickup still selected → **Check price: CHF 245**
3. Check console for: `"Updated base price from AJAX to: 220"`
4. **VERIFY: No price flickering or compounding**

**Test Case 3: Variation Change**
1. Change age group or time slot
2. Check console for: `"New variation detected, storing base price from variation data"`
3. **VERIFY: Base price resets correctly**

### 4. Console Log Checks

**Should See:**
```
✅ InterSoccer Late Pickup: New variation detected, storing base price from variation data: 110
✅ InterSoccer Late Pickup: Same variation, preserving stored base price
✅ InterSoccer Late Pickup: Updated base price from AJAX to: 220
✅ InterSoccer Late Pickup: Updated main price - base: 220 late pickup: 25 total: 245
```

**Should NOT See:**
```
❌ InterSoccer Late Pickup: Cleared stored base price for new variation (when variation didn't change)
❌ Base price values that keep increasing (135, 160, 185, 210...)
❌ Flickering or rapidly changing prices
```

### 5. Deploy to Production
```bash
./deploy.sh production
# or your normal production deployment process
```

### 6. Post-Deployment Testing
1. Test the exact scenario that customers reported
2. Verify cart and checkout prices are still correct
3. Monitor for any JavaScript errors in browser console
4. Test on multiple browsers (Chrome, Firefox, Safari)

### 7. Monitor
- Check for customer reports in first 24 hours
- Monitor support tickets related to pricing
- Review analytics for cart abandonment rates

## Rollback Plan
If issues occur:
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
# Find the backup file
ls -la includes/elementor-widgets.php.backup-*
# Restore the backup (replace TIMESTAMP with actual timestamp)
cp includes/elementor-widgets.php.backup-TIMESTAMP includes/elementor-widgets.php
# Redeploy
./deploy.sh production
```

## Success Criteria
- ✅ No price flickering when selecting days
- ✅ Late pickup costs add correctly
- ✅ Base price stays stable within same variation
- ✅ Base price resets properly on variation change
- ✅ Cart and checkout prices remain accurate
- ✅ No JavaScript errors in console
- ✅ No customer complaints about confusing prices

## Support Notes
If customers still report issues:
1. Ask them to clear browser cache
2. Ask them to try incognito/private mode
3. Get browser console logs (F12 → Console → right-click → Save as...)
4. Get screenshots showing the flickering
5. Note which product/variation they're using

## Technical Details
See `docs/PRICE-FLICKER-FIX.md` for complete technical documentation.

## Date
November 5, 2025

