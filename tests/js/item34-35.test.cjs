/* EZ-REL-20261017-2: item 34 (co-wiki resize in the bead popover is applied to the real card and
   saved), item 35 (auto-height image cards grow after the image loads, in every layout;
   upload <img> carries width/height; upload URLs are https or relative). */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const rd = f => fs.readFileSync(path.join(ROOT, f), 'utf8');
const CORE = rd('resources/js/components/wall-core.js');
const TSX = rd('resources/js/components/EzSheetPreview.tsx');
const PANEL = rd('resources/js/components/EzSheetEditorPanel.tsx');
const UPL = rd('resources/js/components/ezUpload.ts');
const PF = require('./push-feedback-scope.cjs');   /* item 36 */

function stubs(w) {
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
}
function core() {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/W1', pretendToBeVisual: true });
  const w = dom.window; stubs(w);
  w.fetch = () => Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
  w.eval(CORE);
  return { w, WC: w.WallCore };
}

/* the real wall document, built from the EzSheetPreview template (same as the live srcdoc) */
function wallHtml() {
  const EZED = rd('resources/js/components/ezsheet-editor.js');
  const getEzSheetEditor = () => { const G = {}; vm.runInNewContext(EZED, { window: G, globalThis: G }); return G.EzSheetEditor; };
  const ca = TSX.indexOf('const WALL_CSS = `'), cb = TSX.indexOf('`;', ca);
  const WALL_CSS = vm.runInNewContext(TSX.slice(ca + 'const WALL_CSS = '.length, cb + 1), {});
  const a = TSX.indexOf('const doc = `'), b = TSX.indexOf('</html>`;', a);
  const tpl = TSX.slice(a + 'const doc = '.length, b + '</html>`'.length);
  const payload = { id: 'W1', slug: 'demo', funnel: 'demo', lang: 'en', cols: 0, car: false, zones: null, zorder: null, list: [], cards: null, editable: false, fx: { fly: false, snd: false } };
  return vm.runInNewContext(tpl, { title: 'T', EZSHEET_EDITOR_SCRIPT: EZED, getEzSheetEditor, WALL_CSS, ZDOG_SCRIPT: '', WALL_CORE_SCRIPT: CORE, editable: false, isOwner: false,
    json: JSON.stringify(payload), spriteInit: '', resolvedAuth: {}, parentOrigin: 'https://ez.test', resolvedDomains: [], wallId: 'W1', slug: 'demo', funnel: 'demo', messageId: 1,
    effectiveSyncUrl: 'https://ez.test/api/wall', frameCheckUrl: '', metaUrl: '', payload, JSON, autoHeight: false, frameless: true, openEditor: false,
    wallAutoHeightReporter: { toString: () => 'function wallAutoHeightReporter(){}' },
    EZ_PUSH_TEXTS: PF.EZ_PUSH_TEXTS, ezPushRefusalText: PF.ezPushRefusalText });
}
let HTML = null;
const CARDS = (() => {
  let at = 1792100000000; const C = [];
  C.push({ cid: 'mb1', role: 'mother', zone: 'b', content: '<md>\n# Content 1\nmother\n</md>', status: 'approved', at: at++ });
  for (let i = 1; i <= 3; i++) C.push({ cid: 'mb1k' + i, role: 'child', anchor: 'mb1', zone: 'b', content: 'https://example.com/mb1/cowiki-' + i, status: 'approved', at: at++ });
  return { success: true, wall_id: 'W1', slug: 'demo', rev: 1, count: C.length, order: C.map(c => c.cid), cards: C, layout: 'original', view: 'beads' };
})();
function bootWall(seed) {
  HTML = HTML || wallHtml();
  const dom = new JSDOM(HTML, { runScripts: 'dangerously', url: 'https://ez.test/w/W1', pretendToBeVisual: true, beforeParse(w) {
    stubs(w); w.console.error = () => {};
    if (seed) w.localStorage.setItem('wall_size_W1', JSON.stringify(seed));
    w.fetch = u => Promise.resolve(/\/cards/.test(u) ? { ok: true, json: () => Promise.resolve(JSON.parse(JSON.stringify(CARDS))) } : { ok: false, json: () => Promise.resolve(null) });
    Object.defineProperty(w.HTMLElement.prototype, 'offsetWidth', { get() { return parseInt(this.style.width) || 0; } });
    Object.defineProperty(w.HTMLElement.prototype, 'offsetHeight', { get() { return parseInt(this.style.height) || 0; } });
  } });
  const w = dom.window;
  return new Promise((res, rej) => {
    let n = 0; const t = setInterval(() => {
      const ok = w.__wallList && w.__wallList.some(o => o.cid === 'mb1k1') && w.document.querySelector('.bead');
      if (ok) { clearInterval(t); res(w); } else if (++n > 100) { clearInterval(t); rej(new Error('wall did not boot')); }
    }, 30);
  });
}
const pev = (w, t, x, y) => { const e = new w.MouseEvent(t, { bubbles: true, cancelable: true, clientX: x, clientY: y }); e.pointerId = 1; return e; };
function openBead(w, cid) {
  const o = w.__wallList.find(x => x.cid === cid);
  const b = w.document.querySelector('.bead[data-bead="' + o._id + '"]');
  assert.ok(b, 'bead for ' + cid);
  b.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  const pop = w.document.querySelector('.bead-full');
  assert.ok(pop, 'bead popover opened');
  return { o, pop };
}

