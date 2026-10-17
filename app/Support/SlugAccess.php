<?php

namespace App\Support;

use App\Models\AISearchHistory;
use App\Models\Customdomain;
use App\Models\EzFunnel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * EZ-LOCK-20261017: ONE access rule for a slug (conversation) on every delivery path.
 *
 *   public  -> everyone
 *   private -> the owner, or a visitor whose session holds a valid unlock grant
 *              (email code / magic link, or the owner-set password; see SlugUnlockController)
 *   hidden  -> the owner only
 *
 * The status that counts is the ROOT message's (parent_id = null), as everywhere else in the app.
 * Unauthorised viewers get a response WITHOUT any slug data: 404 when the slug is owner-only,
 * or the lock screen (403, page "SlugLocked") when the owner turned an unlock method on.
 * Grants made by the old 4-digit access-number flow (no "v" marker) are void.
 */
final class SlugAccess
{
    public const GRANT_VERSION = 2;

    /** Root message (holds the status and the unlock settings) of any message in the conversation. */
    public static function root(AISearchHistory $m): AISearchHistory
    {
        if (empty($m->conversation_id)) {
            return $m;   // getFirstMessage() would match ANY row with a NULL conversation_id
        }
        return $m->getFirstMessage() ?? $m;
    }

    public static function status(AISearchHistory $root): string
    {
        return (string) ($root->status ?: AISearchHistory::STATUS_PUBLIC);
    }

    public static function isPublic(AISearchHistory $root): bool
    {
        return self::status($root) === AISearchHistory::STATUS_PUBLIC;
    }

    /** Signed-in owner, or (guest-created conversation) the creating session. Same rule as isOwnedBy(). */
    public static function isOwner(AISearchHistory $root): bool
    {
        $user = Auth::user();
        if ($user && $root->user_id && (int) $root->user_id === (int) $user->id) {
            return true;
        }
        $sid = (string) Session::getId();
        return !$root->user_id && !empty($root->session_id) && $sid !== '' && hash_equals((string) $root->session_id, $sid);
    }

    public static function sessionKey(?string $conversationId): string
    {
        return 'private_access_' . (string) $conversationId;
    }

    /** Unlock methods the owner turned on. All off until the P2 migration ran and SLUG_UNLOCK_V1=true. */
    public static function methods(AISearchHistory $root): array
    {
        if (!config('slug_access.unlock_enabled', false) || self::status($root) !== AISearchHistory::STATUS_PRIVATE) {
            return ['email' => false, 'password' => false];
        }
        return [
            'email'    => (bool) ($root->unlock_email_enabled ?? false),
            'password' => (bool) ($root->unlock_password_enabled ?? false) && !empty($root->unlock_password_hash),
        ];
    }

    public static function hasGrant(AISearchHistory $root): bool
    {
        $key = self::sessionKey($root->conversation_id);
        $g = Session::get($key);
        if (!is_array($g) || empty($g['token'])) {
            return false;
        }
        $valid = (int) ($g['v'] ?? 0) === self::GRANT_VERSION
            && (int) ($g['epoch'] ?? -1) === (int) ($root->unlock_epoch ?? 0)
            && (empty($g['expires_at']) || (int) $g['expires_at'] > time())
            && !empty(self::methods($root)[$g['method'] ?? ''] ?? false);
        if (!$valid) {
            // void / expired / revoked: drop it so legacy readers that only check isset($g['token']) stop trusting it
            Session::forget($key);
            Session::forget('has_private_access_' . $root->conversation_id);
            return false;
        }
        self::touchViewer($key, $g);
        return true;
    }

    /** Owner's viewer log: last seen + view count, written at most every 10 minutes per session. */
    private static function touchViewer(string $key, array $g): void
    {
        if (empty($g['log_id']) || (int) ($g['touched_at'] ?? 0) > time() - 600) {
            return;
        }
        try {
            DB::table('private_access_logs')->where('id', (int) $g['log_id'])
                ->update(['last_viewed_at' => now(), 'views' => DB::raw('views + 1')]);
        } catch (\Throwable $e) {
            // log columns missing (P2 migration not run): never block the view
        }
        $g['touched_at'] = time();
        Session::put($key, $g);
    }

    /** Called by SlugUnlockController after a successful email or password unlock. */
    public static function grant(AISearchHistory $root, string $method, ?string $email = null, ?int $logId = null): void
    {
        $minutes = max(5, (int) config('slug_access.grant_minutes', 10080));
        Session::put(self::sessionKey($root->conversation_id), [
            'v'               => self::GRANT_VERSION,
            'token'           => Str::random(48),
            'method'          => $method,
            'email'           => $email,
            'epoch'           => (int) ($root->unlock_epoch ?? 0),
            'granted_at'      => time(),
            'expires_at'      => time() + $minutes * 60,
            'log_id'          => $logId,
            'touched_at'      => time(),
            'conversation_id' => $root->conversation_id,
        ]);
        Session::put('has_private_access_' . $root->conversation_id, true);
    }

