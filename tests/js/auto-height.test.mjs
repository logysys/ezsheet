// Item 10: EzSheetPreview autoHeight / frameless (AISearchView passes both; before this they were ignored
// and the wall frame was a fixed 900px with an inner scroll).
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs';
import { createRequire } from 'node:module';
import { wallAutoHeightReporter, createWallHeightDamper } from '../../resources/js/components/ezAutoHeight.mjs';
const require = createRequire(import.meta.url);
const tsx = fs.readFileSync(new URL('../../resources/js/components/EzSheetPreview.tsx', import.meta.url), 'utf8');

function fakeRaf() { const q = []; const raf = f => q.push(f); raf.run = () => { while (q.length) q.shift()(); }; return raf; }

test('damper: rAF-batched, last value per frame wins', () => {
  const out = [], raf = fakeRaf(), d = createWallHeightDamper(h => out.push(h), { raf, minH: 100 });
  d.push(500); d.push(700); d.push(1400); raf.run();
  assert.deepEqual(out, [1400]);
});
test('damper: changes under 2px ignored, min height enforced, junk ignored', () => {
  const out = [], raf = fakeRaf(), d = createWallHeightDamper(h => out.push(h), { raf, minH: 240 });
  d.push(1000); raf.run(); d.push(1001); raf.run(); d.push(1000.5); raf.run();
  d.push(50); raf.run(); d.push(NaN); d.push('9'); raf.run();
  assert.deepEqual(out, [1000, 240]);
});
test('damper: A<->B oscillation is pinned to the larger height (loop-safe)', () => {
  const out = [], raf = fakeRaf(), d = createWallHeightDamper(h => out.push(h), { raf, minH: 100 });
  for (let i = 0; i < 10; i++) { d.push(i % 2 ? 1200 : 1100); raf.run(); }
  assert.ok(out.length <= 5, 'stops after a few flips: ' + out);
  assert.equal(out.at(-1), 1200);
  d.push(1100); raf.run(); assert.equal(out.at(-1), 1200, 'pinned: no shrink');
  d.push(1500); raf.run(); assert.equal(out.at(-1), 1500, 'pinned: still grows');
});

test('reporter: measures in-flow content only (fixed overlays and viewport ignored), posts damped', () => {
  const { JSDOM } = require('jsdom');
  const dom = new JSDOM('<body style="margin:0"><div id="wall"></div><div id="fx" style="position:fixed"></div><div id="panel" style="display:none"></div></body>', { pretendToBeVisual: true });
  const w = dom.window, posts = [];
  const rects = { wall: 1400, fx: 5000, panel: 9000 };
  for (const id in rects) w.document.getElementById(id).getBoundingClientRect = () => ({ top: 0, bottom: rects[id] });
  w.document.body.getBoundingClientRect = () => ({ top: 0, bottom: 900 });
  Object.defineProperty(w, 'parent', { value: { postMessage: m => posts.push(m) } });
  const raf = fakeRaf(); w.requestAnimationFrame = raf;
  const r = wallAutoHeightReporter(w);
  assert.equal(r.measure(), 1400, 'fixed overlay / hidden panel / body box (viewport) not counted');
  raf.run(); assert.deepEqual(posts, [{ __ezWallDocH: 1400 }]);
  rects.wall = 1401; r.schedule(); raf.run(); assert.equal(posts.length, 1, '<2px dropped');
  rects.wall = 1600; r.schedule(); r.schedule(); raf.run(); assert.deepEqual(posts.at(-1), { __ezWallDocH: 1600 }); assert.equal(posts.length, 2, 'batched');
  w.close();
});
test('reporter is self-contained (it is injected with toString into the srcdoc)', () => {
  const src = wallAutoHeightReporter.toString();
  assert.ok(!/createWallHeightDamper|import|require\(/.test(src));
  assert.doesNotThrow(() => new Function('return (' + src + ')'));
});

test('EzSheetPreview: props wired, default path unchanged', () => {
  assert.match(tsx, /autoHeight\?: boolean;/); assert.match(tsx, /frameless\?: boolean;/);
  assert.match(tsx, /autoHeight = false,\n  frameless = false,/);
  // default (props absent): same wrapper classes, same iframe class, fixed height
  assert.ok(tsx.includes(': `w-full bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm relative ${className}`}'));
  assert.ok(tsx.includes("'w-full block bg-[#F7F5F1]'"));
  assert.ok(tsx.includes('height: `${autoHeight ? Math.max(minHeight, autoHRef.current ?? height) : height}px`'));
  // reporter + overflow only injected when autoHeight; transparent only when frameless
  assert.ok(tsx.includes("${autoHeight ? '<script>(' + wallAutoHeightReporter.toString() + ')(window);</script>' : ''}"));
  assert.ok(tsx.includes("${frameless ? '<style>html,body{background:transparent!important}</style>' : ''}"));
});
test('EzSheetPreview: height goes to the iframe via ref, never React state (no redraw / payload echo)', () => {
  const i = tsx.indexOf('createWallHeightDamper((h: number) => {'); const body = tsx.slice(i, tsx.indexOf('}, { minH', i));
  assert.ok(i > 0 && /f\.style\.height = h \+ 'px'/.test(body) && !/set[A-Z]\w*\(/.test(body));
  assert.match(tsx, /typeof e\.data\.__ezWallDocH === 'number'\) \{\s*if \(autoHeightRef\.current\) heightDamperRef\.current\?\.push\(e\.data\.__ezWallDocH\);\s*return;/);
  assert.ok(tsx.indexOf('__ezWallDocH') > tsx.indexOf('if (e.source !== iframeRef.current.contentWindow) return;'), 'only from our own frame');
  assert.match(tsx, /\$\{autoHeight \? 'ah' : ''\}\$\{frameless \? 'fl' : ''\}`;/);
});
