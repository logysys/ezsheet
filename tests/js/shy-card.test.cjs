// Item 12: non-embeddable cards show the "Shy page" deep-field card (U3) instead of the R.I.P. tombstone.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');
const CORE = read('resources/js/components/wall-core.js');

function boot() {
  const dom = new JSDOM('<!DOCTYPE html><body class="ro"><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/w/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.ios = [];
  w.IntersectionObserver = class { constructor(cb) { this.cb = cb; this.t = new Set(); w.ios.push(this); }
    observe(e) { this.t.add(e); } unobserve(e) { this.t.delete(e); } disconnect() { this.t.clear(); } };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.eval(CORE);
  return { w, d: w.document, WC: w.WallCore };
}
const IG = 'https://www.instagram.com/p/C0ffee123/';
const card = (WC, o) => WC.makeCard(Object.assign({ _id: 'a1', type: 'web', url: IG, emb: 'no', w: 360, h: 250, role: 'child', guest: 1, ok: 1, zone: 'b' }, o), true);

test('item12: shy card is one new-tab link with the domain, favicon and copy', () => {
  const { WC, d } = boot();
  const el = card(WC);
  assert.ok(el.classList.contains('tomb'));
  const a = el.querySelector('a.shy');
  assert.ok(a, 'whole card is an <a>');
  assert.equal(a.getAttribute('href'), IG);
  assert.equal(a.getAttribute('target'), '_blank');
  assert.equal(a.getAttribute('rel'), 'noopener noreferrer');
  assert.equal(a.querySelector('.shy-pill').textContent, 'instagram.com');
  assert.match(a.querySelector('.shy-cap').textContent, /instagram\.com/);
  assert.equal(a.querySelector('.shy-fav img').getAttribute('src'), 'https://www.instagram.com/favicon.ico');
  assert.equal(a.querySelector('.shy-fav').getAttribute('data-m'), 'I');
  assert.match(a.querySelector('.shy-hd').textContent, /Shy page!/);
  assert.equal(a.querySelector('.shy-sub').textContent, 'only comes out in its own tab');
  assert.equal(a.querySelector('.shy-btn').textContent, 'Say hi \u2197');
  assert.doesNotMatch(el.innerHTML, /Non-Embeddable|R\.I\.P|tomb-/);
  assert.equal(a.querySelectorAll('svg,filter').length, 0, 'no per-card SVG or filters');
  assert.ok(el.querySelector('.corner') && el.querySelector('.card-tools') && el.querySelector('.resize-anchor'), 'ribbon, tools and grip kept');
});

test('item12: favicon falls back to a monogram; hostile URLs are escaped', () => {
  const { WC, w } = boot();
  const el = card(WC);
  const img = el.querySelector('.shy-fav img');
  img.dispatchEvent(new w.Event('error'));
  assert.equal(el.querySelector('.shy-fav img'), null, 'broken favicon removed, letter shows');
  const bad = WC.tombHTML({ url: 'javascript:alert(1)' });
  assert.match(bad, /href="#"/);
  const q = WC.tombHTML({ url: 'https://x.test/"><img src=x onerror=1>' });
  assert.doesNotMatch(q, /<img src=x/);
});

test('item12: animation pauses off-screen via one shared observer; renderAll unobserves', () => {
  const { WC, d, w } = boot();
  const wall = d.getElementById('wall');
  WC.renderAll(wall, [{ _id: 'a', type: 'web', url: IG, emb: 'no', w: 360, h: 250 }, { _id: 'b', type: 'web', url: 'https://x.com/a', emb: 'no', w: 300, h: 250 }], true);
  const shy = [...wall.querySelectorAll('.shy')];
  const io = w.ios.find(o => o.t.size);
  assert.equal(io.t.size, 2);
  assert.equal(w.ios.filter(o => o.t.size && [...o.t].some(e => e.classList.contains('shy'))).length, 1, 'one shared observer');
  io.cb([{ target: shy[0], isIntersecting: false }]);
  assert.ok(shy[0].classList.contains('off'));
  io.cb([{ target: shy[0], isIntersecting: true }]);
  assert.ok(!shy[0].classList.contains('off'));
  WC.renderAll(wall, [], true);
  assert.equal(io.t.size, 0, 'old cards released');
});

test('item12: one shared, light, responsive, reduce-motion-aware stylesheet', () => {
  const { WC, d } = boot();
  const css = WC.EZ_SHY_CSS;
  assert.equal(d.querySelectorAll('#ez-card-rules').length, 1);
  assert.ok(d.getElementById('ez-card-rules').textContent.includes('.shy{'));
  assert.ok(css.length < 16000, 'size cap ' + css.length);
  assert.doesNotMatch(css, /feTurbulence|filter=|url\(#/);
  assert.match(css, /container-type:size/);
  assert.match(css, /@container/);
  assert.match(css, /prefers-reduced-motion:reduce\)\{[^}]*\.shy-star[^}]*animation:none/);
  assert.match(css, /\.shy\.off[^{]*\{animation-play-state:paused\}/);
  assert.match(css, /\.shy\{position:absolute;inset:0;z-index:0/, 'below ribbon (z5), grips (z7/8) and tools (z9)');
  const keyframeProps = [...css.matchAll(/@keyframes \w+\{(.*?)\}\}/g)].map(m => m[1]).join();
  assert.doesNotMatch(keyframeProps, /width|height|top|left|margin|padding/, 'compositor-only motion');
});

test('item12: tombstone markup, styles and its i18n keys are gone', () => {
  const { WC } = boot();
  for (const L of Object.keys(WC.I18N)) {
    assert.ok(!('tombNote' in WC.I18N[L]) && !('openTab' in WC.I18N[L]), L);
    assert.ok('tryEmbed' in WC.I18N[L] && 'markTomb' in WC.I18N[L], 'toggle keys kept');
  }
  for (const f of ['resources/js/components/wall-core.js', 'resources/js/components/EzSheetPreview.tsx', 'resources/views/a22.blade.php'])
    assert.doesNotMatch(read(f), /TOMB_SVG|tomb-(body|img|label|host|note|link)/, f);
});
