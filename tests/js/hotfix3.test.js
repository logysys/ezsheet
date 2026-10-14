/* hotfix3: review window never stays on "…"; Co-WiKi only. `node --test tests/js/` */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');

function boot(fetchImpl) {
  const dom = new JSDOM(`<!DOCTYPE html><body class="ro"><div id="wall" class="masonry"></div><div id="toast"></div>
<script id="wall-data" type="application/json">{"id":"W0000057","list":[]}</script></body>`,
    { runScripts: 'outside-only', url: 'https://ez.test/w/W0000057', pretendToBeVisual: true });
  const w = dom.window;
  w.isOwner = true;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {}; w.HTMLElement.prototype.scrollTo = function () {};
  w.EventSource = class { addEventListener() {} close() {} };
  w.alert = () => {}; w.confirm = () => true;
  w.calls = [];
  w.fetch = (url, opts) => { w.calls.push([String(url), opts]); return fetchImpl(String(url), opts || {}); };
  w.eval(CORE);
  w.eval('WallCore.setWallSync("https://ez.test");');
  return { w, d: w.document, WC: w.WallCore };
}
const wait = ms => new Promise(r => setTimeout(r, ms));
const json = (status, body) => Promise.resolve({ ok: status < 300, status, json: async () => body });
const listText = d => d.querySelector('#revList').textContent;

test('hotfix3: 403 shows the denied text, not "…"', async () => {
  const { d, WC } = boot(() => json(403, { error: 'denied' }));
  WC.reviewOpen('W0000057');
  await wait(5);
  assert.notEqual(listText(d).trim(), '…');
  assert.match(listText(d), /owner/i);
});

test('hotfix3: a request that never answers ends with a visible timeout error', async () => {
  const { w, d, WC } = boot((url, opts) => new Promise((_, rej) => {
    opts.signal.addEventListener('abort', () => { const e = new Error('aborted'); e.name = 'AbortError'; rej(e); });
  }));
  const realST = w.setTimeout; w.setTimeout = (fn, ms, ...a) => realST(fn, ms >= 15000 ? 20 : ms, ...a);
  WC.reviewOpen('W0000057');
  await wait(60);
  assert.match(listText(d), /did not answer/i);
  assert.ok(d.querySelector('#revList .rev-err'));
});

test('hotfix3: network error shows revFail, review sends cookies (same-origin) and no client owner flag', async () => {
  const { w, d, WC } = boot(() => Promise.reject(new TypeError('Failed to fetch')));
  WC.reviewOpen('W0000057');
  await wait(5);
  assert.match(listText(d), /Could not reach/);
  const [url, opts] = w.calls[0];
  assert.match(url, /\/api\/wall\/W0000057\/review$/);
  assert.equal(opts.credentials, 'same-origin');
  assert.ok(!('x-is-owner' in opts.headers));
});

test('hotfix3: review lists Co-WiKi children only, pending first', async () => {
  const { d, WC } = boot(() => json(200, { pending_count: 1, cards: [
    { cid: 'm1', role: 'mother', content: 'M', status: 'approved', zone: 'b', at: 3 },
    { cid: 'c1', role: 'child', mother_id: 1, content: 'A', status: 'approved', zone: 'b', at: 2 },
    { cid: 'c2', role: 'child', mother_id: 1, content: 'P', status: 'pending', zone: null, at: 1 },
  ] }));
  WC.reviewOpen('W0000057');
  await wait(5);
  const cids = [...d.querySelectorAll('#revList .rev-row')].map(r => r.dataset.cid);
  assert.deepEqual(cids, ['c2', 'c1']);
  assert.match(d.querySelector('#revDropAll').textContent, /Co-WiKi/);
});
