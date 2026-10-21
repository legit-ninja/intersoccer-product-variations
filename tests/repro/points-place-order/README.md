# Repro: classic checkout "Place Order does nothing" after applying referral points

Local sandbox only. Stripe TEST mode, card 4242 only. Synthetic @example.test users.

## Browser run (headless Chromium via Playwright from intersoccer-ui-tests/node_modules)
1. Snapshot the sandbox DB, then `wp eval-file sandbox-setup.php` (2 users, 1 synthetic child each, +20 CRS points each;
   writes `pw-<tag>.txt` to `/o`).
2. Control (points off): `node browser-run.js <local-base-url> repro-ppo-ctl pw-ctl.txt 0 ctl`
3. Points on:            `node browser-run.js <local-base-url> repro-ppo-pts pw-pts.txt 20 pts`
4. Deterministic failing case (symptom): add `RAW=1` to either run. RAW skips (a) scrolling Place Order into view and
   letting the page settle and (b) unticking Stripe Link opt-in. Result 3/3 on PV master: no pointerdown/click/submit event
   reaches the top document, no checkout_place_order handler runs, no wc-ajax=checkout request, no notice -- with points
   AND without points. Without RAW the same flow places the order (points on and off).
5. Restore the snapshot.

The harness aborts every non-local, non-Stripe request, any request carrying a live Stripe key, wp-cron, and any
request to a .php file under wp-content/plugins/.

## jsdom run
`tests/js/run-points-place-order.sh <vendor-js-dir> <crs-dir> <node_modules-dir> [<campaign-checkout.js> <label>]...`
