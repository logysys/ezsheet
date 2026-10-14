/* Editor (EzSheetPreview iframe script) in jsdom: push flow, results, download. */
const test = require('node:test');
const assertStrict = require('node:assert/strict');
const J = x => JSON.parse(JSON.stringify(x));
const assert = Object.assign(Object.create(assertStrict), assertStrict, { deepEqual: (a, b, m) => assertStrict.deepStrictEqual(J(a), J(b), m) });
const fs = require('fs'), path = require('path'), vm = require('vm');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(root, 'resources/js/components/wall-core.js'), 'utf8');
/* release-1013 change 8: the editor markup/behaviour comes from the shared ezsheet-editor.js */
const EZED_SRC = fs.readFileSync(path.join(root, 'resources/js/components/ezsheet-editor.js'), 'utf8');
const getEzSheetEditor = () => { const G = {}; vm.runInNewContext(EZED_SRC, { window: G, globalThis: G }); return G.EzSheetEditor; };
const tsx = fs.readFileSync(path.join(root, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
const a = tsx.indexOf('const doc = `'), b = tsx.indexOf('</html>`;', a);
const TPL = tsx.slice(a + 'const doc = '.length, b + '</html>`'.length);

function boot(fetchImpl) {
  const html = vm.runInNewContext(TPL, {
    title: 'T', EZSHEET_EDITOR_SCRIPT: EZED_SRC, getEzSheetEditor, WALL_CSS: '', ZDOG_SCRIPT: '', WALL_CORE_SCRIPT: CORE, editable: true, isOwner: true,
    json: JSON.stringify({ id: 'W0000057', list: [] }), spriteInit: '', resolvedAuth: { user: null }, parentOrigin: 'https://ez.test',
    resolvedDomains: [], wallId: 'W0000057', slug: 'W0000057', messageId: 1, effectiveSyncUrl: 'https://ez.test', frameCheckUrl: '', metaUrl: '',
    payload: { id: 'W0000057', lang: 'en', cols: 0, car: false }, JSON,
    autoHeight: false, frameless: false, openEditor: false, wallAutoHeightReporter: function () {},
  });
  const calls = [];
  const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://ez.test/x', pretendToBeVisual: true,
    beforeParse(w) {
      w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
      w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
      w.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
      w.HTMLCanvasElement.prototype.getContext = () => null;
      w.HTMLElement.prototype.scrollTo = function () {}; w.scrollTo = () => {};
      w.EventSource = class { addEventListener() {} close() {} };
      w.URL.createObjectURL = () => 'blob:x'; w.URL.revokeObjectURL = () => {};
      w.confirm = () => true;
      w.fetch = (url, opts = {}) => { calls.push({ url: String(url), opts }); const r = fetchImpl(String(url), opts, calls);
        return Promise.resolve({ ok: r.status >= 200 && r.status < 300, status: r.status, json: async () => r.body }); };
    } });
  return { w: dom.window, d: dom.window.document, calls };
}
const tick = (n = 3) => new Promise(r => { let k = 0; const f = () => (++k >= n ? r() : setTimeout(f, 0)); setTimeout(f, 0); });
const M = (cid, raw, zone = 'b') => ({ cid, raw, zone, role: 'mother', status: 'approved', ok: true });
const SHEET = 'T\n===\n<!-- cid:m1aaaaaa -->\nhttps://b1.example/\n---\n<!-- cid:m2aaaaaa -->\nhttps://b2.example/\n';
const base = (url, opts) => {
  if (url.endsWith('/sheet')) return { status: 200, body: { sheet: SHEET, rev: 3 } };
  if (url.endsWith('/cards')) return { status: 200, body: { cards: [M('m1aaaaaa', 'https://b1.example/'), M('m2aaaaaa', 'https://b2.example/')], rev: 3 } };
  return { status: 404, body: {} };
};

test('editor loads tagged sheet but shows plain text', async () => {
  const env = boot(base);
  await tick();
  env.w.openPanel();
  await tick();
  const ta = env.d.getElementById('input');
  assert.equal(ta.value, 'T\n===\nhttps://b1.example/\n---\nhttps://b2.example/\n');
  assert.ok(!ta.value.includes('cid:'));
});

