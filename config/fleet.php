<?php

/*
| EZ-REL-20261016-3 — fleet host registry + cross-domain sign-in (SSO handoff).
| The database table `admindomains` is the source of truth. This file is only
| the safety net: hosts listed here keep working even if their row is missing
| or the database is unreachable.
*/

return [
    // The sign-in host. Sessions live here; other hosts get a 60-second handoff.
    'flagship' => env('SSO_FLAGSHIP_HOST', 'ez.wiki'),

    // Hosts that are never handled by the fleet catch-all (they get the normal app).
    'app_hosts' => array_values(array_filter(array_unique(array_merge(
        ['ez.wiki', 'www.ez.wiki', 'localhost', '127.0.0.1'],
        array_map('trim', explode(',', (string) env('FLEET_APP_HOSTS', ''))),
        [parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: null]
    )))),

    // Master switch for the handoff (EZBar "Sign in" on other hosts + silent check).
    'sso_enabled' => (bool) env('SSO_HANDOFF_ENABLED', true),

    // Silent "are you signed in on ez.wiki?" check, once per browser session per host.
    'silent_check' => (bool) env('SSO_SILENT_CHECK', true),

    // Handoff token lifetime (seconds). Do not raise above 120.
    'handoff_ttl' => (int) env('SSO_HANDOFF_TTL', 60),

    // Logging out on any host ends every session of that user (all hosts, all devices).
    'logout_everywhere' => (bool) env('SSO_LOGOUT_EVERYWHERE', true),

    // CSRF check on session/owner/upload/sign-in routes (StrictCsrfForSessionRoutes). Emergency switch only.
    'csrf_strict' => (bool) env('FLEET_CSRF_STRICT', true),

    // Registry cache seconds.
    'cache_ttl' => 300,

    // What an unknown / inactive host gets: 'parking' (404 page) or 'app' (old behaviour: home page).
    'unknown_host' => env('FLEET_UNKNOWN_HOST', 'parking'),

    // Fallback fleet list (= the 32 Route::domain groups that existed in 0c877cd).
    'fallback_domains' => [
        'ez3d.ai', 'xn--hny691d.com', 'xn--b9wm4lxz0afud.com', 'xn--h0t795as2et56a.com', 'set.net',
        'opensoothing.com', '0932.com', '0938.com', 'xn--42c5bb7ah3d5byb6m.com', 'xn--22c0cud.com',
        'xn--ll0b110a.love', 'xn--rht090b.wiki', '4u.wiki', 'b2c.wiki', 'no1.wiki', 'ez.wiki', 'ez3d.wiki',
        '81.wiki', '82.wiki', '7.wiki', 'big.wiki', 'xn--fiq228c.wiki', 'xn--kpry57d.wiki', 'bbcc.ai',
        'bbcc.ad', 'xn--fiq228cj5lj9c.com', 'oki.wiki', 'xn--2rq38u.com', 'blaig.chat', 'blaig.wiki',
        'whowhat.wiki', 'nug8.com',
    ],

    // Fixed mini-app hosts (they keep their own route groups; never a handoff target).
    'fixed_hosts' => [
        'genesis.nug8.com', 'spec.nug8.com', 'xn--6qq79v.nug8.com', 'xn--158h.nug8.com',
        'a1.nug8.com', 'a2.nug8.com', 'a3.nug8.com', 'a4.nug8.com', 'a5.nug8.com', 'a6.nug8.com',
        'a7.nug8.com', 'a8.nug8.com', 'a9.nug8.com', 'a15.nug8.com', 'a17.nug8.com', 'a18.nug8.com',
        'a19.nug8.com', 'a20.nug8.com', 'a21.nug8.com',
    ],
];
