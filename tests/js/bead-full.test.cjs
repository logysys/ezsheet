// Item 23: full card anchored at the clicked bead, clamped, 95% backdrop.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs'), path = require('path');
const { JSDOM } = require('jsdom');
const CORE = fs.readFileSync(path.join(__dirname, '../../resources/js/components/wall-core.js'), 'utf8');
function boot() {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/w/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.fetch = () => Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
  w.eval(CORE);
  return { w, WC: w.WallCore };
}
const V = { l: 0, t: 0, r: 1200, b: 800 };
const R = (x, y) => ({ left: x, right: x + 14, top: y, bottom: y + 14 });
const inside = (p, w, v) => p.left >= v.l + 12 && p.left + w <= v.r - 12 && p.top >= v.t + 12 && p.top + p.h <= v.b - 12;
test('right of the bead when it fits', () => {
  const { WC } = boot(); const p = WC.ezFullPos(R(100, 200), 400, 300, V, 12);
  assert.equal(p.side, 'right'); assert.equal(p.left, 128); assert.ok(inside(p, 400, V));
});
test('flips left at the right edge, clamped near the bottom edge', () => {
  const { WC } = boot(); const p = WC.ezFullPos(R(1150, 760), 400, 300, V, 12);
  assert.equal(p.side, 'left'); assert.ok(p.left + 400 <= 1150); assert.ok(inside(p, 400, V));
});
test('narrow view: goes above/below, still inside', () => {
  const { WC } = boot(); const v = { l: 0, t: 0, r: 500, b: 800 };
  const p = WC.ezFullPos(R(240, 700), 460, 300, v, 12);
  assert.equal(p.side, 'above'); assert.ok(inside(p, 460, v));
});
test('taller than the view: height capped to view minus margins', () => {
  const { WC } = boot(); const p = WC.ezFullPos(R(100, 100), 400, 2000, V, 12);
  assert.equal(p.h, 776); assert.equal(p.top, 12);
});
test('visible view follows a parent-clipped iframe', () => {
  const { WC } = boot();
  const fake = { innerWidth: 1000, innerHeight: 5000,
    frameElement: { getBoundingClientRect: () => ({ left: 50, top: -2000 }) },
    parent: { innerWidth: 1280, innerHeight: 900 } };
  assert.deepEqual({ ...WC.ezVisibleView(fake) }, { l: 0, t: 2000, r: 1000, b: 2900 });
});
test('rev 2: no backdrop element, wall not dimmed; outside pointerdown closes', () => {
  assert.doesNotMatch(CORE, /bead-(full|sheet)-bg/);
  assert.doesNotMatch(CORE, /\.bead[^{]*\{[^}]*inset:0[^}]*\}/);
  assert.doesNotMatch(CORE, /\.bead[^{]*\{[^}]*(backdrop-filter|rgba\(15,23,42,\.(5|95)\))/);
  assert.match(CORE, /\.bead-full\{position:fixed;[^}]*opacity:1;[^}]*drop-shadow\([^}]*overflow:auto/);
  assert.match(CORE, /document\.addEventListener\('pointerdown',out,true\)/);
  assert.match(CORE, /if\(beadCur\)beadCur\.close\(\)/);
});

// Item 24: one shared helper for every popover.
function bootP(phone) {
  const r = boot(); const w = r.w;
  w.matchMedia = q => ({ matches: !!phone && /max-width|coarse/.test(q), addEventListener() {}, removeEventListener() {} });
  return r;
}
test('ezAnchorPopover: next to the anchor, inside the view; outside pointerdown and Esc call onClose', () => {
  const { w, WC } = bootP(false); const d = w.document;
  const a = d.createElement('button'); d.body.appendChild(a); a.getBoundingClientRect = () => ({ left: 100, right: 120, top: 50, bottom: 70 });
  const el = d.createElement('div'); d.body.appendChild(el);
  el.getBoundingClientRect = () => ({ left: 0, top: 0, width: 300, height: 200, right: 300, bottom: 200 });
  let closed = 0; const h = WC.ezAnchorPopover(el, { anchor: a, onClose: () => closed++ });
  assert.equal(el.style.position, 'fixed'); assert.equal(el.dataset.side, 'right'); assert.equal(el.style.left, '134px');
  return new Promise(res => setTimeout(() => {
    a.dispatchEvent(new w.Event('pointerdown', { bubbles: true })); assert.equal(closed, 0);      // anchor itself: toggles in its own handler
    el.dispatchEvent(new w.Event('pointerdown', { bubbles: true })); assert.equal(closed, 0);
    d.body.dispatchEvent(new w.Event('pointerdown', { bubbles: true })); assert.equal(closed, 1);
    const el2 = d.createElement('div'); d.body.appendChild(el2); let c2 = 0;
    WC.ezAnchorPopover(el2, { mode: 'center', onClose: () => c2++ });
    d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape' })); assert.equal(c2, 1); res();
  }, 5));
});
test('ezAnchorPopover: bottom sheet on phones at the bottom of the VISIBLE area', () => {
  const { w, WC } = bootP(true); const d = w.document;
  const el = d.createElement('div'); d.body.appendChild(el);
  WC.ezAnchorPopover(el, { anchor: { x: 10, y: 10 } });
  assert.ok(el.classList.contains('ez-sheet')); assert.equal(el.style.left, '0px'); assert.equal(el.style.top, 'auto');
});
test('review box, + box, sign-in, view menu, bead card use the shared helper; anchored popovers have no backdrop', () => {
  assert.match(CORE, /ezPlaceBox\(box,at\|\|null,reviewClose,'below'\)/);
  assert.match(CORE, /reviewOpen\(wallId,[^;]*,rb\)/);
  assert.match(CORE, /ezPlaceBox\(box,opt\.at,insClose\)/);
  assert.match(CORE, /ezPlaceBox\(modal,opt\.at,/);
  assert.match(CORE, /ezAnchorPopover\(vmEl,/);
  assert.match(CORE, /\.ins-modal\.pop\{display:block;background:transparent!important;[^}]*pointer-events:none/);
  assert.doesNotMatch(CORE, /\.ins-modal\.anchored\{[^}]*rgba/);
  const PREV = fs.readFileSync(path.join(__dirname, '../../resources/js/components/EzSheetPreview.tsx'), 'utf8');
  assert.match(PREV, /ezAnchorPopover\(p,\{mode:'top'/);          // editor panel
  assert.match(PREV, /ezAnchorPopover\(box\.querySelector\('\.box'\),\{mode:'center'/);  // push dialog
  assert.match(PREV, /ezVisibleView\(window\); el\.style\.bottom/); // toast
});
test('toolbar popovers prefer BELOW the button and shrink to the room left', () => {
  const { WC } = boot(); const p = WC.ezFullPos({ left: 900, right: 940, top: 20, bottom: 50 }, 600, 2000, { l: 0, t: 0, r: 1360, b: 900 }, 12, 'below');
  assert.equal(p.side, 'below'); assert.equal(p.top, 64); assert.equal(p.h, 900 - 12 - 64); assert.ok(p.left + 600 <= 1348);
});
