<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Base;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;

/**
 * EZ-REL-20261016-3 — CSRF back on for the routes that act with a session.
 * (bootstrap/app.php still lists '*' in the global except list, so the hundreds of
 * legacy routes are unchanged; this middleware runs after it in the 'web' group.)
 *
 *  ALWAYS  : sign-in / handoff / logout-everywhere (login CSRF protection)
 *  SESSION : wall, owner and upload routes — checked whenever the request is
 *            signed in (the only case where a forged cross-site request could act
 *            as someone). Anonymous guest card posts from exported standalone
 *            walls on other origins keep working.
 * Accepts _token, X-CSRF-TOKEN, or X-XSRF-TOKEN (the XSRF-TOKEN cookie Laravel sets;
 * axios/Inertia send it automatically; wall-core sends it since this release).
 */
class StrictCsrfForSessionRoutes extends Base
{
    public const ALWAYS = [
        'auth/handoff/*', 'auth/logout-everywhere',
        'api/wall/auth/*', 'login/magic-link',
    ];

    public const SESSION = [
        'api/wall/*', 'wall/*', 'content/upload', 'content/wiki/upload-file',
        'content/update-upload',
    ];

    protected $except = [];

    public function handle($request, Closure $next)
    {
        if ($this->isReading($request) || !config('fleet.csrf_strict', true)) {
            return $next($request);
        }
        $always = $request->is(...self::ALWAYS);
        $session = !$always && $request->is(...self::SESSION) && Auth::guard('web')->check();
        if (($always || $session) && !$this->tokensMatch($request)) {
            throw new TokenMismatchException('CSRF token mismatch.');
        }
        return $next($request);
    }
}
