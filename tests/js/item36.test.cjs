/* EZ-REL-20261017-3, item 36: push refusals say WHY (401 signed_out, 403 cross_origin, 403 not_owner,
   client_refused), the same words in the EzSheet frame and on the page; GET /api/wall/<id>/me drives
   the globe / Push and runs before every push; the owner key is per wall and only a candidate. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs'), path = require('path'), vm = require('vm');
const { JSDOM } = require('jsdom');
const root = path.join(__dirname, '../..');
const rd = f => fs.readFileSync(path.join(root, f), 'utf8');
const CORE = rd('resources/js/components/wall-core.js');
const TSX = rd('resources/js/components/EzSheetPreview.tsx');
const AIS = rd('resources/js/pages/AISearchView.tsx');
const PF = require('./push-feedback-scope.cjs');
const EZED_SRC = rd('resources/js/components/ezsheet-editor.js');
const getEzSheetEditor = () => { const G = {}; vm.runInNewContext(EZED_SRC, { window: G, globalThis: G }); return G.EzSheetEditor; };
const a = TSX.indexOf('const doc = `'), b = TSX.indexOf('</html>`;', a);
const TPL = TSX.slice(a + 'const doc = '.length, b + '</html>`'.length);

function boot(fetchImpl, opt) {
  opt = opt || {};
  const html = vm.runInNewContext(TPL, {
    title: 'T', EZSHEET_EDITOR_SCRIPT: EZED_SRC, getEzSheetEditor, WALL_CSS: '', ZDOG_SCRIPT: '', WALL_CORE_SCRIPT: CORE, editable: true, isOwner: opt.isOwner !== false,
    json: JSON.stringify({ id: 'W0000093', list: [] }), spriteInit: '', resolvedAuth: { user: null }, parentOrigin: 'https://ez.test',
    resolvedDomains: [], wallId: 'W0000093', slug: 'W0000093', funnel: '', messageId: 1, effectiveSyncUrl: 'https://ez.test', frameCheckUrl: '', metaUrl: '',
    payload: { id: 'W0000093', lang: 'en', cols: 0, car: false }, JSON,
    autoHeight: false, frameless: false, openEditor: false, wallAutoHeightReporter: function () {},
    EZ_PUSH_TEXTS: PF.EZ_PUSH_TEXTS, ezPushRefusalText: PF.ezPushRefusalText,
  });
  const calls = [];
  const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://ez.test/X0003629', pretendToBeVisual: true,
    beforeParse(w) {
      w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
      w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
      w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
      w.HTMLCanvasElement.prototype.getContext = () => null;
      w.HTMLElement.prototype.scrollTo = function () {}; w.scrollTo = () => {};
      w.EventSource = class { addEventListener() {} close() {} };
      w.console.error = () => {};
      if (opt.seed) opt.seed(w.localStorage);
      w.fetch = (url, o = {}) => { calls.push({ url: String(url), opts: o }); const r = fetchImpl(String(url), o, calls);
        return Promise.resolve({ ok: r.status >= 200 && r.status < 300, status: r.status, json: async () => r.body }); };
    } });
  OPEN.push(dom.window);
  return { w: dom.window, d: dom.window.document, calls };
}
const OPEN = [];
test.after(() => { for (const w of OPEN) try { w.close(); } catch (e) {} });
const tick = (n = 6) => new Promise(r => { let k = 0; const f = () => (++k >= n ? r() : setTimeout(f, 0)); setTimeout(f, 0); });
const SHEET = 'T\n===\n<!-- cid:m1aaaaaa -->\nhttps://b1.example/\n';
const M = (cid, raw) => ({ cid, raw, zone: 'b', role: 'mother', status: 'approved', ok: true });
const srv = (me, push) => (url, opts) => {
  if (url.endsWith('/me')) return me;
  if (url.endsWith('/push-to-wall')) return push || { status: 200, body: { success: true, rev: 4, sheet: SHEET, cards: [M('m1aaaaaa', 'https://b1.example/')] } };
  if (url.endsWith('/sheet')) return { status: 200, body: { sheet: SHEET, rev: 3 } };
  if (url.endsWith('/cards')) return { status: 200, body: { cards: [M('m1aaaaaa', 'https://b1.example/')], rev: 3 } };
  return { status: 404, body: {} };
};
const ME_OWNER = { status: 200, body: { authenticated: true, user: { id: 3, name: 'Roy', email: 'ro***@***.com' }, is_owner: true, via: 'session', reason: null } };
const ME_OUT = { status: 200, body: { authenticated: false, user: null, is_owner: false, via: null, reason: 'signed_out' } };
const ME_OTHER = { status: 200, body: { authenticated: true, user: { id: 9, name: 'X', email: 'xy***@***.com' }, is_owner: false, via: null, reason: 'not_owner' } };
const toastText = d => (d.getElementById('toast') || {}).textContent || '';
const T = PF.EZ_PUSH_TEXTS.en;

test('36: refusal texts, one per reason, in en / zh-TW / zh-CN; 419 untouched', () => {
  const R = (s, d, l) => PF.ezPushRefusalText(s, d, l || 'en', PF.EZ_PUSH_TEXTS);
  assert.equal(R(401, {}), T.signedOut);
  assert.equal(R(401, { error: 'signed_out' }), T.signedOut);
  assert.equal(R(403, { error: 'cross_origin' }), T.crossOrigin);
  assert.equal(R(403, { error: 'not_owner', signed_in_as: 'ro***@***.com' }), T.notOwnerAs.replace('{email}', 'ro***@***.com'));
  assert.equal(R(403, { error: 'not_owner' }), T.notOwner);
  assert.equal(R(403, { error: 'client_refused' }), T.clientRefused);
  assert.equal(R(403, { error: 'Unauthorized: Only the wall owner can push updates to this wall.' }), T.owner, 'old server');
  assert.equal(R(419, {}), '', '419 is not a refusal here');
  assert.equal(R(500, {}), '');
  assert.match(R(401, {}, 'zh-Hant'), /已經登出/);
  assert.match(R(403, { error: 'not_owner', signed_in_as: 'a***@***.com' }, 'zh-CN'), /a\*\*\*@\*\*\*\.com/);
  for (const s of [401, 403]) assert.equal(PF.ezPushFeedback(s, { error: s === 401 ? 'signed_out' : 'cross_origin' }, 'en').text, R(s, { error: s === 401 ? 'signed_out' : 'cross_origin' }));
  assert.match(PF.ezPushFeedback(419, {}, 'en').text, /session expired/i);
  assert.equal(Object.keys(PF.EZ_PUSH_TEXTS.en).filter(k => !PF.EZ_PUSH_TEXTS['zh-TW'][k] || !PF.EZ_PUSH_TEXTS['zh-CN'][k]).join(), '', 'every text translated');
});

test('36: the EzSheet frame gets the SAME function and texts as the page', async () => {
  const env = boot(srv(ME_OWNER)); await tick();
  assert.equal(typeof env.w.ezPushRefusalText, 'function');
  for (const [s, d] of [[401, {}], [403, { error: 'cross_origin' }], [403, { error: 'not_owner', signed_in_as: 'ro***@***.com' }], [403, { error: 'client_refused' }]]) {
    for (const l of ['en', 'zh-TW', 'zh-CN']) assert.equal(env.w.ezPushRefusalText(s, d, l, env.w.EZ_PUSH_TEXTS), PF.ezPushRefusalText(s, d, l, PF.EZ_PUSH_TEXTS));
  }
  assert.doesNotMatch(TSX, /if\(status === 403\)\{ toast\('Only the wall owner can push to this wall\.'\); return; \}/, 'no blanket 403 text any more');
});

test('36: GET /api/wall/<id>/me runs on load (same-origin, this host) and drives the globe', async () => {
  const env = boot(srv(ME_OTHER)); await tick();
  const me = env.calls.find(c => /\/api\/wall\/W0000093\/me$/.test(c.url));
  assert.ok(me, 'me called on load'); assert.equal(me.url, '/api/wall/W0000093/me', 'relative = this host');
  assert.equal(me.opts.credentials, 'same-origin');
  assert.equal(env.w.WallCore.ezOwnerNow(), false, 'server said not owner although the page flag said owner');
  assert.ok(env.d.body.classList.contains('not-owner'));
  const g = env.d.querySelector('.globe'); assert.ok(g); assert.equal(g.style.display, 'none', 'globe hidden');
  const env2 = boot(srv(ME_OWNER), { isOwner: false }); await tick();
  assert.equal(env2.w.WallCore.ezOwnerNow(), true, 'server confirmed -> owner');
  assert.equal(env2.d.querySelector('.globe').style.display, '', 'globe shown');
});

test('36: on focus the check runs again (throttled to 15 s)', async () => {
  const env = boot(srv(ME_OWNER)); await tick();
  const n = () => env.calls.filter(c => c.url.endsWith('/me')).length;
  const n0 = n(); env.w.dispatchEvent(new env.w.Event('focus')); await tick();
  assert.equal(n(), n0, 'within 15 s: cached');
  const now = Date.now, wnow = env.w.Date.now; env.w.Date.now = () => now() + 16000; Date.now = () => now() + 16000;
  try { env.w.dispatchEvent(new env.w.Event('focus')); await tick(); } finally { Date.now = now; env.w.Date.now = wnow; }
  assert.equal(n(), n0 + 1, 'after 15 s: asked again');
});

test('36: before each push: signed out -> nothing sent, signed-out text, sign-in sheet opens', async () => {
  const env = boot(srv(ME_OUT)); await tick();
  let asked = 0; env.w.WallCore.signIn = () => { asked++; return Promise.resolve(false); };
  env.w.openPanel(); await tick();
  const before = env.calls.filter(c => c.url.endsWith('/me')).length;
  env.w.batchImport(); await tick();
  assert.equal(env.calls.filter(c => c.url.endsWith('/me')).length, before + 1, 'fresh check right before the push');
  assert.equal(env.calls.filter(c => c.url.endsWith('/push-to-wall')).length, 0, 'nothing sent');
  assert.equal(toastText(env.d), T.signedOut);
  assert.equal(asked, 1, 'sign-in sheet opened');
});

test('36: before each push: another account -> nothing sent, says which account', async () => {
  const env = boot(srv(ME_OTHER)); await tick();
  env.w.openPanel(); await tick(); env.w.batchImport(); await tick();
  assert.equal(env.calls.filter(c => c.url.endsWith('/push-to-wall')).length, 0);
  assert.equal(toastText(env.d), T.notOwnerAs.replace('{email}', 'xy***@***.com'));
});

test('36: owner -> push goes out; the server refusal codes show their own text', async () => {
  for (const [push, want, signIn] of [
    [{ status: 401, body: { error: 'signed_out' } }, T.signedOut, 1],
    [{ status: 403, body: { error: 'cross_origin' } }, T.crossOrigin, 0],
    [{ status: 403, body: { error: 'not_owner', signed_in_as: 'ro***@***.com' } }, T.notOwnerAs.replace('{email}', 'ro***@***.com'), 0],
    [{ status: 419, body: {} }, 'Session expired. Please refresh the page and push again.', 0],
  ]) {
    const env = boot(srv(ME_OWNER, push)); await tick();
    let asked = 0; env.w.WallCore.signIn = () => { asked++; return Promise.resolve(false); };
    env.w.openPanel(); await tick(); env.w.batchImport(); await tick(10);
    assert.equal(env.calls.filter(c => c.url.endsWith('/push-to-wall')).length, 1, 'sent ' + push.status);
    assert.equal(toastText(env.d), want, String(push.status));
    assert.equal(asked, signIn, 'sign-in sheet for ' + push.status);
  }
});

test('36: owner key is per wall and only a candidate; the server confirms it', async () => {
  const seedLegacy = ls => ls.setItem('wall_owner', 'tok-legacy-123456');
  const env = boot(srv({ status: 200, body: { authenticated: false, user: null, is_owner: false, via: null, reason: 'signed_out' } }), { isOwner: false, seed: seedLegacy });
  await tick();
  const me = env.calls.find(c => c.url.endsWith('/me'));
  assert.equal(me.opts.headers['x-wall-owner'], 'tok-legacy-123456', 'legacy key sent as a candidate');
  assert.equal(env.w.WallCore.ezOwnerNow(), false, 'not proof by itself');
  assert.equal(env.d.querySelector('.globe').style.display, 'none');
  assert.equal(env.w.localStorage.getItem('wall_owner:W0000093'), null, 'unconfirmed: not stored for this wall');
  const env2 = boot(srv({ status: 200, body: { authenticated: false, user: null, is_owner: true, via: 'token', reason: null } }), { isOwner: false, seed: seedLegacy });
  await tick();
  assert.equal(env2.w.WallCore.ezOwnerNow(), true);
  assert.equal(env2.w.localStorage.getItem('wall_owner:W0000093'), 'tok-legacy-123456', 'confirmed -> kept for THIS wall');
  assert.equal(env2.w.WallCore.ownerToken(), 'tok-legacy-123456');
  env2.w.WallCore.setOwnerToken('', 'W0000093');
  assert.equal(env2.w.WallCore.ownerTokenOwn('W0000093'), '');
  assert.doesNotMatch(CORE, /\|\|!!\(?typeof ownerToken === 'function' && ownerToken\(\)\)?|\|\|!!ownerToken\(\)/, 'no UI check treats a stored key as ownership');
});

test('36: parent page: refuses WITHOUT sending only when neither the page nor the server sees an owner', () => {
  assert.match(TSX, /if \(!isOwner && e\.data\.meOwner !== true\) \{[\s\S]{0,200}reply\(403, \{ error: 'client_refused' \}\);/);
  assert.match(TSX, /meOwner: !!body\.__meOwner/);
  assert.match(AIS, /headers: typeof data\?\.ownerToken === 'string'[^\n]*'x-wall-owner': data\.ownerToken/);
});

test('36: sign-out: "Sign out everywhere" in the menus; plain Log out stays', () => {
  const UM = rd('resources/js/components/user-menu-content.tsx');
  assert.match(UM, /href=\{route\('logout'\)\}/);
  assert.match(UM, /href="\/auth\/logout-everywhere"[\s\S]{0,200}Sign out everywhere/);
  assert.match(rd('resources/js/components/DraggableMenu.tsx'), /\{ href: '\/auth\/logout-everywhere', action: 'Sign out everywhere'[^}]*method: 'post'/);
});

test('36: public page props never carry the full owner email; plain logout no longer signs out everywhere by default', () => {
  const WC = rd('app/Http/Controllers/WelcomeController.php');
  assert.doesNotMatch(WC, /'email'\s*=>\s*\$search->user->email/);
  assert.equal((WC.match(/'email' => \\App\\Models\\AISearchHistory::maskEmail\(\$search->user->email\)/g) || []).length, 4);
  assert.match(rd('config/fleet.php'), /'logout_everywhere' => \(bool\) env\('SSO_LOGOUT_EVERYWHERE', false\)/);
  assert.match(rd('app/Providers/AppServiceProvider.php'), /config\('fleet\.logout_everywhere', false\)/);
});
