# Day Availability Feature - Admin Control for Camp & Late Pickup Days

## Overview

This feature allows admins to control which days of the week are available for customers to select when booking single-day camps and late pickup options. It provides granular control over camp schedules (e.g., 4-day camps, special schedules) without hardcoding day availability.

## Problem Statement

Previously, the system assumed **all camps run Monday-Friday** and **all late pickup is available Monday-Friday**. This created issues when:
- A camp only ran 4 days (e.g., Monday-Thursday)
- Late pickup was only available certain days
- Special schedules needed to exclude specific days

Admins had no way to control this, leading to customer confusion and booking errors.

## Solution

Added **day-of-week checkboxes** to the WooCommerce variation admin UI that allow admins to:
1. **Enable/disable specific camp days** for single-day booking types
2. **Enable/disable specific late pickup days** when late pickup is enabled
3. **See changes immediately** reflected on the frontend without code changes

## Features

### 1. Admin UI - Variation Settings

**Location**: WooCommerce → Products → Edit Product → Variations → Expand Variation

**New UI Elements**:

#### Available Camp Days (Single-Day Booking Types Only)
- **When shown**: Automatically appears for variations with `attribute_pa_booking-type` = "single-days" (or similar)
- **UI**: Blue highlighted section with 5 checkboxes (Monday-Friday)
- **Default**: All days checked (enabled)
- **Purpose**: Control which days customers can select for their camp booking

#### Available Late Pickup Days (When Late Pickup Enabled)
- **When shown**: Automatically appears when "Enable Late Pick Up" checkbox is checked
- **UI**: Yellow highlighted section with 5 checkboxes (Monday-Friday)
- **Default**: All days checked (enabled)
- **Purpose**: Control which days customers can add late pickup service

### 2. Metadata Storage

**Camp Days Availability**:
- **Meta key**: `_intersoccer_camp_days_available`
- **Format**: Serialized array `['Monday' => true, 'Tuesday' => true, 'Wednesday' => false, ...]`
- **Default**: All days `true` if not set (backward compatibility)

**Late Pickup Days Availability**:
- **Meta key**: `_intersoccer_late_pickup_days_available`
- **Format**: Serialized array `['Monday' => true, 'Tuesday' => true, ...]`
- **Default**: All days `true` if not set (backward compatibility)

### 3. Frontend Integration

**Camp Day Checkboxes**:
- Dynamically rendered based on `available_camp_days` from variation settings
- If admin unchecks "Wednesday", customers will not see Wednesday as an option
- Works seamlessly with existing functionality (AJAX price updates, cart, checkout)

**Late Pickup Day Checkboxes**:
- Dynamically rendered based on `available_late_pickup_days` from variation settings
- Only enabled days appear in the "Single Days" late pickup option
- Price calculations automatically adjust to available days

## User Flows

### Admin Workflow

#### Setting Up a 4-Day Camp (Monday-Thursday Only)

1. Navigate to **Products → Edit Camp Product**
2. Expand the **Variations** section
3. Find the variation with `Booking Type` = "Single Days"
4. Scroll to **"Available Camp Days"** section (blue box)
5. **Uncheck "Friday"**
6. Click **"Save changes"**

**Result**: Customers will now only see Monday-Thursday as selectable days for this camp.

#### Enabling Late Pickup for Monday-Wednesday Only

1. In the same variation, check **"Enable Late Pick Up"**
2. Scroll to **"Available Late Pickup Days"** section (yellow box)
3. **Uncheck "Thursday" and "Friday"**
4. Click **"Save changes"**

**Result**: Customers can now add late pickup, but only for Monday, Tuesday, or Wednesday.

### Customer Experience

#### Booking a 4-Day Camp

**Before** (hardcoded Monday-Friday):
1. Customer selects "Single Days" booking
2. Sees checkboxes for: Monday, Tuesday, Wednesday, Thursday, **Friday** ❌
3. Might select Friday thinking it's available
4. Admin has to manually fix the order

**After** (with day availability):
1. Customer selects "Single Days" booking
2. Sees checkboxes for: Monday, Tuesday, Wednesday, Thursday ✅
3. Friday is not even shown as an option
4. No confusion, no errors

#### Adding Late Pickup

**Before**:
1. Customer adds late pickup
2. Sees all 5 days available
3. Selects days that aren't actually available
4. Admin has to contact customer to adjust

