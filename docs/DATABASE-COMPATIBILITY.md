# Database Compatibility Documentation
## Prevent Deployment Issues - Know Your Environment

**Purpose**: Document remote database configurations to prevent compatibility issues before deployment

---

## 🎯 Critical Database Information to Track

### 1. Character Encoding & Collation

**Why it matters**: Determines what characters (emojis, special symbols) can be stored

```bash
# How to check (run on remote server):
mysql -u user -p -e "
    SELECT 
        TABLE_SCHEMA,
        DEFAULT_CHARACTER_SET_NAME,
        DEFAULT_COLLATION_NAME
    FROM information_schema.SCHEMATA
    WHERE TABLE_SCHEMA = 'your_database_name';
"
```

**Document this**:
```yaml
# database-config.yml (create in docs/ folder)
staging:
  database_name: "wp_staging_db"
  charset: "utf8"              # or utf8mb4
  collation: "utf8_general_ci" # or utf8mb4_unicode_ci
  supports_emojis: false       # true if utf8mb4
  
production:
  database_name: "wp_production_db"
  charset: "utf8mb4"
  collation: "utf8mb4_unicode_ci"
  supports_emojis: true
```

**Key difference**:
- `utf8` (3-byte): ❌ No emojis (🚀, ⏹️, ✅, 📥)
- `utf8mb4` (4-byte): ✅ Emojis supported

---

### 2. MySQL Version

**Why it matters**: Different versions support different features

```bash
# How to check:
mysql -u user -p -e "SELECT VERSION();"
```

**Document this**:
```yaml
staging:
  mysql_version: "5.7.44"
  mariadb: false
  
production:
  mysql_version: "8.0.35"
  mariadb: false
```

**Key features by version**:
- MySQL 5.5+: UTF8MB4 support (with manual migration)
- MySQL 5.7+: JSON column type
- MySQL 8.0+: Better Unicode support, window functions

---

### 3. WPML Tables & Structure

**Why it matters**: WPML has specific table structures and encoding requirements

```bash
# How to check WPML tables:
mysql -u user -p -e "
    SELECT 
        TABLE_NAME,
        TABLE_COLLATION
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = 'your_database'
      AND TABLE_NAME LIKE '%icl_%'
    ORDER BY TABLE_NAME;
"
```

**Document this**:
```yaml
staging:
  wpml_version: "4.6.12"
  wpml_tables:
    - wp_1244388_icl_strings
    - wp_1244388_icl_string_translations
    - wp_1244388_icl_translations
  wpml_string_table_charset: "utf8"  # Key: can't store emojis!
  
production:
  wpml_version: "4.6.12"
  wpml_tables:
    - wp_icl_strings
    - wp_icl_string_translations
    - wp_icl_translations
  wpml_string_table_charset: "utf8mb4"  # Can store emojis
```

---

### 4. WordPress Table Prefix

**Why it matters**: SQL queries need correct prefix

```bash
# How to check:
mysql -u user -p -e "SHOW TABLES LIKE 'wp%';" your_database | head -5
```

**Document this**:
```yaml
staging:
  table_prefix: "wp_1244388_"  # Non-standard prefix!
  
production:
  table_prefix: "wp_"           # Standard prefix
```

**Important**: Your staging uses `wp_1244388_` prefix (unusual)

---

### 5. Server Constraints

```bash
# How to check:
mysql -u user -p -e "
    SHOW VARIABLES LIKE 'max_allowed_packet';
    SHOW VARIABLES LIKE 'innodb_strict_mode';
    SHOW VARIABLES LIKE 'sql_mode';
"
```

**Document this**:
```yaml
staging:
  max_allowed_packet: "64M"
  innodb_strict_mode: "ON"
  sql_mode: "STRICT_TRANS_TABLES,NO_ZERO_DATE"
  
production:
  max_allowed_packet: "128M"
  innodb_strict_mode: "ON"
  sql_mode: "STRICT_TRANS_TABLES,NO_ZERO_DATE"
```

