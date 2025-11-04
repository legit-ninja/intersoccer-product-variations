# WPML String Cleanup Instructions
## Removing Emoji Strings from WPML Database

**Issue**: Even though we've removed emojis from the plugin code, WPML still has the old emoji strings cached in its database, causing activation errors.

**Solution**: Delete all WPML strings for the plugin, then redeploy the cleaned code.

---

## Step 1: Clean WPML String Cache

### Via WPML Admin UI:

1. Navigate to **WPML → Theme and plugins localization**
2. Find the **Domain:** `intersoccer-product-variations`
3. Select all strings for this domain
4. Click **Delete** or use bulk delete option
5. Confirm deletion

### Alternative: Via Database (if UI doesn't work):

```sql
-- Connect to staging database
-- Replace 'wp_' with your actual table prefix

-- View problematic strings
SELECT * FROM wp_icl_strings 
WHERE context = 'intersoccer-product-variations' 
AND (
    name LIKE '%🚀%' OR 
    name LIKE '%⏹️%' OR 
    name LIKE '%✅%' OR 
    name LIKE '%📥%' OR 
    name LIKE '%🔄%' OR
    name LIKE '%⚠️%' OR
    name LIKE '%🎉%'
);

-- Delete all strings for this plugin (safest)
DELETE FROM wp_icl_strings 
WHERE context = 'intersoccer-product-variations';

-- Delete translations
DELETE FROM wp_icl_string_translations 
WHERE string_id NOT IN (SELECT id FROM wp_icl_strings);
```

---

## Step 2: Deactivate & Delete Plugin

1. **Deactivate** the plugin (if it's partially active)
2. **Delete** the plugin from WordPress
   - This ensures no old files remain

---

## Step 3: Redeploy Fixed Plugin

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations

# Deploy the emoji-free version
./deploy.sh
```

---

## Step 4: Activate Plugin

1. Navigate to **Plugins** page
2. **Activate** `InterSoccer Product Variations`
3. WPML should now register the new emoji-free strings ✓

---

## Step 5: Verify WPML Registration

1. Navigate to **WPML → Theme and plugins localization**
2. Find domain: `intersoccer-product-variations`
3. Verify strings now show:
   - ✓ `▶ Start Automated Update` (not 🚀)
   - ✓ `■ Stop Processing` (not ⏹️)
   - ✓ `↓ Download Results Log` (not 📥)
   - ✓ `↻ Process More Orders` (not 🔄)

---

## Troubleshooting

### If Error Persists After Cleanup:

**Option A: Clear WPML Cache**
```bash
# Via WP-CLI on staging server
wp cache flush
wp wpml string-translation clear-cache
```

**Option B: Reset WPML String Translation**

1. Navigate to **WPML → Support**
2. Find **Troubleshooting** section
3. Click **Clear cache in String Translation**
4. Click **Remove ghost entries from String Translation**

**Option C: Nuclear Option (Complete WPML Reset for This Domain)**

```sql
-- BACKUP DATABASE FIRST!

-- Remove all string translations
DELETE st FROM wp_icl_string_translations st
INNER JOIN wp_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- Remove all string registrations
DELETE FROM wp_icl_strings 
WHERE context = 'intersoccer-product-variations';

-- Remove string packages
DELETE FROM wp_icl_string_packages 
WHERE name LIKE '%intersoccer-product-variations%';

-- Clear WPML cache
DELETE FROM wp_options 
WHERE option_name LIKE '%wpml_string_cache%';
```

After running SQL cleanup:
1. Deactivate plugin
2. Delete plugin files
3. Redeploy with `./deploy.sh`
4. Reactivate plugin

---

## Prevention for Future

**Rules for all plugins**:
1. ❌ **NEVER** use emojis in `_e()` or `__()` translatable strings
2. ✅ **ALWAYS** use basic Unicode: ✓, ✗, ⚠, ▶, ■, ↓, ↻, →, ←
3. ✅ Emojis are OK in `error_log()` and `console.log()` (not translated)

**Safe Unicode Symbols** (work on UTF8 and UTF8MB4):
```
✓ ✗ ✔ ✖ ⚠ ★ ☆ ● ○ ■ □ ▶ ◀ ▲ ▼ ↑ ↓ → ← ↻ ⟳ ⌘ ⎈
```

**Unsafe (UTF8MB4 only)**:
```
🚀 ⏹️ ✅ ❌ ⚠️ 🎉 📥 🔄 😀 👍 💡 🔥
```

---

## Expected Outcome

After cleanup and redeployment:
- ✅ Plugin activates without WPML errors
- ✅ All strings register in WPML correctly
- ✅ French/German translations work (if needed)
- ✅ No database encoding issues

---

**Status**: Awaiting WPML string cleanup on staging  
**Next**: Redeploy plugin after cleanup

