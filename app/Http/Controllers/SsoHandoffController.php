<?php

namespace App\Http\Controllers;

use App\Models\LoginToken;
use App\Models\User;
use App\Support\HostRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * EZ-REL-20261016-3 — cross-domain sign-in ("handoff").
 *
 * Sessions stay host-only (no SESSION_DOMAIN). A signed-in user on the flagship
 * (ez.wiki) is handed to another fleet host with a one-time token:
 *   1. GET  https://ez.wiki/auth/handoff?to=<host>&r=<path>[&silent=1]
 *        -> token (32 random bytes; only sha256 stored in login_tokens, type 'handoff',
 *           bound to user + exact audience host, valid 60 s)
 *        -> 302 https://<host>/auth/handoff/claim?t=<token>&r=<path>
 *   2. GET  https://<host>/auth/handoff/claim  -> tiny page: strips the token from the
 *           URL (history.replaceState) and auto-POSTs it with the host's CSRF token
 *   3. POST https://<host>/auth/handoff/claim  -> atomic single use, exact host match,
 *           Auth::login + session regenerate, 302 to the safe local path
 * Refused: unknown / inactive hosts, fixed mini-app hosts, page-mode (user HTML) hosts,
 * the flagship itself, any r that is not a local path.
 */
class SsoHandoffController extends Controller
{
    public static function safePath($r): string
    {
        $r = (string) $r;
        if ($r === '' || strlen($r) > 2000) return '/';
        if ($r[0] !== '/' || str_starts_with($r, '//') || str_starts_with($r, '/\\')) return '/';
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $r)) return '/';
        return $r;
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private function scheme(Request $r): string
    {
        return app()->environment('production') ? 'https' : $r->getScheme();
    }

    private function refused(string $why, int $code = 403)
    {
        return response()->view('fleet.parking', ['title' => 'Sign-in not available here', 'text' => $why, 'host' => ''], $code)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Step 1 (flagship only). */
    public function start(Request $r)
    {
        $flag = HostRegistry::ascii(config('fleet.flagship'));
        if (HostRegistry::ascii($r->getHost()) !== $flag) {
            return redirect()->away($this->scheme($r) . '://' . $flag . '/auth/handoff?' . http_build_query($r->only(['to', 'r', 'silent'])));
        }
        $to = HostRegistry::ascii((string) $r->query('to'));
        $path = self::safePath($r->query('r', '/'));
        $silent = $r->boolean('silent');
        if ($to === '' || $to === $flag || !preg_match('/^[a-z0-9.-]+$/', $to) || !HostRegistry::handoffAllowed($to)) {
            return $this->refused('This site cannot receive an ez.wiki sign-in.');
        }
        $back = $this->scheme($r) . '://' . $to . '/auth/handoff/claim?';
        $user = Auth::user();
        if (!$user) {
            if ($silent) {
                return redirect()->away($back . http_build_query(['none' => 1, 'r' => $path]))->header('Cache-Control', 'no-store');
            }
            // sign in here first, then come back to this exact handoff
            $r->session()->put('url.intended', $r->fullUrlWithoutQuery(['silent']));
            return redirect('/login');
        }
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        LoginToken::create([
            'type' => 'handoff',
            'email' => (string) $user->email,
            'user_id' => (int) $user->getAuthIdentifier(),
            'audience_host' => $to,
            'token_hash' => self::hash($raw),
            'code_hash' => self::hash(random_bytes(16)),
            'attempts' => 0,
            'return_to' => $path,
            'expires_at' => now()->addSeconds(max(10, min(120, (int) config('fleet.handoff_ttl', 60)))),
        ]);
        return redirect()->away($back . http_build_query(['t' => $raw, 'r' => $path]))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Step 2 (target host): never logs in on GET; hands the token to a same-origin POST. */
    public function claimPage(Request $r)
    {
        $host = HostRegistry::ascii($r->getHost());
        if (!HostRegistry::handoffAllowed($host)) abort(404);
        $path = self::safePath($r->query('r', '/'));
        if ($r->has('none')) {
            $r->session()->put('sso_checked', time());
            return redirect($path . (str_contains($path, '?') ? '&' : '?') . '_sso=1')->header('Cache-Control', 'no-store');
        }
        $t = (string) $r->query('t');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) return $this->refused('This sign-in link is not valid.', 410);
        return response()->view('fleet.claim', ['t' => $t, 'r' => $path, 'csrf' => csrf_token()])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY');
    }

    /** Step 3 (target host). CSRF-checked (see StrictCsrfForSessionRoutes). */
    public function claim(Request $r)
    {
        $host = HostRegistry::ascii($r->getHost());
        $fail = fn () => $this->refused('This sign-in link has expired or was already used. Please sign in again.', 410);
        if (!HostRegistry::handoffAllowed($host)) return $fail();
        $t = (string) $r->input('t');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) return $fail();
        $row = LoginToken::where('type', 'handoff')->where('token_hash', self::hash($t))->first();
        if (!$row) return $fail();
        // single use: burn it first, whatever happens next (also burns wrong-host attempts)
        if (LoginToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) return $fail();
        if ($row->expires_at->isPast() || !hash_equals((string) $row->audience_host, $host)) return $fail();
        $user = User::find($row->user_id);
        if (!$user || strcasecmp((string) $user->email, (string) $row->email) !== 0) return $fail();

        Auth::login($user);
        $r->session()->regenerate();
        $r->session()->put('sso_checked', time());
        $r->session()->put('sso_epoch', (int) ($user->session_epoch ?? 0));
        return redirect(self::safePath($r->input('r', $row->return_to ?: '/')))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Sign-in links on a fleet host (/login) go through the flagship. */
    public function login(Request $r)
    {
        $host = HostRegistry::ascii($r->getHost());
        if (!HostRegistry::handoffAllowed($host)) {
            return redirect()->away($this->scheme($r) . '://' . HostRegistry::ascii(config('fleet.flagship')) . '/login');
        }
        $path = $r->query('r');
        if ($path === null) {
            $ref = parse_url((string) $r->headers->get('referer'));
            $path = (($ref['host'] ?? '') === $r->getHost()) ? (($ref['path'] ?? '/') . (isset($ref['query']) ? '?' . $ref['query'] : '')) : '/';
        }
        $url = $this->scheme($r) . '://' . HostRegistry::ascii(config('fleet.flagship')) . '/auth/handoff?'
            . http_build_query(['to' => $host, 'r' => self::safePath($path)]);
        return $r->header('X-Inertia') ? \Inertia\Inertia::location($url) : redirect()->away($url);
    }

    /** Ends every session of this user on every host and device. */
    public function logoutEverywhere(Request $r)
    {
        $user = Auth::user();
        if ($user) {
            User::whereKey($user->getAuthIdentifier())->increment('session_epoch');
            Auth::guard('web')->logout();
        }
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return $r->expectsJson() ? response()->json(['success' => true]) : redirect('/');
    }
}
