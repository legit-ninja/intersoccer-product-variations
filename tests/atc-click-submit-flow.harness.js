#!/usr/bin/env node
'use strict';

/**
 * Offline behavioural checks for ATC stash wait + dedupe (mirrors elementor-widgets.php logic).
 */

function makePendingXHR() {
  const state = { s: 'pending' };
  return {
    state: () => state.s,
    always(fn) {
      this._always = fn;
      return this;
    },
    done(fn) {
      this._done = fn;
      return this;
    },
    fail(fn) {
      this._fail = fn;
      return this;
    },
    resolve() {
      state.s = 'resolved';
      if (this._always) this._always();
      if (this._done) this._done();
    },
  };
}

function installPvStashGlobals() {
  global.window = {
    intersoccerPvPendingStashAjax: [],
    intersoccerPvPendingStashDeferreds: [],
    intersoccerPvStashPendingBeforeAtc: [],
    intersoccerPvDeferredPendingBeforeAtc: [],
    intersoccerPvStashPendingBaselineCaptured: false,
  };
}

function snapshotBaseline() {
  window.intersoccerPvStashPendingBeforeAtc = (window.intersoccerPvPendingStashAjax || []).slice();
  window.intersoccerPvDeferredPendingBeforeAtc = (window.intersoccerPvPendingStashDeferreds || []).slice();
  window.intersoccerPvStashPendingBaselineCaptured = true;
}

function hasPendingForSubmitWait() {
  if (window.intersoccerPvStashPendingBaselineCaptured) {
    const bx = window.intersoccerPvStashPendingBeforeAtc || [];
    const bd = window.intersoccerPvDeferredPendingBeforeAtc || [];
    if (bx.some((xhr) => xhr && xhr.state && xhr.state() === 'pending')) {
      return true;
    }
    return bd.some((dfd) => dfd && dfd.state && dfd.state() === 'pending');
  }
  return (window.intersoccerPvPendingStashAjax || []).some((xhr) => xhr && xhr.state() === 'pending');
}

let lastSavedSig = '';
let inFlightSig = '';
let stashPostCount = 0;
let addPostCount = 0;

function playerStashSignature(idx, playerId) {
  return String(playerId || '') + '|' + String(idx || '');
}

function stashSelectedPlayer(idx, playerId, options) {
  options = options || {};
  const sig = playerStashSignature(idx, playerId);
  if (!options.force) {
    if (sig === lastSavedSig || sig === inFlightSig) {
      return null;
    }
  }
  inFlightSig = sig;
  stashPostCount += 1;
  const xhr = makePendingXHR();
  window.intersoccerPvPendingStashAjax.push(xhr);
  xhr.always(() => {
    const list = window.intersoccerPvPendingStashAjax;
    const i = list.indexOf(xhr);
    if (i >= 0) list.splice(i, 1);
    if (inFlightSig === sig) inFlightSig = '';
  });
  xhr.done(() => {
    lastSavedSig = sig;
  });
  return xhr;
}

function applyPlayerChoice(idx, playerId, options) {
  options = options || {};
  if (!options.skipStash) {
    stashSelectedPlayer(idx, playerId, options);
  }
}

function scheduleNativeAddPost() {
  addPostCount += 1;
}

function simulateAtcClickUnchangedPlayer() {
  snapshotBaseline();
  applyPlayerChoice('0', 'uuid-a', { skipStash: true });
  lastSavedSig = playerStashSignature('0', 'uuid-a');
  if (!hasPendingForSubmitWait()) {
    scheduleNativeAddPost();
  }
}

function simulateClickStartedStashShouldNotBlockSubmit() {
  installPvStashGlobals();
  stashPostCount = 0;
  addPostCount = 0;
  snapshotBaseline();
  const xhr = stashSelectedPlayer('1', 'uuid-b');
  const blocked = hasPendingForSubmitWait();
  if (!blocked) {
    scheduleNativeAddPost();
  }
  return { blocked, xhr, stashPostCount, addPostCount };
}

function simulatePreExistingStuckStashShowsWait() {
  installPvStashGlobals();
  const stuck = makePendingXHR();
  window.intersoccerPvPendingStashAjax.push(stuck);
  snapshotBaseline();
  return hasPendingForSubmitWait();
}

function main() {
  installPvStashGlobals();
  lastSavedSig = '';
  inFlightSig = '';
  stashPostCount = 0;
  addPostCount = 0;

  simulateAtcClickUnchangedPlayer();
  const t1 = {
    addPosts: addPostCount,
    stashPosts: stashPostCount,
    waited: hasPendingForSubmitWait(),
  };

  const t2 = simulateClickStartedStashShouldNotBlockSubmit();
  const t3 = simulatePreExistingStuckStashShowsWait();

  const ok =
    t1.addPosts === 1 &&
    t1.stashPosts === 0 &&
    t1.waited === false &&
    t2.blocked === false &&
    t2.addPostCount === 1 &&
    t2.stashPostCount === 1 &&
    t3 === true;

  console.log(JSON.stringify({ ok, t1, t2: { blocked: t2.blocked, addPostCount: t2.addPostCount, stashPostCount: t2.stashPostCount }, t3 }, null, 2));
  process.exit(ok ? 0 : 1);
}

main();
