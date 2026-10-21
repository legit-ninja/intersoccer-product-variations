/**
 * Repro: classic (non-express) checkout Place Order after applying CRS referral points.
 *
 * Drives the REAL front-end chain in jsdom:
 *   WordPress jQuery + blockUI + WooCommerce frontend/checkout.js (wc_checkout_form.submit)
 *   + CRS assets/js/checkout.js (points toggle / Apply / update_points_session / update_checkout)
 *   + PV js/campaign-checkout.js (the only PV script enqueued on is_checkout())
 * with two stand-ins for third-party code that cannot run in jsdom:
 *   - Stripe UPE classic handler on checkout_place_order_stripe, modelled on
 *     woocommerce-gateway-stripe 10.7.2 build/upe-classic.js processPayment(): returns false,
 *     createPaymentMethod (stub), appends wc-stripe-payment-method, re-triggers submit once.
 *   - phone-number-validation 1.4 checkout_place_order handler (returns undefined).
 * Network is a stubbed $.ajax that records every request.
 *
 * Pass condition per case: Place Order click reaches form.checkout submit, the Stripe step
 * runs createPaymentMethod exactly once, and exactly one wc-ajax=checkout POST is sent
 * (with intersoccer_points_to_redeem=N when points are applied).
 *
 * Env: VENDOR_JS (dir with jquery.js, jquery.blockUI.js, wc-checkout.js), CRS_CHECKOUT_JS,
 *      PV_CAMPAIGN_JS (defaults to this checkout's js/campaign-checkout.js), PV_LABEL.
 * Run: tests/js/run-points-place-order.sh  (throwaway node container, repo mounted read-only)
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const VENDOR = process.env.VENDOR_JS || '/vendor-js';
const CRS_JS = process.env.CRS_CHECKOUT_JS || '/crs/assets/js/checkout.js';
const PV_JS = process.env.PV_CAMPAIGN_JS || path.join(__dirname, '..', '..', 'js', 'campaign-checkout.js');
const PV_LABEL = process.env.PV_LABEL || 'worktree';

function paymentHtml(pointsApplied) {
  const applied = pointsApplied > 0;
  return `<div id="payment" class="woocommerce-checkout-payment">
  <div class="intersoccer-points-redemption-wrapper"><div class="intersoccer-points-redemption">
    <p class="form-row form-row-wide intersoccer-points-redemption-toggle"><label class="checkbox">
      <input class="input-checkbox" type="checkbox" name="intersoccer_use_points" id="intersoccer_use_points" value="on"${applied ? ' checked' : ''}> <span>Use Loyalty Points</span></label></p>
    <div class="points-details ${applied ? 'points-details--visible' : 'points-details--hidden'}">
      <p class="points-available" data-field="points_balance">You have <strong>20</strong> points available</p>
      <div class="custom-amount"><div class="custom-amount-input-group">
        <input type="number" name="intersoccer_points_to_redeem" id="intersoccer_points_to_redeem" data-field="points_to_redeem" data-credit-value="1" min="0" max="20" step="1" placeholder="0"${applied ? ` value="${pointsApplied}"` : ''}>
        <button type="button" class="button apply-all-points" data-max-points="20">Apply</button></div></div>
      <div class="applied-amount ${applied ? 'applied-amount--visible' : 'applied-amount--hidden'}" id="intersoccer-points-applied"><span class="applied-text">${applied ? pointsApplied + ' pts = CHF ' + pointsApplied + '.00' : ''}</span></div>
    </div></div></div>
  <ul class="wc_payment_methods payment_methods methods">
    <li class="wc_payment_method payment_method_stripe"><input id="payment_method_stripe" type="radio" class="input-radio" name="payment_method" value="stripe" checked="checked"><label for="payment_method_stripe">Card</label>
      <div class="payment_box payment_method_stripe"><div class="wc-stripe-upe-element"></div></div></li>
  </ul>
  <div class="form-row place-order">
    <input type="hidden" id="woocommerce-process-checkout-nonce" name="woocommerce-process-checkout-nonce" value="n">
    <button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="Place order">Place order</button>
  </div></div>`;
}

function pageHtml() {
  return `<!doctype html><html><body class="woocommerce-checkout woocommerce-page">
<form name="checkout" method="post" class="checkout woocommerce-checkout" action="/checkout/">
  <div id="customer_details">
    <p class="form-row validate-required"><input class="input-text" name="billing_first_name" id="billing_first_name" value="Repro"></p>
    <p class="form-row validate-required validate-email"><input class="input-text" type="email" name="billing_email" id="billing_email" value="repro@example.test"></p>
    <p class="form-row"><input class="input-text" type="tel" name="billing_phone" id="billing_phone" value="+41790000000"></p>
    <select name="billing_country" id="billing_country" class="country_to_state"><option value="CH" selected>CH</option></select>
  </div>
  <div id="order_review" class="woocommerce-checkout-review-order">
    <table class="shop_table woocommerce-checkout-review-order-table"><tbody><tr class="cart_item"><td>Camp (1 child)</td><td>525.00</td></tr></tbody></table>
    ${paymentHtml(0)}
  </div>
</form></body></html>`;
}

async function settle(win, ms = 60) {
  for (let i = 0; i < 6; i++) { await new Promise((r) => win.setTimeout(r, ms)); }
}

async function runCase({ points, inject }) {
  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!/navigation|Not implemented/i.test(String(e && e.message))) errors.push(String(e && e.message)); });
  const dom = new JSDOM(pageHtml(), { url: 'http://sandbox.invalid/checkout/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc });
  const win = dom.window;
  win.onerror = (m) => { errors.push(String(m)); };
  const load = (file) => { try { win.eval(fs.readFileSync(file, 'utf8')); } catch (e) { errors.push(path.basename(file) + ': ' + e.message); } };

  load(path.join(VENDOR, 'jquery.js'));
  load(path.join(VENDOR, 'jquery.blockUI.js'));
  const $ = win.jQuery;

  // --- network stub: record every request; answer like the server would
  const requests = [];
  let sessionPoints = 0;
  $.ajax = function (opts) {
    const url = String(opts.url || '');
    const data = typeof opts.data === 'string' ? opts.data : $.param(opts.data || {});
    const params = new win.URLSearchParams(data);
    const rec = { url, action: params.get('action'), data };
    requests.push(rec);
    let resp;
    if (rec.action === 'update_points_session') { sessionPoints = parseInt(params.get('points_to_redeem'), 10) || 0; resp = { success: true, data: {} }; }
    else if (rec.action) { resp = { success: true, data: {} }; }
    else if (/wc-ajax=update_order_review/.test(url)) { resp = { result: 'success', reload: false, messages: '', fragments: { '.woocommerce-checkout-payment': paymentHtml(sessionPoints) } }; }
    else if (/wc-ajax=checkout/.test(url)) { resp = { result: 'success', redirect: '#order-received' }; }
    else { resp = {}; }
    const dfd = $.Deferred();
    win.setTimeout(() => { if (opts.success) opts.success(resp, 'success', {}); dfd.resolve(resp, 'success', {}); if (opts.complete) opts.complete({}, 'success'); }, 5);
    const p = dfd.promise(); p.abort = () => {}; return p;
  };

  win.wc_checkout_params = { ajax_url: '/wp-admin/admin-ajax.php', wc_ajax_url: '/?wc-ajax=%%endpoint%%', checkout_url: '/?wc-ajax=checkout',
    update_order_review_nonce: 'n', apply_coupon_nonce: 'n', remove_coupon_nonce: 'n', is_checkout: '1', debug_mode: false, option_guest_checkout: 'no',
    i18n_checkout_error: 'Error processing checkout. Please try again.' };
  win.intersoccer_checkout = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', available_points: 20,
    i18n: { applying: 'Applying...', applied: 'Applied', apply_code: 'Apply', please_enter_referral_code: 'x', error_applying_referral_code: 'x', discount_label: 'Discount' } };
  win.intersoccerCampaignCheckout = { groupCodes: [], ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n' };

  load(path.join(VENDOR, 'wc-checkout.js'));
  load(CRS_JS);
  load(PV_JS);

  // phone-number-validation 1.4 stand-in: binds checkout_place_order, returns undefined
  $('form.checkout').on('checkout_place_order', function () { /* wcPvValidateProcess(); no return */ });
  // Stripe UPE classic stand-in (shape of processPayment in 10.7.2)
  const stripe = { createPaymentMethod: 0 };
  let hasCheckoutCompleted = false;
  $('form.checkout').on('checkout_place_order_stripe', function () {
    const $form = $(this);
    if (hasCheckoutCompleted) { hasCheckoutCompleted = false; return; }
    $form.block({ message: null });
    Promise.resolve().then(() => {
      stripe.createPaymentMethod++;
      $form.find('#wc-stripe-payment-method').remove();
      $form.append('<input type="hidden" id="wc-stripe-payment-method" name="wc-stripe-payment-method" value="pm_card_test" />');
      hasCheckoutCompleted = true;
      $form.removeClass('processing').trigger('submit');
    });
    return false;
  });

  await settle(win); // init_checkout -> first update_order_review
  if (inject) inject($, win);

  if (points > 0) {
    $('#intersoccer_use_points').prop('checked', true).trigger('change');
    $('#intersoccer_points_to_redeem').val(String(points));
    $('.intersoccer-points-redemption .apply-all-points').trigger('click');
    await settle(win); // update_points_session -> update_checkout -> update_order_review -> updated_checkout
  }

  const before = requests.length;
  const submitSeen = [];
  win.document.addEventListener('submit', (e) => submitSeen.push(e.target.className), true);
  win.document.querySelector('#place_order').click();
  await settle(win);

  const after = requests.slice(before);
  const checkoutPosts = after.filter((r) => /wc-ajax=checkout(&|$)/.test(r.url));
  return { errors, submitSeen, stripe, after, checkoutPosts, form: win.document.querySelector('form.checkout').className,
    notices: [...win.document.querySelectorAll('.woocommerce-error, .woocommerce-NoticeGroup')].map((n) => n.textContent.trim()) };
}

