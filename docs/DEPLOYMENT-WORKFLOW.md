# Deployment Workflow
## InterSoccer Product Variations Plugin

**Updated:** November 5, 2025

---

## 🚀 Quick Reference

### Standard Deployment (PHPUnit tests always run)
```bash
./deploy.sh
```
**What happens:**
1. ✅ Runs PHPUnit tests (mandatory)
2. ✅ Blocks deployment if tests fail
3. ✅ Deploys code to server
4. ✅ Shows success message

### Full Deployment with E2E Tests
```bash
./deploy.sh --test
```
**What happens:**
1. ✅ Runs PHPUnit tests (mandatory)
2. ✅ Blocks deployment if tests fail
3. ✅ Deploys code to server
4. ✅ Clears server caches automatically
5. ✅ Waits 3 seconds for stabilization
6. ✅ Runs Cypress E2E tests from `../intersoccer-ui-tests`
7. ⚠️ Warns if Cypress tests fail (code already deployed)

### With Cache Clearing (No E2E tests)
```bash
./deploy.sh --clear-cache
```
**What happens:**
1. ✅ Runs PHPUnit tests (mandatory)
2. ✅ Blocks deployment if tests fail
3. ✅ Deploys code to server
4. ✅ Clears server caches

### Dry Run (Preview changes)
```bash
./deploy.sh --dry-run
```
**What happens:**
1. ⏭️ Skips PHPUnit tests (dry run only)
2. 📋 Shows what files would be uploaded
3. ❌ Does NOT upload anything
4. ❌ Does NOT run any tests

---

## 📊 Test Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    ./deploy.sh                              │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ 1. PHPUnit Tests (ALWAYS - Cannot be skipped)       │  │
│  │    Location: tests/                                   │  │
│  │    • RegressionTest.php                              │  │
│  │    • PriceFlickerRegressionTest.php                  │  │
│  │    • CoursePriceCalculationTest.php                  │  │
│  │    • CartDisplayTest.php                             │  │
│  │    • OrderMetadataTest.php                           │  │
│  │    • EmojiTranslationTest.php                        │  │
│  │                                                       │  │
│  │    If FAIL → ❌ DEPLOYMENT BLOCKED                   │  │
│  │    If PASS → ✅ Continue to deployment               │  │
│  └──────────────────────────────────────────────────────┘  │
│                          ↓                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ 2. Deploy Code to Server                            │  │
│  │    • Compile translations (.mo files)                │  │
│  │    • Upload PHP files                                │  │
│  │    • Upload JS/CSS files                             │  │
│  │    • Upload language files                           │  │
│  │    • Exclude: vendor, tests, docs, node_modules     │  │
│  └──────────────────────────────────────────────────────┘  │
│                          ↓                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ 3. Clear Server Caches (if --test or --clear-cache) │  │
│  │    • PHP Opcache                                     │  │
│  │    • WooCommerce transients                          │  │
│  │    • WordPress object cache                          │  │
│  └──────────────────────────────────────────────────────┘  │
│                          ↓                                  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │ 4. Cypress E2E Tests (ONLY if --test flag)          │  │
│  │    Location: ../intersoccer-ui-tests/                │  │
│  │    • Wait 3 seconds for server to stabilize          │  │
│  │    • Run Cypress tests against deployed site         │  │
│  │    • Target: https://intersoccer.legit.ninja         │  │
│  │                                                       │  │
│  │    If FAIL → ⚠️  WARNING (code already deployed)     │  │
│  │    If PASS → ✅ Full deployment success              │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔍 Test Types

### PHPUnit Tests (Backend/Logic)
**Location:** `tests/` directory in this plugin

**What they test:**
- ✅ Price calculation logic
- ✅ Course prorating
- ✅ Cart metadata display
- ✅ Order metadata persistence
- ✅ Regression prevention (past bugs)
- ✅ WPML UTF8 compliance
- ✅ Input sanitization
- ✅ XSS prevention

**When they run:**
- **ALWAYS** before deployment (cannot be skipped)
- Run locally in repository
- Fast (< 30 seconds)
- No server required

**Why mandatory:**
- Prevent broken code from being deployed
- Catch calculation errors before production
- Protect recent fixes (price flicker regression)
- Ensure data integrity

### Cypress E2E Tests (Frontend/Integration)
**Location:** `../intersoccer-ui-tests/` repository

**What they test:**
- ✅ User workflows (booking camps, courses)
- ✅ Product page functionality
- ✅ Cart operations
- ✅ Checkout process
- ✅ Browser compatibility
- ✅ Visual regressions
- ✅ Form validation

