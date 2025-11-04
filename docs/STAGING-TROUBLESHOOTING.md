# WPML Emoji Error Troubleshooting - Staging

## The Issue

Even after deleting WPML strings and the plugin, you're still getting:
```
WordPress database error: Could not perform query because it contains invalid data.
[name] => 🚀 Start Automated Update
```

## Root Cause Analysis

**Most Likely**: The plugin files on staging still contain the OLD code with emojis.

When you:
1. ✅ Deleted WPML strings from database
2. ✅ Deleted plugin from WordPress admin

But if you didn't redeploy the FIXED code, then when you try to activate again:
- WordPress reads the plugin files (still has emojis)
- WPML tries to register those emoji strings
- Database rejects them (UTF8 encoding)
- Error appears again

## Solution Steps

### Step 1: Verify Current Code on Server

SSH into staging and check:

```bash
# SSH to staging
ssh your-staging-server

# Check if plugin directory exists
ls -la /path/to/wp-content/plugins/intersoccer-product-variations/

# If it exists, check for emojis in the code
grep -r "🚀" /path/to/wp-content/plugins/intersoccer-product-variations/includes/woocommerce/admin-ui.php

# Should return NOTHING if code is fixed
# If it returns matches, the old code is still there!
```

### Step 2: Completely Remove Plugin from Server

```bash
# On staging server, remove plugin directory completely
rm -rf /path/to/wp-content/plugins/intersoccer-product-variations/

# Verify it's gone
ls -la /path/to/wp-content/plugins/ | grep intersoccer-product-variations
# Should return nothing
```

### Step 3: Clear ALL WPML Cache

Run this SQL on staging database:

```sql
-- Clear WPML string cache from wp_options
DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%wpml_string_cache%';

DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%icl_string%';

DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%wpml_st_%';

-- Verify WPML strings are still gone
SELECT COUNT(*) FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';
-- Should still be 0

-- Check for any string packages
SELECT * FROM wp_1244388_icl_string_packages 
WHERE name LIKE '%intersoccer-product-variations%';

-- If any exist, delete them
DELETE FROM wp_1244388_icl_string_packages 
WHERE name LIKE '%intersoccer-product-variations%';
```

### Step 4: Deploy FIXED Code

On your **local machine** (not staging):

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations

# Verify local code is fixed (should return nothing)
grep "🚀" includes/woocommerce/admin-ui.php

# If nothing found, deploy:
./deploy.sh

# Watch for success message
```

### Step 5: Activate Plugin on Staging

1. Go to WordPress admin → **Plugins**
2. Find `InterSoccer Product Variations`
3. Click **Activate**
4. Should work now! ✓

---

## Additional Checks

### Check WPML Tables for String Packages

```sql
-- See all WPML tables
SHOW TABLES LIKE '%icl%';

-- Common WPML tables that might have cached data:
-- wp_1244388_icl_strings (we already cleaned)
-- wp_1244388_icl_string_translations (we already cleaned)
-- wp_1244388_icl_string_packages
-- wp_1244388_icl_string_pages
-- wp_1244388_icl_string_urls
-- wp_1244388_icl_string_positions

-- Check string packages
SELECT * FROM wp_1244388_icl_string_packages;

-- Check string pages  
SELECT * FROM wp_1244388_icl_string_pages 
WHERE string_id IN (
    SELECT id FROM wp_1244388_icl_strings 
    WHERE context = 'intersoccer-product-variations'
);
```

### Nuclear Option: Complete WPML Reset for This Plugin

If all else fails, run this comprehensive cleanup:

```sql
-- BACKUP FIRST!

-- 1. Delete from string positions
DELETE sp FROM wp_1244388_icl_string_positions sp
INNER JOIN wp_1244388_icl_strings s ON sp.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- 2. Delete from string pages
DELETE spg FROM wp_1244388_icl_string_pages spg
INNER JOIN wp_1244388_icl_strings s ON spg.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- 3. Delete from string urls
DELETE su FROM wp_1244388_icl_string_urls su
INNER JOIN wp_1244388_icl_strings s ON su.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- 4. Delete string translations (already done, but repeat for safety)
DELETE st FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- 5. Delete string packages
DELETE FROM wp_1244388_icl_string_packages 
WHERE name LIKE '%intersoccer-product-variations%' 
   OR name LIKE '%intersoccer_product_variations%';

-- 6. Delete the strings themselves
DELETE FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';

-- 7. Clear options cache
DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%wpml%string%intersoccer%';

-- 8. Final verification
SELECT 
    'icl_strings' as table_name,
    COUNT(*) as count
FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations'
UNION ALL
SELECT 
    'icl_string_translations',
    COUNT(*)
FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';
-- All should be 0
```

---

## Verify Fixed Code Before Deployment

On your local machine, run these checks:

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations

# Should return NOTHING (no emojis in translatable strings):
grep -r "🚀" includes/woocommerce/admin-ui.php
grep -r "⏹️" includes/woocommerce/admin-ui.php
grep -r "📥" includes/woocommerce/admin-ui.php
grep -r "🔄" includes/woocommerce/admin-ui.php

# Should return matches showing the FIXED symbols:
grep "▶ Start" includes/woocommerce/admin-ui.php
grep "■ Stop" includes/woocommerce/admin-ui.php
grep "↓ Download" includes/woocommerce/admin-ui.php
grep "↻ Process" includes/woocommerce/admin-ui.php
```

Expected output:
```
# Emojis: NOTHING
# Fixed symbols: FOUND ✓
```

---

## If You Need to Restore Database

### Before Restoring

Try the nuclear WPML cleanup above first. A database restore will lose any other work done on staging.

### Restore Process (If Necessary)

1. **Restore database backup**
2. **Run nuclear WPML cleanup SQL** (all tables above)
3. **Completely remove plugin directory from server**:
   ```bash
   rm -rf /path/to/wp-content/plugins/intersoccer-product-variations/
   ```
4. **Deploy FIXED code**:
   ```bash
   ./deploy.sh
   ```
5. **Activate plugin**

---

## Prevention

After this is fixed, add this to your deployment checklist:

✅ **Never use emojis in `_e()` or `__()` calls**
✅ **Use basic Unicode**: ▶ ■ ↓ ↻ ✓ ⚠ → ←
✅ **Test on staging before production**
✅ **Check WPML string registration after activation**

---

## Expected Final State

After successful fix:

1. ✅ Plugin activates without errors
2. ✅ WPML String Translation shows emoji-free strings
3. ✅ All functionality works normally
4. ✅ French/German translations can be added if needed

---

**Next Step**: Verify the code on staging server actually has the fixes, then redeploy if needed.

