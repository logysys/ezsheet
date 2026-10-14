// One EzSheet editor for the wall EzBar panel and the homepage composer (change 8).
import test from 'node:test';import assert from 'node:assert';import fs from 'node:fs';import vm from 'node:vm';
const rd=p=>fs.readFileSync(new URL('../../resources/js/'+p,import.meta.url),'utf8');
const src=rd('components/ezsheet-editor.js'),core=rd('components/wall-core.js'),prev=rd('components/EzSheetPreview.tsx'),smc=rd('components/SocialMediaComposer.tsx'),panel=rd('components/EzSheetEditorPanel.tsx');
const G={};vm.runInNewContext(src,{window:G,globalThis:G});const E=G.EzSheetEditor;
test('markup has every piece; wall ids only when asked', () => {
  const w=E.html({ids:true,i18n:true,top:'<div id="metaFields"></div>',picsHTML:'<input id="picFile">'});
  for(const id of ['tabSheet','tabPics','taFind','taFindQ','taFindN','taFindList','paneSheet','input','taBar','taThumb','panePics','count','btnPush'])assert.match(w,new RegExp('id="'+id+'"'),id);
  assert.match(w,/data-i18n="hint"/);assert.match(w,/>===<\/button>/);assert.match(w,/>###<\/button>/);
  assert.ok(w.indexOf('metaFields')<w.indexOf('role="tablist"'));assert.match(w,/id="picFile"/);
  const h=E.html({labels:{push:'✓ Post'}});
  assert.doesNotMatch(h,/ id="/);assert.match(h,/✓ Post/);assert.match(h,/Filter cards \/ ### \/ ===/);assert.match(h,/Images &#38; 3D/);assert.match(h,/>Cancel</);
});
test('helpers match the server/core boundary rules', () => {
  assert.ok(E.isZone('=== x')&&E.isCZone('###')&&E.isSep('━━━')&&E.isSep('---')&&!E.isSep('--'));
  assert.ok(E.closed('<div><b>x</b></div>')&&!E.closed('<div><p>x'));
  assert.equal(E.SEP,'━'.repeat(30));
});
test('filter/count parser comes from wall-core itself', () => {
  const P=E.pureFromCore(core);assert.ok(P);
  const ix=P.ezSheetIndex('a\n---\nb\n===\nc');assert.equal(ix.filter(x=>x.kind==='card').length,3);
  assert.deepEqual(P.ezSheetFilter(ix,'===','').map(x=>x.line),[3]);
  assert.equal(P.ezParseSheet('a\n---\nb').length,2);
});
test('wall uses the shared editor (no second copy)', () => {
  assert.match(prev,/import \{ EZSHEET_EDITOR_SCRIPT, getEzSheetEditor \} from '\.\/ezsheetEditor'/);
  assert.match(prev,/<script>\$\{EZSHEET_EDITOR_SCRIPT\}<\/script>/);
  assert.match(prev,/getEzSheetEditor\(\)\.html\(\{ ids: true, i18n: true/);
  assert.match(prev,/EZED = EzSheetEditor\.mount\(document\.getElementById\('panel'\)/);
  for(const gone of ['lostpointercapture','function topOf','aria-controls="taFindList"','.ta-thumb{position'])assert.ok(!prev.includes(gone),'duplicate left in EzSheetPreview: '+gone);
  assert.ok(prev.indexOf('<script>${WALL_CORE_SCRIPT}</script>')<prev.indexOf('<script>${EZSHEET_EDITOR_SCRIPT}</script>'));
});
test('homepage composer uses the same editor and keeps its own post flow', () => {
  assert.match(smc,/import EzSheetEditorPanel from '\.\/EzSheetEditorPanel'/);
  assert.match(smc,/effectiveFormat === 'ezsheet' \? \(/);
  assert.match(smc,/onPush=\{handlePost\}/);
  assert.match(smc,/pushLabel=\{isSubmitting \? submittingText : `✓ \$\{buttonText\}`\}/);
  assert.match(smc,/\{effectiveFormat !== 'ezsheet' && \(\n\s*<button\n\s*onClick=\{handlePost\}/);
  assert.match(panel,/getEzSheetEditor\(\)/);assert.match(panel,/E\.mount\(root/);assert.match(panel,/import\('\.\/wall-core\.js\?raw'\)/);
  assert.match(panel,/escCancel: false/);
});
test('no closing script tag inside the inlined editor source', () => { assert.ok(!/<\/script/i.test(src)); });
