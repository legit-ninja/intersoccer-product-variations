#!/bin/bash

# Run PHPUnit Tests
# Quick test runner for development

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

cd "$(dirname "$0")/.."

echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo -e "${BLUE}  PHPUnit Test Runner${NC}"
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo ""

# Check if vendor/bin/phpunit exists
if [ ! -f "vendor/bin/phpunit" ]; then
    echo -e "${RED}❌ PHPUnit not found!${NC}"
    echo ""
    echo "Install PHPUnit:"
    echo "  composer install"
    echo ""
    exit 1
fi

# Check if tests directory exists
if [ ! -d "tests" ]; then
    echo -e "${RED}❌ Tests directory not found!${NC}"
    exit 1
fi

# Run specific test if provided, otherwise run all
if [ ! -z "$1" ]; then
    echo -e "${BLUE}Running specific test: $1${NC}"
    echo ""
    vendor/bin/phpunit "tests/$1"
    EXIT_CODE=$?
else
    echo -e "${BLUE}Running all tests...${NC}"
    echo ""
    vendor/bin/phpunit
    EXIT_CODE=$?
fi

echo ""

if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✅ All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Some tests failed${NC}"
    echo ""
    echo "Review errors above and fix before deploying."
    exit 1
fi

