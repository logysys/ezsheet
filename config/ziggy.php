<?php

/*
 | EZ-LOCK-20261017 P0-15: visitors who are not signed in get only these route names in
 | @routes and in the Inertia "ziggy" prop (was: all ~239 routes, incl. admin/owner APIs).
 | The browser does not use Ziggy for navigation (resources/js/app.tsx installs its own
 | window.route polyfill); previewez.tsx only checks ziggy.routes.login.
 | Signed-in users still get the full list (unchanged behaviour).
 */
return [
    'groups' => [
        'guest' => [
            'home',
            'login',
            'register',
            'logout',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'magic-link.*',
            'login.magic',
            'ai.view',
            'ai.view.api',
            'public.*',
            'privacypolicy',
            'termsandconditions',
            'marketplace',
            'templatemarketplace',
            'sso.handoff',
            'sso.claim',
            'sso.claim.page',
            'wall.auth.*',
            'slug.unlock.*',
        ],
    ],
];
