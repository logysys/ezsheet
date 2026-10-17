// Item 9: a card whose content is taller than the card must scroll (drag, wheel, touch).
// Live bug on /X/Horoscope-每日占星: the card's own CSS had body{overflow:hidden}; with html at
// overflow:visible that propagates to the viewport, so the iframe could not scroll. The width grip
// (.side-anchor, 28px wide, grey pill) also sat over the iframe's scroll bar and swallowed drag/wheel.
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs'; import vm from 'node:vm';
const core = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
const shell = fs.readFileSync(new URL('../../resources/js/components/EzSheetPreview.tsx', import.meta.url), 'utf8');
const line = core.split('\n').find(l => l.startsWith('const REPORT='));
const js = line.slice(line.indexOf('<script>') + 8, line.indexOf('<\\/script>'));

function run(htmlOv, bodyOv) {
  const L = {}, msgs = [], set = {}; let scrolled = 0; const parent = {postMessage: m => msgs.push(m)};
  const html = {scrollHeight: 809, clientHeight: 421, style: {setProperty: (k, v, p) => { set[k] = v + '|' + p; }}};
  const body = {_o: bodyOv, getBoundingClientRect: () => ({bottom: 809}), scrollHeight: 809};
  html._o = htmlOv;
  const document = {documentElement: html, body, scrollingElement: {scrollBy: (x, y) => { scrolled += y; }}};
  const ctx = {document, parent, window: {}, msgs, setTimeout: () => 0, isFinite,
    getComputedStyle: el => ({overflowY: el === html ? (set['overflow-y'] ? 'auto' : el._o) : el._o, marginBottom: '0'}),
    addEventListener: (t, f) => { (L[t] ||= []).push(f); }};
  vm.runInNewContext(js, ctx);
  (L.load || []).forEach(f => f());
  return {set, msgs, L, parent, get scrolled() { return scrolled; }};
}

test('body{overflow:hidden} in card content no longer blocks scrolling', () => {
  assert.equal(run('visible', 'hidden').set['overflow-y'], 'auto|important');
  assert.equal(run('hidden', 'visible').set['overflow-y'], 'auto|important');
  assert.equal(run('visible', 'visible').set['overflow-y'], undefined, 'normal cards untouched');
  assert.equal(run('auto', 'hidden').set['overflow-y'], undefined, 'html already scrolls');
});
test('height reporting unchanged', () => {
  assert.ok(line.includes('d.scrollHeight>d.clientHeight+1?d.scrollHeight:0'));
  assert.deepEqual(run('visible', 'hidden').msgs.at(-1), {__wallH: 809, __wallImgs: 0}); // item 35: + loaded-image count
});
test('card scrolls on __wallScroll only from its parent', () => {
  const r = run('visible', 'hidden');
  r.L.message.forEach(f => f({source: {}, data: {__wallScroll: 100}}));
  assert.equal(r.scrolled, 0, 'foreign sender ignored');
  r.L.message.forEach(f => f({source: r.parent, data: {__wallScroll: 120}}));
  r.L.message.forEach(f => f({source: r.parent, data: {__wallScroll: 'x'}}));
  assert.equal(r.scrolled, 120);
});
test('wheel over the grips is forwarded to the card, not swallowed', () => {
  assert.match(core, /wall\.addEventListener\('wheel',e=>\{\s*const a=e\.target\.closest&&e\.target\.closest\('\.resize-anchor,\.side-anchor'\)/);
  assert.match(core, /contentWindow\.postMessage\(\{__wallScroll:/);
  assert.ok(!/addEventListener\('wheel'[^\n]*\n[^\n]*\n[^\n]*\n\s*e\.preventDefault\(\);(?![^\n]*__wallScroll)/.test(core));
});
test('width grip is moved off the scroll bar on clipped cards (carousel still hides it)', () => {
  assert.match(shell, /\.item\.clipped \.side-anchor\{right:16px;width:16px\}/);
  assert.match(shell, /\.masonry\.carousel \.resize-anchor,\.masonry\.carousel \.side-anchor\{display:none\}/);
});
