<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Reddit\Provider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('reddit', Provider::class);
        });

        // EZ-REL-20261016-3: logout everywhere (users.session_epoch).
        // EZ-REL-20261017-3 (item 36): an ordinary logout bumps the epoch ONLY when SSO_LOGOUT_EVERYWHERE=true
        // (config fleet.logout_everywhere, default false). "Sign out everywhere" (SsoHandoffController::logoutEverywhere) always does.
        Event::listen(function (\Illuminate\Auth\Events\Login $e) {
            try {
                if (request()->hasSession()) {
                    request()->session()->put('sso_epoch', (int) ($e->user->session_epoch ?? 0));
                }
            } catch (\Throwable $x) {}
        });
        Event::listen(function (\Illuminate\Auth\Events\Logout $e) {
            if (!$e->user || \App\Http\Middleware\EnsureSessionEpoch::$forcing || !config('fleet.logout_everywhere', false)) return;
            try {
                \App\Models\User::whereKey($e->user->getAuthIdentifier())->increment('session_epoch');
            } catch (\Throwable $x) {}   // column missing before migrate: plain logout still works
        });
    }
}