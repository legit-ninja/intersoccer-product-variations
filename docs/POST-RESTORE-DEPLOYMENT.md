# Post-Restore Deployment Instructions
## Deploy Fixed Plugin to Staging (After Database Restore)

**Status**: ✅ Database restored successfully  
**Next**: Deploy emoji-free plugin code

---

## ✅ Simple Deployment Steps

### Step 1: Verify Local Code is Fixed

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations

# Should return NOTHING (no emojis):
grep "🚀" includes/woocommerce/admin-ui.php

# Should return matches (fixed symbols):
grep "▶ Start" includes/woocommerce/admin-ui.php
```

If emojis are found, the code isn't fixed yet (let me know).  
If you see "▶ Start", you're good to deploy! ✓

---

### Step 2: Deploy to Staging

```bash
./deploy.sh
```

Watch for success message confirming files transferred.

---

### Step 3: Activate Plugin

1. Go to WordPress admin → **Plugins**
2. Find `InterSoccer Product Variations`
3. Click **Activate**
4. ✅ Should activate without WPML errors!

---

### Step 4: Verify WPML Registration (Optional)

Navigate to: **WPML → String Translation**

Search for: `intersoccer-product-variations`

You should see emoji-free strings:
- ✓ `▶ Start Automated Update`
- ✓ `■ Stop Processing`
- ✓ `↓ Download Results Log`
- ✓ `↻ Process More Orders`

---

## What Changed in the Fixed Code

All emoji characters in translatable strings replaced with UTF8-safe Unicode:

| Before | After |
|--------|-------|
| 🚀 Start Automated Update | ▶ Start Automated Update |
| ⏹️ Stop Processing | ■ Stop Processing |
| ✅ Course holiday fix completed | ✓ Course holiday fix completed |
| ⚠️ Warning message | ⚠ Warning message |
| 📥 Download Results Log | ↓ Download Results Log |
| 🔄 Process More Orders | ↻ Process More Orders |
| 🎉 Processing complete! | ✓ Processing complete! |

All changes are purely visual - functionality is identical.

---

## Files Modified (Already Fixed in Your Local Code)

- ✅ `includes/woocommerce/admin-ui.php` (9 replacements)
- ✅ `intersoccer-product-variations.php` (4 replacements in help text)

---

## Prevention for Future

**Rule for ALL plugins going forward**:
- ❌ **NEVER** use emojis in `_e()` or `__()` translatable strings
- ✅ **ALWAYS** use basic Unicode: ▶ ■ ↓ ↻ ✓ ✗ ⚠ → ←
- ✅ Emojis are OK in `console.log()` and `error_log()` (not translated)

---

## If You Still Get WPML Errors

This would be very unusual after a database restore, but if it happens:

1. Check that plugin files on server are actually the new version:
   ```bash
   # SSH to staging
   ssh your-server
   
   # Check for emojis (should return nothing)
   grep "🚀" /path/to/wp-content/plugins/intersoccer-product-variations/includes/woocommerce/admin-ui.php
   ```

2. If emojis found, the deployment didn't work:
   ```bash
   # Remove plugin completely
   rm -rf /path/to/wp-content/plugins/intersoccer-product-variations/
   
   # Redeploy from local
   ./deploy.sh
   ```

---

**Ready to deploy!** 🚀 (emoji OK here, it's not translated 😄)

