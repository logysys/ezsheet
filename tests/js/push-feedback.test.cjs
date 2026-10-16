/* Item 28: push feedback on the parent page, 30 s timeout, no double pushes, frame messages pinned. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '../..');
const SRC = fs.readFileSync(path.join(ROOT, 'resources/js/components/push-feedback.js'), 'utf8');
const AIS = fs.readFileSync(path.join(ROOT, 'resources/js/pages/AISearchView.tsx'), 'utf8');
const PREV = fs.readFileSync(path.join(ROOT, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
const CORE = fs.readFileSync(path.join(ROOT, 'resources/js/components/wall-core.js'), 'utf8');
const F = new Function(SRC.replace(/^export /gm, '') + '\nreturn { ezPushFeedback, ezPushErrorStatus, ezPushLang, EZ_PUSH_TIMEOUT_MS };')();
const cards = [
  { role: 'mother', zone: 'a' }, { role: 'mother', zone: 'b' }, { role: 'child', zone: 'b' },
  { role: 'mother', zone: 'b' }, { role: 'mother', zone: 'c' }];

test('success: Saved, with a count and the zones (en / zh-TW / zh-CN)', () => {
  const en = F.ezPushFeedback(200, { cards, stats: { created: 1, updated: 2 } }, 'en');
  assert.equal(en.ok, true);
  assert.equal(en.text, '✓ Saved: 4 WiKi cards (+1 new, 2 changed)');
  assert.deepEqual(en.lines, ['Topic 1 · Content 2 · InfoMercial 1']);
  const tw = F.ezPushFeedback(200, { cards, stats: { created: 1 } }, 'zh-TW');
  assert.equal(tw.text, '✓ 已儲存: 4 張 WiKi 卡 (新增 1)');
  assert.deepEqual(tw.lines, ['主題 1 · 內容 2 · 資訊廣告 1']);
  assert.match(F.ezPushFeedback(200, { cards }, 'zh-CN').text, /已保存/);
  assert.match(F.ezPushFeedback(200, { cards, not_added: [{}] }, 'en').lines[1], /1 not added/);
});
test('errors in plain words: 403, 419, 422, timeout/network, 5xx, busy', () => {
  assert.match(F.ezPushFeedback(403, { error: 'forbidden' }, 'en').text, /Only the wall owner/);
  assert.match(F.ezPushFeedback(403, {}, 'zh-TW').text, /只有牆的擁有者/);
  assert.match(F.ezPushFeedback(419, {}, 'en').text, /session expired.*refresh/i);
  assert.match(F.ezPushFeedback(419, {}, 'zh-TW').text, /重新整理/);
  const l = F.ezPushFeedback(422, { error: 'mother_zone_locked', cards: [{ raw: 'Wiki card one' }] }, 'en');
  assert.equal(l.ok, false); assert.equal(l.text, 'Nothing was saved:'); assert.match(l.lines[0], /Content zone: Wiki card one/);
  assert.deepEqual(F.ezPushFeedback(422, { errors: { raw: ['The raw field is required.'] } }, 'en').lines, ['The raw field is required.']);
  assert.deepEqual(F.ezPushFeedback(422, { message: 'Pending cards limit reached' }, 'en').lines, ['Pending cards limit reached']);
  for (const d of [{ error: 'timeout' }, { error: 'network' }, {}]) {
    assert.equal(F.ezPushFeedback(0, d, 'en').text, "Server didn't answer. Nothing may have been saved. Please try again.");
  }
  assert.match(F.ezPushFeedback(0, {}, 'zh-TW').text, /伺服器沒有回應/);
  assert.match(F.ezPushFeedback(504, {}, 'en').text, /Server error \(504\)/);
  assert.match(F.ezPushFeedback(409, { error: 'busy' }, 'en').text, /already running/);
});
test('axios errors: abort / timeout / no response -> status 0; HTTP errors keep status and body', () => {
  assert.deepEqual(F.ezPushErrorStatus({ code: 'ERR_CANCELED' }), { status: 0, data: { error: 'timeout' } });
  assert.deepEqual(F.ezPushErrorStatus({ code: 'ECONNABORTED' }), { status: 0, data: { error: 'timeout' } });
  assert.deepEqual(F.ezPushErrorStatus({ response: { status: 419, data: { message: 'CSRF token mismatch.' } } }), { status: 419, data: { message: 'CSRF token mismatch.' } });
  assert.equal(F.EZ_PUSH_TIMEOUT_MS, 30000);
  assert.equal(F.ezPushLang('zh-Hant-TW'), 'zh-TW'); assert.equal(F.ezPushLang('zh_TW'), 'zh-TW');
  assert.equal(F.ezPushLang('zh-CN'), 'zh-CN'); assert.equal(F.ezPushLang('ja'), 'en');
});
test('parent page: AbortController 30 s, one push per wall, feedback shown on the parent', () => {
  assert.match(AIS, /from '@\/components\/push-feedback\.js'/);
  assert.match(AIS, /new AbortController\(\)/);
  assert.match(AIS, /setTimeout\(\(\) => ctl\.abort\(\), EZ_PUSH_TIMEOUT_MS\)/);
  assert.match(AIS, /\{ signal: ctl\.signal, timeout: EZ_PUSH_TIMEOUT_MS \}/);
  assert.match(AIS, /if \(pushInFlightRef\.current\[wallId\]\)/);
  assert.match(AIS, /delete pushInFlightRef\.current\[wallId\]/);
  assert.match(AIS, /showPushFeedback\(status, body, lang\)/);
  assert.match(AIS, /whitespace-pre-line max-w-md" role="alert"/);
});
test('editor frame: Push button busy + spinner, no double push, language sent, messages pinned', () => {
  assert.match(PREV, /if\(pushBusy\)\{/);
  assert.match(PREV, /btn\.classList\.toggle\('busy', !!on\)/);
  assert.match(PREV, /\.tool-btn\.busy::after\{/);
  assert.match(PREV, /lang: \(WallCore\.getLang && WallCore\.getLang\(\)\) \|\| ''/);
  assert.match(PREV, /}, 35000\);/);
  assert.doesNotMatch(PREV, /45000/);
  assert.match(PREV, /if\(status === 419\)/);
  assert.match(PREV, /function toast\(m\)\{\n  var el=\$\('toast'\);if\(!el\) return;\n  try\{ var v=WallCore\.ezVisibleView\(window\)/);
  assert.match(PREV, /WallCore\.ezAnchorPopover\(box\.querySelector\('\.box'\),\{mode:'center'/);
  assert.match(CORE, /tEl\.style\.bottom=\(Math\.max\(0,innerHeight-v\.b\)\+24\)\+'px'/);
});
