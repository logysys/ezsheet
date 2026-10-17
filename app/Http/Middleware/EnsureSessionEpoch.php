<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * EZ-REL-20261016-3 — "logout everywhere".
 * users.session_epoch is raised by POST /auth/logout-everywhere ("Sign out everywhere"), and by an ordinary
 * logout only when SSO_LOGOUT_EVERYWHERE=true (EZ-REL-20261017-3, item 36).
 * Every session remembers the epoch it was created with; an older session is
 * ended on its next request, on every host and every device.
 */
class EnsureSessionEpoch
{
    public static bool $forcing = false;

    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession() && Auth::guard('web')->check()) {
            $user = Auth::guard('web')->user();
            $current = (int) ($user->session_epoch ?? 0);
            $mine = $request->session()->get('sso_epoch');
            if ($mine === null) {
                $request->session()->put('sso_epoch', $current);
            } elseif ((int) $mine < $current) {
                self::$forcing = true;
                try {
                    Auth::guard('web')->logout();
                } finally {
                    self::$forcing = false;
                }
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
        return $next($request);
    }
}
