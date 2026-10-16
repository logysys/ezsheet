<?php

namespace App\Http\Middleware;

use App\Support\HostRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * EZ-REL-20261016-3 — on a guest's first page view on a fleet host, ask the
 * flagship once (per browser session per host) whether the visitor is signed in
 * there. Never loops: the "checked" flag is stored BEFORE leaving, and the
 * return URL carries _sso=1, which is never redirected again (so even a browser
 * that drops cookies sees the page after one round trip).
 */
class SsoSilentCheck
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->isMethod('GET') || !config('fleet.silent_check', true) || !config('fleet.sso_enabled', true)
            || !$request->hasSession() || Auth::guard('web')->check()) {
            return $next($request);
        }
        if ($request->query->has('_sso')) {
            if ($request->session()->has('sso_checked')) {
                $q = $request->query(); unset($q['_sso']);
                return redirect($request->url() . ($q ? '?' . http_build_query($q) : ''));
            }
            return $next($request);
        }
        if ($request->session()->has('sso_checked')
            || !str_contains((string) $request->header('Accept'), 'text/html')
            || $request->header('X-Inertia') || $request->header('Purpose') === 'prefetch' || $request->header('Sec-Purpose')
            || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|headless/i', (string) $request->userAgent())) {
            return $next($request);
        }
        $host = HostRegistry::ascii($request->getHost());
        if (!HostRegistry::handoffAllowed($host)) {
            return $next($request);
        }
        $request->session()->put('sso_checked', time());
        $scheme = app()->environment('production') ? 'https' : $request->getScheme();
        $path = \App\Http\Controllers\SsoHandoffController::safePath($request->getRequestUri());
        return redirect()->away($scheme . '://' . HostRegistry::ascii(config('fleet.flagship')) . '/auth/handoff?'
            . http_build_query(['to' => $host, 'r' => $path, 'silent' => 1]))->header('Cache-Control', 'no-store');
    }
}