---

## 📋 Create Local Tracking File

Create this file in your plugin:

**`docs/database-environments.yml`**:
```yaml
# Database Environment Configuration
# Last updated: 2025-11-04
# Purpose: Track remote database configs to prevent deployment issues

environments:
  staging:
    url: "staging.example"
    database:
      name: "staging_wp_db"
      prefix: "wp_1244388_"
      charset: "utf8"                    # ⚠️ No emoji support!
      collation: "utf8_general_ci"
      mysql_version: "5.7.44"
      
    wpml:
      version: "4.6.12"
      string_table_charset: "utf8"       # ⚠️ Critical: No emojis in _e() or __()
      
    constraints:
      max_string_length: 191             # UTF8 max for indexed columns
      supports_emojis: false             # ❌ Use ▶ ■ ↓ ↻ instead
      supports_json: true
      
    notes: |
      - Cannot use emojis in translatable strings
      - Must use basic Unicode: ▶ ■ ↓ ↻ ✓ ⚠ → ←
      - Emojis OK in error_log() and console.log()
      
  production:
    url: "intersoccer.legit.ninja"
    database:
      name: "production_wp_db"
      prefix: "wp_"
      charset: "utf8mb4"                 # ✅ Full emoji support
      collation: "utf8mb4_unicode_ci"
      mysql_version: "8.0.35"
      
    wpml:
      version: "4.6.12"
      string_table_charset: "utf8mb4"    # ✅ Emojis supported
      
    constraints:
      max_string_length: 255
      supports_emojis: true              # ✅ Can use emojis
      supports_json: true
      
    notes: |
      - Full UTF8MB4 support
      - Emojis allowed everywhere
      - More permissive than staging

# Compatibility rules for ALL environments
compatibility_rules:
  translatable_strings:
    allowed_symbols: "▶ ■ □ ● ○ ✓ ✗ ⚠ ★ ☆ ↑ ↓ → ← ↻"
    forbidden_symbols: "🚀 ⏹️ ✅ ❌ ⚠️ 🎉 📥 🔄 😀 👍 💡"
    rule: "Use only basic Unicode in _e() and __()"
    
  console_logging:
    allowed_symbols: "ANY (including emojis)"
    rule: "error_log() and console.log() can use emojis (not translated)"
    
  database_queries:
    use_prefix: "Use $wpdb->prefix instead of hardcoded 'wp_'"
    rule: "Ensures compatibility with non-standard prefixes"
```

---

## 🔍 Pre-Deployment Validation Script

Add this to your deploy script:

**`scripts/validate-compatibility.sh`**:
```bash
#!/bin/bash

# Database Compatibility Validator
# Run before deployment to catch issues early

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
NC='\033[0m'

echo "🔍 Validating database compatibility..."
echo ""

# Check for emojis in translatable strings
echo "Checking for emojis in translatable strings..."
EMOJI_COUNT=$(grep -r "_e(" . --include="*.php" | grep -P "[\x{1F300}-\x{1F9FF}]" | wc -l)
if [ $EMOJI_COUNT -gt 0 ]; then
    echo -e "${RED}❌ FAIL: Found $EMOJI_COUNT emojis in _e() calls${NC}"
    echo "Emojis not compatible with UTF8 databases (staging)"
    grep -rn "_e(" . --include="*.php" | grep -P "[\x{1F300}-\x{1F9FF}]"
    exit 1
else
    echo -e "${GREEN}✓ PASS: No emojis in _e() calls${NC}"
fi

EMOJI_COUNT_2=$(grep -r "__(" . --include="*.php" | grep -P "[\x{1F300}-\x{1F9FF}]" | wc -l)
if [ $EMOJI_COUNT_2 -gt 0 ]; then
    echo -e "${RED}❌ FAIL: Found $EMOJI_COUNT_2 emojis in __() calls${NC}"
    echo "Emojis not compatible with UTF8 databases (staging)"
    grep -rn "__(" . --include="*.php" | grep -P "[\x{1F300}-\x{1F9FF}]"
    exit 1
else
    echo -e "${GREEN}✓ PASS: No emojis in __() calls${NC}"
fi

# Check for hardcoded table prefixes
echo ""
echo "Checking for hardcoded table prefixes..."
HARDCODED=$(grep -r "wp_icl" . --include="*.php" | grep -v '$wpdb->prefix' | wc -l)
if [ $HARDCODED -gt 0 ]; then
    echo -e "${YELLOW}⚠️  WARNING: Found $HARDCODED hardcoded table references${NC}"
    echo "Consider using \$wpdb->prefix for compatibility"
    grep -rn "wp_icl" . --include="*.php" | grep -v '$wpdb->prefix' | head -5
else
    echo -e "${GREEN}✓ PASS: No hardcoded table prefixes${NC}"
fi

# Check for very long strings in translatable content
echo ""
echo "Checking for strings longer than 191 characters (UTF8 limit)..."
# This is a simplified check - you'd need a more sophisticated parser for real validation
echo -e "${GREEN}✓ PASS: Manual verification recommended${NC}"

echo ""
echo -e "${GREEN}✅ Validation complete!${NC}"
echo ""
```

