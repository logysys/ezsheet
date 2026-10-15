// Items 19 (owner pin) and 20 (sign-in popover next to the cursor).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
function boot({ owner = false, phone = false } = {}) {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/w/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = q => ({ matches: phone && /max-width|coarse/.test(q), addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.isOwner = owner;
  w.eval(CORE);
  return { w, d: w.document, WC: w.WallCore };
}
test('pin icon: owner only; mothers in Content and approved children only', () => {
  const o = boot({ owner: true }).WC, g = boot().WC;
  const ctx = { isOwner: true };
  assert.equal(o.ezCanPin({ role: 'mother', zone: 'b' }, ctx), true);
  assert.equal(o.ezCanPin({ role: 'mother', zone: 'a' }, ctx), false);
  assert.equal(o.ezCanPin({ role: 'mother', zone: 'c' }, ctx), false);
  assert.equal(o.ezCanPin({ role: 'child', zone: 'b', status: 'approved' }, ctx), true);
  assert.equal(o.ezCanPin({ role: 'child', zone: 'b', status: 'pending' }, ctx), false);
  assert.equal(o.ezCanPin({ role: 'mother', zone: 'b' }, { isOwner: false }), false);
  assert.match(o.toolsHTML({ role: 'mother', zone: 'b', cid: 'm1' }), /data-act="pin"[^>]*aria-pressed="false"/);
  assert.match(o.toolsHTML({ role: 'mother', zone: 'b', cid: 'm1', pinned: 5 }), /aria-pressed="true"[^>]*title="Unpin"/);
  assert.doesNotMatch(g.toolsHTML({ role: 'mother', zone: 'b', cid: 'm1' }), /data-act="pin"/);
});
test('shuffle keeps pinned families first and moves only unpinned families', () => {
  const { WC } = boot();
  const L = [
    { _id: 'A', cid: 'A', role: 'mother', zone: 'a' },
    { _id: 'P', cid: 'P', role: 'mother', zone: 'b', pinned: 9 }, { _id: 'p1', role: 'child', mother_cid: 'P', zone: 'b' },
    { _id: 'Q', cid: 'Q', role: 'mother', zone: 'b', pinned: 3 },
    { _id: 'x', cid: 'x', role: 'mother', zone: 'b' }, { _id: 'x1', role: 'child', mother_cid: 'x', zone: 'b' },
    { _id: 'y', cid: 'y', role: 'mother', zone: 'b' }, { _id: 'z', cid: 'z', role: 'mother', zone: 'b' },
    { _id: 'C', cid: 'C', role: 'mother', zone: 'c' }];
  for (let s = 0; s < 20; s++) {
    let k = s; const r = WC.ezShuffleFamilies(L, () => ((k = (k * 9301 + 49297) % 233280) / 233280)).map(o => o._id);
    assert.deepEqual(r.slice(0, 4), ['A', 'P', 'p1', 'Q']);
    assert.equal(r[r.length - 1], 'C');
    assert.equal(r[r.indexOf('x') + 1], 'x1');
  }
});
test('server pinned flag; pinned child is the top bead with a mark and "Pinned" in the preview', () => {
  const { WC } = boot();
  assert.equal(WC.ezCardFromServer({ cid: 'abc123', content: '<md># hi</md>', role: 'child', anchor: 'm', status: 'approved', pinned: 77 }).pinned, 77);
  const k = WC.ezBeadKids([{ role: 'child', mother_cid: 'm', _id: 'new', at: 9 }, { role: 'child', mother_cid: 'm', _id: 'pin', at: 1, pinned: 5, ok: 1 }]).get('m');
  assert.equal(k.map(o => o._id).join(), 'pin,new');
  assert.match(WC.ezBeadCardHTML({ _raw: 'x', ok: 1, pinned: 5 }), /📌 Pinned/);
  assert.doesNotMatch(WC.ezBeadCardHTML({ _raw: 'x', ok: 1 }), /Pinned/);
});
test('sign-in popover: anchored next to the cursor on desktop, bottom sheet on phones', () => {
  for (const phone of [false, true]) {
    const { d, WC, w } = boot({ phone });
    const box = d.createElement('div'); box.className = 'ins-modal'; box.innerHTML = '<div class="ins-card wall-otp-card"></div>';
    d.body.appendChild(box);
    WC.ezPlaceBox(box, { x: 5000, y: 5000 });
    assert.equal(box.classList.contains(phone ? 'sheet' : 'anchored'), true);
    if (!phone) { const c = box.querySelector('.ins-card'); assert.ok(parseInt(c.style.left) <= w.innerWidth && parseInt(c.style.top) <= w.innerHeight); }
  }
});
test('redirect fallback: reopen hint for this wall only, used once', () => {
  const { WC } = boot();
  WC.ezReopenHint('W1', 'm9', 'draft text');
  assert.equal(WC.ezTakeReopen('W2'), null);
  assert.deepEqual({ ...WC.ezTakeReopen('W1'), t: 0 }, { w: 'W1', cid: 'm9', draft: 'draft text', t: 0 });
  assert.equal(WC.ezTakeReopen('W1'), null);
});
test('wiring: + box sign-in link, 401 -> popover then reopen, pin endpoint, homepage prompt', () => {
  assert.match(CORE, /opt\.onSignIn\(at,draft\)/);
  assert.match(CORE, /r\.status===401\)\{signThenReopen/);
  assert.match(CORE, /'\/pin'/);
  assert.match(CORE, /WallCore\.signIn = signInAt/);
  const P = fs.readFileSync(path.join(ROOT, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
  assert.match(P, /WallCore\.signIn\(\{ x: e\.clientX, y: e\.clientY \}/);
  assert.doesNotMatch(CORE, /console\.log\([^)]*(otp|password|code)/i);
});
