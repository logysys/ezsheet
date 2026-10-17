/* EZ-REL-20261017-1: item 29 (Co-WiKi beads on the right in every zone + red-dot copy),
   item 30 (reply box starts closed, plain Markdown, no demo cards), item 31 (www null-check). */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
const AIS = fs.readFileSync(path.join(ROOT, 'resources/js/pages/AISearchView.tsx'), 'utf8');
const SMC = fs.readFileSync(path.join(ROOT, 'resources/js/components/SocialMediaComposer.tsx'), 'utf8');
const WEL = fs.readFileSync(path.join(ROOT, 'app/Http/Controllers/WelcomeController.php'), 'utf8');
function boot(html) {
  const dom = new JSDOM('<!DOCTYPE html><body>' + (html || '<div id="wall" class="masonry"></div>') + '</body>',
    { runScripts: 'outside-only', url: 'https://ez.test/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.fetch = () => Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
  w.eval(CORE);
  return { w, d: w.document, WC: w.WallCore };
}
const kid = (i, extra) => Object.assign({ _id: 'k' + i, cid: 'k' + i, role: 'child', mother_cid: 'M', ok: true, at: 100 - i, _raw: 'https://example.com/' + i }, extra || {});

test('29: copy format = mother, ===, co-wikis separated by ---, in displayed order', () => {
  const { WC } = boot();
  const t = WC.ezFamilyCopyText({ _raw: '  https://en.wikipedia.org/wiki/Wiki \n' }, [kid(1), kid(2, { _raw: '<md>\nhello\n</md>' }), kid(3)]);
  assert.equal(t, 'https://en.wikipedia.org/wiki/Wiki\n===\nhttps://example.com/1\n---\n<md>\nhello\n</md>\n---\nhttps://example.com/3');
  assert.equal(WC.ezFamilyCopyText({ _raw: 'M only' }, []), 'M only', 'no co-wikis -> mother only, no ===');
  assert.equal(WC.ezFamilyCopyText({ _raw: 'M' }, [kid(1, { _raw: '' }), kid(2)]), 'M\n===\nhttps://example.com/2', 'empty kids skipped');
});

test('29: the copied order is the bead order (pinned first, then newest)', () => {
  const { w, WC } = boot();
  const M = { _id: 'M', cid: 'M', role: 'mother', _raw: 'MOTHER' };
  w.__wallList = [M, kid(1, { at: 1 }), kid(2, { at: 3 }), kid(3, { at: 2, pinned: true })];
  const ks = WC.ezBeadKids(w.__wallList).get('M');
  assert.equal(ks.map(k => k._id).join(), 'k3,k2,k1');
  assert.equal(WC.ezFamilyMother('M'), M);
  assert.equal(WC.ezFamilyCopyText(M, ks), 'MOTHER\n===\nhttps://example.com/3\n---\nhttps://example.com/2\n---\nhttps://example.com/1');
});

test('29: red dot is the LAST thing on the bar, has aria-label + tooltip, and fits the card', () => {
  const { WC } = boot();
  const few = WC.ezRailHTML({ cid: 'M' }, [kid(1), kid(2)], 300, false);
  assert.match(few.html, /<button type="button" class="bead-copy" data-copy="M" style="top:\d+px" aria-label="Copy this WiKi \+ 2 co-wikis to the clipboard" title="Copy this WiKi \+ 2 co-wikis to the clipboard"><\/button>$/);
  assert.equal(few.len, 300, 'a short bar stays inside the card height');
  const many = WC.ezRailHTML({ cid: 'M' }, Array.from({ length: 30 }, (_, i) => kid(i)), 200, false);
  assert.ok(many.html.indexOf('bead-more') < many.html.indexOf('bead-copy'), 'dot after +N');
  const dotTop = +many.html.match(/bead-copy" data-copy="M" style="top:(\d+)px/)[1];
  assert.ok(dotTop + 16 <= 200, 'collapsed bar incl. dot fits a 200px card: ' + dotTop);
  const open = WC.ezRailHTML({ cid: 'M' }, Array.from({ length: 30 }, (_, i) => kid(i)), 200, true);
  assert.ok(open.len > 200 && open.open, 'an opened bar grows (band grows with it)');
});

test('29: Content rail is on the RIGHT of the mother and ends with the red dot', () => {
  const { w, d, WC } = boot();
  const wall = d.getElementById('wall');
  const el = d.createElement('div'); el.className = 'item mother'; el._o = { cid: 'M', _id: 'M' };
  Object.assign(el.style, { left: '10px', top: '0px', width: '300px', height: '240px' }); wall.appendChild(el);
  w.__wallList = [kid(1), kid(2)];
  WC.setBeadsFlag(true); WC.beadsPaint(wall);
  const rail = wall.querySelector('.bead-rail');
  assert.ok(parseFloat(rail.style.left) > 310, 'rail right of the card');
  assert.equal(rail.lastElementChild.className, 'bead-copy');
});

test('29: Topic/InfoMercial use the same rail, placed on the RIGHT of each band card; no bottom-left row', () => {
  const { w, d, WC } = boot();
  const sec = d.createElement('section'); sec.id = 'zoneA'; sec.innerHTML = '<div class="za-track"></div>'; d.body.appendChild(sec);
  const track = sec.firstChild;
  const M = { _id: 'M', cid: 'M', role: 'mother', zone: 'a', _raw: 'T' };
  w.__wallList = [M, kid(1, { zone: 'a' }), kid(2, { zone: 'a' })];
  WC.setBeadsFlag(true);
  assert.equal(WC.ezBandRailOn([M]), true);
  const el = d.createElement('div'); el.className = 'item mother'; el._o = M; el._rel = 0;
  Object.assign(el.style, { left: '0px', top: '20px', width: '300px', height: '200px' }); track.appendChild(el);
  const B = { id: 'zoneA', items: [M], els: new Map([['M', el]]), k: 3, h: 240, step: 344, gap: 44, rail: true };
  WC.bandRailsPaint(B, true);
  const rail = track.querySelector('.bead-rail.band-rail[data-rail="M"]');
  assert.ok(rail, 'band rail exists');
  assert.equal(parseFloat(rail.style.left), 300 + 22, 'right of the card, centred in the bead gap');
  assert.equal(rail.querySelectorAll('.bead').length, 2);
  assert.equal(rail.lastElementChild.className, 'bead-copy');
  assert.equal(track.querySelector('.band-beads'), null);
  WC.setBeadsFlag(false); B.rail = false; WC.bandRailsPaint(B, true);
  assert.equal(track.querySelector('.bead-rail'), null, 'Cards view: no rail');
});

test('29: clipboard API first, execCommand fallback, failure message; toast in the visible area', async () => {
  const { w, d, WC } = boot();
  let got = null;
  Object.defineProperty(w.navigator, 'clipboard', { configurable: true, value: { writeText: t => { got = t; return Promise.resolve(); } } });
  assert.equal(await WC.ezCopyText('A\n===\nB'), true); assert.equal(got, 'A\n===\nB');
  Object.defineProperty(w.navigator, 'clipboard', { configurable: true, value: { writeText: () => Promise.reject(new Error('denied')) } });
  let ex = 0; d.execCommand = c => { ex++; return c === 'copy'; };
  assert.equal(await WC.ezCopyText('x'), true); assert.equal(ex, 1, 'falls back to execCommand');
  d.execCommand = () => false;
  assert.equal(await WC.ezCopyText('x'), false);
  const M = { _id: 'M', cid: 'M', role: 'mother', _raw: 'MOTHER' };
  w.__wallList = [M, kid(1)];
  const dot = d.createElement('button'); d.body.appendChild(dot);
  assert.equal(await WC.ezCopyFamily('M', dot), false);
  const t = d.querySelector('.bead-toast.err'); assert.ok(t); assert.match(t.textContent, /blocked clipboard access/); assert.equal(t.getAttribute('role'), 'status');
  Object.defineProperty(w.navigator, 'clipboard', { configurable: true, value: { writeText: v => { got = v; return Promise.resolve(); } } });
  assert.equal(await WC.ezCopyFamily('M', dot), true);
  assert.equal(got, 'MOTHER\n===\nhttps://example.com/1');
  const ok = [...d.querySelectorAll('.bead-toast')].find(x => !x.classList.contains('err'));
  assert.equal(ok.textContent, 'Copied WiKi + 1 co-wiki'); assert.equal(ok.style.position, 'fixed');
  assert.match(CORE, /const cp=e\.target\.closest&&e\.target\.closest\('\.bead-copy'\)/);
});

test('30: reply box starts CLOSED, opens on Reply as Markdown, closes after post / cancel; no demo pre-fill', () => {
  assert.match(AIS, /const \[replyOpen, setReplyOpen\] = useState\(false\);/);
  assert.match(AIS, /if \(!replyOpen\) \{[\s\S]{0,400}data-testid="reply-collapsed"/);
  assert.match(AIS, /const openReply = \(\) => \{\s*setContentFormat\('markdown'\);/);
  assert.match(AIS, /showAlert\('Social media post created successfully!', 'success'\);\s*closeReply\(\);/);
  assert.match(AIS, /onCancel=\{closeReply\}/);
  assert.match(AIS, /useState<'markdown' \| 'html' \| 'ezsheet'>\('markdown'\)/);
  assert.match(SMC, /useState\(\(\) => initialContent \|\| ''\)/);
  assert.doesNotMatch(SMC, /return DEMO_EZSHEET_CARDS|setContent\(DEMO_EZSHEET_CARDS\);\s*\}\s*\}, \[effectiveFormat\]/);
  assert.match(SMC, /onCancel\?\.\(\);/);
  assert.match(SMC, /data-testid="reply-cancel"/);
});

test('31: www.<domain> uses the apex front page and a missing row never reads ->type on null', () => {
  assert.match(WEL, /stripos\(\$fullDomain, 'www\.'\) === 0/);
  assert.match(WEL, /if \(!\$type\) abort\(404\);\s*if\(\$type->type=='AISEARCH'\)/);
});
