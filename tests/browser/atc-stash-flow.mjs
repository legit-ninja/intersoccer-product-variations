#!/usr/bin/env node
/**
 * Offline Playwright harness: real inline script from elementor-widgets.php (PHP render).
 */
import { spawnSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { chromium } from '/home/jlee/projects/underdog/intersoccer/intersoccer-ui-tests/node_modules/playwright-core/index.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const TMP = path.join(__dirname, 'tmp');
const PRODUCT_ID = 9001;
const TIMEOUT_NOTICE = 'Booking data is still saving';

function renderInlineScript() {
  const uid = process.getuid();
  const gid = process.getgid();
  const r = spawnSync(
    'docker',
    [
      'run',
      '--rm',
      '--network',
      'none',
      '-u',
      `${uid}:${gid}`,
      '-v',
      `${ROOT}:/app`,
      '-w',
      '/app',
      'php:8.3-cli',
      'php',
      'tests/browser/render-elementor-inline-script.php',
    ],
    { encoding: 'utf8', maxBuffer: 80 * 1024 * 1024 }
  );
  if (r.status !== 0) {
    throw new Error(`render failed: ${r.stderr || r.stdout}`);
  }
  return r.stdout;
}

function ensureJquery() {
  fs.mkdirSync(TMP, { recursive: true });
  const dest = path.join(TMP, 'jquery.min.js');
  if (fs.existsSync(dest) && fs.statSync(dest).size > 10000) {
    return dest;
  }
  const sources = [
    '/home/jlee/projects/underdog/intersoccer/_vendor/rr-phpunit-external/phpunit/php-code-coverage/src/Report/Html/Renderer/Template/js/jquery.min.js',
  ];
  for (const src of sources) {
    if (fs.existsSync(src)) {
      fs.copyFileSync(src, dest);
      return dest;
    }
  }
  throw new Error('jquery.min.js not found locally');
}

function buildHtml(inlineScript) {
  return `<!DOCTYPE html>
<html><head><base href="http://pv-test.local/"></head><body>
<form id="pv-form" class="variations_form cart" method="post" action="http://pv-test.local/product/camp-test/">
  <input type="hidden" name="variation_id" value="501">
  <input type="hidden" name="add-to-cart" value="${PRODUCT_ID}">
  <input type="hidden" name="attribute_pa_booking-type" value="full-week">
  <div data-intersoccer-product-id="${PRODUCT_ID}">
    <select name="player_assignment" class="intersoccer-player-select player-select">
      <option value="">Select</option>
      <option value="0" data-player-id="uuid-test-player">Test Kid</option>
      <option value="1" data-player-id="uuid-second">Second</option>
    </select>
  </div>
  <button type="submit" class="single_add_to_cart_button">Add to cart</button>
</form>
<script src="http://pv-test.local/tmp/jquery.min.js"></script>
<script>${inlineScript}</script>
</body></html>`;
}

async function openHarnessPage(browser, inlineScript, jqueryPath) {
  const context = await browser.newContext();
  // Count add submissions at the source: native submit() calls plus submit events whose default
  // action was not prevented (a navigation can cancel an earlier POST before page.route sees it).
  await context.addInitScript(() => {
    window.__pvNativeSubmits = 0;
    window.__pvDefaultSubmits = 0;
    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
      window.__pvNativeSubmits += 1;
      return nativeSubmit.call(this);
    };
    window.addEventListener('submit', (ev) => {
      setTimeout(() => {
        if (!ev.defaultPrevented) window.__pvDefaultSubmits += 1;
      }, 0);
    }, true);
  });
  const page = await context.newPage();
  const stashPosts = [];
  const addPosts = [];
  let stashHold = null;

  await page.route('**/*', async (route) => {
    const req = route.request();
    const url = req.url();
    if (url.includes('/tmp/jquery.min.js')) {
      return route.fulfill({ status: 200, contentType: 'application/javascript', path: jqueryPath });
    }
    const post = req.postData() || '';
    if (post.includes('action=intersoccer_store_selected_player')) {
      stashPosts.push(post);
      if (stashHold) {
        await stashHold(route);
        return;
      }
      return route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
    }
    if (url.includes('admin-ajax.php')) {
      return route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
    }
    if (req.method() === 'POST' && url.includes('/product/camp-test')) {
      addPosts.push(post);
      // 204 keeps the current document, so the submit counters below survive the POST.
      return route.fulfill({ status: 204, body: '' });
    }
    return route.fulfill({ status: 404, body: 'not found' });
  });

  await page.setContent(buildHtml(inlineScript), { waitUntil: 'load' });
  await page.waitForFunction(() => typeof window.jQuery !== 'undefined' && window.jQuery('form.cart').length > 0);

  return {
    page,
    context,
    stashPosts,
    addPosts,
    setStashHold(fn) {
      stashHold = fn;
    },
    clearStashHold() {
      stashHold = null;
    },
  };
}