for (const points of [0, 20]) {
  test(`[PV ${PV_LABEL}] classic Place Order with points=${points} sends exactly one wc-ajax=checkout`, async () => {
    const r = await runCase({ points });
    const summary = JSON.stringify({ submitSeen: r.submitSeen.length, createPaymentMethod: r.stripe.createPaymentMethod,
      checkoutPosts: r.checkoutPosts.length, after: r.after.map((x) => x.action || x.url), errors: r.errors, notices: r.notices, form: r.form });
    console.log(`[PV ${PV_LABEL}] points=${points} ${summary}`);
    assert.deepStrictEqual(r.errors, [], 'no JS exception while loading/driving the checkout scripts');
    assert.ok(r.submitSeen.length >= 1, 'Place Order click reached form.checkout submit');
    assert.strictEqual(r.stripe.createPaymentMethod, 1, 'Stripe step (createPaymentMethod) ran exactly once');
    assert.strictEqual(r.checkoutPosts.length, 1, 'exactly one wc-ajax=checkout POST was sent');
    const body = new URLSearchParams(r.checkoutPosts[0].data);
    assert.strictEqual(body.get('wc-stripe-payment-method'), 'pm_card_test');
    if (points > 0) {
      assert.strictEqual(body.get('intersoccer_use_points'), 'on');
      assert.strictEqual(body.get('intersoccer_points_to_redeem'), String(points));
    }
  });
}

// Symptom models: the two front-end ways WooCommerce's own submit() goes silent (no request, no notice).
// These assert the silence, so they document what "Place Order does nothing" looks like at this layer.
test(`[PV ${PV_LABEL}] SYMPTOM: a checkout_place_order handler returning false => no request, no notice`, async () => {
  const r = await runCase({ points: 20, inject: ($) => $('form.checkout').on('checkout_place_order', () => false) });
  assert.ok(r.submitSeen.length >= 1);
  assert.strictEqual(r.stripe.createPaymentMethod, 0);
  assert.strictEqual(r.checkoutPosts.length, 0);
  assert.deepStrictEqual(r.notices, []);
});
test(`[PV ${PV_LABEL}] SYMPTOM: form.checkout stuck with .processing => no request, no notice`, async () => {
  const r = await runCase({ points: 20, inject: ($) => $('form.checkout').addClass('processing') });
  assert.ok(r.submitSeen.length >= 1);
  assert.strictEqual(r.stripe.createPaymentMethod, 0);
  assert.strictEqual(r.checkoutPosts.length, 0);
  assert.deepStrictEqual(r.notices, []);
});
