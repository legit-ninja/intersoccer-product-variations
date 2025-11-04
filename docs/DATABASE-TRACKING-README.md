# Database Compatibility Tracking System
## Quick Reference Guide

**Created**: November 2025  
**Purpose**: Prevent deployment issues by tracking database configurations

---

## 📁 Files Created

### 1. `docs/database-environments.yml`
**What it is**: Configuration tracker for staging/production databases

**What to do**:
1. Fill in actual values by running SQL commands on each server
2. Update when database changes (upgrades, migrations)
3. Reference before deployments

**Key info tracked**:
- Database charset (UTF8 vs UTF8MB4)
- Table prefix (wp_ vs wp_1244388_)
- WPML configuration
- MySQL version
- Emoji support (yes/no)

---

### 2. `scripts/validate-compatibility.sh`
**What it is**: Pre-deployment validation script

**How to use**:
```bash
# Run before every deployment
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-product-variations
./scripts/validate-compatibility.sh
```

**What it checks**:
- ✓ No 4-byte emojis in translatable strings
- ✓ No hardcoded table prefixes
- ✓ No excessively long strings
- ✓ Environment documentation exists

**Exit codes**:
- `0` = All good, safe to deploy
- `1` = Errors found, fix before deploying

---

### 3. `docs/DATABASE-COMPATIBILITY.md`
**What it is**: Complete documentation guide

**Contains**:
- How to check database info
- What to track
- Why it matters
- Prevention strategies
- Lessons learned

---

## 🎯 Quick Deployment Checklist

Before deploying to **staging** or **production**:

```bash
# 1. Run validator
./scripts/validate-compatibility.sh

# 2. If validation passes, deploy
./deploy.sh

# 3. Activate and test on staging first
# (WordPress admin → Plugins → Activate)

# 4. If staging works, deploy to production
```

---

## 🔍 How to Fill in database-environments.yml

### For Staging:

```bash
# SSH to staging server
ssh staging-server

# Check database charset
mysql -u user -p -e "
  SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
  FROM information_schema.SCHEMATA
  WHERE TABLE_SCHEMA = 'your_staging_db';
"

# Check table prefix
mysql -u user -p -e "SHOW TABLES LIKE 'wp%';" your_staging_db | head -1

# Check MySQL version
mysql -u user -p -e "SELECT VERSION();"
```

Copy results into `docs/database-environments.yml` under `environments.staging.*`

### For Production:

Repeat the same commands on production server, update `environments.production.*`

---

## ⚠️ Known Issues & Solutions

### Issue: Staging has UTF8, Production has UTF8MB4

**Problem**: Code works on production but fails on staging

**Solution**: Always test on staging first (most restrictive)

**Rule**: Use basic Unicode (▶ ■ ↓ ↻ ✓) instead of emojis (🚀 ⏹️)

### Issue: Staging has non-standard table prefix

**Problem**: Hardcoded `wp_icl_strings` doesn't work on staging (`wp_1244388_`)

**Solution**: Always use `$wpdb->prefix . 'icl_strings'`

---

## 📊 Safe Symbol Reference

### ✅ SAFE for UTF8 (use these):
```
▶ ◀ ▲ ▼ ■ □ ● ○ ★ ☆ ✓ ✗ ⚠ → ← ↑ ↓ ↻
```

### ❌ UNSAFE for UTF8 (4-byte emojis):
```
🚀 ⏹️ ✅ ❌ ⚠️ 🎉 📥 🔄 😀 👍 💡 🔥
```

### Rule of Thumb:
- If it's colorful → probably 4-byte emoji → don't use in `_e()` or `__()`
- If it's black/white → probably safe UTF8 → OK to use

---

## 🚀 Integration with Deploy Script

To automatically run validation before deployment, add this to `deploy.sh`:

```bash
# Add after initial checks, before rsync

echo "🔍 Running compatibility validation..."
if [ -f "scripts/validate-compatibility.sh" ]; then
    bash scripts/validate-compatibility.sh
    if [ $? -ne 0 ]; then
        echo "❌ Validation failed! Fix errors before deploying."
        exit 1
    fi
fi
```

This prevents deployment if validation fails.

---

## 📝 Maintenance

### Update when:
- Database is upgraded (MySQL 5.7 → 8.0)
- Database charset changes (UTF8 → UTF8MB4)
- New environment is added (e.g., "local dev")
- WPML is updated
- Table prefix changes

### Review regularly:
- Before major releases
- After server migrations
- When adding new translatable content

---

## 🎓 Lessons Learned

### What Happened:
1. Plugin used emoji (🚀) in `_e()` translatable string
2. Staging database has UTF8 encoding (not UTF8MB4)
3. WPML tried to store emoji in database → rejected
4. Plugin activation failed

### What We Did:
1. Replaced all emojis with UTF8-safe symbols
2. Created tracking system to prevent future issues
3. Added automated validation
4. Documented all environments

### Prevention:
- **Track** database configs locally
- **Validate** before every deployment
- **Test** on staging (most restrictive) first
- **Use** basic Unicode universally

---

## 🆘 If Validation Fails

### Check 1: Emojis Found
```bash
# Find and replace:
🚀 → ▶
⏹️ → ■
✅ → ✓
📥 → ↓
🔄 → ↻
```

### Check 2: Hardcoded Prefixes
```php
// Bad:
FROM wp_icl_strings

// Good:
FROM {$wpdb->prefix}icl_strings
```

### Check 3: Long Strings
- UTF8 max for indexed columns: 191 characters
- Split long help text into multiple strings

---

## 📞 Quick Help

**Validator passes but plugin fails**: Database config may have changed  
→ Re-run SQL checks and update `database-environments.yml`

**Validator fails**: Fix reported issues before deploying  
→ See safe symbol reference above

**Need to add new environment**: Copy existing section in YAML, fill in values  
→ Use SQL commands provided

---

## ✅ Current Status

**Plugin Code**: ✅ All emojis removed, UTF8-safe  
**Validator**: ✅ Working, catches 4-byte emojis  
**Documentation**: ✅ Complete  
**Staging**: ⏳ Ready to test deployment  

---

**Next Step**: Test deployment on staging with clean database restore!

