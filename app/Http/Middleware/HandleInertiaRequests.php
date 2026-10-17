<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            // EZ-LOCK-20261017 P0-15: visitors who are not signed in get only the "guest" route group
            // (config/ziggy.php), not the full backend route map.
            'ziggy' => fn (): array => [
                ...(new Ziggy($request->user() ? null : 'guest'))->toArray(),
                'location' => $request->url(),
            ],
            // EZ-REL-20261016-3: cross-domain sign-in (wall "Sign in" goes through ez.wiki)
            'sso' => [
                'flagship' => (string) config('fleet.flagship', 'ez.wiki'),
                'handoff' => (bool) \Illuminate\Support\Facades\Cache::remember(
                    'fleet.ho.' . \App\Support\HostRegistry::ascii($request->getHost()), 60,
                    fn () => \App\Support\HostRegistry::handoffAllowed($request->getHost()) ? 1 : 0),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