**When they run:**
- **ONLY** when `--test` flag is passed
- After deployment and cache clearing
- Run against live server
- Slower (2-5 minutes)

**Why optional:**
- Test against deployed code
- Require live server
- Can catch integration issues
- Non-blocking (warn if fail)

---

## 🛡️ Safety Mechanisms

### PHPUnit Tests Block Deployment
```bash
$ ./deploy.sh

Running PHPUnit Tests...
F.......

FAILURES!
Tests: 9, Assertions: 45, Failures: 1.

✗ PHPUnit tests failed. Deployment BLOCKED.

Fix the failing tests before deploying:
  ./vendor/bin/phpunit --testdox
```

### Cypress Tests Warn But Don't Block
```bash
$ ./deploy.sh --test

✓ PHPUnit Tests Passed
✓ Code Deployed Successfully
✓ Server Caches Cleared

Running Cypress E2E Tests...

Running:  camp-booking.cy.js                                     (1 of 3)
  ✓ Should load camp product page (2 seconds)
  1) Should calculate correct price for single day

⚠ WARNING: Cypress tests failed but code is already deployed.
You may need to fix issues and redeploy.
```

**Why this approach?**
- PHPUnit catches logic errors **before** deployment
- Cypress catches integration issues **after** deployment
- Cypress failures might be temporary (server load, timing)
- You can investigate and redeploy if needed

---

## 🎯 Use Cases

### Daily Development
```bash
# Make code changes
# Run tests locally first (optional but recommended)
./vendor/bin/phpunit

# Deploy when ready
./deploy.sh
```

### Before Major Release
```bash
# Deploy and run full E2E test suite
./deploy.sh --test

# If Cypress tests fail, investigate and fix
# Redeploy with fixes
./deploy.sh
```

### Urgent Hotfix
```bash
# Make fix
# PHPUnit tests will automatically run and block if broken
./deploy.sh --clear-cache
```

### Checking Changes Before Deploy
```bash
# See what would be uploaded
./deploy.sh --dry-run

# Review output, then deploy for real
./deploy.sh
```

---

## 📋 Pre-Deployment Checklist

### Before Running `./deploy.sh`

1. **✅ Code Changes Complete**
   - All features implemented
   - Code reviewed
   - Comments added where needed

2. **✅ Local Testing Done**
   ```bash
   # Run PHPUnit tests locally first
   ./vendor/bin/phpunit --testdox
   
   # Check for linter errors
   # (if you have linting set up)
   ```

3. **✅ Translations Updated**
   - .pot file regenerated (if strings changed)
   - .po files updated
   - .mo files compiled (script does this automatically)

4. **✅ Documentation Updated**
   - README.md (if needed)
   - Changelog updated
   - Comments in code

5. **✅ Git Committed**
   ```bash
   git add .
   git commit -m "Descriptive commit message"
   git push
   ```

### After Running `./deploy.sh`

1. **✅ Verify Deployment**
   - Visit: https://intersoccer.legit.ninja/shop/
   - Hard refresh: Ctrl+Shift+R (or Cmd+Shift+R on Mac)
   - Test critical features

2. **✅ Check Browser Console**
   - F12 → Console tab
   - Look for JavaScript errors
   - Verify no warnings

