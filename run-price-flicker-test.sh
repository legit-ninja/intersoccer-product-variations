#!/bin/bash
###############################################################################
# Run Price Flicker Cypress Test
###############################################################################
#
# This script runs the Cypress E2E test for the price flicker bug from this repo
#
# Usage:
#   ./run-price-flicker-test.sh          # Run headless
#   ./run-price-flicker-test.sh --open   # Run interactive
#
###############################################################################

set -e

# Color output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Path to intersoccer-ui-tests repository
CYPRESS_REPO="../intersoccer-ui-tests"

# Check if Cypress repo exists
if [ ! -d "$CYPRESS_REPO" ]; then
    echo -e "${YELLOW}⚠ Cypress tests repository not found at: $CYPRESS_REPO${NC}"
    echo ""
    echo "Expected location: /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-ui-tests"
    exit 1
fi

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}  Price Flicker Regression Test${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo "Test file: camp-price-flicker-regression.spec.js"
echo "Test repo: $CYPRESS_REPO"
echo "Target: https://intersoccer.legit.ninja"
echo ""

# Change to Cypress repo
cd "$CYPRESS_REPO"

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo -e "${YELLOW}⚠ Installing Cypress dependencies...${NC}"
    npm install
fi

# Parse arguments
if [ "$1" = "--open" ]; then
    echo "Opening Cypress Test Runner..."
    echo ""
    npx cypress open
else
    echo "Running Cypress test headlessly..."
    echo ""
    CYPRESS_BASE_URL="https://intersoccer.legit.ninja" npx cypress run \
        --spec "cypress/e2e/camp-price-flicker-regression.spec.js" \
        --browser chrome
    
    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo -e "${GREEN}  ✓ All Price Flicker Tests PASSED${NC}"
        echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo ""
        echo "Results:"
        echo "  ✅ No price flickering detected"
        echo "  ✅ No price compounding detected"
        echo "  ✅ Console messages correct"
        echo "  ✅ Base price preservation working"
        echo "  ✅ Late pickup calculations accurate"
        echo ""
        echo "The price flicker bug fix is working correctly! 🎉"
        echo ""
    else
        echo ""
        echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo -e "${YELLOW}  ⚠ Some Tests FAILED${NC}"
        echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo ""
        echo "Check the output above for details."
        echo ""
        echo "Common issues:"
        echo "  1. Old cached page still loading - clear browser cache"
        echo "  2. Server cache not cleared - run: ./deploy.sh --clear-cache"
        echo "  3. AJAX timing issues - test may need longer waits"
        echo ""
        echo "Test artifacts:"
        echo "  Videos: cypress/videos/"
        echo "  Screenshots: cypress/screenshots/"
        echo ""
    fi
fi

# Return to original directory
cd - > /dev/null

