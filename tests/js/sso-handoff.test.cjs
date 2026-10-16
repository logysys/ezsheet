// EZ-REL-20261016-3: cross-domain sign-in, CSRF headers, "Use the wall default".
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
const PREV = fs.readFileSync(path.join(ROOT, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
const AIS = fs.readFileSync(path.join(ROOT, 'resources/js/pages/AISearchView.tsx'), 'utf8');
function boot(url) {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall"></div></body>', { runScripts: 'outside-only', url: url || 'https://261016.ez.wiki/X/W1' });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.eval(CORE);
  return { w, WC: w.WallCore };
}
test('wall requests carry the XSRF-TOKEN cookie value as X-XSRF-TOKEN', () => {
  const { w, WC } = boot();
  assert.equal(JSON.stringify(WC.csrfHeaders()), '{}');
  w.document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
  assert.equal(JSON.stringify(WC.csrfHeaders()), JSON.stringify({ 'X-XSRF-TOKEN': 'abc==' }));
  assert.match(CORE, /if\(ezSameOriginSync\(\)\)Object\.assign\(hd,ezCsrfHeaders\(\)\)/);   // never sent to another origin
  assert.match(CORE, /'X-Requested-With':'XMLHttpRequest'\},ezCsrfHeaders\(\)\)/);          // magic-link send / verify
  assert.match(PREV, /WallCore\.csrfHeaders\(\)/);                                            // push, sheet, uploads
});
test('Sign in on another fleet host goes through the ez.wiki handoff, not the local popover', () => {
  assert.match(CORE, /const sso=ezSsoInfo\(\);\s*if\(sso&&sso\.handoff\)\{/);
  assert.match(CORE, /window\.top\.location\.href='\/login\?r='\+encodeURIComponent\(r\)/);
  const { w, WC } = boot();
  assert.equal(WC.ssoInfo(), null);
  w.__EZ_SSO = { flagship: 'ez.wiki', handoff: true };
  assert.equal(WC.ssoInfo().handoff, true);
});
test('view menu offers "Use the wall default" only when this browser saved its own choice', () => {
  const { w, WC } = boot();
  assert.doesNotMatch(WC.ezViewMenuHTML('cards', 'beads', false, false, false), /data-vm="reset"/);
  assert.match(WC.ezViewMenuHTML('cards', 'beads', false, false, true), /data-vm="reset"[^>]*>↺ Use the wall default \(Beads\)/);
  WC.ezViewSaveLocal('W1', 'cards');
  assert.equal(WC.ezViewEffective(WC.ezViewLocal('W1'), 'beads'), 'cards');
  WC.ezViewClearLocal('W1');
  assert.equal(WC.ezViewEffective(WC.ezViewLocal('W1'), 'beads'), 'beads');   // owner default applies again
});
test('parent page: view is a whitelisted wall setting and every wall id uses one rule', () => {
  assert.match(AIS, /'sound', 'language', 'view'\s*\]/);
  assert.match(AIS, /view: res\.data\.view === 'cards' \? 'cards' : 'beads'/);
  assert.equal((AIS.match(/social_media_metadata\?\.wall_id \|\|/g) || []).length, 1);   // only inside ezWallIdOf
  assert.ok((AIS.match(/ezWallIdOf\(/g) || []).length >= 7);
});
