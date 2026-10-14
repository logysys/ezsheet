/* Syntax checks for the TSX files and for the editor script that EzSheetPreview
   writes into the iframe (it lives inside a template string, so tsc can't see it). */
const fs = require('fs'), path = require('path'), vm = require('vm');
const esbuild = require('esbuild');
const root = path.join(__dirname, '../..');
let bad = 0;
for (const f of ['resources/js/components/EzSheetPreview.tsx', 'resources/js/pages/AISearchView.tsx']) {
  const src = fs.readFileSync(path.join(root, f), 'utf8');
  try { esbuild.transformSync(src, { loader: 'tsx', jsx: 'preserve' }); console.log('tsx ok   ', f); }
  catch (e) { bad++; console.log('tsx FAIL ', f, e.message.slice(0, 400)); }
}
const tsx = fs.readFileSync(path.join(root, 'resources/js/components/EzSheetPreview.tsx'), 'utf8');
/* release-1013 change 8: the editor markup/behaviour comes from the shared ezsheet-editor.js */
const EZED_SRC = fs.readFileSync(path.join(root, 'resources/js/components/ezsheet-editor.js'), 'utf8');
const getEzSheetEditor = () => { const G = {}; vm.runInNewContext(EZED_SRC, { window: G, globalThis: G }); return G.EzSheetEditor; };
const a = tsx.indexOf('const doc = `'), b = tsx.indexOf('</html>`;', a);
const tpl = tsx.slice(a + 'const doc = '.length, b + '</html>`'.length);
const AH = fs.readFileSync(path.join(root, 'resources/js/components/ezAutoHeight.mjs'), 'utf8');
const AH_FN = AH.slice(AH.indexOf('function wallAutoHeightReporter'), AH.indexOf('\n}\n', AH.indexOf('function wallAutoHeightReporter')) + 2);
const scope = {
  title: 'T', EZSHEET_EDITOR_SCRIPT: EZED_SRC, getEzSheetEditor, WALL_CSS: '', ZDOG_SCRIPT: '', WALL_CORE_SCRIPT: '', editable: true, isOwner: true, json: '{}', spriteInit: '',
  resolvedAuth: {}, parentOrigin: '', resolvedDomains: [], wallId: 'W0000057', slug: 'x', messageId: 1, effectiveSyncUrl: '',
  frameCheckUrl: '', metaUrl: '', payload: { id: 'W0000057', lang: 'en', cols: 0, car: false, zones: null, zorder: null }, JSON,
  /* item 10: syntax-check the injected auto-height reporter too */
  autoHeight: true, frameless: true, openEditor: false, wallAutoHeightReporter: { toString: () => AH_FN },
};
const html = vm.runInNewContext(tpl, scope);
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
scripts.forEach((js, i) => {
  try { new vm.Script(js, { filename: 'iframe-script-' + i + '.js' }); console.log('iframe script', i, 'ok', js.length, 'chars'); }
  catch (e) { bad++; console.log('iframe script', i, 'FAIL', e.message); const m = /:(\d+)/.exec(e.stack || ''); console.log(e.stack.split('\n').slice(0, 3).join('\n')); }
});
fs.writeFileSync('/tmp/iframe-editor.js', scripts.filter(js => !js.includes('__ezWallDocH')).pop());
process.exit(bad ? 1 : 0);