test('push sends tagged text, no local render; 2xx replaces text with res.sheet and applies snapshot', async () => {
  let pushed;
  const env = boot((url, opts) => {
    if (url.endsWith('/push-to-wall')) { pushed = JSON.parse(opts.body);
      return { status: 200, body: { success: true, rev: 4, sheet: 'T\n===\n<!-- cid:m1aaaaaa -->\nhttps://b1.example/\n---\n<!-- cid:m2aaaaaa -->\nhttps://b2.example/edited\n',
        cards: [M('m1aaaaaa', 'https://b1.example/'), M('m2aaaaaa', 'https://b2.example/edited')], unmatched_mothers_with_children: [] } }; }
    return base(url, opts);
  });
  await tick(); env.w.openPanel(); await tick();
  const ta = env.d.getElementById('input');
  ta.value = ta.value.replace('https://b2.example/\n', 'https://b2.example/edited\n');
  ta.dispatchEvent(new env.w.Event('input'));
  const before = env.w.__wallList.map(o => o.cid);
  env.w.batchImport();
  assert.deepEqual(env.w.__wallList.map(o => o.cid), before, 'nothing rendered before the server answers');
  await tick();
  assert.equal(pushed.raw, 'T\n===\n<!-- cid:m1aaaaaa -->\nhttps://b1.example/\n---\n<!-- cid:m2aaaaaa -->\nhttps://b2.example/edited\n');
  assert.ok(!('items' in pushed) && !('cards' in pushed), 'no client items/zones sent');
  assert.equal(ta.value, 'T\n===\nhttps://b1.example/\n---\nhttps://b2.example/edited\n');
  assert.deepEqual(env.w.__wallList.map(o => o.cid), ['m1aaaaaa', 'm2aaaaaa']);
  assert.equal(env.w.WallCore.currentRev(), 4);
});

test('mother_zone_locked: dialog lists cards, text kept, wall unchanged', async () => {
  const env = boot((url, opts) => url.endsWith('/push-to-wall')
    ? { status: 422, body: { error: 'mother_zone_locked', message: 'Must stay in Content', cards: [{ cid: 'm1aaaaaa', raw: 'https://b1.example/', to_zone: 'a', children: 2 }], rev: 3 } }
    : base(url, opts));
  await tick(); env.w.openPanel(); await tick();
  const ta = env.d.getElementById('input');
  ta.value = 'https://b1.example/\n===\nB2\n'; ta.dispatchEvent(new env.w.Event('input'));
  env.w.batchImport(); await tick();
  const dlg = env.d.querySelector('.ez-dlg');
  assert.ok(dlg && /Must stay in Content/.test(dlg.textContent) && /b1\.example/.test(dlg.textContent) && /Topic/.test(dlg.textContent));
  assert.equal(ta.value, 'https://b1.example/\n===\nB2\n');
});

test('empty_sheet: confirm then re-push with confirm_empty=1', async () => {
  const bodies = [];
  const env = boot((url, opts) => {
    if (url.endsWith('/push-to-wall')) { const bd = JSON.parse(opts.body); bodies.push(bd);
      return bd.confirm_empty ? { status: 200, body: { success: true, rev: 5, sheet: '\n', cards: [], unmatched_mothers_with_children: [] } }
        : { status: 422, body: { error: 'empty_sheet', message: 'Empty?' } }; }
    return base(url, opts);
  });
  await tick(); env.w.openPanel(); await tick();
  const ta = env.d.getElementById('input'); ta.value = ''; ta.dispatchEvent(new env.w.Event('input'));
  env.w.batchImport(); await tick(6);
  assert.deepEqual(bodies.map(x => x.confirm_empty), [0, 1]);
  assert.equal(env.w.__wallList.length, 0);
});

