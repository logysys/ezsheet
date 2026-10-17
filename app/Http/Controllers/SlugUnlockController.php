<?php

namespace App\Http\Controllers;

use App\Mail\SlugPasswordRequestMail;
use App\Mail\SlugUnlockMail;
use App\Models\AISearchHistory;
use App\Models\LoginToken;
use App\Models\PrivateAccessLog;
use App\Models\SlugPasswordRequest;
use App\Models\User;
use App\Support\SlugAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * EZ-LOCK-20261017 P2: unlock a PRIVATE slug. The owner turns each method on per slug
 * (AIHistoryController::updatePrivateSettings); with none on, the slug is owner-only.
 *
 *  POST /slug-unlock/email/send        {ref, email, return_to}  any email; 6-digit code + link (hashed, single use)
 *  POST /slug-unlock/email/verify      {ref, email, code}       5 wrong codes = cancelled
 *  GET  /slug-unlock/email/{token}                              magic link
 *  POST /slug-unlock/password          {ref, password}          owner-set password; lockout per IP and per slug
 *  POST /slug-unlock/request-password  {ref, note}              signed-in visitors only; emails the owner
 *  GET  /ai/conversation/{id}/password-requests                 owner: list
 *  PATCH /ai/conversation/{id}/password-requests/{rid}          owner: {status: answered|dismissed}
 *
 * "ref" is what the visitor typed (X0000000 handle or the /X/ slug). Every answer for a slug that is
 * not private, unknown, or does not have that method on is the same 404 "not_available".
 * Storage reuses login_tokens (item 21) with type = 'slug_unlock', subject = conversation_id.
 * Codes, links and passwords are never logged.
 */
class SlugUnlockController extends Controller
{
    public const TYPE = 'slug_unlock';

    private function ttl(): int
    {
        return max(1, (int) config('slug_access.code_ttl', 10));
    }

    private static function tokenHash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private static function codeHash(string $email, string $conversationId, string $code): string
    {
        return hash_hmac('sha256', strtolower($email) . '|' . $conversationId . '|' . $code, (string) config('app.key'));
    }

