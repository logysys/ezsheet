// Items 16-18: bead view, anchored + box, upload row.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
function boot(auth) {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/w/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.fetch = () => Promise.resolve({ ok: !!auth, json: () => Promise.resolve(auth ? { authenticated: true, user: { id: 1 } } : null) });
  w.eval(CORE);
  return { w, d: w.document, WC: w.WallCore };
}
test('bead slots collapse into +N beyond the mother height', () => {
  const { WC } = boot();
  assert.deepEqual({ ...WC.ezBeadSlots(3, 300, false) }, { show: 3, more: 0, cap: 11 });
  const s = WC.ezBeadSlots(20, 300, false); assert.equal(s.show + s.more, 20); assert.equal(s.show, 10);
  assert.equal(WC.ezBeadSlots(20, 300, true).show, 20);
});
test('popup flips left/up at viewport edges; + box clamped', () => {
  const { WC } = boot();
  assert.deepEqual({ ...WC.ezPopPos(100, 100, 200, 100, 1000, 800) }, { left: 116, top: 112 });
  const p = WC.ezPopPos(950, 780, 200, 100, 1000, 800); assert.ok(p.left < 950 && p.top < 780);
  const b = WC.ezAnchorBox(990, 790, 400, 300, 1000, 800);
  assert.ok(b.left >= 8 && b.left + 400 <= 992 && b.top + 300 <= 792);
});
test('beadsPaint: no rail for 0 children, newest first, pend/ok classes, +N', () => {
  const { w, d, WC } = boot();
  const wall = d.getElementById('wall');
  const mk = (cid, top) => { const el = d.createElement('div'); el.className = 'item mother'; el._o = { cid, _id: cid };
    Object.assign(el.style, { left: '0px', top: top + 'px', width: '300px', height: '120px' }); wall.appendChild(el); };
  mk('M1', 0); mk('M2', 200);
  const kids = []; for (let i = 0; i < 9; i++) kids.push({ _id: 'k' + i, role: 'child', mother_cid: 'M1', ok: i % 2, at: i, _raw: 'x' + i });
  w.__wallList = kids;
  WC.setBeadsFlag(true); WC.beadsPaint(wall);
  const rails = wall.querySelectorAll('.bead-rail'); assert.equal(rails.length, 1);
  const beads = rails[0].querySelectorAll('.bead');
  assert.equal(beads[0].dataset.bead, 'k8');
  assert.ok(beads[0].classList.contains('pend') && beads[1].classList.contains('ok'));
  assert.match(rails[0].querySelector('.bead-more').textContent, /^\+\d+$/);
  WC.setBeadsFlag(false); WC.beadsPaint(wall); assert.equal(wall.querySelector('.bead-layer'), null);
});
test('popup card escapes title and author, unsafe thumbs dropped', () => {
  const { WC } = boot();
  const h = WC.ezBeadCardHTML({ _raw: '<img src="javascript:x">', author: '<b>', ok: 0 });
  assert.ok(!h.includes('<b>') && !h.includes('javascript:') && h.includes('Pending'));
});
test('server sends author + view; client code wires them', () => {
  assert.match(CORE, /o\.author=c\.author/);
  assert.match(CORE, /data\.view === 'beads'/);
  assert.match(CORE, /'\/cards\/'\+encodeURIComponent\(opt\.mother\.cid\)\+'\/media'/);
  assert.match(CORE, /Sign in to upload/);
});
