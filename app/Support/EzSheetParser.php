<?php
namespace App\Support;

/**
 * The ONE EzSheet separator parser.
 *
 * Rules (a boundary labels the text ABOVE it, back to the previous boundary):
 *   a line starting with ###  => blocks above it are zone 'c' (InfoMercial)
 *   a line starting with ===  => blocks above it are zone 'a' (Topic)
 *   text after the last boundary (or with no boundaries at all) => zone 'b' (Content)
 *
 * Inside a section, cards are split by: a dash rule (---, ━━━, ———), a bare URL line
 * (one card), or a <md>...</md> block (one card). Other consecutive lines form one card.
 *
 * Optional stable id: a line `<!-- cid:XYZ -->` directly before a card's text tags the
 * next card with cid XYZ (EzSheet does not emit this today; see README).
 *
 * Returns list of ['raw' => string, 'zone' => 'a'|'b'|'c', 'cid' => ?string] in sheet order.
 */
final class EzSheetParser
{
    public const CID_TAG = '/^<!--\s*cid:([A-Za-z0-9_-]{1,255})\s*-->$/';

    /** Remove every <!-- cid:... --> line. Used for downloads/exports. */
    public static function stripTags(string $text): string
    {
        return preg_replace('/^[ \t]*<!--\s*cid:[A-Za-z0-9_-]{1,255}\s*-->[ \t]*(\r\n|\r|\n|$)/m', '', $text);
    }

    public static function parse(string $text): array
    {
        $text = preg_replace('/<\/?(?:wiki|co-wiki|cowiki)\b[^>]*>/i', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', $text);

        $out = [];       // finished, zone-labelled blocks
        $pending = [];   // blocks since the last boundary (zone not yet known)
        $buffer = [];
        $inMd = false;
        $nextCid = null;

        $flush = function () use (&$buffer, &$pending, &$nextCid) {
            $raw = trim(implode("\n", $buffer));
            $buffer = [];
            if ($raw !== '') {
                $pending[] = ['raw' => $raw, 'cid' => $nextCid];
                $nextCid = null;
            }
        };
        $label = function (string $zone) use (&$pending, &$out) {
            foreach ($pending as $b) {
                $out[] = ['raw' => $b['raw'], 'zone' => $zone, 'cid' => $b['cid']];
            }
            $pending = [];
        };

        foreach ($lines as $line) {
            $t = trim($line);

            if ($inMd) {
                $buffer[] = $line;
                if (preg_match('/<\/md>\s*$/i', $t)) {
                    $inMd = false;
                    $flush();
                }
                continue;
            }
            if (preg_match('/^#{3,}/', $t)) { $flush(); $label('c'); continue; }
            if (preg_match('/^={3,}/', $t)) { $flush(); $label('a'); continue; }
            if (preg_match(self::CID_TAG, $t, $m)) { $flush(); $nextCid = $m[1]; continue; }
            if (preg_match('/^<md>/i', $t)) {
                $flush();
                $buffer[] = $line;
                if (preg_match('/<\/md>\s*$/i', $t) && strlen($t) > 4) {
                    $flush();
                } else {
                    $inMd = true;
                }
                continue;
            }
            if (preg_match('/^[━─—\-]{3,}$/u', $t)) { $flush(); continue; }
            if (preg_match('/^https?:\/\/\S+$/i', $t)) {
                $flush();
                $buffer[] = $t;
                $flush();
                continue;
            }
            if ($t === '') {
                continue;
            }
            $buffer[] = $line;
        }
        $flush();
        $label('b');

        return $out;
    }
}
