// Item 21: the wall sign-in popover uses the magic link (email -> "Check your email" + 6-digit code, cross-tab polling).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
test('no 4-digit OTP left in the wall client', () => {
  assert.doesNotMatch(CORE, /send-otp|verify-otp|4-Digit|debug_otp/);
  assert.match(CORE, /\/api\/wall\/auth\/magic\/send/);
  assert.match(CORE, /\/api\/wall\/auth\/magic\/verify/);
  assert.match(CORE, /maxlength="6"/);
  assert.match(CORE, /autocomplete="one-time-code"/);
});
test('waiting tab polls auth/me every 4 s for up to 15 min and continues on sign-in', () => {
  assert.match(CORE, /deadline=Date\.now\(\)\+15\*60\*1000;timer=setInterval\(poll,4000\)/);
  assert.match(CORE, /fetch\('\/api\/wall\/auth\/me'[^)]*\)\s*\.then\(r=>r\.ok\?r\.json\(\):null\)\.then\(d=>\{if\(d&&d\.authenticated&&d\.user&&d\.user\.id\)finish\(d\.user\)/);
  assert.match(CORE, /visibilitychange/);
});
test('return_to is the wall page path; test_link only rendered when the server sends it', () => {
  assert.match(CORE, /return_to:returnTo\(\)/);
  assert.match(CORE, /if\(j\.test_link\)\{tl\.hidden=false;tl\.innerHTML='Test account: <a target="_top" href="'\+esc\(j\.test_link\)/);
});
test('reopen hint is shared across tabs (localStorage), single-use, per wall', () => {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall"></div></body>', { runScripts: 'outside-only', url: 'https://ez.test/W1' });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.eval(CORE);
  w.WallCore.ezReopenHint('W1', 'm1', 'hello');
  assert.ok(w.localStorage.getItem('ez_reopen'));
  assert.equal(w.WallCore.ezTakeReopen('W1').cid, 'm1');
  assert.equal(w.localStorage.getItem('ez_reopen'), null);
});