3. **✅ Smoke Test**
   - Add product to cart
   - View cart
   - Test checkout (don't complete order)

4. **✅ If Using --test Flag**
   - Review Cypress test output
   - Investigate any failures
   - Redeploy if needed

---

## 🚨 Troubleshooting

### PHPUnit Tests Failing

**Problem:** Tests fail and block deployment

**Solution:**
```bash
# Run tests with detailed output
./vendor/bin/phpunit --testdox

# Run specific failing test
./vendor/bin/phpunit tests/PriceFlickerRegressionTest.php

# Check test documentation
cat docs/TEST-COVERAGE-ANALYSIS.md
```

### Cypress Tests Not Found

**Problem:** `../intersoccer-ui-tests` directory doesn't exist

**Solution:**
```bash
# Clone the repository
cd /home/jeremy-lee/projects/underdog/intersoccer/
git clone <intersoccer-ui-tests-repo-url> intersoccer-ui-tests

# Install dependencies
cd intersoccer-ui-tests
npm install
```

### Deployment Hangs

**Problem:** Script seems stuck

**Possible causes:**
- SSH connection timeout
- Server not responding
- Large file transfer

**Solution:**
```bash
# Cancel with Ctrl+C

# Check SSH connection
ssh -p 22 -i ~/.ssh/id_rsa user@intersoccer.legit.ninja

# Try dry-run first
./deploy.sh --dry-run
```

### Caches Not Clearing

**Problem:** Changes not visible on site

**Solution:**
```bash
# Force cache clear
./deploy.sh --clear-cache

# Or manually clear on server
ssh user@server "cd /path/to/plugin && php -r 'opcache_reset();'"
```

---

## 📚 Related Documentation

- **Test Coverage Analysis:** `docs/TEST-COVERAGE-ANALYSIS.md`
- **Price Flicker Fix:** `docs/PRICE-FLICKER-FIX.md`
- **Deployment Checklist:** `DEPLOY-PRICE-FIX.md`
- **Test Coverage Summary:** `TEST-COVERAGE-SUMMARY.md`

---

## 🔄 Deployment Flow Examples

### Example 1: Simple Deploy
```bash
$ ./deploy.sh

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  InterSoccer Product Variations Deployment
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Configuration:
  Server: user@intersoccer.legit.ninja
  Path: /var/www/html/wp-content/plugins/intersoccer-product-variations
  SSH Port: 22

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Running PHPUnit Tests
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

PHPUnit 10.5.48 by Sebastian Bergmann and contributors.

.........                                                  9 / 9 (100%)

Time: 00:00.156, Memory: 10.00 MB

OK (9 tests, 45 assertions)

✓ All PHPUnit tests passed

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Deploying to Server
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Target: user@intersoccer.legit.ninja:/var/www/html/...
Uploading files...

sent 145.23K bytes  received 1.54K bytes  98.51K bytes/sec
total size is 1.23M  speedup is 8.39

✓ Files uploaded successfully

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Deployment Complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✓ Plugin successfully deployed to intersoccer.legit.ninja

Next steps:
  1. Clear browser cache and hard refresh (Ctrl+Shift+R)
  2. Test the changes on: https://intersoccer.legit.ninja/shop/
  3. Check browser console for any errors

Tip: Run with --test flag to run Cypress E2E tests:
  ./deploy.sh --test
```

### Example 2: Deploy with Full Testing
```bash
$ ./deploy.sh --test

[... PHPUnit tests pass ...]
[... Deployment succeeds ...]

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Clearing Server Caches
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Executing cache clear script on server...
✓ PHP Opcache cleared
✓ WooCommerce transients cleared
✓ WordPress object cache cleared

Caches cleared successfully!

✓ Server caches cleared

Waiting 3 seconds for server to stabilize...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Running Cypress E2E Tests
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Running tests from: ../intersoccer-ui-tests
Target server: https://intersoccer.legit.ninja

Running:  camp-booking.cy.js                              (1 of 3)
  ✓ Should load camp product page (1234ms)
  ✓ Should calculate correct price for single day (2345ms)
  ✓ Should add late pickup correctly (1567ms)

  3 passing (5s)

✓ All Cypress E2E tests passed

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Deployment Complete
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✓ Plugin successfully deployed to intersoccer.legit.ninja
✓ All Cypress E2E tests passed

Next steps:
  1. Clear browser cache and hard refresh (Ctrl+Shift+R)
  2. Test the changes on: https://intersoccer.legit.ninja/shop/
  3. Check browser console for any errors
```

---

## 🎓 Best Practices

### 1. Always Run Local Tests First
```bash
# Before deploying
./vendor/bin/phpunit --testdox
```

### 2. Use --test Flag for Important Changes
```bash
# For price changes, new features, refactoring
./deploy.sh --test
```

### 3. Commit Before Deploying
```bash
git add .
git commit -m "Fix: Price flicker issue"
./deploy.sh
```

### 4. Review Dry Run Output
```bash
# Check what will be uploaded
./deploy.sh --dry-run | less
```

### 5. Monitor First Deploy of the Day
```bash
# First deployment, run full tests
./deploy.sh --test

# Subsequent deploys (if tests passed earlier)
./deploy.sh
```

---

## ⚡ Quick Commands

```bash
# Standard deploy
./deploy.sh

# Deploy with E2E tests
./deploy.sh --test

# Deploy and clear caches
./deploy.sh --clear-cache

# Preview changes
./deploy.sh --dry-run

# Run tests locally
./vendor/bin/phpunit
./vendor/bin/phpunit --testdox

# Get help
./deploy.sh --help
```

---

**Remember:** PHPUnit tests are your safety net. They run ALWAYS and prevent broken code from reaching production.

**Updated:** November 5, 2025

