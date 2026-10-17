// Item 15: media/YouTube display fixes.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '../..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');
const CORE = read('resources/js/components/wall-core.js');

function boot() {
  const dom = new JSDOM('<!DOCTYPE html><body class="ro"><div id="wall" class="masonry"></div></body>',
    { runScripts: 'outside-only', url: 'https://ez.test/w/W1', pretendToBeVisual: true });
  const w = dom.window;
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLCanvasElement.prototype.getContext = () => null;
  w.scrollTo = () => {};
  w.eval(CORE);
  return { w, WC: w.WallCore };
}

test('item15: YouTube iframe sends a referrer (strict-origin-when-cross-origin) and has a title', () => {
  assert.match(CORE, /youtube-nocookie\.com\/embed\/\$\{esc\(o\.id\)\}" allow="[^"]*" referrerpolicy="strict-origin-when-cross-origin" title="YouTube video"/);
  const { WC } = boot();
  const o = WC.parseOne('https://www.youtube.com/watch?v=TxM9wRCPW3U');
  assert.equal(o.type, 'youtube');
  assert.equal(o.id, 'TxM9wRCPW3U');
});

for (const f of ['resources/js/components/EzSheetPreview.tsx', 'resources/js/components/EzSheetEditorPanel.tsx']) {
  const src = read(f);
  test('item15/-3: ' + path.basename(f) + ' has no 15 s timeout', () => {
    assert.doesNotMatch(src, /xhr\.timeout = 15000/);
    assert.match(src, /xhr\.timeout = 0/);
  });
  test('item15: ' + path.basename(f) + ' video blocks use preload=metadata + an Open video link, absolute URLs', () => {
    assert.match(src, /<video src="[^>]*preload="metadata"/);
    assert.match(src, /Open video ↗/);
    assert.match(src, /ezUploadUrl\(url\)/); // item 35: absolute https (or relative on dev), never http://
  });
  test('item15: ' + path.basename(f) + ' only small images may fall back to an inline data: URL', () => {
    assert.match(src, /item\.kind === 'image' && item\.(file\.)?size <= 2 \* 1024 \* 1024/);
  });
}

test('-3: upload-ezsheet-file stays routed (homepage, signed-in only) and throttled', () => {
  for (const f of ['routes/api.php', 'routes/web.php']) assert.match(read(f), /upload-ezsheet-file'[^\n]*throttle:30,1/, f);
});

test('rel-2: no hard-coded dev user / wall in app.tsx; wall-card-only display restored', () => {
  const app = read('resources/js/app.tsx');
  assert.doesNotMatch(app, /indianstory555|SUJIT DAS|W0000002/);
  const v = read('resources/js/pages/AISearchView.tsx');
  assert.match(v, /wallCardContentMap\[targetWallId\] !== undefined/);
  assert.doesNotMatch(v, /Fall back to original raw text/);
});