test('34: dragging the yellow corner in the bead popover resizes, saves under sizes[rawHash] and survives reload', async () => {
  const w = await bootWall();
  const { o, pop } = openBead(w, 'mb1k2');
  const el = pop.querySelector('.item'); const a = el.querySelector('.resize-anchor');
  assert.ok(a, 'popover card has the corner grip');
  const w0 = parseInt(el.style.width);
  a.dispatchEvent(pev(w, 'pointerdown', 100, 100));
  a.dispatchEvent(pev(w, 'pointermove', 300, 100));
  a.dispatchEvent(pev(w, 'pointerup', 300, 100));
  assert.ok(parseInt(el.style.width) > w0, 'popover card got wider: ' + w0 + ' -> ' + el.style.width);
  assert.equal(o.w, parseInt(el.style.width), 'real card got the new width');
  assert.equal(o.h, parseInt(el.style.height), 'real card got the new height');
  const saved = JSON.parse(w.localStorage.getItem('wall_size_W1') || '{}');
  const k = w.WallCore.rawHash(o._raw);
  assert.deepEqual({ ...saved[k] }, { w: o.w, h: o.h, manual: !!o.manual }, 'saved under sizes[rawHash(o._raw)]');
  assert.ok(w.document.querySelector('.bead-full'), 'popover stays open after the drag');
  const wallEl = w.document.querySelector('#wall>.item[data-id="' + o._id + '"]');
  if (wallEl) assert.equal(wallEl.style.width, o.w + 'px', 'the wall copy (Cards view) is redrawn');
  /* reload with the saved sizes: the card and the popover open at that size */
  const w2 = await bootWall(saved);
  const r = openBead(w2, 'mb1k2');
  assert.equal(r.o.w, o.w); assert.equal(r.o.h, o.h);
  assert.equal(r.pop.querySelector('.item').style.width, o.w + 'px', 'popover opens at the saved width');
  assert.equal(r.pop.querySelector('.item').style.height, o.h + 'px', 'popover opens at the saved height');
});