async function enableAddButton(page) {
  await page.evaluate(() => {
    const btn = document.querySelector('button.single_add_to_cart_button');
    if (btn) {
      btn.disabled = false;
      btn.removeAttribute('disabled');
      btn.classList.remove('disabled', 'wc-variation-selection-needed');
    }
  });
}

async function addAttempts(page) {
  try {
    return await page.evaluate(() => (window.__pvNativeSubmits || 0) + (window.__pvDefaultSubmits || 0));
  } catch (e) {
    return -1; // page navigated away; counters lost
  }
}

function parseAddBody(post) {
  const out = {};
  for (const part of post.split('&')) {
    const [k, v] = part.split('=');
    if (k) out[decodeURIComponent(k)] = decodeURIComponent((v || '').replace(/\+/g, ' '));
  }
  return out;
}

async function main() {
  const inlineScript = renderInlineScript();
  const jqueryPath = ensureJquery();
  const chromiumPath = '/home/jlee/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
  const browser = await chromium.launch({
    headless: true,
    executablePath: fs.existsSync(chromiumPath) ? chromiumPath : undefined,
  });
  const failures = [];

  try {
    // (a) Player already stashed — second add: 1 add, 0 new stash, fast
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(50);
      const stashAfterChange = h.stashPosts.length;
      const t0 = Date.now();
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(80);
      const elapsed = Date.now() - t0;
      const body = h.addPosts.length ? parseAddBody(h.addPosts[0]) : {};
      if (h.addPosts.length !== 1) failures.push(`(a) expected 1 add POST, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(a) expected 1 add submission attempt, got ${n}`); }
      if (h.stashPosts.length !== stashAfterChange) failures.push(`(a) expected no extra stash on click, stash ${h.stashPosts.length} vs ${stashAfterChange}`);
      if (elapsed > 1000) failures.push(`(a) add took ${elapsed}ms`);
      if (body.assigned_attendee !== '0') failures.push(`(a) assigned_attendee missing`);
      if (body.assigned_player_id !== 'uuid-test-player') failures.push(`(a) assigned_player_id missing`);
      await h.context.close();
    }

    // (b) No stash ever — click add with player selected via DOM only
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      await h.page.evaluate(() => {
        const sel = document.querySelector('select[name="player_assignment"]');
        sel.value = '0';
      });
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(80);
      const body = h.addPosts.length ? parseAddBody(h.addPosts[0]) : {};
      if (h.stashPosts.length !== 0) failures.push(`(b) expected 0 stash, got ${h.stashPosts.length}`);
      if (h.addPosts.length !== 1) failures.push(`(b) expected 1 add, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(b) expected 1 add submission attempt, got ${n}`); }
      if (body.assigned_attendee !== '0') failures.push(`(b) assigned_attendee missing on add`);
      await h.context.close();
    }

    // (c) Stash pending before click — held until release, then 1 add
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      let releaseStash;
      h.setStashHold(
        (route) =>
          new Promise((resolve) => {
            releaseStash = () => {
              route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
              resolve();
            };
          })
      );
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(120);
      if (h.addPosts.length !== 0) failures.push(`(c) expected 0 add while stash held, got ${h.addPosts.length}`);
      h.clearStashHold();
      if (releaseStash) releaseStash();
      await h.page.waitForTimeout(400);
      if (h.addPosts.length !== 1) failures.push(`(c) expected 1 add after stash, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(c) expected 1 add submission attempt, got ${n}`); }
      await h.context.close();
    }

    // (d) Stuck stash — clock 60s, notice, 0 adds
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      await h.page.clock.install({ time: new Date('2020-01-01T00:00:00Z') });
      h.setStashHold((route) => new Promise(() => {}));
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.clock.fastForward(60000);
      await h.page.waitForTimeout(100);
      const notice = await h.page.locator('.intersoccer-pv-stash-wait-notice').textContent().catch(() => '');
      if (!notice || !notice.includes(TIMEOUT_NOTICE)) failures.push(`(d) timeout notice missing: ${notice}`);
      if (h.addPosts.length !== 0) failures.push(`(d) expected 0 add on timeout, got ${h.addPosts.length}`);
      const guardCleared = await h.page.evaluate(() => {
        const map = window.intersoccerPvStashSubmitWaitInProgress || {};
        return !Object.values(map).some((v) => v === true);
      });
      if (!guardCleared) failures.push('(d) stash submit wait guard not cleared after timeout');
      await h.context.close();
    }

    // (e) Enter submit after click snapshot + new pending stash
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      let releaseStash;
      h.setStashHold(
        (route) =>
          new Promise((resolve) => {
            releaseStash = () => {
              route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
              resolve();
            };
          })
      );
      await h.page.evaluate(() => {
        if (typeof intersoccerPvSnapshotStashPendingBaseline === 'function') {
          intersoccerPvSnapshotStashPendingBaseline();
        }
      });
      await h.page.selectOption('select[name="player_assignment"]', '1');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.evaluate(() => {
        const form = document.getElementById('pv-form');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      });
      await h.page.waitForTimeout(120);
      if (h.addPosts.length !== 0) failures.push(`(e) expected 0 add POSTs while waiting on Enter, got ${h.addPosts.length}`);
      h.clearStashHold();
      if (releaseStash) releaseStash();
      await h.page.waitForTimeout(400);
      if (h.addPosts.length !== 1) failures.push(`(e) expected 1 add after stash, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(e) expected 1 add submission attempt, got ${n}`); }
      await h.context.close();
    }

    // (f) Save started after the click snapshot (during click dispatch) must not block the submit
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      h.setStashHold((route) => new Promise(() => {}));
      await h.page.evaluate(() => {
        const sel = document.querySelector('select[name="player_assignment"]');
        sel.value = '0';
        // Body listener added after the widget script runs after its delegated click handler.
        document.body.addEventListener('click', () => {
          sel.value = '1';
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        }, { once: true });
      });
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(150);
      if (h.stashPosts.length !== 1) failures.push(`(f) expected 1 click-time stash, got ${h.stashPosts.length}`);
      if (h.addPosts.length !== 1) failures.push(`(f) expected 1 add without waiting on click-time stash, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(f) expected 1 add submission attempt, got ${n}`); }
      await h.context.close();
    }

    // (g) Double-click during stash wait — second click blocked; later add still works
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      let releaseStash;
      h.setStashHold(
        (route) =>
          new Promise((resolve) => {
            releaseStash = () => {
              route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
              resolve();
            };
          })
      );
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(50);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(80);
      if (h.addPosts.length !== 0) failures.push(`(g) expected 0 add POSTs while stash held, got ${h.addPosts.length}`);
      h.clearStashHold();
      if (releaseStash) releaseStash();
      await h.page.waitForTimeout(400);
      if (h.addPosts.length !== 1) failures.push(`(g) expected 1 add after stash, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(g) expected 1 add submission attempt after first add, got ${n}`); }
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(200);
      if (h.addPosts.length !== 2) failures.push(`(g) expected 2 add POSTs after second legitimate click, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 2) failures.push(`(g) expected 2 add submission attempts total, got ${n}`); }
      await h.context.close();
    }

    // (h) Enter/submit during stash wait — second submit blocked; one add after release
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      let releaseStash;
      h.setStashHold(
        (route) =>
          new Promise((resolve) => {
            releaseStash = () => {
              route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
              resolve();
            };
          })
      );
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(50);
      await h.page.evaluate(() => {
        const form = document.getElementById('pv-form');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      });
      await h.page.waitForTimeout(80);
      if (h.addPosts.length !== 0) failures.push(`(h) expected 0 add POSTs while waiting on duplicate submit, got ${h.addPosts.length}`);
      h.clearStashHold();
      if (releaseStash) releaseStash();
      await h.page.waitForTimeout(400);
      if (h.addPosts.length !== 1) failures.push(`(h) expected 1 add after stash, got ${h.addPosts.length}`);
      { const n = await addAttempts(h.page); if (n !== 1) failures.push(`(h) expected 1 add submission attempt, got ${n}`); }
      await h.context.close();
    }

    // (i) Single click waits on pending save — button disabled during wait; one add after release
    {
      const h = await openHarnessPage(browser, inlineScript, jqueryPath);
      let releaseStash;
      h.setStashHold(
        (route) =>
          new Promise((resolve) => {
            releaseStash = () => {
              route.fulfill({ status: 200, contentType: 'text/plain', body: '0' });
              resolve();
            };
          })
      );
      await h.page.selectOption('select[name="player_assignment"]', '0');
      await h.page.evaluate(() => {
        document.querySelector('select[name="player_assignment"]').dispatchEvent(new Event('change', { bubbles: true }));
      });
      await h.page.waitForTimeout(30);
      await enableAddButton(h.page);
      await h.page.click('button.single_add_to_cart_button', { force: true });
      await h.page.waitForTimeout(120);
      const btnDuring = await h.page.evaluate(() => {
        const btn = document.querySelector('button.single_add_to_cart_button');
        return btn ? { disabled: btn.disabled, hasDisabledClass: btn.classList.contains('disabled') } : null;
      });
      if (!btnDuring || btnDuring.disabled !== true) {
        failures.push(
          `(i) expected add button prop disabled true during wait, got ${JSON.stringify(btnDuring)} add POSTs=${h.addPosts.length}`
        );
      }
      if (h.addPosts.length !== 0) {
        failures.push(`(i) expected 0 add POSTs during wait, got ${h.addPosts.length}`);
      }
      h.clearStashHold();
      if (releaseStash) releaseStash();
      await h.page.waitForTimeout(400);
      if (h.addPosts.length !== 1) {
        failures.push(`(i) expected 1 add POST after stash release, got ${h.addPosts.length}`);
      }
      {
        const n = await addAttempts(h.page);
        if (n !== 1) failures.push(`(i) expected 1 add submission attempt, got ${n} add POSTs=${h.addPosts.length}`);
      }
      const btnAfter = await h.page.evaluate(() => {
        const btn = document.querySelector('button.single_add_to_cart_button');
        return btn ? { disabled: btn.disabled, hasDisabledClass: btn.classList.contains('disabled') } : null;
      });
      if (!btnAfter || btnAfter.disabled !== false || btnAfter.hasDisabledClass) {
        failures.push(
          `(i) expected add button enabled after wait add (prop disabled false, no .disabled class), got ${JSON.stringify(btnAfter)} add POSTs=${h.addPosts.length}`
        );
      }
      await h.context.close();
    }
  } finally {
    await browser.close();
  }

  if (failures.length) {
    console.error('FAILURES:\n' + failures.join('\n'));
    process.exit(1);
  }
  console.log('OK: all browser ATC stash flow assertions passed (9 scenarios).');
  process.exit(0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
