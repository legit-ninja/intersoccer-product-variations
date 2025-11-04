# Emergency Recovery - Staging Database
## Fix WPML Issue Without Full Restore

**Situation**: Database restore failed, staging needs to be recovered  
**Solution**: Surgical fix - just clean WPML strings and deploy fixed code

---

## 🚨 Quick Recovery (No Full Restore Needed!)

You don't need a full database restore. We just need to:
1. Clean WPML strings for this one plugin
2. Deploy the fixed code
3. Activate

The rest of your database is fine!

---

## Option 1: Direct SQL Fix (Fastest - 5 minutes)

### Step 1: Run This SQL on Staging Database

Connect to staging database:
```bash
mysql -u your_user -p your_database_name
```

Then run:
```sql
-- Delete WPML string translations
DELETE st FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

-- Delete WPML string registrations
DELETE FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';

-- Clear WPML cache
DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%wpml_string_cache%'
   OR option_name LIKE '%icl_string%';

-- Verify cleanup (should return 0)
SELECT COUNT(*) FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';
```

### Step 2: Remove Plugin Files from Server

```bash
# SSH to staging
ssh your-server

# Remove plugin directory
cd /var/www/html/wp-content/plugins/  # or wherever your plugins are
rm -rf intersoccer-product-variations/

# Verify removed
ls -la | grep intersoccer-product-variations
```

### Step 3: Deploy Fixed Code

On your local machine:
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
./deploy.sh
```

### Step 4: Activate Plugin

WordPress admin → Plugins → Activate `InterSoccer Product Variations`

✅ Should work now!

---

## Option 2: Via phpMyAdmin (If No SSH Access)

If you can't SSH but have phpMyAdmin access:

### Step 1: Run SQL via phpMyAdmin

1. Log into phpMyAdmin on staging
2. Select your database
3. Click **SQL** tab
4. Copy/paste this query:

```sql
DELETE st FROM wp_1244388_icl_string_translations st
INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
WHERE s.context = 'intersoccer-product-variations';

DELETE FROM wp_1244388_icl_strings 
WHERE context = 'intersoccer-product-variations';

DELETE FROM wp_1244388_options 
WHERE option_name LIKE '%wpml_string_cache%'
   OR option_name LIKE '%icl_string%';
```

5. Click **Go**

### Step 2: Delete Plugin via WordPress Admin

1. Go to **Plugins**
2. Deactivate `InterSoccer Product Variations` (if active)
3. Click **Delete**
4. Confirm deletion

### Step 3: Deploy Fixed Code

On your local machine:
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
./deploy.sh
```

### Step 4: Activate

WordPress admin → Plugins → Activate

---

## Option 3: Skip This Plugin Temporarily

If you need staging working NOW and can skip this plugin:

1. **Deactivate the plugin** in WordPress admin
2. **Delete the plugin** via WordPress admin
3. Staging is now functional (without this plugin)
4. Deploy the fixed version later when convenient

The rest of staging should work fine.

---

## Option 4: Contact Hosting Provider

If none of the above work, contact hosting support:

**What to ask for**:
> "Can you help restore just these two database tables from backup?
> - wp_1244388_icl_strings
> - wp_1244388_icl_string_translations
> 
> Or alternatively, truncate these tables:
> ```sql
> TRUNCATE TABLE wp_1244388_icl_strings;
> TRUNCATE TABLE wp_1244388_icl_string_translations;
> ```
> This will clear WPML string cache and allow our plugin to register fresh strings."

Most hosting providers can do this quickly.

---

## What Went Wrong (For Future Reference)

1. Plugin had emoji characters in translatable strings: `🚀 Start`
2. Staging database uses UTF8 (3-byte) encoding
3. Emojis require UTF8MB4 (4-byte) encoding
4. WPML tried to store emojis in database → rejected
5. Plugin activation failed

**The fix**: Replace emojis with UTF8-safe Unicode symbols (▶ ■ ↓ ↻)

This is already done in your local code! Just need to deploy it.

---

## Recovery Checklist

- [ ] Run SQL cleanup (Option 1 or 2)
- [ ] Remove old plugin files from server
- [ ] Deploy fixed code: `./deploy.sh`
- [ ] Activate plugin in WordPress
- [ ] Verify WPML strings are emoji-free
- [ ] Test plugin functionality

---

## If Staging is Completely Broken

**Nuclear option** (only if nothing else works):

1. **Export database** (via phpMyAdmin or mysqldump)
2. **Find/replace in SQL dump**:
   ```bash
   # On local machine after downloading dump
   sed -i 's/🚀/▶/g' staging-dump.sql
   sed -i 's/⏹️/■/g' staging-dump.sql
   sed -i 's/📥/↓/g' staging-dump.sql
   sed -i 's/🔄/↻/g' staging-dump.sql
   ```
3. **Import modified dump** back to staging
4. Deploy fixed plugin code

But try Options 1-2 first - they're much faster!

---

## Need Help?

If you're stuck at any step, let me know:
- What error messages you're seeing
- Which option you tried
- What your hosting setup is (cPanel, Plesk, custom, etc.)

We'll get this fixed! 💪

---

**TL;DR**: You don't need a full database restore. Just run the SQL cleanup, remove the plugin, redeploy the fixed code, and activate. Should take ~5 minutes.