test('unmatched_mothers_with_children: delete action removes their blocks and sends delete_cids', async () => {
  const bodies = [];
  const kept = 'https://b1.example/\n---\n<!-- cid:m2aaaaaa -->\nhttps://b3.example/edited\n---\n<!-- cid:m9aaaaaa -->\nhttps://old.example/kids\n';
  const env = boot((url, opts) => {
    if (url.endsWith('/push-to-wall')) { const bd = JSON.parse(opts.body); bodies.push(bd);
      return bodies.length === 1
        ? { status: 200, body: { success: true, rev: 6, sheet: kept, cards: [M('m1aaaaaa', 'https://b1.example/'), M('m2aaaaaa', 'https://b3.example/edited'), M('m9aaaaaa', 'https://old.example/kids')],
            unmatched_mothers_with_children: [{ cid: 'm9aaaaaa', raw: 'https://old.example/kids', zone: 'b', children: 1 }] } }
        : { status: 200, body: { success: true, rev: 7, sheet: 'https://b1.example/\n---\nhttps://b3.example/edited\n', cards: [M('m1aaaaaa', 'https://b1.example/'), M('m2aaaaaa', 'https://b3.example/edited')], unmatched_mothers_with_children: [] } };
    }
    return base(url, opts);
  });
  await tick(); env.w.openPanel(); await tick();
  env.w.batchImport(); await tick();
  const btn = [...env.d.querySelectorAll('.ez-dlg button')].find(b => /Delete them/.test(b.textContent));
  assert.ok(btn); btn.click(); await tick();
  assert.deepEqual(bodies[1].delete_cids, ['m9aaaaaa']);
  assert.ok(!bodies[1].raw.includes('https://old.example/kids') && !bodies[1].raw.includes('m9aaaaaa'));
  assert.ok(bodies[1].raw.includes('<!-- cid:m2aaaaaa -->'));
  assert.equal(env.w.WallCore.currentRev(), 7);
});

test('download is plain text without tags (one download, via parent when embedded)', async () => {
  const env = boot(base);
  await tick(); env.w.openPanel(); await tick();
  let blobText = null;
  env.w.Blob = class { constructor(parts) { blobText = parts.join(''); } };
  env.d.getElementById('input').value = '<!-- cid:m1aaaaaa -->\nhttps://b1.example/\n';
  env.d.getElementById('btnDownloadJSON').click();
  assert.equal(blobText, 'https://b1.example/\n');
});

/* Item 11 (EZ-REL-20261014-1): W0000076. A block the server did not add is never silent:
   the dialog names it and its Co-WiKi parent, and "Add as WiKi card" re-pushes the same
   text with add_cowiki_raws. */
test('not_added: dialog explains, "Add as WiKi card" re-pushes with add_cowiki_raws', async () => {
  const bodies = [], EB = 'https://yoursilentlaunch.com/eBook';
  const env = boot((url, opts) => {
    if (url.endsWith('/push-to-wall')) {
      const bd = JSON.parse(opts.body); bodies.push(bd);
      const added = (bd.add_cowiki_raws || []).includes(EB);
      return { status: 200, body: { success: true, rev: 4 + bodies.length, sheet: SHEET + (added ? '---\n<!-- cid:m3aaaaaa -->\n' + EB + '\n' : ''),
        stats: { created: added ? 1 : 0, updated: 0, deleted: 0 },
        cards: [M('m1aaaaaa', 'https://b1.example/'), M('m2aaaaaa', 'https://b2.example/')].concat(added ? [M('m3aaaaaa', EB)] : []),
        unmatched_mothers_with_children: [],
        not_added: added ? [] : [{ raw: EB, zone: 'b', reason: 'same_as_cowiki_card', cowiki_cid: 'c1', mother_cid: 'm1aaaaaa', mother_raw: 'https://b1.example/' }] } };
    }
    return base(url, opts);
  });
  await tick(); env.w.openPanel(); await tick();
  const ta = env.d.getElementById('input');
  ta.value = 'T\n===\nhttps://b1.example/\n---\nhttps://b2.example/\n---\n' + EB + '\n'; ta.dispatchEvent(new env.w.Event('input'));
  env.w.batchImport(); await tick(6);
  const dlg = env.d.querySelector('.ez-dlg');
  assert.ok(dlg, 'a dialog is shown');
  assert.match(dlg.textContent, /not added/); assert.ok(dlg.textContent.includes(EB)); assert.match(dlg.textContent, /Co-WiKi under: https:\/\/b1\.example\//);
  const add = [...dlg.querySelectorAll('button')].find(b => /Add as WiKi card/.test(b.textContent));
  assert.ok(add, 'offers to add it');
  add.click(); await tick(6);
  assert.equal(bodies.length, 2);
  assert.deepEqual(bodies[1].add_cowiki_raws, [EB]);
  assert.equal(bodies[1].raw, bodies[0].raw, 're-pushes the same sheet');
  assert.ok(env.w.__wallList.some(o => (o._raw || o.raw || o.url) === EB || JSON.stringify(o).includes('eBook')), 'card on the wall');
  assert.ok(!env.d.querySelector('.ez-dlg'), 'no dialog after it was added');
});
