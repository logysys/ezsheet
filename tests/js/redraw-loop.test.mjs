// Live bugs X0003603 (OOM) / X0003604 (flicker): parent<->frame snapshot ping-pong and height damping.
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs'; import vm from 'node:vm';
const core = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
const ez = fs.readFileSync(new URL('../../resources/js/components/EzSheetPreview.tsx', import.meta.url), 'utf8');
const fn = n => { const i = core.indexOf('function ' + n + '('); assert.ok(i >= 0, n); let d = 0, j = core.indexOf('{', i);
  for (; j < core.length; j++) { if (core[j] === '{') d++; else if (core[j] === '}' && --d === 0) break; } return core.slice(i, j + 1); };
const ctx = {}; vm.runInNewContext(fn('ezHeightDamper') + ';' + fn('ezSnapSig') + ';' + fn('ezRevGate') + ';this.D=ezHeightDamper;this.S=ezSnapSig;this.G=ezRevGate;', ctx);
function damper(){ const q = []; const d = ctx.D(f => q.push(f)); const out = []; const cb = (f, h) => out.push(h);
  return { push: (f, h) => d.push(f, h, cb), frame: () => q.splice(0).forEach(f => f()), out }; }
test('height reports: rAF batch, last value wins, <2px ignored', () => {
  const D = damper(), f = {};
  D.push(f, 300); D.push(f, 310); D.push(f, 320); D.frame(); assert.deepEqual(D.out, [320]);
  D.push(f, 321); D.frame(); assert.deepEqual(D.out, [320]);
  D.push(f, 330); D.frame(); assert.deepEqual(D.out, [320, 330]);
});
test('height reports: A<->B oscillation gets pinned to the larger height', () => {
  const D = damper(), f = {};
  for (let i = 0; i < 20; i++) { D.push(f, i % 2 ? 400 : 380); D.frame(); }
  assert.ok(D.out.length <= 5, 'stopped oscillating: ' + D.out.length);
  assert.equal(D.out[D.out.length - 1], 400);
  D.push(f, 390); D.frame(); assert.equal(D.out[D.out.length - 1], 400, 'pinned does not shrink');
  D.push(f, 500); D.frame(); assert.equal(D.out[D.out.length - 1], 500, 'real growth still passes');
});
test('separate frames are independent', () => {
  const D = damper(), a = {}, b = {}; D.push(a, 100); D.push(b, 200); D.frame(); assert.deepEqual(D.out, [100, 200]);
});
test('identical snapshot (same rev, same cards) is a no-op and frame does not echo parent payloads', () => {
  const as = core.slice(core.indexOf('function applySnapshot(cards,rev,opt)'), core.indexOf('WallCore.applySnapshot=applySnapshot'));
  assert.match(as, /rev===gate\.rev&&sig===snapSig\)return false;/);
  assert.ok(as.indexOf('sig===snapSig') < as.indexOf('gate.accept(rev)'), 'dedupe before the gate');
  assert.match(as, /if\(!\(opt&&opt\.quiet\)\)try\{[^\n]*__ezsheetSnapshot/);
  assert.match(ez, /WallCore\.applySnapshot\(p\.cards, p\.rev, \{ quiet: true \}\)/);
  assert.match(ez, /if \(sig && sig === lastPostedRef\.current\) return;/);
  assert.equal(ctx.S([{ cid: 'a', raw: 'x' }]), ctx.S([{ cid: 'a', raw: 'x' }]));
  assert.notEqual(ctx.S([{ cid: 'a', raw: 'x' }]), ctx.S([{ cid: 'a', raw: 'y' }]));
});
test('ping-pong simulation terminates', () => {
  // frame: applySnapshot echoes unless identical/quiet; parent re-posts whatever it gets
  const gate = ctx.G(); let snap = '', renders = 0, posts = 0;
  const apply = (cards, rev, opt) => { const s = ctx.S(cards); if (rev === gate.rev && s === snap) return; if (!gate.accept(rev)) return;
    snap = s; renders++; if (!(opt && opt.quiet)) parent(cards, rev); };
  let last = ''; const parent = (cards, rev) => { const s = JSON.stringify({ cards, rev }); if (s === last) return; last = s; if (++posts > 100) throw new Error('loop'); apply(cards, rev, { quiet: true }); };
  apply([{ cid: 'a', raw: 'x' }], 0); apply([{ cid: 'a', raw: 'x' }], 0); parent([{ cid: 'a', raw: 'x' }], 0);
  assert.equal(renders, 1); assert.ok(posts <= 1);
});
