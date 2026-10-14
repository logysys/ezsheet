// EzSheet filter: block index (same parser as push) and matching.
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs'; import vm from 'node:vm';
const src = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
const pick = n => { const i = src.indexOf(n); assert.ok(i >= 0, n); return i; };
const code = src.slice(pick('function ezParseSheet('), pick('/* Server sheet (tagged)'));
const ctx = {}; vm.runInNewContext(code + ';this.I=ezSheetIndex;this.F=ezSheetFilter;this.P=ezParseSheet;', ctx);
const sheet = [
  'Topic one <b>Bold</b>',            // 0
  '━━━━━━━━',                         // 1
  '<div class="x"><p>Second TOPIC</p></div>', // 2
  '==============================',   // 3  labels 0 and 2 as Topic
  'https://example.com/ad',           // 4
  '##############################',   // 5  labels 4 as InfoMercial
  '<!-- cid:abc_123 -->',             // 6
  'Content card',                     // 7
  '',                                 // 8
  'still same card',                  // 9
  '---',                              // 10
  '<md>',                             // 11
  '### heading inside md',            // 12
  '</md>',                            // 13
].join('\n');
test('index: cards with zones/lines from ezParseSheet, plus separators', () => {
  const ix = ctx.I(sheet);
  const cards = ix.filter(b => b.kind === 'card'), seps = ix.filter(b => b.kind === 'sep');
  assert.deepEqual(cards.map(c => [c.line, c.zone]), ctx.P(sheet).map(b => [b.line, b.zone]).sort((a, b) => a[0] - b[0]));
  assert.deepEqual(cards.map(c => [c.line + 1, c.zname]), [[1,'Topic'],[3,'Topic'],[5,'InfoMercial'],[8,'Content'],[12,'Content']]);
  assert.equal(cards[1].preview, 'Second TOPIC');
  assert.equal(cards[0].preview, 'Topic one Bold');
  assert.equal(cards[3].cid, 'abc_123'); assert.equal(cards[3].end, 9);
  assert.deepEqual(seps.map(s => [s.line, s.mark]), [[3,'==='],[5,'###']], 'md heading is not a separator');
  assert.match(seps[0].preview, /2 cards above/); assert.match(seps[1].preview, /1 card above/);
  assert.deepEqual(ix.map(b => b.line), [...ix.map(b => b.line)].sort((a, b) => a - b));
});
test('filter: case-insensitive text, cid, separator chips and typed ### / ===', () => {
  const ix = ctx.I(sheet);
  assert.deepEqual(ctx.F(ix, 'second topic', '').map(b => b.line), [2]);
  assert.deepEqual(ctx.F(ix, 'ABC_1', '').map(b => b.line), [7]);
  assert.deepEqual(ctx.F(ix, '', 'c').map(b => b.line), [5]);
  assert.deepEqual(ctx.F(ix, '===', '').map(b => b.line), [3]);
  assert.deepEqual(ctx.F(ix, '###', '').map(b => b.line), [5]);
  assert.deepEqual(ctx.F(ix, '', ''), []);
  assert.deepEqual(ctx.F(ix, 'topic', 'a').map(b => b.line), [3]);
});
test('read-only and fast with 150 cards', () => {
  const big = Array.from({ length: 150 }, (_, i) => 'card ' + i + (i % 50 === 49 ? '\n===' : '')).join('\n---\n');
  const t = Date.now(); const ix = ctx.I(big); const r = ctx.F(ix, 'card 1', '');
  assert.ok(Date.now() - t < 200); assert.equal(ix.filter(b => b.kind === 'card').length, 150);
  assert.equal(r.length, 61); assert.equal(ctx.F(ix, '', 'a').length, 3);
});
