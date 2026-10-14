// Item 14: on a wall's custom subdomain (https://3619.ez.wiki) the ez.wiki login cookie is host-only,
// so the owner gets an "Edit on ez.wiki" link that opens the editor on the main host (?ezsheet=edit).
import test from 'node:test'; import assert from 'node:assert/strict'; import fs from 'node:fs';
import { ownerEditLink, wantsEditorOpen } from '../../resources/js/components/ownerEditLink.mjs';
const read = f => fs.readFileSync(new URL('../../' + f, import.meta.url), 'utf8');

test('item14: subdomain → main-host edit link; none on the main host', () => {
  assert.equal(ownerEditLink('3619.ez.wiki', 'X0003619'), 'https://ez.wiki/X0003619?ezsheet=edit');
  assert.equal(ownerEditLink('3619.EZ.WIKI.', 'X0003619'), 'https://ez.wiki/X0003619?ezsheet=edit');
  assert.equal(ownerEditLink('abc.oki.wiki', 'X1', 'http:'), 'http://oki.wiki/X1?ezsheet=edit');
  assert.equal(ownerEditLink('ez.wiki', 'X0003619'), null);
  assert.equal(ownerEditLink('www.ez.wiki', 'X0003619'), null);
  assert.equal(ownerEditLink('localhost', 'X1'), null);
  assert.equal(ownerEditLink('127.0.0.1', 'X1'), null);
  assert.equal(ownerEditLink('3619.ez.wiki', ''), null);
  assert.equal(ownerEditLink('3619.ez.wiki', 'ezsheet'), null);
  assert.equal(ownerEditLink('3619.ez.wiki', 'a b/../c'), 'https://ez.wiki/a%20b%2F..%2Fc?ezsheet=edit', 'slug is encoded, cannot change the path');
  assert.equal(ownerEditLink('3619.ez.wiki', 'X1', 'javascript:'), 'https://ez.wiki/X1?ezsheet=edit');
});

test('item14: ?ezsheet=edit is recognised exactly', () => {
  assert.equal(wantsEditorOpen('?ezsheet=edit'), true);
  assert.equal(wantsEditorOpen('?a=1&ezsheet=edit'), true);
  assert.equal(wantsEditorOpen('?ezsheet=view'), false);
  assert.equal(wantsEditorOpen(''), false);
});

test('item14: wiring: the link only for non-owners on a subdomain; the wall opens the editor only for the owner', () => {
  const v = read('resources/js/pages/AISearchView.tsx');
  assert.match(v, /\{!isOwner && typeof window !== 'undefined' && ownerEditLink\(window\.location\.hostname, message\.slug/);
  assert.match(v, /openEditor=\{typeof window !== 'undefined' && wantsEditorOpen\(window\.location\.search\)\}/);
  const p = read('resources/js/components/EzSheetPreview.tsx');
  assert.match(p, /window\.EZ_OPEN_EDITOR = \$\{JSON\.stringify\(!!openEditor\)\};/);
  assert.match(p, /if\(window\.isOwner\)\{ var ezLoad = loadSheet\(\); if\(window\.EZ_OPEN_EDITOR\) ezLoad\.then\(function\(\)\{ if\(window\.openPanel\) window\.openPanel\(\); \}\); \}/);
});

test('item14: no cookie-scope widening in the code (SESSION_DOMAIN stays host-only)', () => {
  const cfg = read('config/session.php');
  assert.match(cfg, /'domain' => env\('SESSION_DOMAIN'\)/);
});
