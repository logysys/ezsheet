<?php

namespace App\Http\Controllers;

use App\Support\HostRegistry;
use Illuminate\Http\Request;

/**
 * EZ-REL-20261016-3 — the ONE catch-all that replaced the 32 hard-coded
 * Route::domain('{subdomain}.<fleet>') groups. Driven by the admindomains registry.
 *   label.<live fleet domain>  -> WelcomeController@domainfunnel(label)   (as before)
 *   <live fleet domain> (apex) -> WelcomeController@index                 (as before)
 *   unknown / inactive host    -> 404 parking page (config fleet.unknown_host)
 */
class FleetHostController extends Controller
{
    public function home(Request $r)
    {
        $res = HostRegistry::resolve($r->getHost());
        switch ($res['type']) {
            case 'sub':
            case 'fixed':
                return app()->call([app(WelcomeController::class), 'domainfunnel'], ['query' => $res['label']]);
            case 'apex':
            case 'app':
                return app()->call([app(WelcomeController::class), 'index']);
        }
        if (config('fleet.unknown_host') === 'app') {
            return app()->call([app(WelcomeController::class), 'index']);
        }
        return response()->view('fleet.parking', [
            'title' => 'This site is not active',
            'text' => 'There is nothing published at this address yet.',
            'host' => HostRegistry::utf8($res['host']),
        ], 404)->header('Cache-Control', 'public, max-age=60');
    }
}
