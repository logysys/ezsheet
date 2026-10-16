<?php
namespace App\Http\Controllers\Api;

use App\Support\SafeUpload;

use App\Http\Controllers\Controller;
use App\Models\Wall;
use App\Models\WallCard;
use App\Models\AISearchHistory;
use App\Models\User;
use App\Models\EmailVerification;
use App\Models\Emaildesign;
use App\Mail\Eznew;
use App\Services\WallHasher;
use App\Support\EzSheetParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WallController extends Controller
{
    /* ---------- helpers ---------- */

    private function wall(string $slug, bool $create = true): ?Wall
    {
        $clean = trim(urldecode($slug));
        if (is_numeric($clean)) {
            $byPk = Wall::find((int) $clean);
            if ($byPk) {
                return $byPk;
            }
        }

        // Try direct find first
        $existing = Wall::where('slug', $clean)->first();
        if ($existing) {
            return $existing;
        }

        if (!$create) {
            return null;
        }

        // Sanitize to valid 64-char slug for database column
        $safeSlug = preg_replace('/[^A-Za-z0-9_-]/', '', $clean);
        if (strlen($safeSlug) < 2 || strlen($safeSlug) > 64) {
            $safeSlug = substr($safeSlug, 0, 48) . '-' . substr(md5($clean), 0, 8);
        }
        if (strlen($safeSlug) > 64) {
            $safeSlug = substr($safeSlug, 0, 64);
        }

        return Wall::firstOrCreate(['slug' => $safeSlug]);
    }

    private function meHash(Request $r): ?string
    {
        $me = (string) $r->header('x-wall-me', $r->input('me', ''));
        if (preg_match('/^[a-z0-9-]{8,64}$/i', $me)) {
            return WallHasher::me($me);
        }
        $ip = $r->ip() ?? '127.0.0.1';
        return WallHasher::me('me-' . substr(md5($ip . '_wall'), 0, 16));
    }

    private function isOwner(Wall $w, Request $r): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user() ?? $r->user();
        if ($user && !$this->sameSiteRequest($r)) {
            $user = null;
        }
        if ($user) {
            $userId = (int)$user->id;
            $meta = is_string($w->meta) ? json_decode($w->meta, true) : (array) $w->meta;
            if (isset($meta['user_id']) && (int)$meta['user_id'] === $userId) {
                return true;
            }
            $search = \App\Models\AISearchHistory::where('slug', $w->slug)
                ->orWhere('conversation_id', $w->slug)
                ->orWhere('id', $w->slug)
                ->orWhere('social_media_metadata->wall_id', $w->slug)
                ->first();
            if ($search && (int)$search->user_id === $userId) {
                return true;
            }
            $reqSlug = $r->input('slug') ?: $r->header('x-wall-slug');
            if ($reqSlug) {
                $searchBySlug = \App\Models\AISearchHistory::where('slug', $reqSlug)
                    ->orWhere('conversation_id', $reqSlug)
                    ->first();
                if ($searchBySlug && (int)$searchBySlug->user_id === $userId) {
                    return true;
                }
            }
            if (empty($w->owner_hash)) {
                return true;
            }
        }

        $tok = (string) $r->header('x-wall-owner', '');
        if (!$w->owner_hash || $tok === '') {
            return false;
        }
        return hash_equals($w->owner_hash, WallHasher::owner($tok));
    }

    /** EZ-REL-20261016-3: strict same-ORIGIN for state-changing requests.
     *  Sec-Fetch-Site must be 'same-origin' (or 'none'); 'same-site' (e.g. a sibling
     *  subdomain like evil.ez.wiki) is refused. If Origin is sent it must equal this
     *  request's own host. Each fleet host is checked for its own origin. */
    private function sameSiteRequest(Request $r): bool
    {
        if (in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $site = strtolower((string) $r->header('sec-fetch-site', ''));
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            return false;
        }
        $origin = (string) $r->header('origin', '');
        if ($origin === '') {
            return true;   // old browsers / non-browser clients: other checks (auth, owner) still apply
        }
        // exact host (not just the registrable domain). Scheme/port are not compared because
        // behind nginx/Cloudflare the app may see http while the browser sends https.
        $host = parse_url($origin, PHP_URL_HOST);
        return is_string($host) && $host !== '' && strcasecmp($host, $r->getHost()) === 0;
    }

    /** Logged-in / guest-session / guest-token user id. Never trusts a client-sent user_id. */
    private function currentUserId(Request $r): ?int
    {
        $userId = Auth::id() ?? ($r->user() ? $r->user()->id : null);
        if (!$userId && $r->hasSession()) {
            $guestUser = $r->session()->get('wall_guest_user');
            if (!empty($guestUser['id'])) {
                $userId = (int) $guestUser['id'];
            }
        }
        if (!$userId) {
            $tok = $r->header('x-guest-token', $r->header('x-user-token', ''));
            if (!empty($tok)) {
                $cachedUser = Cache::get('guest_token_' . $tok);
                if (!empty($cachedUser['id'])) {
                    $userId = (int) $cachedUser['id'];
                }
            }
        }
        return $userId ? (int) $userId : null;
    }

    private function toTimestampMs($date): ?int
    {
        if (!$date) return null;
        if (is_object($date) && method_exists($date, 'getTimestamp')) {
            return $date->getTimestamp() * 1000;
        }
        if (is_numeric($date)) {
            $num = (int) $date;
            return $num > 10000000000 ? $num : $num * 1000;
        }
        $ts = strtotime((string) $date);
        return $ts !== false ? $ts * 1000 : null;
    }

    private function cardJson(WallCard $c): array
    {
        $at = $this->toTimestampMs($c->created_at) ?: (int) (microtime(true) * 1000);

        $status = $c->status === 'pending' ? 'pending' : 'approved';
        $isMother = $c->isMother();
        // Role comes from mother_id/anchor.
        $origin = $isMother ? ($c->origin ?: 'wiki') : 'cowiki';
        $content = (string) ($c->content ?? '');

        return [
            'cid'         => (string) $c->cid,
            'content'     => $content,
            'anchor'      => $c->anchor ? (string) $c->anchor : null,
            'mother_id'   => $c->mother_id ? (int) $c->mother_id : null,
            'role'        => $isMother ? 'mother' : 'child',
            'zone'        => (string) ($c->zone ?? 'b'),
            'origin'      => (string) $origin,
            'status'      => $status,
            // DEPRECATED (pass 4): legacy keys for old cached clients, derived from
            // content/status/role. The DB columns are gone. Remove these in the next release.
            'raw'         => $content,
            'ok'          => $status === 'approved',
            'type'        => $isMother ? 'wiki' : 'cowiki',
            'user_id'     => $c->user_id ? (int) $c->user_id : null,
            'position'    => (int) ($c->position ?? 0),
            'at'          => (int) $at,
            'approved_at' => $this->toTimestampMs($c->approved_at),
            'ip'          => substr((string) ($c->ip_hash ?? ''), 0, 8),
            'author'      => $this->authorName($c->user_id),
        ];
    }

    /** item 16: first word of the author's name for the bead preview (null for anonymous). */
    private function authorName($userId): ?string
    {
        if (!$userId) {
            return null;
        }
        // per-request cache (no static: long-running workers would serve stale names)
        $bag = request()->attributes;
        $names = (array) $bag->get('ez_author_names', []);
        if (!array_key_exists($userId, $names)) {
            $full = trim((string) \App\Models\User::whereKey($userId)->value('name'));
            $names[$userId] = $full === '' ? null : mb_substr(preg_split('/\s+/', $full)[0], 0, 24);
            $bag->set('ez_author_names', $names);
        }
        return $names[$userId];
    }

    /**
     * Where Co-WiKi children sit relative to their mother in the flat list.
     * 'after'  => mother, newest child, ..., oldest child
     * 'before' => oldest child, ..., newest child, mother   (current wall-core.js
     *             placeGuest() inserts guests BEFORE the anchor card)
     * Either way the newest child is adjacent to the mother. Flip here only.
     */
    public const CHILDREN_PLACEMENT = 'after';

    private const ZONE_ORDER = ['a' => 0, 'b' => 1, 'c' => 2];

    /** item 19: why a card cannot be pinned, or null when it can. */
    private function pinRefusal(WallCard $c, ?WallCard $mother = null): ?string
    {
        // item 26: pinning works in every zone (Topic, Content, InfoMercial); a pin only
        // reorders inside its own zone because ZONE_ORDER is sorted first.
        if ($c->isMother()) {
            return null;
        }
        if ($c->status === 'pending') {
            return 'pin_not_approved';
        }
        if (!$mother) {
            return 'pin_no_mother';
        }
        return null;
    }

    private function motherOf(WallCard $c, $all = null): ?WallCard
    {
        if ($c->isMother()) {
            return null;
        }
        $list = $all ?? WallCard::where('wall_id', $c->wall_id)->get();
        foreach ($list as $m) {
            if ($m->isMother() && (($c->mother_id && (int) $m->id === (int) $c->mother_id) || (!$c->mother_id && $c->anchor && (string) $m->cid === (string) $c->anchor))) {
                return $m;
            }
        }
        return null;
    }

    /** item 19: keep only pins whose card still exists and is pinnable. */
    private function prunePins(Wall $w, array $pins): array
    {
        $all = WallCard::where('wall_id', $w->id)->get();
        $byCid = $all->keyBy(fn ($c) => (string) $c->cid);
        $out = [];
        foreach ($pins as $cid => $at) {
            $c = $byCid[(string) $cid] ?? null;
            if ($c && $this->pinRefusal($c, $this->motherOf($c, $all)) === null) {
                $out[(string) $cid] = (int) $at;
            }
        }
        return $out;
    }

    private function pinsOf(Wall $w): array
    {
        $meta = Wall::whereKey($w->id)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
        return is_array($meta['pins'] ?? null) ? $meta['pins'] : [];
    }

    /** The one canonical ordering, used by getCards, push, every write response, and SSE.
     *  item 19: pinned Content mothers first (newest pin first), each with its whole family;
     *  a pinned (approved) child sits right after its mother. */
    private function orderedCards(Wall $w): array
    {
        $all = WallCard::where('wall_id', $w->id)->get();
        $pins = $this->pinsOf($w);
        if ($pins) {
            $pins = array_filter($pins, function ($at, $cid) use ($all) {
                $c = $all->first(fn ($x) => (string) $x->cid === (string) $cid);
                return $c && $this->pinRefusal($c, $this->motherOf($c, $all)) === null;
            }, ARRAY_FILTER_USE_BOTH);
        }
        $pinOf = fn ($c) => (int) ($pins[(string) $c->cid] ?? 0);
        $mothers = $all->filter(fn ($c) => $c->isMother())
            ->sortBy(fn ($c) => sprintf('%d-%d-%015d-%010d-%012d', self::ZONE_ORDER[$c->zone] ?? 9, $pinOf($c) ? 0 : 1, $pinOf($c) ? 999999999999999 - $pinOf($c) : 0, (int) $c->position, $c->id));
        $byMother = [];
        $orphans = [];
        $motherCids = $mothers->pluck('id', 'cid');
        foreach ($all as $c) {
            if ($c->isMother()) continue;
            $mid = $c->mother_id ?: ($motherCids[$c->anchor] ?? null);
            if ($mid) { $byMother[$mid][] = $c; } else { $orphans[] = $c; }
        }
        $cardTs = fn ($c) => $this->toTimestampMs($c->created_at) ?? 0;
        $newest = fn ($a, $b) => [$pinOf($b), $cardTs($b), $b->id] <=> [$pinOf($a), $cardTs($a), $a->id];

        $out = [];
        $zoneOf = [];   // item 26: a child is emitted in its mother's zone (legacy rows may say 'b')
        foreach ($mothers as $m) {
            $kids = $byMother[$m->id] ?? [];
            usort($kids, $newest);
            foreach ($kids as $k) $zoneOf[$k->id] = (string) ($m->zone ?: 'b');
            if (self::CHILDREN_PLACEMENT === 'before') {
                foreach (array_reverse($kids) as $k) $out[] = $k;
                $out[] = $m;
            } else {
                $out[] = $m;
                foreach ($kids as $k) $out[] = $k;
            }
        }
        // Children whose mother is gone/unknown (legacy data): at the end, newest first.
        usort($orphans, $newest);
        foreach ($orphans as $o) $out[] = $o;

        return array_map(function ($c) use ($pinOf, $zoneOf) {
            $j = $this->cardJson($c);
            if (isset($zoneOf[$c->id])) $j['zone'] = $zoneOf[$c->id];
            $j['pinned'] = $pinOf($c) ?: null;
            return $j;
        }, $out);
    }

    private function rev(Wall $w): int
    {
        $meta = Wall::whereKey($w->id)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
        return (int) ($meta['rev'] ?? 0);
    }

    /** Increment walls.meta.rev under a row lock. Call inside the write transaction. */
    private function bumpRev(Wall $w, ?callable $mutate = null): int
    {
        $locked = Wall::whereKey($w->id)->lockForUpdate()->first();
        $meta = $locked->meta ?? [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        if ($mutate) {
            $meta = $mutate($meta);
        }
        // item 19: a pin only survives while its card is pinnable (deleted / unapproved / moved => cleared)
        if (!empty($meta['pins']) && is_array($meta['pins'])) {
            $meta['pins'] = $this->prunePins($w, $meta['pins']);
            if (!$meta['pins']) {
                unset($meta['pins']);
            }
        }
        $meta['rev'] = (int) ($meta['rev'] ?? 0) + 1;
        $locked->meta = $meta;
        $locked->save();
        $w->meta = $meta;
        return $meta['rev'];
    }

    /** Full list + rev: the only shape sent to clients after any write and on SSE. */
    private function snapshot(Wall $w): array
    {
        $cards = $this->orderedCards($w);
        return [
            'wall_id' => $w->id,
            'slug'    => $w->slug,
            'rev'     => $this->rev($w),
            'count'   => count($cards),
            'order'   => array_column($cards, 'cid'),
            'cards'   => $cards,
        ];
    }

    /**
     * Bump the wall's monotonic version so connected SSE clients can detect
     * change without polling the cards table for every event type.
     */
    private function touch(Wall $w): void
    {
        Cache::put("wall:{$w->id}:v", microtime(true), 3600);
    }

    private function version(Wall $w): float
    {
        return (float) (Cache::get("wall:{$w->id}:v") ?? 0);
    }

    /* ---------- GET /api/wall/{id}/stream (SSE) ---------- */

    public function stream(Request $r, string $slug): StreamedResponse
    {
        $targetId = $r->input('wall_id') ?: $slug;
        $wall   = $this->wall((string) $targetId, false);
        if (!$wall) {
            return new StreamedResponse(function () {
                echo "event: error\ndata: " . json_encode(['error' => 'not_found', 'message' => 'Wall not found']) . "\n\n";
            }, 404, ['Content-Type' => 'text/event-stream']);
        }

        return response()->stream(function () use ($wall) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @set_time_limit(0);

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no'); // nginx: don't buffer SSE

            $last    = time();
            $lastRev = -1;
            $lastVer = -1.0;

            // Every change is announced as ONE full 'sync' (same list/order as getCards)
            // carrying `rev`. No partial 'card'/'drop' events, so clients can simply
            // replace their list and ignore any snapshot with rev <= the one they hold.
            while (!connection_aborted()) {
                $rev = $this->rev($wall);
                $ver = $this->version($wall);
                if ($rev !== $lastRev || $ver > $lastVer) {
                    $snap = $this->snapshot($wall);
                    $this->emit('sync', $snap + ['authoritative' => true, 'v' => $ver], $snap['rev']);
                    $lastRev = $snap['rev'];
                    $lastVer = $ver;
                }

                if (time() - $last >= 20) {
                    echo ": ping\n\n";
                    @ob_flush();
                    @flush();
                    $last = time();
                }

                sleep(2);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function emit(string $event, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush();
        @flush();
    }

    /* ---------- GET /api/wall/{id}/cards ---------- */

    public function getCards(Request $r, string $slug)
    {
        try {
            $targetId = $r->input('wall_id') ?: $slug;
            $wall = $this->wall((string) $targetId, false);
            if (!$wall) {
                return response()->json([
                    'success' => false,
                    'error'   => 'not_found',
                    'message' => 'Wall not found',
                    'cards'   => [],
                    'order'   => [],
                    'count'   => 0,
                ], 404);
            }

            $snap = $this->snapshot($wall);

            $meta = $wall->meta;
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            } elseif (!is_array($meta)) {
                $meta = [];
            }
            $zones = is_array($meta['zones'] ?? null) ? $meta['zones'] : [];

            return response()->json([
                'success'     => true,
                'wall_id'     => $wall->id,
                'slug'        => $wall->slug,
                'rev'         => $snap['rev'],
                'count'       => $snap['count'],
                'order'       => $snap['order'],
                'cards'       => $snap['cards'],
                'layout'      => $wall->layout ?? ($meta['layout'] ?? null),
                'carousel'    => $wall->carousel ?? ($meta['carousel'] ?? ($meta['car'] ?? false)),
                'topic'       => $wall->topic ?? ($meta['topic'] ?? ($zones['a'] ?? true)),
                'content'     => $wall->content ?? ($meta['content'] ?? ($zones['b'] ?? true)),
                'infomercial' => $wall->infomercial ?? ($meta['infomercial'] ?? ($zones['c'] ?? true)),
                'flybees'     => $wall->flybees ?? ($meta['flybees'] ?? null),
                'sound'       => $wall->sound ?? ($meta['sound'] ?? null),
                'language'    => $wall->language ?? ($meta['language'] ?? null),
                // item 27: system default is Beads; only an explicit owner 'cards' shows Cards
                'view'        => ($meta['view'] ?? 'beads') === 'cards' ? 'cards' : 'beads',
                'meta'        => $meta,
            ]);
        } catch (\Throwable $e) {
            \Log::error('getCards error: ' . $e->getMessage());
            return response()->json([
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* ---------- POST /api/wall/{id}/settings ---------- */

    public function saveSettings(Request $r, string $slug)
    {
        try {
            $targetId = $r->input('wall_id') ?: $slug;
            $wall = $this->wall((string) $targetId);

            // Check if user is owner
            if (!$this->isOwner($wall, $r)) {
                return response()->json([
                    'error'   => 'unauthorized',
                    'message' => 'Only the wall owner can change settings',
                ], 403);
            }

            $meta = $wall->meta ?? [];
            if (!isset($meta['fx'])) {
                $meta['fx'] = [];
            }
            if (!isset($meta['zones'])) {
                $meta['zones'] = ['a' => true, 'b' => true, 'c' => true];
            }

            // 1. Layout
            if ($r->has('layout')) {
                $layout = (string) $r->input('layout');
                $wall->layout = $layout;
                $meta['layout'] = $layout;
                if (is_numeric($layout)) {
                    $meta['cols'] = (int) $layout;
                }
            }

            // 2. Carousel on/off
            if ($r->has('carousel') || $r->has('carousal')) {
                $carVal = $r->has('carousel') ? $r->input('carousel') : $r->input('carousal');
                $car = filter_var($carVal, FILTER_VALIDATE_BOOLEAN);
                $wall->carousel = $car;
                $meta['carousel'] = $car;
                $meta['car'] = $car;
            }

            // 3. Topic on/off (Zone A)
            if ($r->has('topic')) {
                $top = filter_var($r->input('topic'), FILTER_VALIDATE_BOOLEAN);
                $wall->topic = $top;
                $meta['topic'] = $top;
                $meta['zones']['a'] = $top;
            }

            // 4. Content on/off (Zone B)
            if ($r->has('content')) {
                $cnt = filter_var($r->input('content'), FILTER_VALIDATE_BOOLEAN);
                $wall->content = $cnt;
                $meta['content'] = $cnt;
                $meta['zones']['b'] = $cnt;
            }

            // 5. InfoMercial on/off (Zone C)
            if ($r->has('infomercial')) {
                $info = filter_var($r->input('infomercial'), FILTER_VALIDATE_BOOLEAN);
                $wall->infomercial = $info;
                $meta['infomercial'] = $info;
                $meta['zones']['c'] = $info;
            }

            // Zones map handling
            if ($r->has('zones')) {
                $zones = (array) $r->input('zones');
                if (isset($zones['a'])) {
                    $wall->topic = filter_var($zones['a'], FILTER_VALIDATE_BOOLEAN);
                    $meta['topic'] = $wall->topic;
                    $meta['zones']['a'] = $wall->topic;
                }
                if (isset($zones['b'])) {
                    $wall->content = filter_var($zones['b'], FILTER_VALIDATE_BOOLEAN);
                    $meta['content'] = $wall->content;
                    $meta['zones']['b'] = $wall->content;
                }
                if (isset($zones['c'])) {
                    $wall->infomercial = filter_var($zones['c'], FILTER_VALIDATE_BOOLEAN);
                    $meta['infomercial'] = $wall->infomercial;
                    $meta['zones']['c'] = $wall->infomercial;
                }
            }

            // 6. (removed in EZ-REL-20261016-5 rev 2) Fireworks: the effect is gone; a 'fireworks'
            //    input is ignored and stored values are left alone.

            // 7. Flybees on/off
            if ($r->has('flybees')) {
                $fly = filter_var($r->input('flybees'), FILTER_VALIDATE_BOOLEAN);
                $wall->flybees = $fly;
                $meta['flybees'] = $fly;
                $meta['fx']['fly'] = $fly;
            }

            // 8. Sound on/off
            if ($r->has('sound')) {
                $snd = filter_var($r->input('sound'), FILTER_VALIDATE_BOOLEAN);
                $wall->sound = $snd;
                $meta['sound'] = $snd;
                $meta['fx']['snd'] = $snd;
            }

            // item 16: display view (owner-set), Cards (default) or Beads
            if ($r->has('view')) {
                $view = (string) $r->input('view');
                if (in_array($view, ['cards', 'beads'], true)) { // item 22: unknown values are ignored
                    $meta['view'] = $view;
                }
            }

            // 9. Language selected
            if ($r->has('language') || $r->has('lang')) {
                $lang = (string) ($r->input('language') ?: $r->input('lang'));
                $wall->language = $lang;
                $meta['language'] = $lang;
                $meta['lang'] = $lang;
            }

            $meta['rev'] = $this->rev($wall) + 1; // settings writes also bump rev
            $wall->meta = $meta;
            $wall->save();
            $this->touch($wall);

            Log::info("Saved wall settings for {$wall->slug}: layout={$wall->layout}, carousel={$wall->carousel}, topic={$wall->topic}, content={$wall->content}, infomercial={$wall->infomercial}, lang={$wall->language}");

            return response()->json([
                'success' => true,
                'message' => 'Wall settings saved successfully to wall table in database',
                'wall'    => [
                    'id'          => $wall->id,
                    'slug'        => $wall->slug,
                    'layout'      => $wall->layout,
                    'carousel'    => (bool)$wall->carousel,
                    'topic'       => (bool)$wall->topic,
                    'content'     => (bool)$wall->content,
                    'infomercial' => (bool)$wall->infomercial,
                    'flybees'     => (bool)$wall->flybees,
                    'sound'       => (bool)$wall->sound,
                    'language'    => $wall->language,
                    'meta'        => $wall->meta,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('saveSettings error: ' . $e->getMessage());
            return response()->json([
                'error'   => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* ---------- POST /api/wall/{id}/cards ---------- */

    public function addCard(Request $r, string $slug)
    {
        $targetId = $r->input('wall_id') ?: $slug;
        $wall = $this->wall((string) $targetId, false);
        if (!$wall) {
            return response()->json(['error' => 'not-found'], 404);
        }

        // Rate limit: 200 cards per IP per hour.
        $key = 'wall-add:' . $wall->id . ':' . ($r->ip() ?? 'x');
        if (RateLimiter::tooManyAttempts($key, 200)) {
            return response()->json(['error' => 'rate'], 429);
        }
        RateLimiter::hit($key, 3600);

        $raw = (string) $r->input('raw', '');
        if ($raw === '' || strlen($raw) > 60000) {
            return response()->json(['error' => 'too-big'], 413);
        }
        if (!$this->looksRenderable($raw)) {
            return response()->json(['error' => 'unrenderable'], 422);
        }

        // addCard only creates Co-WiKi children. Mothers come from EzSheet push.
        // Client `type`, `zone`, `position`, `target_position`, `user_id` are ignored.
        $anchorCid = trim((string) ($r->input('anchor_cid') ?: $r->input('anchor') ?: $r->input('before_cid', '')));
        if ($anchorCid === '') {
            return response()->json([
                'error'   => 'anchor_required',
                'message' => 'A Co-WiKi card must be attached to a WiKi card. WiKi cards are added in EzSheet.',
            ], 422);
        }
        $mother = WallCard::where('wall_id', $wall->id)->where('cid', $anchorCid)->first();
        if (!$mother) {
            return response()->json(['error' => 'anchor_not_found', 'message' => 'That card no longer exists. Reload the wall.'], 422);
        }
        if (!$mother->isMother()) {
            return response()->json(['error' => 'anchor_not_mother', 'message' => 'Co-WiKi cards attach to a WiKi card, not to another Co-WiKi card.'], 422);
        }
        // item 26: Co-WiKi works on WiKi cards in all three zones (no anchor_zone check any more).

        // Approved cards do not count toward the wall's limit (500 pending max).
        $pendingCount = WallCard::where('wall_id', $wall->id)
            ->where(function ($q) {
                $q->where('status', 'pending');
            })
            ->count();
        if ($pendingCount >= 500) {
            return response()->json([
                'error'   => 'limit_exceeded',
                'message' => 'Pending cards limit reached (maximum 500 pending cards allowed). Approved cards do not count toward this limit.',
            ], 422);
        }

        $card = $this->createChild($wall, $mother, $raw, $r);
        if (!$card) {
            return response()->json(['error' => 'db_error', 'message' => 'Could not save card'], 500);
        }

        $this->touch($wall);

        return response()->json(['success' => true, 'card' => $this->cardJson($card)] + $this->snapshot($wall), 201);
    }

    /** One Co-WiKi child under $mother: owner = approved, everyone else = pending. */
    private function createChild(Wall $wall, WallCard $mother, string $raw, Request $r): ?WallCard
    {
        $isOwner = $this->isOwner($wall, $r);
        $userId = $this->currentUserId($r);
        $me = $this->meHash($r);

        try {
            return DB::transaction(function () use ($wall, $mother, $userId, $me, $r, $raw, $isOwner) {
                $card = WallCard::create([
                    'wall_id'     => $wall->id,
                    'user_id'     => $userId,
                    'cid'         => WallHasher::cid(),
                    'me_hash'     => $me,
                    'ip_hash'     => WallHasher::ip($r->ip() ?? ''),
                    'content'     => $raw,
                    'anchor'      => (string) $mother->cid,
                    'mother_id'   => $mother->id,
                    'zone'        => in_array($mother->zone, ['a', 'b', 'c'], true) ? $mother->zone : 'b', // item 26
                    'origin'      => 'cowiki',
                    // Owner-written Co-WiKi cards are auto-approved; guests start pending.
                    'status'      => $isOwner ? 'approved' : 'pending',
                    'approved_at' => $isOwner ? now() : null,
                    'position'    => 0,
                ]);
                $this->bumpRev($wall);
                return $card;
            });
        } catch (\Throwable $e) {
            \Log::error('WallCard::create failed: ' . $e->getMessage());
            return null;
        }
    }

    /* ---------- POST /api/wall/{id}/cards/{cid}/media (item 18) ----------
       Signed-in users upload one media file as a new Co-WiKi child of the WiKi
       card {cid}. Same rules as addCard: Content-zone mothers only, pending until
       the owner approves (owner uploads are approved). Never creates a wall or a mother. */
    public function uploadCowikiMedia(Request $r, string $slug, string $cid)
    {
        if ($deny = SafeUpload::guard($r)) {
            return $deny;
        }
        $wall = $this->wall($slug, false);
        if (!$wall) {
            return response()->json(['error' => 'not-found'], 404);
        }
        $meta = is_array($wall->meta) ? $wall->meta : [];
        if (($meta['cowiki'] ?? true) === false) {
            return response()->json(['error' => 'cowiki_off', 'message' => 'This wall does not accept Co-WiKi cards.'], 403);
        }
        $mother = WallCard::where('wall_id', $wall->id)->where('cid', $cid)->first();
        if (!$mother) {
            return response()->json(['error' => 'anchor_not_found', 'message' => 'That card no longer exists. Reload the wall.'], 422);
        }
        if (!$mother->isMother()) {
            return response()->json(['error' => 'anchor_not_mother', 'message' => 'Co-WiKi cards attach to a WiKi card, not to another Co-WiKi card.'], 422);
        }
        // item 26: media Co-WiKi also works in all three zones.
        if (WallCard::where('wall_id', $wall->id)->where('status', 'pending')->count() >= 500) {
            return response()->json(['error' => 'limit_exceeded', 'message' => 'Pending cards limit reached (maximum 500 pending cards allowed).'], 422);
        }
        if (!$r->hasFile('file')) {
            return response()->json(['error' => 'no_file', 'message' => 'No file provided.'], 422);
        }
        $res = SafeUpload::store($r->file('file'), 'cowiki');
        if (!is_array($res)) {
            return response()->json(['error' => 'bad_file', 'message' => $res], 422);
        }
        $card = $this->createChild($wall, $mother, self::mediaRaw($res, (string) $r->file('file')->getClientOriginalName()), $r);
        if (!$card) {
            @unlink(public_path(ltrim($res['url'], '/')));
            return response()->json(['error' => 'db_error', 'message' => 'Could not save card'], 500);
        }
        $this->touch($wall);
        return response()->json(['success' => true, 'card' => $this->cardJson($card), 'media' => $res] + $this->snapshot($wall), 201);
    }

    /** Card content for an uploaded file (URLs are server-made; the name is escaped). */
    private static function mediaRaw(array $res, string $name): string
    {
        $u = htmlspecialchars($res['url'], ENT_QUOTES);
        $n = htmlspecialchars(mb_substr($name, 0, 120), ENT_QUOTES);
        switch ($res['kind']) {
            case 'image':
                return $res['url'];
            case 'video':
                return '<video src="' . $u . '" controls playsinline preload="metadata" style="width:100%;display:block;background:#000"></video>';
            case 'audio':
                return '<div style="padding:14px;background:linear-gradient(135deg,#0f172a,#1e1b4b);color:#fff;font:600 12px sans-serif"><div style="margin-bottom:8px">🎵 ' . $n . '</div><audio src="' . $u . '" controls preload="metadata" style="width:100%"></audio></div>';
            default:
                return '<div style="padding:14px;font:600 13px sans-serif;background:#fff">📄 ' . $n . ' <a href="' . $u . '" target="_blank" rel="noopener">Open PDF ↗</a></div>';
        }
    }

    private function looksRenderable(string $raw): bool
    {
        $s = trim($raw);
        if ($s === '') {
            return false;
        }
        if (preg_match('#^https?://\S+$#i', $s)) {
            return true;
        }
        // Markdown block: must be a single well-formed wrapper.
        if (preg_match('#^<md>[\s\S]*</md>$#i', $s) && substr_count(strtolower($s), '<md>') === 1) {
            return true;
        }
        if ($s[0] === '<' && preg_match('#<(iframe|model|img|blockquote|div|p|a)\b#i', $s)) {
            return true;
        }
        return true;
    }

    /* ---------- DELETE /api/wall/{id}/cards/{cid} ---------- */

    public function dropCard(Request $r, string $slug, string $cid)
    {
        $wall = $this->wall($slug, false);
        $card = $wall ? WallCard::where('wall_id', $wall->id)->where('cid', $cid)->first() : null;
        if (!$card) {
            return response()->json(['error' => 'not-found'], 404);
        }

        if ($card->isMother()) {
            return response()->json([
                'error'   => 'use_ezsheet',
                'message' => 'WiKi cards are deleted in EzSheet.',
            ], 403);
        }

        if (!$this->isOwner($wall, $r)) {
            // Author: logged-in user id if we have one, otherwise the browser me_hash.
            $userId = $this->currentUserId($r);
            $isAuthor = $userId
                ? ((int) $card->user_id === $userId)
                : ($card->me_hash && hash_equals((string) $card->me_hash, (string) $this->meHash($r)));
            if (!$isAuthor) {
                return response()->json(['error' => 'denied', 'message' => 'Only the WiKi owner or the author can delete this card.'], 403);
            }
            if ($card->status !== 'pending') {
                return response()->json(['error' => 'denied', 'message' => 'Approved Co-WiKi cards can only be deleted by the WiKi owner.'], 403);
            }
        }

        DB::transaction(function () use ($card, $wall) {
            $card->delete();
            $this->bumpRev($wall);
        });
        $this->touch($wall);

        return response()->json(['ok' => true] + $this->snapshot($wall));
    }

    /* ---------- DELETE /api/wall/{id}/cards ---------- */

    public function dropAllCards(Request $r, string $slug)
    {
        $wall = $this->wall($slug, false);
        if (!$wall || !$this->isOwner($wall, $r)) {
            return response()->json([
                'error'   => 'denied',
                'message' => 'Only the wall owner can delete cards.',
            ], 403);
        }

        // hotfix3: "Delete All Cards" in the Co-WiKi window deletes Co-WiKi
        // cards (children) only. WiKi (mother) cards are managed in EzSheet.
        $deleted = 0;
        DB::transaction(function () use ($wall, &$deleted) {
            $deleted = WallCard::where('wall_id', $wall->id)->whereNotNull('mother_id')->delete();
            $this->bumpRev($wall);
        });
        $this->touch($wall);

        return response()->json(['ok' => true, 'deleted' => $deleted] + $this->snapshot($wall));
    }

    /* ---------- POST /api/wall/{id}/cards/{cid}/approve ---------- */

    public function approve(Request $r, string $slug, string $cid)
    {
        $wall = $this->wall($slug, false);
        if (!$wall || !$this->isOwner($wall, $r)) {
            return response()->json(['error' => 'denied'], 403);
        }

        $card = WallCard::where('wall_id', $wall->id)->where('cid', $cid)->first();
        if (!$card) {
            return response()->json(['error' => 'not-found'], 404);
        }

        DB::transaction(function () use ($card, $wall) {
            $card->status = 'approved';
            $card->approved_at = $card->approved_at ?? now();
            $card->save();
            $this->bumpRev($wall);
        });
        $this->touch($wall);

        return response()->json(['ok' => true, 'card' => $this->cardJson($card->fresh())] + $this->snapshot($wall));
    }

    /* ---------- POST /api/wall/{id}/cards/{cid}/pin  (item 19, owner only) ---------- */

    public function pin(Request $r, string $slug, string $cid)
    {
        $wall = $this->wall($slug, false);
        if (!$wall) {
            return response()->json(['error' => 'not-found'], 404);
        }
        if (!$this->isOwner($wall, $r)) {
            return response()->json(['error' => 'denied', 'message' => 'Only the wall owner can pin cards.'], 403);
        }
        $card = WallCard::where('wall_id', $wall->id)->where('cid', $cid)->first();
        if (!$card) {
            return response()->json(['error' => 'not-found'], 404);
        }
        $on = $r->boolean('pinned', true);
        if ($on) {
            $why = $this->pinRefusal($card, $this->motherOf($card));
            if ($why) {
                $msg = $why === 'pin_not_approved' ? 'Only approved Co-WiKi cards can be pinned.' : 'Only Content cards can be pinned.';
                return response()->json(['error' => $why, 'message' => $msg], 422);
            }
        }
        $at = (int) (microtime(true) * 1000);
        DB::transaction(function () use ($wall, $cid, $on, $at) {
            $this->bumpRev($wall, function (array $meta) use ($cid, $on, $at) {
                $pins = is_array($meta['pins'] ?? null) ? $meta['pins'] : [];
                if ($on) {
                    $pins[(string) $cid] = $at;
                } else {
                    unset($pins[(string) $cid]);
                }
                $meta['pins'] = $pins;
                return $meta;
            });
        });
        $this->touch($wall);

        return response()->json(['ok' => true, 'pinned' => $on ? $at : null] + $this->snapshot($wall));
    }

    /* ---------- GET /api/wall/{id}/review ---------- */

    public function review(Request $r, string $slug)
    {
        // hotfix3: never create a wall from a review GET.
        $wall = $this->wall($slug, false);
        if (!$wall) {
            return response()->json(['error' => 'not-found'], 404);
        }
        if (!$this->isOwner($wall, $r)) {
            return response()->json(['error' => 'denied'], 403);
        }

        // hotfix3: this window manages Co-WiKi cards (children) only.
        // Pending first, then approved. MAX (500) counts pending only.
        $pendingCards = WallCard::where('wall_id', $wall->id)
            ->whereNotNull('mother_id')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $approvedCards = WallCard::where('wall_id', $wall->id)
            ->whereNotNull('mother_id')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'approved');
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        // Concatenate: pending cards first, then approved cards
        $cards = $pendingCards->concat($approvedCards)->map(fn($c) => $this->cardJson($c))->all();

        return response()->json([
            'cards'         => $cards,
            'pending_count' => $pendingCards->count(),
            'max_pending'   => 500,
        ]);
    }

    /* item 21: the 4-digit OTP (sendOtp / verifyOtp, debug_otp) was replaced by App\Http\Controllers\MagicLinkController. */

    /**
     * Get currently authenticated user status
     */
    public function authMe(Request $request)
    {
        $user = Auth::user() ?? $request->user();

        if ($user) {
            return response()->json([
                'authenticated' => true,
                'user'          => [
                    'id'    => (int) $user->id,
                    'name'  => (string) $user->name,
                    'email' => (string) $user->email,
                ],
            ]);
        }

        if ($request->hasSession()) {
            $guest = $request->session()->get('wall_guest_user');
            if ($guest && is_array($guest)) {
                return response()->json([
                    'authenticated' => true,
                    'user'          => $guest,
                ]);
            }
        }

        $authHeader = $request->header('Authorization', '');
        $guestHeader = $request->header('x-guest-token', $request->header('x-user-token', ''));
        $tok = '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $tok = $m[1];
        } elseif (!empty($guestHeader)) {
            $tok = $guestHeader;
        }

        if (!empty($tok)) {
            $cachedUser = Cache::get('guest_token_' . $tok);
            if ($cachedUser && is_array($cachedUser)) {
                return response()->json([
                    'authenticated' => true,
                    'user'          => $cachedUser,
                ]);
            }
        }

        return response()->json([
            'authenticated' => false,
            'user'          => null,
        ]);
    }

    /**
     * EzSheet push: the sheet is the source of truth for MOTHER (WiKi) cards only.
     *  - text given  => parsed on the server by EzSheetParser (client zones ignored)
     *  - mothers upserted by cid; matching order: cid hint -> unchanged text -> zone+position
     *  - mothers missing from the sheet are deleted with their children
     *  - Co-WiKi children are never edited here
     * Returns the full list + rev (same shape as getCards).
     */
    public function pushToWall(Request $r, string $id)
    {
        $targetId = $r->input('wall_id') ?: $id;
        $wall = $this->wall((string) $targetId);

        if (!$this->isOwner($wall, $r)) {
            return response()->json([
                'success' => false,
                'error'   => 'Unauthorized: Only the wall owner can push updates to this wall.',
            ], 403);
        }

        $items = $r->input('items', $r->input('cards', []));
        if (is_string($items)) {
            $items = json_decode($items, true);
        }
        $items = is_array($items) ? array_values(array_filter($items, fn ($i) => is_array($i) || is_object($i))) : [];
        $items = array_map(fn ($i) => (array) $i, $items);
        $text = (string) $r->input('raw', $r->input('text', ''));

        $blocks = $this->sheetBlocks($text, $items);

        if (empty($blocks) && !filter_var($r->input('confirm_empty', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => false,
                'error'   => 'empty_sheet',
                'message' => 'The sheet is empty. Send confirm_empty=1 to delete every WiKi card (and their Co-WiKi cards).',
            ], 422);
        }

        $userId = $this->currentUserId($r);
        $ipHash = WallHasher::ip($r->ip() ?? '');
        $me = $this->meHash($r);

        $explicitDeletes = $r->input('delete_cids', []);
        $explicitDeletes = is_array($explicitDeletes) ? array_map('strval', $explicitDeletes) : [];
        // Item 11: texts the owner confirmed to add as WiKi cards although a Co-WiKi card has the same text.
        $allowCowiki = $r->input('add_cowiki_raws', []);
        $allowCowiki = is_array($allowCowiki) ? array_flip(array_map(fn ($x) => trim((string) $x), $allowCowiki)) : [];

        try {
        $stats = DB::transaction(function () use ($wall, $blocks, $userId, $ipHash, $me, $explicitDeletes, $allowCowiki) {
            $this->bumpRev($wall); // takes the wall row lock first: pushes are serialized

            $all = WallCard::where('wall_id', $wall->id)->get();
            $mothers = $all->filter(fn ($c) => $c->isMother())->keyBy('id');
            $childCids = $all->reject(fn ($c) => $c->isMother())->pluck('id', 'cid');

            // Item 11 (EZ-REL-20261014-1): a sheet block that is a Co-WiKi card (tagged with its cid,
            // or the same text as one: an old sheet echoing guest cards) still does not become a
            // WiKi card, but it is no longer dropped silently: it is reported in not_added with the
            // reason and the WiKi card it belongs to. The owner can then re-push with
            // add_cowiki_raws=[text] to add that text as a WiKi card anyway (W0000076: the eBook
            // link was a Co-WiKi card, so adding it to the sheet did nothing and said nothing).
            $notAdded = [];
            $blocks = array_values(array_filter($blocks, function ($b) use ($all, $mothers, $childCids, $allowCowiki, &$notAdded) {
                $raw = trim((string) $b['raw']);
                $child = null; $reason = null;
                if (!empty($b['cid']) && isset($childCids[$b['cid']])) {
                    $child = $all->firstWhere('cid', $b['cid']); $reason = 'is_cowiki_card';
                } elseif (!isset($allowCowiki[$raw])) {
                    $child = $all->first(fn ($c) => !$c->isMother() && trim((string) $c->content) === $raw);
                    if ($child) $reason = 'same_as_cowiki_card';
                }
                if (!$child) return true;
                $mom = $child->mother_id ? $mothers->get($child->mother_id) : $mothers->firstWhere('cid', $child->anchor);
                $notAdded[] = ['raw' => mb_substr($raw, 0, 300), 'zone' => $b['zone'], 'reason' => $reason,
                    'cowiki_cid' => (string) $child->cid,
                    'mother_cid' => $mom ? (string) $mom->cid : null,
                    'mother_raw' => $mom ? mb_substr(trim((string) $mom->content), 0, 120) : null];
                return false;
            }));

            $childCount = $all->reject(fn ($c) => $c->isMother())
                ->groupBy(fn ($c) => $c->mother_id ?: optional($mothers->firstWhere('cid', $c->anchor))->id)
                ->map->count();
            $match = $this->matchMothers($blocks, $mothers, $childCount);

            // item 26: a mother with Co-WiKi children may now move to any zone; the family
            // moves with her (children's zone is synced right after the mothers are saved).

            // A sheet that carries cid tags says exactly which mothers it still has, so a
            // missing tagged mother is "clearly removed". Without tags we never delete a
            // mother that has children; we keep it and warn instead.
            $tagged = collect($blocks)->contains(fn ($b) => !empty($b['cid']) && $mothers->contains('cid', $b['cid']));

            $keep = [];
            $created = 0; $updated = 0;
            $posByZone = ['a' => 0, 'b' => 0, 'c' => 0];

            foreach ($blocks as $i => $b) {
                $zone = $b['zone'];
                $pos = $posByZone[$zone]++;
                $m = isset($match[$i]) ? $mothers[$match[$i]] : null;
                if ($m) {
                    $keep[$m->id] = true;
                    $changes = array_filter([
                        'content'  => (string) $m->content !== $b['raw'] ? $b['raw'] : null,
                        'zone'     => $m->zone !== $zone ? $zone : null,
                        'position' => (int) $m->position !== $pos ? $pos : null,
                    ], fn ($v) => $v !== null);
                    if ($changes) {
                        $m->forceFill($changes)->save();
                        $updated++;
                    }
                } else {
                    $cid = (!empty($b['cid']) && !WallCard::where('wall_id', $wall->id)->where('cid', $b['cid'])->exists())
                        ? $b['cid'] : WallHasher::cid();
                    $new = WallCard::create([
                        'wall_id'  => $wall->id,
                        'user_id'  => $userId,
                        'cid'      => $cid,
                        'me_hash'  => $me,
                        'ip_hash'  => $ipHash,
                        'content'  => $b['raw'],
                        'zone'     => $zone,
                        'origin'   => 'wiki',
                        'status'   => 'approved',
                        'position' => $pos,
                    ]);
                    $keep[$new->id] = true;
                    $created++;
                }
            }

            // item 26: children always live in their mother's zone.
            foreach (WallCard::where('wall_id', $wall->id)->get() as $fm) {
                if (!$fm->isMother()) continue;
                WallCard::where('wall_id', $wall->id)
                    ->where(fn ($q) => $q->where('mother_id', $fm->id)->orWhere(fn ($q2) => $q2->whereNull('mother_id')->where('anchor', $fm->cid)))
                    ->where('zone', '!=', $fm->zone)
                    ->update(['zone' => $fm->zone]);
            }

            // Mothers no longer in the sheet: delete children explicitly (DBs without FK),
            // including legacy children linked only by anchor, then the mothers.
            $gone = $mothers->reject(fn ($m) => isset($keep[$m->id]));
            $kept = $gone->filter(fn ($m) => !$tagged && ($childCount[$m->id] ?? 0) > 0
                && !in_array((string) $m->cid, $explicitDeletes, true));
            $gone = $gone->diffKeys($kept);
            // Kept mothers go after this push's mothers in their zone, old order preserved.
            foreach ($kept->sortBy(fn ($m) => sprintf('%010d-%012d', (int) $m->position, $m->id)) as $m) {
                $m->forceFill(['position' => $posByZone[$m->zone] ?? 0])->save();
                $posByZone[$m->zone] = ($posByZone[$m->zone] ?? 0) + 1;
            }
            $warnings = $kept->map(fn ($m) => [
                'cid' => $m->cid, 'raw' => mb_substr((string) $m->content, 0, 120), 'zone' => $m->zone,
                'children' => $childCount[$m->id] ?? 0,
            ])->values()->all();
            $deleted = 0; $childrenDeleted = 0;
            if ($gone->isNotEmpty()) {
                $childrenDeleted = WallCard::where('wall_id', $wall->id)
                    ->where(function ($q) use ($gone) {
                        $q->whereIn('mother_id', $gone->keys()->all())
                          ->orWhereIn('anchor', $gone->pluck('cid')->all());
                    })
                    ->whereNotIn('id', $gone->keys()->all())
                    ->delete();
                $deleted = WallCard::whereIn('id', $gone->keys()->all())->delete();
            }

            return compact('created', 'updated', 'deleted', 'childrenDeleted', 'warnings', 'notAdded');
        });
        } catch (\App\Support\PushRejected $e) {
            return response()->json($e->payload + ['rev' => $this->rev($wall)], $e->status);
        }
        $warnings = $stats['warnings'];
        $notAdded = $stats['notAdded'];
        unset($stats['warnings'], $stats['notAdded']);

        $this->touch($wall);
        Log::info("pushToWall {$wall->slug}: " . json_encode($stats));
        $snap = $this->snapshot($wall);

        return response()->json([
            'success'     => true,
            'message'     => 'Wall cards successfully updated from EzSheet.',
            'stats'       => $stats,
            'cards_count' => $snap['count'],
            // Mothers with Co-WiKi cards that this (untagged) sheet no longer shows. They were
            // KEPT. To really delete them, push again with delete_cids=[...] or a tagged sheet.
            'unmatched_mothers_with_children' => $warnings,
            // Item 11: sheet blocks that did NOT become WiKi cards, each with a reason. Never silent.
            // reason: is_cowiki_card | same_as_cowiki_card (re-push with add_cowiki_raws to add anyway).
            'not_added'   => $notAdded,
            // Sheet text with <!-- cid:... --> lines for the editor to hold in memory.
            'sheet'       => $this->sheetText($wall, true),
        ] + $snap);
    }

    /** Rebuild the sheet from the mothers. Tags only for the editor; downloads never carry them. */
    private function sheetText(Wall $w, bool $withTags): string
    {
        $mothers = WallCard::where('wall_id', $w->id)->get()->filter(fn ($c) => $c->isMother())
            ->sortBy(fn ($c) => sprintf('%010d-%012d', (int) $c->position, $c->id));
        $section = function (string $z) use ($mothers, $withTags) {
            return $mothers->where('zone', $z)->map(fn ($m) =>
                ($withTags ? "<!-- cid:{$m->cid} -->\n" : '') . trim((string) $m->content))->implode("\n---\n");
        };
        $parts = [];
        if (($a = $section('a')) !== '') $parts[] = $a . "\n===";
        if (($c = $section('c')) !== '') $parts[] = $c . "\n###";
        if (($b = $section('b')) !== '') $parts[] = $b;
        return implode("\n", $parts) . "\n";
    }

    /* ---------- GET /api/wall/{id}/sheet  (editor: JSON, tagged text, owner only) ---------- */
    public function getSheet(Request $r, string $slug)
    {
        $wall = $this->wall($slug, false);
        if (!$wall || !$this->isOwner($wall, $r)) {
            return response()->json(['error' => 'denied'], 403);
        }
        return response()->json(['sheet' => $this->sheetText($wall, true), 'rev' => $this->rev($wall)]);
    }

    /* ---------- GET /api/wall/{id}/sheet/download  (plain text, never any tags) ---------- */
    public function downloadSheet(Request $r, string $slug)
    {
        $wall = $this->wall($slug, false);
        if (!$wall) {
            return response()->json(['error' => 'not-found'], 404);
        }
        // Optional: client sends its own editor text to be cleaned; else rebuild from the DB.
        $text = $r->has('text') ? EzSheetParser::stripTags((string) $r->input('text')) : $this->sheetText($wall, false);
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $wall->slug) ?: 'ezsheet';
        return response($text, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '.txt"',
        ]);
    }

    /**
     * Sheet blocks in sheet order: [raw, zone, cid?]. Text wins over items.
     * Items only contribute cid hints (by index, when the counts line up) or, with no
     * text at all, act as the sheet themselves (legacy clients).
     */
    private function sheetBlocks(string $text, array $items): array
    {
        $itemRaw = function (array $it): string {
            $raw = trim((string) ($it['_raw'] ?? $it['raw'] ?? $it['url'] ?? $it['html'] ?? ''));
            if ($raw === '' && !empty($it['md'])) {
                $raw = "<md>{$it['md']}</md>";
            }
            return $raw;
        };
        // Items that are guest cards are not part of the sheet.
        $items = array_values(array_filter($items, fn ($it) =>
            empty($it['guest']) && ($it['type'] ?? 'wiki') !== 'cowiki' && empty($it['anchor']) && ($it['role'] ?? 'mother') === 'mother'));

        if (trim($text) !== '') {
            $blocks = EzSheetParser::parse($text);
            if (count($items) === count($blocks)) {
                foreach ($blocks as $i => &$b) {
                    if (empty($b['cid']) && !empty($items[$i]['cid'])) {
                        $b['cid'] = (string) $items[$i]['cid'];
                    }
                }
                unset($b);
            }
            return $blocks;
        }

        $out = [];
        foreach ($items as $it) {
            $raw = $itemRaw($it);
            if ($raw === '') continue;
            $out[] = [
                'raw'  => $raw,
                'zone' => in_array($it['zone'] ?? '', ['a', 'b', 'c'], true) ? $it['zone'] : 'b',
                'cid'  => !empty($it['cid']) ? (string) $it['cid'] : null,
            ];
        }
        return $out;
    }

    /**
     * Map sheet block index => existing mother id.
     *  1. cid hint (sheet tag or item cid) that names an existing mother
     *  2. identical text (any zone; first unclaimed in old order)
     *  3. fallback inside each zone for the rest (edited text):
     *     - same number of leftover blocks and mothers => pair them in order
     *     - otherwise (edits mixed with adds/removes) pair only when the block's zone index
     *       equals the mother's old position AND that mother has no children. A mother with
     *       children is only ever matched by cid tag or identical text in that case; if it
     *       stays unmatched the push keeps it and reports it (unmatched_mothers_with_children).
     * Anything unmatched is a new mother; unclaimed mothers get deleted.
     */
    private function matchMothers(array $blocks, $mothers, $childCount = null): array
    {
        $match = [];
        $claimed = [];
        $byCid = $mothers->keyBy('cid');
        $ordered = $mothers->sortBy(fn ($m) => sprintf('%d-%010d-%012d', self::ZONE_ORDER[$m->zone] ?? 9, (int) $m->position, $m->id));

        foreach ($blocks as $i => $b) {
            if (!empty($b['cid']) && isset($byCid[$b['cid']]) && !isset($claimed[$byCid[$b['cid']]->id])) {
                $match[$i] = $byCid[$b['cid']]->id;
                $claimed[$match[$i]] = true;
            }
        }
        foreach ($blocks as $i => $b) {
            if (isset($match[$i])) continue;
            foreach ($ordered as $m) {
                if (!isset($claimed[$m->id]) && trim((string) $m->content) === trim($b['raw'])) {
                    $match[$i] = $m->id;
                    $claimed[$m->id] = true;
                    break;
                }
            }
        }
        foreach (['a', 'b', 'c'] as $z) {
            $zoneIdx = [];
            $k = 0;
            foreach ($blocks as $i => $b) {
                if ($b['zone'] === $z) { $zoneIdx[$i] = $k++; }
            }
            $leftB = array_values(array_filter(array_keys($zoneIdx), fn ($i) => !isset($match[$i])));
            $leftM = array_values($ordered->filter(fn ($m) => $m->zone === $z && !isset($claimed[$m->id]))->all());
            if (!$leftB || !$leftM) continue;
            if (count($leftB) === count($leftM)) {
                foreach ($leftB as $n => $i) {
                    $match[$i] = $leftM[$n]->id;
                    $claimed[$leftM[$n]->id] = true;
                }
            } else {
                foreach ($leftB as $i) {
                    foreach ($leftM as $m) {
                        if (!isset($claimed[$m->id]) && (int) $m->position === $zoneIdx[$i]
                            && (int) ($childCount[$m->id] ?? 0) === 0) {
                            $match[$i] = $m->id;
                            $claimed[$m->id] = true;
                            break;
                        }
                    }
                }
            }
        }
        return $match;
    }

    /* ---------- /reorder and /cards/reorder: removed ---------- */
    public function reorderGone(Request $r, string $slug)
    {
        return response()->json([
            'error'   => 'gone',
            'message' => 'Card order is set in EzSheet. Push the sheet instead.',
        ], 410);
    }

    /**
     * Upload media file for a wall.
     * Owner only, max 100 MB.
     * Byte-sniffing checks: reject HTML, SVG, md, zip, office files, executables and scripts.
     * Stored in public/upload/wall/<slug>/ under a server-generated name.
     */
    public function uploadMedia(Request $request, string $id)
    {
        // EZ-REL-20261015-3-hotfix: owner of the wall in the URL only (no body wall_id,
        // no 'ezsheet' delegation), same-origin, never creates a wall, safe storage.
        if (!SafeUpload::sameOrigin($request)) {
            return response()->json(['success' => false, 'error' => 'Cross-site upload refused.'], 403);
        }
        $wall = $this->wall((string) $id, false);
        if (!$wall) {
            return response()->json(['success' => false, 'error' => 'Wall not found.'], 404);
        }
        if (!$this->isOwner($wall, $request)) {
            return response()->json(['success' => false, 'error' => 'Unauthorized: Only the wall owner can upload media.'], 403);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'error' => 'No file provided.'], 422);
        }
        $file = $request->file('file');
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) ($wall->slug ?: $wall->id)) ?: 'wall';
        $res = SafeUpload::store($file, 'wall/' . $slug);
        if (!is_array($res)) {
            return response()->json(['success' => false, 'error' => $res], 422);
        }
        return response()->json(['success' => true, 'filename' => $file->getClientOriginalName()] + $res);
    }

    /**
     * Upload image, pdf, html, video, audio into public/upload/ezsheet
     */
    public function uploadEzsheetFile(Request $request)
    {
        // EZ-REL-20261015-3-hotfix: logged-in, same-origin, sniffed allow-list,
        // server name + extension, stored only in public/upload/ezsheet/.
        if ($deny = SafeUpload::guard($request)) {
            return $deny;
        }
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $original = $file->getClientOriginalName();
            $res = SafeUpload::store($file, 'ezsheet');
        } elseif ($request->filled('data')) {
            $original = (string) $request->input('filename', 'upload');
            $data = (string) $request->input('data');
            if (strlen($data) > (int) ceil(SafeUpload::MAX_BYTES * 4 / 3) + 256) {
                return response()->json(['success' => false, 'message' => 'File exceeds the 100 MB limit.'], 422);
            }
            if (preg_match('/^data:[^;,]*;base64,/', $data, $m)) {
                $data = substr($data, strlen($m[0]));
            }
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                return response()->json(['success' => false, 'message' => 'Bad file data.'], 422);
            }
            $res = SafeUpload::store($bytes, 'ezsheet');
        } else {
            return response()->json(['success' => false, 'message' => 'No file or data provided'], 422);
        }
        if (!is_array($res)) {
            return response()->json(['success' => false, 'message' => $res, 'error' => $res], 422);
        }
        return response()->json(['success' => true, 'message' => 'File uploaded', 'filename' => $original] + $res);
    }
}