test('34: the wall itself never had a listener for the popover (root cause stays fixed)', () => {
  assert.match(CORE, /m\.bg\.addEventListener\('pointerdown',e=>\{\s*const a=e\.target\.closest&&e\.target\.closest\('\.resize-anchor,\.side-anchor'\)/);
  assert.match(CORE, /resizeStart\(a,el,c,a\.classList\.contains\('side-anchor'\),e,\(\)=>\{\s*ezPopResized\(o,c,el\)/);
  assert.match(CORE, /function ezPopResized\(o,c,skip\)\{[\s\S]{0,200}sizes\[rawHash\(o\._raw\)\]=\{w:o\.w,h:o\.h,manual:!!o\.manual\};saveSizes\(\);/);
});

test('35: small report then large report grows the card in "original" layout (X0003629 image card)', () => {
  const { WC } = core();
  const o = { fit: { t: 'auto' }, w: 520, h: 624 }, s = {};
  assert.equal(WC.ezMeasureStep(o, 52, 0, s, -1, true), true);
  assert.equal(o.h, 57, 'first report before the image loads: 52 + border');
  assert.equal(WC.ezMeasureStep(o, 523, 1, s, -1, true), true);
  assert.equal(o.h, 528, 'image loaded: card grows to the full image');
  assert.equal(o.mh + 5 > o.h + 6, false, 'so foldSync shows no "Show all"');
  for (const mode of [0, 2, 3]) {
    const p = { fit: { t: 'auto' }, w: 520, h: 300 }, q = {};
    WC.ezMeasureStep(p, 52, 0, q, mode, true); WC.ezMeasureStep(p, 523, 1, q, mode, true);
    assert.equal(p.h, WC.fitH(p, 520, mode > 0 ? mode : 2), 'mode ' + mode);
  }
});

test('35: image growth does not count toward the 4-grow guard; other growth still does', () => {
  const { WC } = core();
  const a = { fit: { t: 'auto' }, w: 520, h: 100 }, sa = {};
  WC.ezMeasureStep(a, 50, 0, sa, -1, true);
  for (let i = 1; i <= 6; i++) assert.equal(WC.ezMeasureStep(a, 50 + i * 60, i, sa, -1, true), true, 'image ' + i);
  assert.equal(a.mh, 410); assert.equal(sa.grow || 0, 0);
  const b = { fit: { t: 'auto' }, w: 520, h: 100 }, sb = {};
  WC.ezMeasureStep(b, 50, 0, sb, -1, true);
  const r = []; for (let i = 1; i <= 6; i++) r.push(WC.ezMeasureStep(b, 50 + i * 60, 0, sb, -1, true));
  assert.deepEqual(r, [true, true, true, true, false, false], 'loop guard still stops endless growth');
});

test('35: hand-stretched cards and non-auto cards keep the old "original" rule (shrink only)', () => {
  const { WC } = core();
  const man = { fit: { t: 'auto' }, manual: true, w: 520, h: 200 };
  WC.ezMeasureStep(man, 52, 0, {}, -1, true); assert.equal(man.h, 57);
  WC.ezMeasureStep(man, 523, 1, {}, -1, true); assert.equal(man.h, 57, 'manual: no auto growth');
  const md = { fit: { t: 'auto' }, w: 520, h: 300 }, sm = {};
  WC.ezMeasureStep(md, 140, 0, sm, -1, true); assert.equal(md.h, 145);
  WC.ezMeasureStep(md, 589, 0, sm, -1, true); assert.equal(md.h, 145, 'no image loaded: a passing tall report does not grow it in "original"');
  const pg = { fit: { t: 'page' }, w: 520, h: 300 };
  WC.ezMeasureStep(pg, 600, 1, {}, -1, true); assert.equal(pg.h, 300, 'non-auto card: unchanged');
  assert.equal(WC.ezAutoFollows({ fit: { t: 'page' } }), false);
  assert.equal(WC.ezAutoFollows({ fit: { t: 'auto' } }), true);
  assert.match(CORE, /if\(mode===-1\)items\.forEach\(o=>\{const g=orig\.get\(o\._id\);o\.w=g\.w;o\.h=g\.h;o\.span=g\.span;o\.rh=g\.rh;\s*if\(o\._imgGrown&&ezAutoFollows\(o\)&&o\.mh\)o\.h=fitH\(o,o\.w,2\);\}\);/,
    'apply() in "original" does not undo the measured height (the race on X0003629)');
});

test('35: the card reporter sends the loaded-image count and re-measures on <img> load', async () => {
  const m = CORE.match(/const REPORT='(<script>[\s\S]*?<\\\/script>)';/);
  assert.ok(m);
  const src = m[1].replace(/^<script>/, '').replace(/<\\\/script>$/, '');
  const dom = new JSDOM('<!DOCTYPE html><body><p>x</p><img src="a.png"></body>', { runScripts: 'outside-only', url: 'https://ez.test/' });
  const w = dom.window; const sent = [];
  Object.defineProperty(w, 'parent', { value: { postMessage: d => sent.push(d) } });
  const img = w.document.querySelector('img');
  w.eval(src);
  await new Promise(r => setTimeout(r, 60));
  assert.equal(sent[sent.length - 1].__wallImgs, 0);
  Object.defineProperty(img, 'complete', { value: true }); Object.defineProperty(img, 'naturalWidth', { value: 724 });
  img.dispatchEvent(new w.Event('load'));
  assert.equal(sent[sent.length - 1].__wallImgs, 1, 'img load -> new report with 1 loaded image');
  assert.match(CORE, /if\(typeof d\.__wallImgs==='number'\)f\.__imgN=d\.__wallImgs;/);
  assert.match(CORE, /measureCbs\.forEach\(cb=>cb\(el\.dataset\.id\.replace\(\/\^\[ac\]-\/,''\),h,fr\.__imgN\|\|0\)\)/);
});

const LOC = { host: 'ez.wiki', protocol: 'https:' };
const URL_CASES = [
  ['/upload/wall/W1/a.png', LOC, 'https://ez.wiki/upload/wall/W1/a.png'],
  ['/upload/wall/W1/a.png', { host: 'ez.wiki', protocol: 'http:' }, 'https://ez.wiki/upload/wall/W1/a.png'],
  ['http://ez.wiki/upload/wall/W0000093/u3.png', LOC, 'https://ez.wiki/upload/wall/W0000093/u3.png'],
  ['http://seniorpilates.ez.wiki/upload/x.png?a=1', LOC, 'https://seniorpilates.ez.wiki/upload/x.png?a=1'],
  ['//ez.wiki/upload/x.png', LOC, 'https://ez.wiki/upload/x.png'],
  ['https://ez.wiki/upload/x.png', LOC, 'https://ez.wiki/upload/x.png'],
  ['/upload/x.png', { host: 'localhost:8000', protocol: 'http:' }, 'http://localhost:8000/upload/x.png'],
  ['http://127.0.0.1:8000/upload/x.png', LOC, 'http://127.0.0.1:8000/upload/x.png'],
  ['/upload/x.png', { host: '', protocol: 'about:' }, '/upload/x.png'],
  ['data:image/png;base64,AAAA', LOC, 'data:image/png;base64,AAAA'],
  ['blob:https://ez.wiki/1234', LOC, 'blob:https://ez.wiki/1234'],
];
test('35: upload URLs are https (or relative), never http:// — wall-core', () => {
  const { WC } = core();
  for (const [u, l, want] of URL_CASES) assert.equal(WC.ezUploadUrl(u, l), want, u + ' @' + l.protocol + l.host);
});
test('35: <img> tag carries the natural width/height and escapes', () => {
  const { WC } = core();
  assert.equal(WC.ezImgTag('https://ez.wiki/upload/a.png', 'shot "1".png', { w: 724, h: 710 }),
    '<img src="https://ez.wiki/upload/a.png" alt="shot &quot;1&quot;.png" width="724" height="710" style="max-width:100%;height:auto;display:block;border-radius:8px;margin:0 auto" />');
  assert.doesNotMatch(WC.ezImgTag('x.png', 'a', null), /width=/, 'unknown size: no attributes');
});
test('35: React panel helper (ezUpload.ts) behaves exactly like wall-core', (t) => {
  let ts; try { ts = require('typescript'); } catch (e) { try { ts = require(path.join(ROOT, 'node_modules/typescript')); } catch (e2) { t.skip('typescript not installed'); return; } }
  const js = ts.transpileModule(UPL, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
  const mod = { exports: {} }; new Function('module', 'exports', js)(mod, mod.exports);
  const { WC } = core();
  for (const [u, l, want] of URL_CASES) assert.equal(mod.exports.ezUploadUrl(u, l), want, u);
  for (const d of [{ w: 724, h: 710 }, null]) assert.equal(mod.exports.ezImgTag('https://ez.wiki/a.png?x=1&y=2', 'n\'<>', d), WC.ezImgTag('https://ez.wiki/a.png?x=1&y=2', 'n\'<>', d));
});
test('35: both upload paths use the helpers and wait for the natural size', () => {
  assert.match(TSX, /if\(item\.kind === 'image' && item\.file && WallCore\.ezImgDims && !item\.dimsP\) item\.dimsP = WallCore\.ezImgDims\(item\.file\);/);
  assert.match(TSX, /if\(WallCore\.ezUploadUrl\) url = WallCore\.ezUploadUrl\(url\);/);
  assert.match(TSX, /block = WallCore\.ezImgTag \? WallCore\.ezImgTag\(url, item\.name, item\.dims\)/);
  assert.match(PANEL, /import \{ ezImgDims, ezImgTag, ezUploadUrl \} from '\.\/ezUpload';/);
  assert.match(PANEL, /const dimsP = item\.kind === 'image' \? ezImgDims\(item\.file\) : null;/);
  assert.match(PANEL, /url = ezUploadUrl\(url\);/);
  assert.match(PANEL, /block = ezImgTag\(url, item\.name, dims\);/);
  assert.doesNotMatch(PANEL, /url = location\.origin \+ url/);
});
