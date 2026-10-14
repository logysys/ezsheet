// Item 11 (EZ-REL-20261014-1): Roy's W0000076 sheet with a second URL added in each place.
// The editor parser (same rules as app/Support/EzSheetParser.php) must give the new URL its
// own card in every case: after ---, after the Enter divider ━━━━, or on a bare line with no
// separator, in InfoMercial (above ###) or in Content. Retag (cid tags from memory) must leave
// the new card untagged so the server creates it, and must not move an existing tag onto it.
import test from 'node:test'; import assert from 'node:assert'; import fs from 'node:fs'; import vm from 'node:vm';
const src = fs.readFileSync(new URL('../../resources/js/components/wall-core.js', import.meta.url), 'utf8');
const pick = n => { const i = src.indexOf(n); assert.ok(i >= 0, n); return i; };
const ctx = {}; vm.runInNewContext(src.slice(pick('function ezStripTags('), pick('/* Drop the blocks with these cids')) + ';this.P=ezParseSheet;this.R=ezRetag;this.M=ezSheetMemory;', ctx);
const ALI = 'https://alibabacocreate.com/zh', WIKI = 'https://en.wikipedia.org/wiki/Wiki', YT = 'https://www.youtube.com/watch?v=kJQP7kiw5Fk',
  SP = 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT', NEW = 'https://yoursilentlaunch.com/eBook';
const TAGGED = `<!-- cid:1a4aeab2e5c085c6 -->\n${ALI}\n###\n<!-- cid:248c547f497bb924 -->\n${WIKI}\n---\n<!-- cid:9c6355ab6ab8426d -->\n${YT}\n---\n<!-- cid:4e71dc979ea213ab -->\n${SP}\n`;
const cases = {
  infomercial_with_sep: [[ALI, '---', NEW, '###', WIKI, '---', YT, '---', SP], 'c'],
  infomercial_bare_line: [[ALI, NEW, '###', WIKI, '---', YT, '---', SP], 'c'],
  infomercial_enter_divider: [[ALI, '━━━━━━━━', NEW, '###', WIKI, '---', YT, '---', SP], 'c'],
  infomercial_first: [[NEW, ALI, '###', WIKI, '---', YT, '---', SP], 'c'],
  content_with_sep: [[ALI, '###', WIKI, '---', NEW, '---', YT, '---', SP], 'b'],
  content_bare_line: [[ALI, '###', WIKI, NEW, '---', YT, '---', SP], 'b'],
  content_at_end: [[ALI, '###', WIKI, '---', YT, '---', SP, NEW], 'b'],
};
for (const [name, [lines, zone]] of Object.entries(cases)) {
  test('W0000076 + second URL: ' + name, () => {
    const text = lines.join('\n');
    const blocks = ctx.P(text);
    assert.equal(blocks.length, 5, 'five cards');
    const nb = blocks.find(b => b.raw === NEW);
    assert.ok(nb, 'the new URL is a card of its own'); assert.equal(nb.zone, zone);
    const tagged = ctx.R(text, ctx.M(TAGGED).mem);
    const tb = ctx.P(tagged);
    assert.equal(tb.find(b => b.raw === NEW).cid, null, 'new card stays untagged (server creates it)');
    assert.deepEqual(tb.filter(b => b.cid).map(b => b.raw).sort(), [ALI, WIKI, YT, SP].sort(), 'old cards keep their own tags');
  });
}
