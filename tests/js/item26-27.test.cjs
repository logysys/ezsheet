/* Item 26 (Co-WiKi in all three zones) and item 27 (view switch is Cards/Beads only, default Beads). */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
const AIS = fs.readFileSync(path.join(ROOT, 'resources/js/pages/AISearchView.tsx'), 'utf8');
const PHP = fs.readFileSync(path.join(ROOT, 'app/Http/Controllers/Api/WallController.php'), 'utf8');
function boot(owner) {
  const dom = new JSDOM('<!DOCTYPE html><body><div id="zoneA"></div><div id="wall"></div><div id="zoneC"></div></body>', { runScripts: 'outside-only', url: 'https://ez.test/W1' });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.isOwner = !!owner;
  w.eval(CORE);
  return { w, WC: w.WallCore };
}
test('item 26: + icon on Topic and InfoMercial mother cards, never on a child', () => {
  const { WC } = boot();
  for (const zone of ['a', 'b', 'c']) {
    assert.equal(WC.ezCanInsert({ role: 'mother', zone }), true, zone);
  }
  assert.equal(WC.ezCanInsert({ role: 'child', zone: 'a' }), false);
});
test('item 26: - icon and pin on band children follow the same rules as Content', () => {
  const { WC } = boot(true);
  const kid = { role: 'child', zone: 'a', cid: 'k', mother_cid: 'm', status: 'approved' };
  assert.equal(WC.ezCanDelete(kid, { isOwner: true }), true);
  assert.equal(WC.ezCanPin(kid, { isOwner: true }), true);
  assert.equal(WC.ezCanPin({ ...kid, status: 'pending' }, { isOwner: true }), false);
});
test('item 26: children take their mother zone; shuffle still moves Content only', () => {
  const { WC } = boot();
  const L = WC.ezInheritZones([
    { _id: 'T', cid: 'T', role: 'mother', zone: 'a' }, { _id: 't1', cid: 't1', role: 'child', mother_cid: 'T', zone: 'b' },
    { _id: 'x', cid: 'x', role: 'mother', zone: 'b' }, { _id: 'y', cid: 'y', role: 'mother', zone: 'b' },
    { _id: 'C', cid: 'C', role: 'mother', zone: 'c' }, { _id: 'c1', cid: 'c1', role: 'child', mother_cid: 'C' }]);
  assert.deepEqual(L.map(o => o.zone), ['a', 'a', 'b', 'b', 'c', 'c']);
  for (let s = 0; s < 10; s++) {
    let k = s; const r = WC.ezShuffleFamilies(L, () => ((k = (k * 9301 + 49297) % 233280) / 233280)).map(o => o._id);
    assert.deepEqual(r.slice(0, 2), ['T', 't1']);
    assert.deepEqual(r.slice(-2), ['C', 'c1']);
  }
});
test('item 26 -> 29: band mother cards use the same right-hand rail as Content (pending / approved / pinned, +N, red dot)', () => {
  const { WC } = boot();
  const ks = Array.from({ length: 10 }, (_, i) => ({ _id: 'k' + i, cid: 'k' + i, role: 'child', ok: i % 2 === 0, pinned: i === 0, content: 'kid ' + i }));
  const r = WC.ezRailHTML({ cid: 'T', zone: 'a' }, ks, 200, false);
  assert.match(r.html, /class="bead ok pinned"/);
  assert.match(r.html, /class="bead pend"/);
  assert.match(r.html, /data-more="T"[^>]*>\+\d+</);
  assert.match(r.html, /class="bead-copy" data-copy="T"/);
  assert.equal((WC.ezRailHTML({ cid: 'T' }, ks, 200, true).html.match(/class="bead /g) || []).length, 10);
  assert.doesNotMatch(CORE, /band-beads|ezBandBeadsHTML/);
  assert.match(CORE, /\['wall','zoneA','zoneC'\]\.forEach\(function bindBeads/);
});
test('item 26: server has no zone lock for Co-WiKi any more', () => {
  assert.doesNotMatch(PHP, /'anchor_zone'|'mother_zone_locked'|'pin_zone'/);
  assert.match(PHP, /'zone'\s*=>\s*in_array\(\$mother->zone/);
});
test('item 27: the view switch is Cards/Beads only; system default Beads; reset item kept', () => {
  const { WC } = boot(true);
  for (const owner of [false, true]) {
    const m = WC.ezViewMenuHTML('cards', 'beads', owner, true, true);
    assert.doesNotMatch(m, /Fireworks|data-vm="fw"/);
    assert.match(m, /data-vm="reset">↺ Use the wall default \(Beads\)/);
  }
  assert.equal(WC.ezViewEffective(null, undefined), 'beads');
  assert.equal(WC.ezViewEffective(null, 'cards'), 'cards');
  assert.equal(WC.ezViewEffective('cards', 'beads'), 'cards');
  assert.match(CORE, /let VIEW_DEF='beads'/);
  assert.doesNotMatch(CORE, /a==='fw'/);
  assert.doesNotMatch(CORE, /g\('wiki_fx_fw2'\)/);
  assert.match(AIS, /view: res\.data\.view === 'cards' \? 'cards' : 'beads'/);
  assert.match(PHP, /\(\$meta\['view'\] \?\? 'beads'\) === 'cards' \? 'cards' : 'beads'/);
});
test('item 27 rev 2: the fireworks effect is removed entirely (code, sounds, setting usage)', () => {
  const { WC } = boot(true);
  for (const name of ['fireworks', 'fxSetFw', 'fwBtnHTML']) assert.equal(WC[name], undefined, name);
  assert.doesNotMatch(CORE, /fxFw|function fireworks|sfxLaunch|sfxBoom|FX_COLORS|fx-layer|fxFire|wiki_fx_fw2/);
  assert.equal('fw' in WC.fxState(), false);
  const PREV = fs.readFileSync(path.join(ROOT, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
  assert.doesNotMatch(PREV, /fireworks|fx-layer/);
  assert.doesNotMatch(AIS, /fireworks/);
  assert.doesNotMatch(PHP, /\$r->has\('fireworks'\)|'fireworks'\s*=>/);
  assert.match(WC.viewBtnHTML(), /id="viewBtn"/);
});
