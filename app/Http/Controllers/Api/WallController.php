<?php
namespace App\Http\Controllers\Api;

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

    /** False only for a state-changing request that the browser marks as
     *  coming from another site (Sec-Fetch-Site, else Origin host). */
    private function sameSiteRequest(Request $r): bool
    {
        if (in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $site = strtolower((string) $r->header('sec-fetch-site', ''));
        if ($site !== '') {
            return in_array($site, ['same-origin', 'same-site', 'none'], true);
        }
        $origin = (string) $r->header('origin', '');
        if ($origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        return is_string($host) && strcasecmp($host, $r->getHost()) === 0;
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
        ];
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

    /** The one canonical ordering, used by getCards, push, every write response, and SSE. */
    private function orderedCards(Wall $w): array
    {
        $all = WallCard::where('wall_id', $w->id)->get();
        $mothers = $all->filter(fn ($c) => $c->isMother())
            ->sortBy(fn ($c) => sprintf('%d-%010d-%012d', self::ZONE_ORDER[$c->zone] ?? 9, (int) $c->position, $c->id));
        $byMother = [];
        $orphans = [];
        $motherCids = $mothers->pluck('id', 'cid');
        foreach ($all as $c) {
            if ($c->isMother()) continue;
            $mid = $c->mother_id ?: ($motherCids[$c->anchor] ?? null);
            if ($mid) { $byMother[$mid][] = $c; } else { $orphans[] = $c; }
        }
        $cardTs = fn ($c) => $this->toTimestampMs($c->created_at) ?? 0;
        $newest = fn ($a, $b) => [$cardTs($b), $b->id] <=> [$cardTs($a), $a->id];

        $out = [];
        foreach ($mothers as $m) {
            $kids = $byMother[$m->id] ?? [];
            usort($kids, $newest);
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

        return array_map(fn ($c) => $this->cardJson($c), $out);
    }

    private function rev(Wall $w): int
    {
        $meta = Wall::whereKey($w->id)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
        return (int) ($meta['rev'] ?? 0);
    }

    /** Increment walls.meta.rev under a row lock. Call inside the write transaction. */
    private function bumpRev(Wall $w): int
    {
        $locked = Wall::whereKey($w->id)->lockForUpdate()->first();
        $meta = $locked->meta ?? [];
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
                'fireworks'   => $wall->fireworks ?? ($meta['fireworks'] ?? null),
                'flybees'     => $wall->flybees ?? ($meta['flybees'] ?? null),
                'sound'       => $wall->sound ?? ($meta['sound'] ?? null),
                'language'    => $wall->language ?? ($meta['language'] ?? null),
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

            // 6. Fireworks on/off
            if ($r->has('fireworks')) {
                $fw = filter_var($r->input('fireworks'), FILTER_VALIDATE_BOOLEAN);
                $wall->fireworks = $fw;
                $meta['fireworks'] = $fw;
                $meta['fx']['fw'] = $fw;
            }

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
                    'fireworks'   => (bool)$wall->fireworks,
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
        if (($mother->zone ?? 'b') !== 'b') {
            return response()->json(['error' => 'anchor_zone', 'message' => 'Co-WiKi cards can only be added in the Content zone.'], 422);
        }

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

        $isOwner = $this->isOwner($wall, $r);
        $userId = $this->currentUserId($r);
        $me = $this->meHash($r);

        try {
            $card = DB::transaction(function () use ($wall, $mother, $userId, $me, $r, $raw, $isOwner) {
                $card = WallCard::create([
                    'wall_id'     => $wall->id,
                    'user_id'     => $userId,
                    'cid'         => WallHasher::cid(),
                    'me_hash'     => $me,
                    'ip_hash'     => WallHasher::ip($r->ip() ?? ''),
                    'content'     => $raw,
                    'anchor'      => (string) $mother->cid,
                    'mother_id'   => $mother->id,
                    'zone'        => 'b',
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
            return response()->json(['error' => 'db_error', 'message' => 'Could not save card'], 500);
        }

        $this->touch($wall);

        return response()->json(['success' => true, 'card' => $this->cardJson($card)] + $this->snapshot($wall), 201);
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

    /* ---------- OTP Login for Wall Cards & Website ---------- */

    /**
     * Send 4-digit OTP to visitor's email for instant login
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));
        // Generate 4-digit OTP
        $otp = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(15);

        // Store or update in database
        EmailVerification::updateOrCreate(
            ['email' => $email],
            [
                'otp'           => $otp,
                'attempts'      => 0,
                'is_verified'   => false,
                'is_subscribed' => false,
                'expires_at'    => $expiresAt,
            ]
        );

        // Store in cache for fast lookup
        Cache::put('wall_otp_' . $email, [
            'code'     => $otp,
            'attempts' => 0,
        ], $expiresAt);

        // Send email with 4-digit OTP
        try {
            $emaildesign = Emaildesign::where('id', 11)->first();
            $template = $emaildesign ? $emaildesign['design'] : '<p>Your 4-digit login PIN code is: <strong>{pincode}</strong></p>';
            $content = str_replace('{pincode}', $otp, $template);
            $mailData = ['design' => $content];
            $subject = "Ez.wiki Your 4-Digit Login Code";

            try {
                Mail::to($email)->send(new Eznew($mailData, $subject));
                if (method_exists(Mail::class, 'getSymfonyTransport')) {
                    @Mail::getSymfonyTransport()->stop();
                }
            } catch (\Throwable $e) {
                @mail($email, $subject, $content, "Content-Type: text/html\r\n", 'funnel@ez.wiki');
            }
        } catch (\Throwable $e) {
            \Log::warning('WallController OTP mail dispatch failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'   => true,
            'message'   => 'A 4-digit code has been sent to your email.',
            'email'     => $email,
            // Include OTP in debug/local testing so users can verify instantly
            'debug_otp' => config('app.debug', true) ? $otp : null,
        ]);
    }

    /**
     * Verify 4-digit OTP, auto-create user if not exists, and log in to website
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|digits:4',
        ]);

        $email = strtolower(trim($request->email));
        $cacheKey = 'wall_otp_' . $email;
        $stored = Cache::get($cacheKey);

        $dbRecord = EmailVerification::where('email', $email)
            ->where('expires_at', '>', now())
            ->first();

        $expectedOtp = $stored['code'] ?? ($dbRecord ? $dbRecord->otp : null);

        if (!$expectedOtp) {
            return response()->json([
                'success' => false,
                'message' => 'The OTP code has expired or was not requested. Please request a new code.',
            ], 400);
        }

        if ((string) $expectedOtp !== (string) $request->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid 4-digit code. Please check your email and try again.',
            ], 422);
        }

        // OTP is valid - clear verification
        Cache::forget($cacheKey);
        if ($dbRecord) {
            $dbRecord->update(['is_verified' => true]);
        }

        // Find existing user or automatically create new user
        $user = User::where('email', $email)->first();
        $isNewUser = false;

        if (!$user) {
            $isNewUser = true;
            $username = explode('@', $email)[0];
            // Ensure unique username
            $baseName = $username;
            $counter = 1;
            while (User::where('name', $username)->exists()) {
                $username = $baseName . $counter;
                $counter++;
            }

            $user = User::create([
                'name'              => $username,
                'email'             => $email,
                'email_verified_at' => now(),
                'password'          => Hash::make(Str::random(32)),
            ]);
        }

        // Automatically log user into website session
        Auth::login($user, true);

        $guestToken = 'guest_' . Str::random(40);
        Cache::put('guest_token_' . $guestToken, [
            'id'    => (int) $user->id,
            'name'  => (string) $user->name,
            'email' => (string) $user->email,
        ], 86400 * 30);

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('wall_guest_user', [
                'id'    => (int) $user->id,
                'name'  => (string) $user->name,
                'email' => (string) $user->email,
            ]);
        }

        $token = null;
        if (method_exists($user, 'createToken')) {
            try {
                $token = $user->createToken('wall-session')->plainTextToken;
            } catch (\Throwable $e) {
                // Sanctum token optional
            }
        }

        return response()->json([
            'success'      => true,
            'message'      => $isNewUser ? 'Account created and logged in!' : 'Welcome back! You are now logged in.',
            'is_new_user'  => $isNewUser,
            'user'         => [
                'id'    => (int) $user->id,
                'name'  => (string) $user->name,
                'email' => (string) $user->email,
            ],
            'token'        => $token,
            'guest_token'  => $guestToken,
        ]);
    }

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

            // A mother that has Co-WiKi children may not leave zone b. Reject the whole push.
            $locked = [];
            foreach ($match as $i => $mid) {
                if (($childCount[$mid] ?? 0) > 0 && $blocks[$i]['zone'] !== 'b') {
                    $m = $mothers[$mid];
                    $locked[] = ['cid' => $m->cid, 'raw' => mb_substr((string) $blocks[$i]['raw'], 0, 120),
                                 'to_zone' => $blocks[$i]['zone'], 'children' => $childCount[$mid]];
                }
            }
            if ($locked) {
                throw new \App\Support\PushRejected(422, [
                    'success' => false,
                    'error'   => 'mother_zone_locked',
                    'message' => 'WiKi cards that have Co-WiKi cards must stay in the Content zone. Nothing was saved.',
                    'cards'   => $locked,
                ]);
            }

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
        $targetId = $request->input('wall_id') ?: $id;

        // If target is ezsheet (draft mode before wall publication), delegate to uploadEzsheetFile
        if ($targetId === 'ezsheet' || $id === 'ezsheet') {
            return $this->uploadEzsheetFile($request);
        }

        $wall = $this->wall((string) $targetId);

        if (!$wall) {
            return response()->json([
                'success' => false,
                'error'   => 'Wall not found.',
            ], 404);
        }

        if (!$this->isOwner($wall, $request)) {
            return response()->json([
                'success' => false,
                'error'   => 'Unauthorized: Only the wall owner can upload media.',
            ], 403);
        }

        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'error'   => 'No file provided.',
            ], 422);
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return response()->json([
                'success' => false,
                'error'   => 'File upload failed: ' . $file->getErrorMessage(),
            ], 422);
        }

        // Limit: 100 MB (104,857,600 bytes)
        $maxBytes = 100 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return response()->json([
                'success' => false,
                'error'   => 'File size exceeds maximum limit of 100 MB.',
            ], 422);
        }

        $filePath = $file->getPathname();
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        if (!$ext) {
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        }

        // 1. Sniff MIME type using finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffedMime = strtolower($finfo->file($filePath) ?: $file->getMimeType() ?: '');

        // 2. Sniff header bytes (first 8KB)
        $handle = @fopen($filePath, 'rb');
        $bytes = ($handle !== false) ? (string) fread($handle, 8192) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        // Extension check for explicitly rejected categories
        $rejectedExts = [
            'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'md', 'markdown', 'mdown', 'mkd',
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'iso', 'tgz',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf',
            'exe', 'dll', 'so', 'dylib', 'bin', 'sh', 'bash', 'bat', 'cmd', 'ps1', 'vbs',
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'py', 'rb', 'pl', 'cgi',
            'js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'jar', 'class', 'apk'
        ];
        if (in_array($ext, $rejectedExts, true)) {
            return response()->json([
                'success' => false,
                'error'   => "File type '{$ext}' is rejected for server media storage.",
            ], 422);
        }

        // Byte sniffing & MIME checks:

        // Reject HTML
        if (
            str_contains($sniffedMime, 'html') ||
            str_contains($sniffedMime, 'xhtml') ||
            preg_match('/<\s*(!doctype\s+html|html|body|head|script|iframe)[\s>]/i', $bytes)
        ) {
            return response()->json([
                'success' => false,
                'error'   => 'HTML files must be inlined in the sheet as sandboxed cards and are not stored on the server.',
            ], 422);
        }

        // Reject SVG
        if (
            str_contains($sniffedMime, 'svg') ||
            (str_contains($sniffedMime, 'xml') && str_contains(strtolower($bytes), '<svg')) ||
            preg_match('/<\s*(!doctype\s+svg|svg)[\s>]/i', $bytes)
        ) {
            return response()->json([
                'success' => false,
                'error'   => 'SVG files are rejected.',
            ], 422);
        }

        // Reject Markdown
        if (str_contains($sniffedMime, 'markdown') || str_contains($sniffedMime, 'text/x-markdown')) {
            return response()->json([
                'success' => false,
                'error'   => 'Markdown files are rejected from media storage.',
            ], 422);
        }

        // Reject ZIP / Archives / compressed files
        if (
            str_starts_with($bytes, "PK\x03\x04") ||
            str_starts_with($bytes, "PK\x05\x06") ||
            str_starts_with($bytes, "Rar!\x1A\x07") ||
            str_starts_with($bytes, "7z\xBC\xAF\x27\x1C") ||
            str_starts_with($bytes, "\x1F\x8B") ||
            str_starts_with($bytes, "BZh") ||
            str_starts_with($bytes, "\xFD7zXZ\x00") ||
            str_contains($sniffedMime, 'zip') ||
            str_contains($sniffedMime, 'compressed') ||
            str_contains($sniffedMime, 'archive') ||
            str_contains($sniffedMime, 'tar') ||
            str_contains($sniffedMime, 'rar') ||
            str_contains($sniffedMime, '7z')
        ) {
            return response()->json([
                'success' => false,
                'error'   => 'Archive, ZIP, and compressed files are rejected.',
            ], 422);
        }

        // Reject Office documents (OLE2 / legacy .doc, .xls, .ppt, or OpenXML)
        if (
            str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") ||
            str_contains($sniffedMime, 'msword') ||
            str_contains($sniffedMime, 'officedocument') ||
            str_contains($sniffedMime, 'ms-excel') ||
            str_contains($sniffedMime, 'ms-powerpoint')
        ) {
            return response()->json([
                'success' => false,
                'error'   => 'Office documents are rejected.',
            ], 422);
        }

        // Reject executables and scripts
        if (
            str_starts_with($bytes, "MZ") ||
            str_starts_with($bytes, "\x7FELF") ||
            str_starts_with($bytes, "\xCA\xFE\xBA\xBE") ||
            str_starts_with($bytes, "\xCE\xFA\xED\xFE") ||
            str_starts_with($bytes, "\xCF\xFA\xED\xFE") ||
            str_starts_with($bytes, "#!") ||
            preg_match('/<\?(?:php|=)/i', $bytes) ||
            str_contains($sniffedMime, 'executable') ||
            str_contains($sniffedMime, 'x-sh') ||
            str_contains($sniffedMime, 'x-php') ||
            str_contains($sniffedMime, 'javascript') ||
            str_contains($sniffedMime, 'x-python') ||
            str_contains($sniffedMime, 'x-perl')
        ) {
            return response()->json([
                'success' => false,
                'error'   => 'Executables and scripts are rejected.',
            ], 422);
        }

        // Check if allowed media type: Image, Video, Audio, or PDF
        $isPdf = ($sniffedMime === 'application/pdf' || str_starts_with($bytes, "%PDF-"));
        $isImage = str_starts_with($sniffedMime, 'image/');
        $isVideo = str_starts_with($sniffedMime, 'video/');
        $isAudio = str_starts_with($sniffedMime, 'audio/');

        if (!$isPdf && !$isImage && !$isVideo && !$isAudio) {
            return response()->json([
                'success' => false,
                'error'   => 'Unsupported media file type. Supported types: Images, Videos, Audio, and PDF.',
            ], 422);
        }

        $kind = 'file';
        if ($isImage) {
            $kind = 'image';
            if (!$ext) $ext = 'jpg';
        } elseif ($isVideo) {
            $kind = 'video';
            if (!$ext) $ext = 'mp4';
        } elseif ($isAudio) {
            $kind = 'audio';
            if (!$ext) $ext = 'mp3';
        } elseif ($isPdf) {
            $kind = 'pdf';
            if (!$ext) $ext = 'pdf';
        }

        // Target directory: public/upload/wall/<slug>/
        $wallSlug = $wall->slug ?: (string)$wall->id;
        $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $wallSlug) ?: 'wall';
        $targetDir = public_path("upload/wall/{$safeSlug}");
        if (!file_exists($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // Server-generated name
        $serverName = 'media_' . time() . '_' . Str::lower(Str::random(12)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $serverName;

        $file->move($targetDir, $serverName);
        @chmod($targetPath, 0644);

        $publicUrl = "/upload/wall/{$safeSlug}/{$serverName}";

        return response()->json([
            'success'   => true,
            'url'       => $publicUrl,
            'filename'  => $originalName,
            'name'      => $serverName,
            'kind'      => $kind,
            'mime_type' => $sniffedMime,
            'size'      => @filesize($targetPath) ?: $file->getSize(),
        ]);
    }

    /**
     * Upload image, pdf, html, video, audio into public/upload/ezsheet
     */
    public function uploadEzsheetFile(Request $request)
    {
        $hasFile = $request->hasFile('file');
        $hasData = $request->filled('data');

        if (!$hasFile && !$hasData) {
            return response()->json([
                'success' => false,
                'message' => 'No file or data provided',
            ], 422);
        }

        try {
            $userId = Auth::id() ?? 0;
            $targetDir = public_path('upload/ezsheet');
            if (!file_exists($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }
            $uploadsTargetDir = public_path('uploads/ezsheet');
            if (!file_exists($uploadsTargetDir)) {
                @mkdir($uploadsTargetDir, 0755, true);
            }
            $storageTargetDir = storage_path('app/public/upload/ezsheet');
            if (!file_exists($storageTargetDir)) {
                @mkdir($storageTargetDir, 0755, true);
            }

            if ($hasFile) {
                $file = $request->file('file');
                $originalName = $file->getClientOriginalName();
                $extension = strtolower($file->getClientOriginalExtension());
                if (!$extension) {
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                }
                $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_') ?: 'file';
                $filename = 'ezsheet_' . ($userId ? 'user_' . $userId . '_' : '') . time() . '_' . Str::random(6) . '_' . $safeName . '.' . ($extension ?: 'bin');

                $file->move($targetDir, $filename);
                @copy($targetDir . '/' . $filename, $uploadsTargetDir . '/' . $filename);
                if (file_exists($storageTargetDir)) {
                    @copy($targetDir . '/' . $filename, $storageTargetDir . '/' . $filename);
                }

                $mimeType = @mime_content_type($targetDir . '/' . $filename) ?: $file->getClientMimeType();
                $size = @filesize($targetDir . '/' . $filename);
            } else {
                $originalName = (string) $request->input('filename', 'uploaded_file');
                $rawMime = (string) $request->input('mime_type', 'application/octet-stream');
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!$extension) {
                    $extMap = [
                        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
                        'application/pdf' => 'pdf', 'text/html' => 'html', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
                    ];
                    $extension = $extMap[$rawMime] ?? 'bin';
                }
                $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_') ?: 'file';
                $filename = 'ezsheet_' . ($userId ? 'user_' . $userId . '_' : '') . time() . '_' . Str::random(6) . '_' . $safeName . '.' . $extension;

                $data = $request->input('data');
                if (preg_match('/^data:([^;]+);base64,(.+)$/', $data, $matches)) {
                    $rawMime = $matches[1];
                    $binaryData = base64_decode($matches[2]);
                } else {
                    $binaryData = base64_decode($data);
                }

                file_put_contents($targetDir . '/' . $filename, $binaryData);
                @copy($targetDir . '/' . $filename, $uploadsTargetDir . '/' . $filename);
                if (file_exists($storageTargetDir)) {
                    @copy($targetDir . '/' . $filename, $storageTargetDir . '/' . $filename);
                }

                $mimeType = $rawMime;
                $size = strlen($binaryData);
            }

            $publicUrl = '/upload/ezsheet/' . $filename;

            return response()->json([
                'success' => true,
                'message' => 'File uploaded and stored in ezsheet folder successfully',
                'url' => $publicUrl,
                'filename' => $originalName,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'size' => $size,
            ]);
        } catch (\Exception $e) {
            Log::error('uploadEzsheetFile error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload ezsheet file: ' . $e->getMessage(),
            ], 500);
        }
    }
}
