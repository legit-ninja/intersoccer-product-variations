#!/bin/bash
# Run the jsdom repro in a throwaway node container. Repo, CRS and vendor JS are mounted read-only;
# node_modules (jsdom) live OUTSIDE the repo. No npm install in the repo, no lockfile changes.
# usage: tests/js/run-points-place-order.sh <vendor-js-dir> <crs-checkout-dir> <node_modules-dir> [<pv-campaign-checkout.js> <label>]...
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"; VJ="$1"; CRS="$2"; NM="$3"; shift 3
run() { docker run --rm -u "$(id -u):$(id -g)" -v "$REPO":/repo:ro -v "$VJ":/vendor-js:ro -v "$CRS":/crs:ro -v "$NM":/nm:ro \
  ${4:+-v "$4":/pv-campaign.js:ro} -e NODE_PATH=/nm/node_modules -e VENDOR_JS=/vendor-js -e CRS_CHECKOUT_JS=/crs/assets/js/checkout.js \
  ${4:+-e PV_CAMPAIGN_JS=/pv-campaign.js} -e PV_LABEL="${5:-worktree}" -w /repo node:22-alpine node --test --test-reporter=spec tests/js/points-place-order.test.js; }
if [ $# -eq 0 ]; then run "$VJ" "$CRS" "$NM"; else while [ $# -ge 2 ]; do run "$VJ" "$CRS" "$NM" "$1" "$2"; shift 2; done; fi
