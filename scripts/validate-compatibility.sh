#!/bin/bash

# Database Compatibility Validator
# Run before deployment to catch compatibility issues early
# 
# Usage: ./scripts/validate-compatibility.sh
# 
# Checks:
# - No emojis in translatable strings (_e, __)
# - No hardcoded table prefixes
# - No excessively long strings

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

ERRORS=0
WARNINGS=0

echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo -e "${BLUE}  Database Compatibility Validator${NC}"
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo ""

# Change to script directory
cd "$(dirname "$0")/.."

# ============================================
# Check 1: Emojis in _e() calls
# ============================================
echo -e "${BLUE}[1/5]${NC} Checking for emojis in _e() translatable strings..."

# 4-byte emoji ranges (unsafe for UTF8, safe for UTF8MB4 only)
# Excludes basic Unicode symbols like ✓ ⚠ which are safe
EMOJI_PATTERN='[\x{1F300}-\x{1F9FF}]'

EMOJI_E=$(grep -rn "_e(" . --include="*.php" | grep -P "$EMOJI_PATTERN" | grep -v "vendor/" | grep -v "node_modules/")
if [ ! -z "$EMOJI_E" ]; then
    echo -e "${RED}  ❌ FAIL: Found emojis in _e() calls${NC}"
    echo "$EMOJI_E" | head -5
    if [ $(echo "$EMOJI_E" | wc -l) -gt 5 ]; then
        echo "  ... and $(($(echo "$EMOJI_E" | wc -l) - 5)) more"
    fi
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ PASS${NC}"
fi

# ============================================
# Check 2: Emojis in __() calls
# ============================================
echo -e "${BLUE}[2/5]${NC} Checking for emojis in __() translatable strings..."

EMOJI_UNDERSCORE=$(grep -rn "__(" . --include="*.php" | grep -P "$EMOJI_PATTERN" | grep -v "vendor/" | grep -v "node_modules/")
if [ ! -z "$EMOJI_UNDERSCORE" ]; then
    echo -e "${RED}  ❌ FAIL: Found emojis in __() calls${NC}"
    echo "$EMOJI_UNDERSCORE" | head -5
    if [ $(echo "$EMOJI_UNDERSCORE" | wc -l) -gt 5 ]; then
        echo "  ... and $(($(echo "$EMOJI_UNDERSCORE" | wc -l) - 5)) more"
    fi
    ERRORS=$((ERRORS + 1))
else
    echo -e "${GREEN}  ✓ PASS${NC}"
fi

# ============================================
# Check 3: Hardcoded table prefixes
# ============================================
echo -e "${BLUE}[3/5]${NC} Checking for hardcoded table prefixes..."

# Look for wp_icl or wp_options without $wpdb->prefix
HARDCODED=$(grep -rn "wp_icl\|wp_options\|wp_postmeta" . --include="*.php" | grep -v '\$wpdb->prefix' | grep -v 'vendor/' | grep -v 'node_modules/' | grep -v 'docs/' | grep -v '//.*wp_')
if [ ! -z "$HARDCODED" ]; then
    HARDCODED_COUNT=$(echo "$HARDCODED" | wc -l)
    echo -e "${YELLOW}  ⚠️  WARNING: Found $HARDCODED_COUNT potential hardcoded table references${NC}"
    echo "$HARDCODED" | head -3
    if [ $HARDCODED_COUNT -gt 3 ]; then
        echo "  ... and $((HARDCODED_COUNT - 3)) more"
    fi
    echo -e "${YELLOW}  Consider using \$wpdb->prefix for compatibility with non-standard prefixes${NC}"
    WARNINGS=$((WARNINGS + 1))
else
    echo -e "${GREEN}  ✓ PASS${NC}"
fi

# ============================================
# Check 4: Very long translatable strings
# ============================================
echo -e "${BLUE}[4/5]${NC} Checking for very long strings (>190 chars)..."

# This is a simplified check - looks for strings that might be too long
LONG_STRINGS=$(grep -rn "_e(\|__(" . --include="*.php" | awk -F: '{print $2}' | grep -E ".{190,}" | wc -l)
if [ $LONG_STRINGS -gt 0 ]; then
    echo -e "${YELLOW}  ⚠️  WARNING: Found $LONG_STRINGS potentially long translatable strings${NC}"
    echo -e "${YELLOW}  UTF8 max for indexed columns is 191 characters${NC}"
    echo -e "${YELLOW}  Manual verification recommended${NC}"
    WARNINGS=$((WARNINGS + 1))
else
    echo -e "${GREEN}  ✓ PASS${NC}"
fi

# ============================================
# Check 5: Database environment documentation
# ============================================
echo -e "${BLUE}[5/5]${NC} Checking for environment documentation..."

if [ -f "docs/database-environments.yml" ]; then
    echo -e "${GREEN}  ✓ PASS: docs/database-environments.yml exists${NC}"
else
    echo -e "${YELLOW}  ⚠️  WARNING: docs/database-environments.yml not found${NC}"
    echo -e "${YELLOW}  Consider creating this file to document database configs${NC}"
    WARNINGS=$((WARNINGS + 1))
fi

# ============================================
# Summary
# ============================================
echo ""
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo -e "${BLUE}  Validation Summary${NC}"
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}  ✅ ALL CHECKS PASSED!${NC}"
    echo ""
    echo -e "${GREEN}  Safe to deploy to all environments.${NC}"
    echo ""
    exit 0
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}  ⚠️  $WARNINGS WARNING(S)${NC}"
    echo ""
    echo -e "${YELLOW}  Review warnings above but safe to proceed.${NC}"
    echo ""
    exit 0
else
    echo -e "${RED}  ❌ $ERRORS ERROR(S), $WARNINGS WARNING(S)${NC}"
    echo ""
    echo -e "${RED}  FIX ERRORS BEFORE DEPLOYMENT!${NC}"
    echo ""
    echo "Common fixes:"
    echo "  - Replace emojis with basic Unicode:"
    echo "    🚀 → ▶   (start/play)"
    echo "    ⏹️ → ■   (stop)"
    echo "    ✅ → ✓   (success)"
    echo "    📥 → ↓   (download)"
    echo "    🔄 → ↻   (refresh)"
    echo ""
    echo "  - Use \$wpdb->prefix instead of 'wp_' hardcoded"
    echo ""
    exit 1
fi

