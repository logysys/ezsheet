<?php

namespace Tests\Feature;

use App\Models\Admindomain;
use App\Support\HostRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** EZ-REL-20261016-7: a registry row's status must never take a config fallback domain offline. */
class FleetFallbackAlwaysLiveTest extends TestCase
{
    use RefreshDatabase;

    private function on(string $host): static
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://' . $host);
        return $this;
    }

    public function test_every_fallback_domain_routes_even_with_an_inactive_or_empty_row(): void
    {
        $domains = (array) config('fleet.fallback_domains');
        $this->assertNotEmpty($domains);
        foreach ($domains as $i => $d) {
            Admindomain::create(['domain' => $d, 'days' => 'all', 'status' => $i % 2 ? '' : 'Inactive']);
        }
        Cache::forget(HostRegistry::CACHE_KEY);
        foreach ($domains as $d) {
            if (HostRegistry::isAppHost($d)) continue;   // ez.wiki etc. are the app itself
            $this->assertSame('apex', HostRegistry::resolve($d)['type'] ?? null, "$d apex");
            $this->assertSame('sub', HostRegistry::resolve('261016.' . $d)['type'] ?? null, "$d sub");
            $this->assertSame('sub', HostRegistry::resolve('www.' . $d)['type'] ?? null, "$d www");
        }
        foreach (['ez3d.ai', '4u.wiki', 'b2c.wiki'] as $d) {
            foreach ([$d, '261016.' . $d] as $h) {
                $res = $this->on($h)->get('/', ['Accept' => '*/*']);
                $this->assertNotSame(404, $res->getStatusCode(), $h);   // 200, or 302 silent-SSO hop
                $res->assertDontSee('This site is not active');
            }
        }
    }

    public function test_new_shipyard_domain_still_needs_active(): void
    {
        Admindomain::create(['domain' => 'shipyard-new.wiki', 'days' => 'all', 'status' => 'Inactive']);
        $this->assertSame('inactive', HostRegistry::resolve('x.shipyard-new.wiki')['type']);
        $this->on('x.shipyard-new.wiki')->get('/')->assertStatus(404);
        Admindomain::where('domain', 'shipyard-new.wiki')->first()->update(['status' => 'Active']);
        $this->on('x.shipyard-new.wiki')->get('/')->assertOk();
    }

    public function test_magic_login_route_exists_on_ez_wiki(): void
    {
        $res = $this->on('ez.wiki')->get('/login/magic/x');
        $this->assertNotSame(404, $res->getStatusCode());
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('login.magic'));
    }
}