    private function tooMany(string $key, int $max, int $decay): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }
        RateLimiter::hit($key, $decay);
        return false;
    }

    private function busy()
    {
        return response()->json(['success' => false, 'error' => 'throttled', 'message' => 'Too many tries. Please wait a few minutes and try again.'], 429)
            ->header('Cache-Control', 'no-store');
    }

    private function unavailable()
    {
        return response()->json(['success' => false, 'error' => 'not_available', 'message' => 'This page cannot be opened this way.'], 404)
            ->header('Cache-Control', 'no-store');
    }

    /** Root of the slug named by "ref" on this host, only if it is private and $method is on. */
    private function target(Request $r, string $method): ?AISearchHistory
    {
        $root = SlugAccess::resolve((string) $r->input('ref', ''), $r->getHost());
        if (!$root || SlugAccess::status($root) !== AISearchHistory::STATUS_PRIVATE) {
            return null;
        }
        return !empty(SlugAccess::methods($root)[$method]) ? $root : null;
    }

    private function logViewer(Request $r, AISearchHistory $root, string $method, ?string $email): PrivateAccessLog
    {
        return PrivateAccessLog::create([
            'conversation_id' => $root->conversation_id,
            'email'           => $email,
            'access_number'   => null,          // the old plaintext access number is never stored again
            'method'          => $method,
            'user_id'         => Auth::id(),
            'verified_at'     => now(),
            'last_viewed_at'  => now(),
            'views'           => 0,
            'ip_address'      => $r->ip(),
            'user_agent'      => Str::limit((string) $r->userAgent(), 500, ''),
        ]);
    }

    private function unlocked(Request $r, AISearchHistory $root, string $method, ?string $email): void
    {
        if ($r->hasSession()) {
            $r->session()->migrate(true);   // new session id after a privilege change (no fixation)
        }
        $log = $this->logViewer($r, $root, $method, $email);
        SlugAccess::grant($root, $method, $email, (int) $log->id);
    }

    /* ---------- email (any address) ---------- */

    public function sendEmail(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        if ($email === '' || strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'bad_email', 'message' => 'Please enter a valid email address.'], 422);
        }
        if ($this->tooMany('slug-send-ip:' . $r->ip(), 10, 600) || $this->tooMany('slug-send-em:' . sha1($email), 3, 600)) {
            return $this->busy();
        }
        $root = $this->target($r, 'email');
        if (!$root) {
            return $this->unavailable();
        }
        if ($this->tooMany('slug-send-slug:' . $root->id, 60, 3600)) {   // ceiling per slug (mail bombing)
            return $this->busy();
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // 43 chars
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cid = (string) $root->conversation_id;
        DB::transaction(function () use ($email, $raw, $code, $cid, $r) {
            LoginToken::where('type', self::TYPE)->where('email', $email)->where('subject', $cid)->whereNull('used_at')->update(['used_at' => now()]);
            LoginToken::create([
                'type'          => self::TYPE,
                'subject'       => $cid,
                'email'         => $email,
                'token_hash'    => self::tokenHash($raw),
                'code_hash'     => self::codeHash($email, $cid, $code),
                'attempts'      => 0,
                'return_to'     => MagicLinkController::safeReturn($r->input('return_to', '/'), $r),
                'audience_host' => strtolower($r->getHost()),
                'expires_at'    => now()->addMinutes($this->ttl()),
            ]);
        });

        try {
            Mail::to($email)->send(new SlugUnlockMail(url('/slug-unlock/email/' . $raw), $code, $this->ttl()));
        } catch (\Throwable $e) {
            Log::warning('Slug unlock mail failed: ' . get_class($e));   // no address, link or code in logs
        }

        return response()->json([
            'success'    => true,
            'message'    => 'We sent a 6-digit code and a link to ' . $email . '. They expire in ' . $this->ttl() . ' minutes. Check spam too.',
            'expires_in' => $this->ttl() * 60,
        ])->header('Cache-Control', 'no-store');
    }

    public function verifyEmail(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        $code = trim((string) $r->input('code', ''));
        if ($this->tooMany('slug-verify:' . $r->ip() . '|' . sha1($email), 5, 60)) {
            return $this->busy();
        }
        $root = $this->target($r, 'email');
        if (!$root) {
            return $this->unavailable();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            return response()->json(['success' => false, 'error' => 'bad_code', 'message' => 'Enter the 6-digit code from the email.'], 422);
        }
        $cid = (string) $root->conversation_id;
        $row = LoginToken::where('type', self::TYPE)->where('email', $email)->where('subject', $cid)
            ->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (!$row) {
            return response()->json(['success' => false, 'error' => 'expired', 'message' => 'This code has expired or was already used. Ask for a new one.'], 422);
        }
        if (!hash_equals($row->code_hash, self::codeHash($email, $cid, $code))) {
            $n = $row->attempts + 1;
            $row->attempts = $n;
            if ($n >= 5) {
                $row->used_at = now();
            }
            $row->save();
            return response()->json($n >= 5
                ? ['success' => false, 'error' => 'locked', 'message' => 'Too many wrong codes. Ask for a new one.']
                : ['success' => false, 'error' => 'wrong_code', 'message' => 'Wrong code. ' . (5 - $n) . ' tries left.'], 422);
        }
        if (LoginToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return response()->json(['success' => false, 'error' => 'expired', 'message' => 'This code has expired or was already used. Ask for a new one.'], 422);
        }
        RateLimiter::clear('slug-verify:' . $r->ip() . '|' . sha1($email));
        $this->unlocked($r, $root, 'email', $email);

        return response()->json(['success' => true, 'return_to' => $row->return_to ?: '/'])->header('Cache-Control', 'no-store');
    }

    public function consumeEmail(Request $r, string $token)
    {
        $fail = fn () => response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="robots" content="noindex">'
            . '<title>Link expired</title><body style="font-family:system-ui,sans-serif;max-width:440px;margin:15vh auto;padding:0 20px;color:#111">'
            . '<h1 style="font-size:20px">This link has expired or was already used</h1>'
            . '<p>Go back to the page and ask for a new code. Links work once.</p><p>此連結已失效或已使用，請回到頁面重新索取。</p>'
            . '<p><a href="/">ez.wiki</a></p></body>', 410
        )->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');

        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return $fail();
        }
        $row = LoginToken::where('type', self::TYPE)->where('token_hash', self::tokenHash($token))->first();
        if (!$row || $row->used_at || $row->expires_at->isPast()) {
            return $fail();
        }
        $root = SlugAccess::rootOf($row->subject);
        if (!$root || SlugAccess::status($root) !== AISearchHistory::STATUS_PRIVATE || empty(SlugAccess::methods($root)['email'])) {
            return $fail();
        }
        if (LoginToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return $fail();
        }
        $this->unlocked($r, $root, 'email', (string) $row->email);
        return redirect(MagicLinkController::safeReturn($row->return_to ?: '/', $r))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /* ---------- owner-set password ---------- */

    public function password(Request $r)
    {
        $root = $this->target($r, 'password');
        if (!$root) {
            return $this->unavailable();
        }
        $ipKey = 'slug-pw:' . $r->ip() . '|' . $root->id;     // 5 wrong per 15 min per IP and slug
        $slugKey = 'slug-pw-all:' . $root->id;               // 50 wrong per hour per slug from everywhere
        if (RateLimiter::tooManyAttempts($ipKey, 5) || RateLimiter::tooManyAttempts($slugKey, 50)) {
            return $this->busy();
        }
        $pw = (string) $r->input('password', '');
        if ($pw === '' || strlen($pw) > 200 || !Hash::check($pw, (string) $root->unlock_password_hash)) {
            RateLimiter::hit($ipKey, 900);
            RateLimiter::hit($slugKey, 3600);
            return response()->json(['success' => false, 'error' => 'wrong_password', 'message' => 'Wrong password.'], 422);
        }
        RateLimiter::clear($ipKey);
        $user = $r->user();
        $this->unlocked($r, $root, 'password', $user ? strtolower((string) $user->email) : null);
        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    public function requestPassword(Request $r)
    {
        $user = $r->user();
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'signed_out', 'message' => 'Please sign in first, so the owner knows who is asking.'], 401);
        }
        $root = $this->target($r, 'password');
        if (!$root) {
            return $this->unavailable();
        }
        if ($this->tooMany('slug-pwreq:' . $user->id . '|' . $root->id, 3, 86400) || $this->tooMany('slug-pwreq-u:' . $user->id, 20, 86400)) {
            return $this->busy();
        }
        $note = Str::limit(trim(strip_tags((string) $r->input('note', ''))), 500, '');
        SlugPasswordRequest::create([
            'conversation_id'   => $root->conversation_id,
            'requester_user_id' => $user->id,
            'requester_email'   => strtolower((string) $user->email),
            'note'              => $note !== '' ? $note : null,
            'ip_address'        => $r->ip(),
            'status'            => 'open',
        ]);
        $owner = $root->user_id ? User::find($root->user_id) : null;
        if ($owner && $owner->email) {
            try {
                $ref = (string) preg_replace('#^/(api/)?X/#', '', ltrim((string) $r->input('ref', ''), '/'));
                $path = preg_match('/^X\d{7}$/', $ref) ? '/' . $ref : '/X/' . $ref;
                Mail::to($owner->email)->send(new SlugPasswordRequestMail(
                    (string) $user->name, strtolower((string) $user->email), url($path), $note, url('/ai/history')
                ));
            } catch (\Throwable $e) {
                Log::warning('Slug password request mail failed: ' . get_class($e));
            }
        }
        return response()->json(['success' => true, 'message' => 'Request sent. The owner will reply to you by email.']);
    }

    /* ---------- owner: password requests ---------- */

    private function ownedRoot(string $conversationId): ?AISearchHistory
    {
        $root = SlugAccess::rootOf($conversationId);
        return ($root && $root->isOwnedBy(Auth::user(), Session::getId())) ? $root : null;
    }

    public function listPasswordRequests(string $conversationId)
    {
        if (!$this->ownedRoot($conversationId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $rows = SlugPasswordRequest::where('conversation_id', $conversationId)->latest('id')->limit(200)->get()
            ->map(fn ($q) => [
                'id'         => $q->id,
                'email'      => $q->requester_email,
                'note'       => $q->note,
                'status'     => $q->status,
                'created_at' => optional($q->created_at)->toISOString(),
            ]);
        return response()->json(['success' => true, 'data' => $rows, 'open' => $rows->where('status', 'open')->count()]);
    }

    public function updatePasswordRequest(Request $r, string $conversationId, int $requestId)
    {
        $r->validate(['status' => 'required|in:answered,dismissed,open']);
        if (!$this->ownedRoot($conversationId)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $n = SlugPasswordRequest::where('conversation_id', $conversationId)->whereKey($requestId)->update(['status' => $r->input('status')]);
        return $n ? response()->json(['success' => true]) : response()->json(['error' => 'Not found'], 404);
    }
}
