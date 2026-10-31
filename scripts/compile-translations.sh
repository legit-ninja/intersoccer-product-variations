#!/bin/bash

# Compile Translation Files (.po → .mo)
# Run before deployment to ensure .mo files are up to date

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

cd "$(dirname "$0")/.."

echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo -e "${BLUE}  Translation Compiler${NC}"
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo ""

# Check if msgfmt is installed
if ! command -v msgfmt &> /dev/null; then
    echo -e "${RED}❌ ERROR: msgfmt not found!${NC}"
    echo ""
    echo "Install gettext tools:"
    echo "  Ubuntu/Debian: sudo apt-get install gettext"
    echo "  macOS: brew install gettext"
    echo ""
    exit 1
fi

LANG_DIR="lang"
if [ ! -d "$LANG_DIR" ]; then
    echo -e "${YELLOW}⚠️  No lang directory found${NC}"
    echo "Nothing to compile."
    exit 0
fi

cd "$LANG_DIR"

PO_FILES=(
    intersoccer-product-variations-fr.po
    intersoccer-product-variations-fr_FR.po
    intersoccer-product-variations-de.po
    intersoccer-product-variations-de_DE.po
)

echo "Compiling ${#PO_FILES[@]} translation file(s)"
echo ""

COMPILED=0
ERRORS=0

for po_file in "${PO_FILES[@]}"; do
    if [ ! -f "$po_file" ]; then
        echo -e "${YELLOW}⚠️  Skipping missing $po_file${NC}"
        continue
    fi
    mo_file="${po_file%.po}.mo"

    echo -n "Compiling $po_file → $mo_file ... "

    if msgfmt -o "$mo_file" "$po_file" 2>/dev/null; then
        echo -e "${GREEN}✓${NC}"
        COMPILED=$((COMPILED + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        echo "  Error compiling $po_file"
        msgfmt -o "$mo_file" "$po_file"
        ERRORS=$((ERRORS + 1))
    fi
done

echo ""
echo "═══════════════════════════════════════"
echo ""

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✅ Successfully compiled $COMPILED translation file(s)${NC}"
    echo ""
    echo "Generated files:"
    ls -lh intersoccer-product-variations-*.mo 2>/dev/null | awk '{print "  " $9 " (" $5 ")"}'
    echo ""
    echo "These files will be included in the next deployment."
    echo ""
    exit 0
else
    echo -e "${RED}❌ $ERRORS error(s) occurred${NC}"
    echo ""
    echo "Fix the .po file syntax errors and try again."
    exit 1
fi
