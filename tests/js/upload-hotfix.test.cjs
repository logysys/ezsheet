// EZ-REL-20261015-3-hotfix: front end asks guests to sign in and never silently inlines big files.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const read = f => fs.readFileSync(path.join(__dirname, '../..', f), 'utf8');
const PREV = read('resources/js/components/EzSheetPreview.tsx');
const PANEL = read('resources/js/components/EzSheetEditorPanel.tsx');

test('hotfix: media tab has a hidden "Sign in to upload" prompt shown for guests', () => {
  assert.match(PREV, /id="mediaSignIn" hidden[^>]*>🔒 Sign in to upload media\. <a href="\/login" target="_top"/);
  assert.match(PREV, /fetch\('\/api\/wall\/auth\/me'/);
  assert.match(PREV, /function ezShowSignIn\(\)/);
});
test('hotfix: 401 is handled (no fallback) in both uploaders', () => {
  assert.match(PREV, /xhr\.status === 401\)\{\s*window\.EZ_AUTHED = false; ezShowSignIn\(\);\s*failUpload\('Sign in to upload'\);/);
  assert.match(PREV, /uploadRes\.status === 401\)\{ upDenied = true;/);
  assert.match(PANEL, /xhr\.status === 401\) \{ failUpload\('Sign in to upload'\); return; \}/);
});
test('hotfix: only small images (or inlined HTML) may fall back to a data: URL; no 15 s timeout', () => {
  for (const s of [PREV, PANEL]) {
    assert.match(s, /item\.kind === 'image' && item\.(file\.)?size <= 2 \* 1024 \* 1024/);
    assert.doesNotMatch(s, /xhr\.timeout = 15000/);
  }
  assert.match(PREV, /if\(!fileUrl && !isHtmlFile && !\(type\.indexOf\('image\/'\) === 0 && file\.size <= 2 \* 1024 \* 1024\)\)\{/);
});
test('hotfix: homepage fetch sends the same-origin XHR header', () => {
  assert.match(PREV, /fetch\('\/api\/wall\/upload-ezsheet-file', \{\s*method: 'POST',\s*credentials: 'same-origin',\s*headers: (?:Object\.assign\()?\{ 'Content-Type': 'application\/json', 'X-Requested-With': 'XMLHttpRequest'/);
});
test('hotfix: upload routes are throttled 30/min; .htaccess blocks scripts', () => {
  const api = read('routes/api.php'), web = read('routes/web.php');
  for (const s of [api, web]) for (const l of s.split(/\r?\n/).filter(l => /'uploadEzsheetFile'|'uploadMedia'/.test(l))) assert.match(l, /throttle:30,1/, l);
  assert.match(read('public/upload/.htaccess'), /php_flag engine off/);
  assert.match(read('public/aicontent/.htaccess'), /php_flag engine off/);
});
