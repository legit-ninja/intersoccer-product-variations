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

# Check if lang directory exists
if [ ! -d "lang" ]; then
    echo -e "${YELLOW}⚠️  No lang directory found${NC}"
    echo "Nothing to compile."
    exit 0
fi

cd lang

# Count .po files
PO_COUNT=$(ls -1 *.po 2>/dev/null | wc -l)
if [ $PO_COUNT -eq 0 ]; then
    echo -e "${YELLOW}⚠️  No .po files found${NC}"
    echo "Nothing to compile."
    exit 0
fi

echo "Found $PO_COUNT translation file(s)"
echo ""

COMPILED=0
ERRORS=0

# Compile each .po file (and locale-specific .mo copies WordPress loads)
for po_file in *.po; do
    mo_file="${po_file%.po}.mo"

    echo -n "Compiling $po_file → $mo_file ... "

    if msgfmt -o "$mo_file" "$po_file" 2>/dev/null; then
        echo -e "${GREEN}✓${NC}"
        COMPILED=$((COMPILED + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        echo "  Error compiling $po_file"
        msgfmt -o "$mo_file" "$po_file"  # Show error
        ERRORS=$((ERRORS + 1))
        continue
    fi

    case "$po_file" in
        *-de.po)
            locale_mo="${po_file%.po}_DE.mo"
            echo -n "Compiling $po_file → $(basename "$locale_mo") ... "
            if msgfmt -o "$locale_mo" "$po_file" 2>/dev/null; then
                echo -e "${GREEN}✓${NC}"
                COMPILED=$((COMPILED + 1))
            else
                echo -e "${RED}✗ FAILED${NC}"
                msgfmt -o "$locale_mo" "$po_file"
                ERRORS=$((ERRORS + 1))
            fi
            ;;
        *-fr.po)
            locale_mo="${po_file%.po}_FR.mo"
            echo -n "Compiling $po_file → $(basename "$locale_mo") ... "
            if msgfmt -o "$locale_mo" "$po_file" 2>/dev/null; then
                echo -e "${GREEN}✓${NC}"
                COMPILED=$((COMPILED + 1))
            else
                echo -e "${RED}✗ FAILED${NC}"
                msgfmt -o "$locale_mo" "$po_file"
                ERRORS=$((ERRORS + 1))
            fi
            ;;
    esac
done

echo ""
echo "═══════════════════════════════════════"
echo ""

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✅ Successfully compiled $COMPILED translation file(s)${NC}"
    echo ""
    echo "Generated files:"
    ls -lh *.mo 2>/dev/null | awk '{print "  " $9 " (" $5 ")"}'
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
