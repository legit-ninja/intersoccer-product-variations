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

## Stripe r5 matrix (Emily task 3) -- stripe-r5-run.js
Same args as browser-run.js. Env switches: `LINK=phone` (keep Stripe Link opt-in ticked, type phone, fill Link full name),
`LINKPHONE=791234567` (type a local number instead of +41...), `NONAME=1` (clear the Link full-name field),
`HCAP=1` (allow Stripe's hCaptcha host), `NOBILL=1` (clear billing fields), `OPEN=1` (allow all third parties; not used
on the sandbox). Logs every wc-ajax/admin-ajax and Stripe API response (status + redacted body) and failed requests.
`worktree-guard.sh <tag>` checks the mounted PV worktree is still babd4ca072f4 and clean before each run.

Deterministic failing cases (points on OR off, identical): `LINK=phone NONAME=1` -> "Please provide your full name."
(staging r4); `LINK=phone LINKPHONE=791234567` -> Link phone country defaults to US -> "Incomplete phone number. Check
that the correct country is selected." (staging r3). Both stop inside Stripe Elements: checkout_place_order_stripe
returns false, no payment_methods call, no wc-ajax=checkout. Points + Link off, points + Link+phone+name, points +
hCaptcha allowed, and control all place the order (points orders CHF 505 with fee -20, charge captured).
