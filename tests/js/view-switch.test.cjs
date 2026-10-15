// Item 22: the old Fireworks icon is the Cards/Beads switch for everyone; owner sets the wall default.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
function boot() {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="wall"></div></body>', { runScripts: 'outside-only', url: 'https://ez.test/W1' });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.eval(CORE);
  return { w, WC: w.WallCore };
}
test('visitor choice per wall in localStorage wins over the owner default', () => {
  const { w, WC } = boot();
  assert.equal(WC.ezViewEffective(null, 'beads'), 'beads');
  assert.equal(WC.ezViewEffective(null, undefined), 'cards');
  assert.equal(WC.ezViewLocal('W1'), null);
  WC.ezViewSaveLocal('W1', 'beads');
  assert.equal(w.localStorage.getItem('ez_view_W1'), 'beads');
  assert.equal(WC.ezViewLocal('W2'), null);
  assert.equal(WC.ezViewEffective(WC.ezViewLocal('W1'), 'cards'), 'beads');
  w.localStorage.setItem('ez_view_W1', 'junk');
  assert.equal(WC.ezViewLocal('W1'), null);
});
test('the bar has one view icon (no separate Cards|Beads toggle, no fireworks button) for everyone', () => {
  const { WC } = boot();
  assert.match(WC.viewBtnHTML(), /id="viewBtn"[^>]*aria-haspopup="menu"[^>]*title="Switch to Beads/);
  assert.doesNotMatch(WC.fxBtnHTML(), /fwBtn/);
  assert.match(WC.fxBtnHTML(), /flyBtn/);
  assert.doesNotMatch(CORE, /viewSeg/);
  assert.match(CORE, /zoneBtnHTML\(\)\+viewBtnHTML\(\)\+fxBtnHTML\(\)/);
  assert.doesNotMatch(CORE, /isOwner\?viewBtnHTML/);
});
test('menu keeps Fireworks reachable; only the owner gets "Set as default for everyone"', () => {
  const { WC } = boot();
  const guest = WC.ezViewMenuHTML('beads', 'cards', false, true);
  assert.match(guest, /data-vm="fw"[^>]*aria-checked="true">🎆 Fireworks: on/);
  assert.doesNotMatch(guest, /default for everyone/);
  const owner = WC.ezViewMenuHTML('beads', 'cards', true, false);
  assert.match(owner, /data-vm="def">★ Set Beads as default for everyone/);
  assert.match(owner, /Wall default: Cards/);
  assert.match(WC.ezViewMenuHTML('cards', 'cards', true, false), /data-vm="def" disabled/);
});
test('wiring: click switches locally (no server save); default only via owner action', () => {
  assert.match(CORE, /function chooseView\(v\)\{ezViewSaveLocal\(wallId,v\);setView\(v\);\}/);
  assert.match(CORE, /if\(!isOwnerNow\(\)\)return;\s*VIEW_DEF=BEADS\?'beads':'cards';\s*saveWallSetting\(\{view:VIEW_DEF\}\)/);
  assert.equal((CORE.match(/saveWallSetting\(\{view/g) || []).length, 1);
  assert.match(CORE, /setView\(ezViewEffective\(ezViewLocal\(wallId\), VIEW_DEF\)\)/);
  assert.match(CORE, /'contextmenu'/);
  assert.match(CORE, /hold=setTimeout\(\(\)=>\{held=true;viewMenuOpen\(b\);\},450\)/);
});