**After**:
1. Customer adds late pickup
2. Sees only enabled days (e.g., Mon-Wed)
3. Can only select days that are actually available
4. No manual intervention needed

## Technical Implementation

### Admin Side (PHP)

#### File: `includes/admin-product-fields.php`

**Display Logic** (lines 336-410):
```php
// Detect single-day booking type
$booking_type = get_post_meta($variation_id, 'attribute_pa_booking-type', true);
$is_single_day = ($booking_type === 'single-days' || /* other variations */);

// Load saved day availability (default to all enabled)
$camp_days_available = get_post_meta($variation_id, '_intersoccer_camp_days_available', true);
if (!is_array($camp_days_available) || empty($camp_days_available)) {
    $camp_days_available = array_fill_keys($weekdays, true);
}

// Render checkboxes for camp days (if single-day booking)
if ($is_single_day) {
    // Blue box with checkboxes
}

// Render checkboxes for late pickup days (if late pickup enabled)
if ($enable_late_pickup === 'yes') {
    // Yellow box with checkboxes
}
```

**Save Logic** (lines 486-532):
```php
// Process camp days availability
if (isset($_POST['_intersoccer_camp_days_available'][$loop])) {
    foreach ($weekdays as $day) {
        $camp_days_available[$day] = isset($_POST['_intersoccer_camp_days_available'][$loop][$day]);
    }
}

// Save to post meta
update_post_meta($variation_id, '_intersoccer_camp_days_available', $camp_days_available);
```

### Frontend Side (JavaScript)

#### File: `includes/elementor-widgets.php`

**Passing Data to Frontend** (lines 173-204):
```javascript
foreach ($variations as $variation) {
    $camp_days_available = get_post_meta($variation_id, '_intersoccer_camp_days_available', true);
    
    // Filter to only include enabled days
    $available_camp_days = array_keys(array_filter($camp_days_available));
    
    $variation_settings[$variation_id] = [
        'enabled' => ($enable_late_pickup === 'yes'),
        'per_day_cost' => $per_day_cost,
        'full_week_cost' => $full_week_cost,
        'available_camp_days' => $available_camp_days,
        'available_late_pickup_days' => $available_late_pickup_days,
    ];
}
```

**Camp Day Rendering** (lines 525-539):
```javascript
// Get available days from variation settings
var variationId = $form.find('input[name="variation_id"]').val();
var availableDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']; // Default

if (variationId && latePickupVariationSettings[variationId]) {
    var settings = latePickupVariationSettings[variationId];
    if (settings.available_camp_days && settings.available_camp_days.length > 0) {
        availableDays = settings.available_camp_days;
    }
}

var daysToShow = availableDays;
```

**Late Pickup Day Rendering** (lines 728-733):
```javascript
// Get available late pickup days from settings
var days = (settings.available_late_pickup_days && settings.available_late_pickup_days.length > 0) 
    ? settings.available_late_pickup_days 
    : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']; // Default
```

## Backward Compatibility

### Existing Variations Without Day Availability Data

**Behavior**: All days enabled (Monday-Friday)

**Why**: When metadata doesn't exist:
- PHP defaults to `array_fill_keys($weekdays, true)`
- Frontend defaults to `['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']`
- Existing camps continue to work exactly as before

### Migration Path

**No migration needed!**
- Existing variations: Work as before (all days available)
- New variations: Admin can customize as needed
- Gradual adoption: Update variations as schedules change

## Edge Cases & Validation

### No Days Selected

**Admin Side**:
- No validation currently - admin can uncheck all days
- Consider adding JavaScript validation in future to require at least 1 day

**Frontend Side**:
- If `available_camp_days` is empty array, fallback to default (all days)
- Prevents broken UI if admin misconfigures

### Late Pickup Without Camp Days

**Scenario**: Admin enables late pickup but variation is NOT single-day booking

**Behavior**:
- Camp days checkboxes: Not shown (correct)
- Late pickup days checkboxes: Shown and functional (correct)
- Late pickup is independent of camp booking type

### Mixed Languages

**Current**: Days stored in English (`'Monday'`, `'Tuesday'`, etc.)

**Translation**: Frontend uses `intersoccerDayTranslations` to display in customer's language

**Validation**: Server-side validation should normalize to English before checking availability

## Testing Checklist