    public static function canView(AISearchHistory $root): bool
    {
        $status = self::status($root);
        if ($status === AISearchHistory::STATUS_PUBLIC) {
            return true;
        }
        if (self::isOwner($root)) {
            return true;
        }
        if ($status === AISearchHistory::STATUS_PRIVATE) {
            return self::hasGrant($root);
        }
        return false;   // hidden / unknown status: owner only
    }

    /** True when the session holds a valid unlock grant for this conversation (root must be private). */
    public static function hasGrantForConversation(?string $conversationId): bool
    {
        $root = self::rootOf($conversationId);
        return $root !== null && self::status($root) === AISearchHistory::STATUS_PRIVATE && self::hasGrant($root);
    }

    public static function rootOf(?string $conversationId): ?AISearchHistory
    {
        if (!$conversationId) {
            return null;
        }
        return AISearchHistory::where('conversation_id', $conversationId)->whereNull('parent_id')->orderBy('created_at')->first()
            ?? AISearchHistory::where('conversation_id', $conversationId)->orderBy('created_at')->first();
    }

    /** Same check for code that only has a conversation id (content APIs). */
    public static function canViewConversation(?string $conversationId): bool
    {
        if (!$conversationId) {
            return true;
        }
        $root = self::rootOf($conversationId);
        return $root ? self::canView($root) : true;
    }

    /** Headers for every non-public response (granted view, lock screen, 404). */
    public static function headers(): array
    {
        return [
            'Cache-Control'   => 'private, no-store, max-age=0',
            'Pragma'          => 'no-cache',
            'X-Robots-Tag'    => 'noindex, nofollow, noarchive',
            'Vary'            => 'Cookie, X-Inertia',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    public static function privatize($response)
    {
        if ($response instanceof Response) {
            foreach (self::headers() as $k => $v) {
                $response->headers->set($k, $v);
            }
        }
        return $response;
    }

    /**
     * Response for a viewer who may not see $root. $ref is what the visitor already typed
     * (the /X/ slug they used, or the X0000000 handle), never anything resolved from the DB.
     */
    public static function deny(Request $r, AISearchHistory $root, string $ref): Response
    {
        if ($r->expectsJson() && !$r->header('X-Inertia')) {
            return self::privatize(response()->json(['success' => false, 'error' => 'not_found', 'message' => 'Not found'], 404));
        }
        $m = self::methods($root);
        if ($m['email'] || $m['password']) {
            $resp = Inertia::render('SlugLocked', [
                'lock' => [
                    'ref'              => Str::limit($ref, 300, ''),
                    'email'            => $m['email'],
                    'password'         => $m['password'],
                    'request_password' => $m['password'],
                ],
            ])->withViewData(['noindex' => true])->toResponse($r);
            $resp->setStatusCode(403);
            return self::privatize($resp);
        }
        return self::privatize(response()->view('errors.slug-not-found', [], 404));
    }

    /**
     * Resolve what a visitor typed back to the root message: an X0000000 handle (EzFunnel token,
     * display mode "ai"), a custom-domain name on this host, or an /X/ slug.
     */
    public static function resolve(string $ref, ?string $host = null): ?AISearchHistory
    {
        $ref = trim(urldecode($ref));
        $ref = preg_replace('#^/(api/)?X/#', '', $ref);
        $ref = ltrim((string) $ref, '/');
        if ($ref === '' || mb_strlen($ref) > 300) {
            return null;
        }
        $aiid = EzFunnel::where('token', $ref)->value('aiid');
        if (!$aiid && $host) {
            $fid = Customdomain::where('domain', $ref)->where('domainselected', $host)->value('funnelid');
            $aiid = $fid ? EzFunnel::whereKey($fid)->value('aiid') : null;
        }
        $m = $aiid ? AISearchHistory::find($aiid) : AISearchHistory::where('slug', $ref)->first();
        return $m ? self::root($m) : null;
    }

    /* ---------- query helpers (sitemap, listings) ---------- */

    /** ids of every message whose conversation root is not public. */
    public static function nonPublicMessageIds(): Builder
    {
        return AISearchHistory::query()->select('id')->whereIn(
            'conversation_id',
            AISearchHistory::query()->select('conversation_id')->whereNull('parent_id')
                ->where('status', '!=', AISearchHistory::STATUS_PUBLIC)->whereNotNull('conversation_id')
        );
    }

    /** ids of EzFunnels (handles) that show a non-public conversation. */
    public static function nonPublicFunnelIds(): Builder
    {
        return EzFunnel::query()->select('id')->whereIn('aiid', self::nonPublicMessageIds());
    }

    /** where $column is NULL or not in $sub (a bare NOT IN would also drop NULL rows). */
    public static function whereNotInOrNull($query, string $column, $sub)
    {
        return $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, $sub));
    }
}
