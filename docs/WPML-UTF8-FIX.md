# WPML UTF8 Database Fix

**Date**: November 2025  
**Issue**: Plugin activation failed on staging server with WPML database error  
**Root Cause**: Emoji characters in translatable strings require UTF8MB4 encoding

## Problem

When activating the plugin on `staging.intersoccer.ch`, WPML encountered this error:

```
We have detected a problem with some tables in the database. Please contact WPML support to get this fixed.

WordPress database error: Could not perform query because it contains invalid data.

Array
(
    [language] => en
    [context] => intersoccer-product-variations
    [name] => 🚀 Start Automated Update
    [value] => 🚀 Start Automated Update
    ...
)
```

**Root Cause**: The WordPress database on staging uses UTF8 (3-byte) encoding instead of UTF8MB4 (4-byte). Emojis like 🚀, ⏹️, ✅, 📥, 🔄 require UTF8MB4.

## Solution

Replaced all emoji characters in translatable strings (`_e()`, `__()`) with UTF8-compatible Unicode symbols:

| Emoji | Replacement | Meaning |
|-------|-------------|---------|
| 🚀 | ▶ | Play/Start |
| ⏹️ | ■ | Stop |
| ✅ | ✓ | Check/Success |
| ⚠️ | ⚠ | Warning |
| 🎉 | ✓ | Complete |
| 📥 | ↓ | Download |
| 🔄 | ↻ | Refresh/Reload |

## Files Modified

1. **`includes/woocommerce/admin-ui.php`**:
   - Line 2901: Start button text
   - Line 2904: Stop button text
   - Line 2056: Course fix completion message
   - Line 2059: Warning message
   - Line 2938: Download button
   - Line 2941: Process more button
   - Line 2984: JS button reset text
   - Line 3040: JS status log
   - Line 3128: JS button reset text
   - Line 3131: JS completion message
   - Line 3164: JS button reset text

2. **`intersoccer-product-variations.php`**:
   - Lines 227-232: Tooltip help text registration for translation

## Emojis Still Safe to Use

Emojis in the following contexts are **NOT** sent to WPML and are safe:
- ✅ JavaScript `console.log()` statements
- ✅ PHP `error_log()` statements  
- ✅ Non-translatable HTML/text

These don't interact with the WordPress database translation tables.

## Testing

After fix:
1. Deploy to staging: `./deploy.sh`
2. Navigate to **Plugins** page
3. Activate **InterSoccer Product Variations**
4. No WPML database error should appear ✓

## Long-term Solution Options

### Option A: Keep Basic Unicode (Current Fix)
**Pros**: 
- Works on all database configurations
- No database changes needed
- Clean, professional look

**Cons**:
- Less visually appealing than emojis

### Option B: Upgrade Database to UTF8MB4
**Pros**:
- Can use emojis everywhere
- Future-proof for other Unicode characters

**Cons**:
- Requires database migration
- Potential downtime
- Must update all tables and columns

**Recommendation**: Keep current fix (Option A). Basic Unicode symbols are professional and universally compatible.

## Prevention

**For future development**:
1. ❌ **DON'T**: Use emojis in `_e()` or `__()` translatable strings
2. ✅ **DO**: Use basic Unicode symbols (✓, ✗, ⚠, ▶, ■, ↓, ↻)
3. ✅ **DO**: Use emojis in console.log() or error_log() if needed

---

**Status**: ✓ Fixed and deployed to staging  
**Next**: Monitor for any similar issues in other plugins

