<?php

namespace App\Http\Controllers;

use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Item 21: magic-link sign-in (replaces the 4-digit OTP).
 *  POST /api/wall/auth/magic/send    {email, return_to}  -> always the same generic answer
 *  POST /api/wall/auth/magic/verify  {email, code}       -> 6-digit backup code (5 wrong = cancelled)
 *  GET  /login/magic/{token}                             -> sign in, regenerate session, back to the wall
 * Only hashes are stored. Codes, links and tokens are never logged.
 */
class MagicLinkController extends Controller
{
    public const GENERIC = 'If that address can receive email, we sent a sign-in link and a 6-digit code. They expire in 15 minutes.';

    private function ttl(): int
    {
        return (int) config('magic_link.ttl', 15);
    }

    private static function tokenHash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private static function codeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', strtolower($email) . '|' . $code, (string) config('app.key'));
    }

    /** Same-site path only: "/x", or an absolute http(s) URL on this host (reduced to its path). Else "/". */
    public static function safeReturn($v, Request $r): string
    {
        $v = trim((string) $v);
        if ($v === '' || strlen($v) > 2000 || preg_match('/[\x00-\x1F\x7F\\\\]/', $v)) {
            return '/';
        }
        if ($v[0] === '/') {
            return (strlen($v) > 1 && ($v[1] === '/')) ? '/' : $v;
        }
        $u = parse_url($v);
        if (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($u['host']) || strcasecmp($u['host'], $r->getHost()) !== 0 || isset($u['user']) || isset($u['pass'])) {
            return '/';
        }
        $path = $u['path'] ?? '/';
        if ($path === '' || $path[0] !== '/' || (strlen($path) > 1 && $path[1] === '/')) {
            $path = '/';
        }
        return $path . (isset($u['query']) ? '?' . $u['query'] : '') . (isset($u['fragment']) ? '#' . $u['fragment'] : '');
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
        return response()->json(['success' => false, 'error' => 'throttled', 'message' => 'Too many requests. Please wait a few minutes and try again.'], 429);
    }

    public function send(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        if ($email === '' || strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'error' => 'bad_email', 'message' => 'Please enter a valid email address.'], 422);
        }
        // per IP (10 / 10 min) and per email (3 / 10 min); identical for known and unknown emails
        if ($this->tooMany('magic-send-ip:' . $r->ip(), 10, 600) || $this->tooMany('magic-send-em:' . sha1($email), 3, 600)) {
            return $this->busy();
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');   // 43 chars, 256 bits
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($email, $raw, $code, $r) {
            // a new request cancels older unused ones for this email
            LoginToken::where('type', 'login')->where('email', $email)->whereNull('used_at')->update(['used_at' => now()]);
            LoginToken::create([
                'email'      => $email,
                'token_hash' => self::tokenHash($raw),
                'code_hash'  => self::codeHash($email, $code),
                'attempts'   => 0,
                'return_to'  => self::safeReturn($r->input('return_to', '/'), $r),
                'expires_at' => now()->addMinutes($this->ttl()),
            ]);
        });
        $url = url('/login/magic/' . $raw);

        try {
            Mail::to($email)->send(new MagicLinkMail($url, $code, $this->ttl()));
        } catch (\Throwable $e) {
            Log::warning('Magic link mail failed: ' . get_class($e));   // no address, link or code in logs
        }

        $out = ['success' => true, 'message' => self::GENERIC, 'expires_in' => $this->ttl() * 60];
        // TEMPORARY test convenience: only when MAGIC_LINK_TEST_EMAILS is set and lists this email
        $test = (array) config('magic_link.test_emails', []);
        if ($test && in_array($email, $test, true)) {
            $out['test_link'] = $url;
        }
        return response()->json($out);
    }

    /** Finds or creates the user (old accounts are matched by email), signs in, regenerates the session. */
    private function signIn(Request $r, string $email): User
    {
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user) {
            $base = Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $email)[0]) ?: 'user', 40, '');
            $name = $base;
            for ($i = 1; User::where('name', $name)->exists(); $i++) {
                $name = $base . $i;
            }
            $user = User::create([
                'name'              => $name,
                'email'             => $email,
                'email_verified_at' => now(),
                'password'          => Hash::make(Str::random(40)),
            ]);
        } elseif (!$user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        Auth::login($user, true);
        if ($r->hasSession()) {
            $r->session()->regenerate();
            $r->session()->put('wall_guest_user', ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email]);
        }
        return $user;
    }

    public function verify(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        $code = trim((string) $r->input('code', $r->input('otp', '')));
        if ($this->tooMany('magic-verify:' . $r->ip() . '|' . sha1($email), 5, 60)) {
            return $this->busy();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            return response()->json(['success' => false, 'error' => 'bad_code', 'message' => 'Enter the 6-digit code from the email.'], 422);
        }
        $row = LoginToken::where('type', 'login')->where('email', $email)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (!$row) {
            return response()->json(['success' => false, 'error' => 'expired', 'message' => 'This code has expired or was already used. Request a new link.'], 422);
        }
        if (!hash_equals($row->code_hash, self::codeHash($email, $code))) {
            $n = $row->attempts + 1;
            $row->attempts = $n;
            if ($n >= 5) {
                $row->used_at = now();   // cancelled
            }
            $row->save();
            return response()->json($n >= 5
                ? ['success' => false, 'error' => 'locked', 'message' => 'Too many wrong codes. Request a new link.']
                : ['success' => false, 'error' => 'wrong_code', 'message' => 'Wrong code. ' . (5 - $n) . ' tries left.'], 422);
        }
        // single use: only the request that flips used_at wins
        if (LoginToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return response()->json(['success' => false, 'error' => 'expired', 'message' => 'This code has expired or was already used. Request a new link.'], 422);
        }
        RateLimiter::clear('magic-verify:' . $r->ip() . '|' . sha1($email));
        $user = $this->signIn($r, $email);
        return response()->json([
            'success'   => true,
            'message'   => 'You are signed in.',
            'user'      => ['id' => (int) $user->id, 'name' => (string) $user->name, 'email' => (string) $user->email],
            'return_to' => $row->return_to ?: '/',
        ]);
    }

    public function consume(Request $r, string $token)
    {
        $fail = fn () => response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Sign-in link</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:440px;margin:15vh auto;padding:0 20px;color:#111">'
            . '<h1 style="font-size:20px">This sign-in link has expired or was already used</h1>'
            . '<p>Go back to the wall and ask for a new link. Links work once and expire after 15 minutes.</p>'
            . '<p><a href="/">ez.wiki</a></p></body>', 410
        )->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');

        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return $fail();
        }
        $row = LoginToken::where('type', 'login')->where('token_hash', self::tokenHash($token))->first();
        if (!$row || $row->used_at || $row->expires_at->isPast()) {
            return $fail();
        }
        if (LoginToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return $fail();
        }
        $this->signIn($r, $row->email);
        return redirect(self::safeReturn($row->return_to ?: '/', $r))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
