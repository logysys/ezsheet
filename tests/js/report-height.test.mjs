// Regression: REPORT must not use documentElement.scrollHeight unconditionally
// (it is >= viewport height, so md/html cards could never shrink -> 600px bands).
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs';
const src = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
test('REPORT only uses scrollHeight on real overflow', () => {
  const line = src.split('\n').find(l => l.startsWith('const REPORT='));
  assert.ok(line, 'REPORT found');
  assert.ok(!line.includes('document.documentElement.scrollHeight,'), 'unconditional scrollHeight is back');
  assert.ok(line.includes('d.scrollHeight>d.clientHeight+1?d.scrollHeight:0'), 'overflow guard missing');
});