### Admin UI
- [ ] Single-day variation shows "Available Camp Days" section
- [ ] Full-week variation does NOT show "Available Camp Days" section
- [ ] Enabling late pickup shows "Available Late Pickup Days" section
- [ ] Disabling late pickup hides "Available Late Pickup Days" section
- [ ] Checkboxes default to all checked (enabled) for new variations
- [ ] Checkboxes remember previous state when editing existing variation
- [ ] Unchecking days and saving persists the changes
- [ ] Re-opening the variation shows correct checkbox states

### Frontend - Camp Days
- [ ] Customer sees only enabled days when selecting single-day camp
- [ ] Unchecked days in admin do not appear on frontend
- [ ] Price calculation works correctly with filtered days
- [ ] AJAX price updates work when toggling enabled days
- [ ] Cart displays correct selected days
- [ ] Checkout displays correct days in order item metadata
- [ ] Order confirmation shows correct days

### Frontend - Late Pickup
- [ ] "Single Days" late pickup shows only enabled days
- [ ] Unchecked late pickup days do not appear on frontend
- [ ] Late pickup cost calculates correctly with filtered days
- [ ] Main price display updates to include late pickup cost
- [ ] Cart displays correct late pickup days
- [ ] Checkout displays correct late pickup days in metadata
- [ ] Order confirmation shows correct late pickup days

### Backward Compatibility
- [ ] Existing variations without day metadata default to all days (Mon-Fri)
- [ ] Frontend handles missing `available_camp_days` gracefully
- [ ] Frontend handles missing `available_late_pickup_days` gracefully
- [ ] Old orders placed before this feature still display correctly

### Edge Cases
- [ ] Variation with 1 day enabled (e.g., only Friday) works correctly
- [ ] Variation with 0 days enabled falls back to default (all days)
- [ ] Changing booking type from full-week to single-day shows day checkboxes
- [ ] Changing booking type from single-day to full-week hides day checkboxes
- [ ] Multilingual sites translate day names correctly

## Future Enhancements

1. **Admin Validation**: Require at least 1 day to be enabled
2. **Bulk Edit**: Allow editing day availability for multiple variations at once
3. **Templates**: Save day availability templates (e.g., "4-Day Week", "Mon-Wed-Fri")
4. **Visual Calendar**: Replace checkboxes with interactive calendar picker
5. **Holiday Integration**: Auto-disable days based on public holidays
6. **Conditional Late Pickup**: Different late pickup days based on camp days selected
7. **Analytics**: Track which day combinations are most popular
8. **Copy Settings**: Button to copy day availability from one variation to another

## Troubleshooting

### Days Not Filtering on Frontend

**Symptoms**: Admin unchecks days, but all days still show on frontend

**Possible Causes**:
1. **Cache**: WooCommerce variation cache not cleared
   - **Solution**: Clear cache: `wc_delete_product_transients($variation_id)`
   
2. **JavaScript not loading settings**: `latePickupVariationSettings` is empty
   - **Solution**: Check browser console for errors, verify `data-variation-settings` attribute

3. **Old cached page**: Browser or CDN cache
   - **Solution**: Hard refresh (Ctrl+Shift+R) or clear CDN cache

### Checkboxes Not Saving

**Symptoms**: Admin checks/unchecks days, saves, but changes don't persist

**Possible Causes**:
1. **PHP error during save**: Check `error_log` for save errors
2. **Loop index mismatch**: Bulk save uses `$loop` index
   - **Solution**: Verify `$loop` is correct in POST data
3. **POST data not reaching server**: Form submission issue
   - **Solution**: Check network tab in browser dev tools

### Price Calculation Incorrect

**Symptoms**: Customer selects 3 days, but price shows 5 days

**Possible Causes**:
1. **Available days not being used**: Fallback to default (all days)
   - **Solution**: Verify `available_camp_days` is in variation settings
2. **Server-side validation mismatch**: Server calculates differently
   - **Solution**: Ensure server uses same day availability logic

## Related Files

- **Admin UI**: `includes/admin-product-fields.php` (lines 336-532)
- **Frontend Data**: `includes/elementor-widgets.php` (lines 173-204)
- **Frontend Rendering**: `includes/elementor-widgets.php` (lines 484-596, 717-780)
- **Documentation**: This file

## Version History

- **v1.0** (November 4, 2025): Initial implementation
  - Admin UI for day-of-week checkboxes
  - Metadata storage for camp and late pickup days
  - Frontend dynamic rendering
  - Full backward compatibility

---

**Date**: November 4, 2025  
**Status**: ✅ Ready for testing & deployment  
**Priority**: P1 (Major admin UX improvement)