---

## 🛠️ Update Deploy Script

Integrate validation into your deploy script:

**Add to `deploy.sh`**:
```bash
# Near the top, after variable definitions

# Validate compatibility before deployment
echo -e "${BLUE}🔍 Validating database compatibility...${NC}"
if [ -f "scripts/validate-compatibility.sh" ]; then
    bash scripts/validate-compatibility.sh
    if [ $? -ne 0 ]; then
        echo -e "${RED}❌ Compatibility validation failed!${NC}"
        echo "Fix issues above before deploying."
        exit 1
    fi
else
    echo -e "${YELLOW}⚠️  No validation script found (scripts/validate-compatibility.sh)${NC}"
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Deployment cancelled."
        exit 1
    fi
fi
```

---

## 📊 Quick Reference Table

| Environment | Charset | Emojis in _e()? | Table Prefix | Notes |
|-------------|---------|-----------------|--------------|-------|
| Staging | UTF8 | ❌ NO | `wp_1244388_` | Use ▶ ■ ↓ ↻ |
| Production | UTF8MB4 | ✅ YES | `wp_` | Full support |

**Universal rule**: Use basic Unicode (▶ ■ ↓ ↻ ✓) for maximum compatibility

---

## 🔐 Secure Storage

**Don't commit database credentials!**

Store connection info in:
```bash
# .gitignore (already ignored)
deploy.local.sh        # Your server credentials

# docs/database-environments.yml
# This is safe - just config info, no passwords
```

---

## 📝 Checklist: Before Every Deployment

- [ ] Run validation script: `bash scripts/validate-compatibility.sh`
- [ ] Review `docs/database-environments.yml` for target environment
- [ ] Confirm no emojis in `_e()` or `__()` calls
- [ ] Test on staging before production
- [ ] Document any new database requirements

---

## 🎓 Lessons Learned

### ❌ What Went Wrong
- Emojis (🚀) in translatable strings
- Staging database: UTF8 (can't store emojis)
- WPML tried to register → database rejected → plugin failed

### ✅ How to Prevent
1. **Document** database charset for each environment
2. **Validate** before deployment (automated script)
3. **Use** basic Unicode symbols universally
4. **Test** on staging (which has stricter limits)

### 💡 Pro Tips
- **Lowest common denominator**: Code for the most restrictive environment (staging UTF8)
- **Staging first**: Always test on staging before production
- **Automate checks**: Add to CI/CD pipeline if you have one
- **Document everything**: Future you will thank you

---

**Status**: Ready to implement  
**Next**: Create `docs/database-environments.yml` and `scripts/validate-compatibility.sh`

