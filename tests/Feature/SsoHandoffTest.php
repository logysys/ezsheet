<?php

namespace Tests\Feature;

use App\Models\Admindomain;
use App\Models\LoginToken;
use App\Models\User;
use App\Models\Wall;
use App\Support\HostRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EZ-REL-20261016-3: fleet host registry (catch-all), cross-domain sign-in handoff,
 * logout everywhere, strict same-origin + CSRF on session routes, unified wall settings.
 */
class SsoHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const SUB = '261016.ez.wiki';
    private const SUB2 = 'roy.4u.wiki';
    private const PAGE = 'mypage.ez.wiki';
    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->u = User::factory()->create(['email' => 'owner@example.test']);
        $ai = DB::table('ez_funnels')->insertGetId(['displaymode' => 'ai']);
        $page = DB::table('ez_funnels')->insertGetId(['displaymode' => 'page']);
        DB::table('domains')->insert([
            ['domain' => '261016', 'domainselected' => 'ez.wiki', 'funnelid' => $ai],
            ['domain' => 'roy', 'domainselected' => '4u.wiki', 'funnelid' => $ai],
            ['domain' => 'mypage', 'domainselected' => 'ez.wiki', 'funnelid' => $page],
        ]);
        HostRegistry::flush();
    }

    private function on(string $host): static
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://' . $host);   // test requests go to this host
        return $this;
    }

    /** fresh session token after a login / logout regenerated it */
    private function csrf(): array
    {
        $this->withSession(['_token' => self::CSRF]);
        return ['X-CSRF-TOKEN' => self::CSRF];
    }

    /** signed in on ez.wiki -> raw token from the redirect */
    private function handoffToken(string $to, string $r = '/X/w25'): string
    {
        $res = $this->on('ez.wiki')->actingAs($this->u)->get('/auth/handoff?to=' . $to . '&r=' . urlencode($r));
        $res->assertStatus(302);
        $loc = $res->headers->get('Location');
        $this->assertStringStartsWith('http://' . $to . '/auth/handoff/claim?t=', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        Auth::guard('web')->logout();
        $this->flushSession();
        return $q['t'];
    }

    // ---------- registry / catch-all ----------

    public function test_catch_all_resolves_subdomains_apex_and_parks_unknown_hosts(): void
    {
        $this->on(self::SUB)->get('/', ['Accept' => '*/*'])->assertOk()->assertSee('FUNNEL:261016:guest');
        $this->on('abc.xn--hny691d.com')->get('/')->assertOk()->assertSee('FUNNEL:abc');
        $this->on('4u.wiki')->get('/', ['Accept' => '*/*'])->assertOk()->assertSee('INDEX');
        $this->on('foo.not-ours.example')->get('/')->assertStatus(404)->assertSee('This site is not active');
        $this->on('a.b.4u.wiki')->get('/')->assertStatus(404);
    }

    public function test_registry_rows_win_activation_is_immediate_and_inactive_is_parked(): void
    {
        $this->on('x.new-fleet.wiki')->get('/')->assertStatus(404);
        $d = Admindomain::create(['domain' => 'new-fleet.wiki', 'days' => 'all', 'status' => 'Active']);
        $this->assertNotNull($d->fresh()->activated_at, 'activation hook stamps activated_at');
        $this->on('x.new-fleet.wiki')->get('/')->assertOk()->assertSee('FUNNEL:x');      // cache flushed on save
        Admindomain::create(['domain' => '4u.wiki', 'days' => 'all', 'status' => 'Inactive']);
        $this->assertNotSame(404, $this->on(self::SUB2)->get('/')->getStatusCode());                                    // EZ-REL-7: fallback domains are always live
        $this->assertSame('sub', HostRegistry::resolve(self::SUB)['type'], 'fallback keeps current domains');
    }

    public function test_seeder_registers_every_hard_coded_host_without_selling_them(): void
    {
        Admindomain::create(['domain' => 'ez.wiki', 'days' => 'all', 'status' => 'Active']);
        $this->seed(\Database\Seeders\FleetRegistrySeeder::class);
        $this->assertSame(32 + 19, DB::table('admindomains')->count());
        $this->assertSame('Active', DB::table('admindomains')->where('domain', 'ez.wiki')->value('status'));
        $this->assertSame('Routed', DB::table('admindomains')->where('domain', '4u.wiki')->value('status'));
        $this->assertSame('fixed', DB::table('admindomains')->where('domain', 'genesis.nug8.com')->value('kind'));
        $this->assertSame(0, (int) DB::table('admindomains')->where('domain', 'genesis.nug8.com')->value('sso_enabled'));
        $this->seed(\Database\Seeders\FleetRegistrySeeder::class);
        $this->assertSame(51, DB::table('admindomains')->count(), 'idempotent');
    }

    // ---------- handoff ----------

    public function test_handoff_signs_in_on_the_exact_host_and_strips_the_token(): void
    {
        $t = $this->handoffToken(self::SUB);
        $row = LoginToken::where('type', 'handoff')->first();
        $this->assertSame(self::SUB, $row->audience_host);
        $this->assertSame((int) $this->u->id, (int) $row->user_id);
        $this->assertSame(hash('sha256', $t), $row->token_hash, 'only the hash is stored');
        $this->assertLessThanOrEqual(61, now()->diffInSeconds($row->expires_at, true));

        $page = $this->on(self::SUB)->get('/auth/handoff/claim?t=' . $t . '&r=%2FX%2Fw25');
        $page->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('history.replaceState', false);
        $this->assertGuest();

        $h = $this->csrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t, 'r' => '/X/w25'], $h)->assertRedirect('http://' . self::SUB . '/X/w25');
        $this->assertAuthenticatedAs($this->u);
        $this->on(self::SUB)->get('/', ['Accept' => '*/*'])->assertSee('FUNNEL:261016:' . $this->u->id);
    }

    public function test_handoff_token_is_single_use_and_expires_after_60_seconds(): void
    {
        $t = $this->handoffToken(self::SUB);
        $h = $this->csrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t], $h)->assertRedirect();
        Auth::guard('web')->logout();
        $h = $this->csrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t], $h)->assertStatus(410);
        $this->assertGuest();

        $t2 = $this->handoffToken(self::SUB);
        $this->travel(61)->seconds();
        $h = $this->csrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t2], $h)->assertStatus(410);
        $this->assertGuest();
    }

    public function test_token_for_one_host_is_refused_and_burned_on_another(): void
    {
        $t = $this->handoffToken(self::SUB);
        $h = $this->csrf();
        $this->on(self::SUB2)->post('/auth/handoff/claim', ['t' => $t], $h)->assertStatus(410);
        $this->assertGuest();
        $h = $this->csrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t], $h)->assertStatus(410);   // burned
        $this->assertGuest();
    }

    public function test_no_open_redirect(): void
    {
        foreach (['//evil.example/x', 'https://evil.example/', '/\\evil.example', "/x\r\nLocation: http://evil"] as $bad) {
            $t = $this->handoffToken(self::SUB, $bad);
            $h = $this->csrf();
            $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t, 'r' => $bad], $h)->assertRedirect('http://' . self::SUB);
            Auth::guard('web')->logout();
        }
        $this->on('ez.wiki')->actingAs($this->u)->get('/auth/handoff?to=evil.example&r=/')->assertStatus(403)->assertHeaderMissing('Location');
        $this->assertSame(0, LoginToken::where('type', 'handoff')->where('audience_host', 'evil.example')->count());
    }

    public function test_page_mode_fixed_unregistered_and_flagship_hosts_are_refused(): void
    {
        foreach ([self::PAGE, 'genesis.nug8.com', 'x.unknown-host.example', 'nolabel.ez.wiki', 'ez.wiki'] as $to) {
            $this->on('ez.wiki')->actingAs($this->u)->get('/auth/handoff?to=' . $to)->assertStatus(403);
        }
        $this->assertSame(0, LoginToken::where('type', 'handoff')->count());
        $this->on(self::PAGE)->get('/auth/handoff/claim?t=' . str_repeat('a', 43))->assertStatus(404);
        Admindomain::create(['domain' => '4u.wiki', 'days' => 'all', 'status' => 'Active', 'sso_enabled' => false]);
        $this->on('ez.wiki')->actingAs($this->u)->get('/auth/handoff?to=' . self::SUB2)->assertStatus(403);
    }

    public function test_claim_without_csrf_token_is_419(): void
    {
        $t = $this->handoffToken(self::SUB);
        $this->withoutCsrf();
        $this->on(self::SUB)->post('/auth/handoff/claim', ['t' => $t])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_handoff_tokens_never_work_as_magic_links(): void
    {
        $t = $this->handoffToken(self::SUB);
        $this->on('ez.wiki')->get('/login/magic/' . $t)->assertStatus(410);
        $this->assertGuest();
    }

    // ---------- silent check / sign-in links ----------

    public function test_silent_check_runs_once_per_session_and_never_loops(): void
    {
        $html = ['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 Firefox'];
        $r = $this->on(self::SUB)->get('/', $html);
        $r->assertRedirect('http://ez.wiki/auth/handoff?to=' . self::SUB . '&r=%2F&silent=1');
        $this->on(self::SUB)->get('/', $html)->assertOk();                                // checked: no 2nd trip
        // flagship, guest, silent -> straight back with none=1 (no login page)
        $this->flushSession();
        $this->on('ez.wiki')->get('/auth/handoff?to=' . self::SUB . '&r=%2F&silent=1')
            ->assertRedirect('http://' . self::SUB . '/auth/handoff/claim?none=1&r=%2F');
        $this->on(self::SUB)->get('/auth/handoff/claim?none=1&r=%2F')->assertRedirect('http://' . self::SUB . '?_sso=1');
        $this->on(self::SUB)->get('/?_sso=1', $html)->assertRedirect('http://' . self::SUB);   // clean URL
        // cookies dropped: _sso=1 is served, never redirected again
        $this->flushSession();
        $this->on(self::SUB)->get('/?_sso=1', $html)->assertOk();
        // bots, curl and non-HTML requests never bounce
        $this->flushSession();
        $this->on(self::SUB)->get('/', ['Accept' => 'text/html', 'User-Agent' => 'Googlebot/2.1'])->assertOk();
        $this->on(self::SUB)->get('/', ['Accept' => '*/*', 'User-Agent' => 'Mozilla/5.0'])->assertOk();
        // page-mode hosts are never part of it
        $this->on(self::PAGE)->get('/', $html)->assertOk();
    }

    public function test_sign_in_link_on_a_fleet_host_goes_through_the_flagship(): void
    {
        $this->on(self::SUB)->get('/login?r=%2FX%2Fw25')
            ->assertRedirect('http://ez.wiki/auth/handoff?to=' . self::SUB . '&r=%2FX%2Fw25');
        $this->on(self::SUB)->get('/login?r=%2F%2Fevil.example')
            ->assertRedirect('http://ez.wiki/auth/handoff?to=' . self::SUB . '&r=%2F');
        $this->on(self::PAGE)->get('/login')->assertRedirect('http://ez.wiki/login');
        // not signed in on the flagship: login first, then back to the same handoff
        $this->on('ez.wiki')->get('/auth/handoff?to=' . self::SUB . '&r=%2FX%2Fw25')->assertRedirect('http://ez.wiki/login');
        $this->assertStringContainsString('/auth/handoff?', session('url.intended'));
    }

    // ---------- logout everywhere ----------

    public function test_logout_everywhere_ends_older_sessions_on_every_host(): void
    {
        $this->on(self::SUB)->actingAs($this->u)->withSession(['sso_epoch' => 0])
            ->getJson('/api/wall/auth/me')->assertJson(['authenticated' => true]);
        User::whereKey($this->u->id)->increment('session_epoch');        // logout on another host/device
        $this->actingAs($this->u->fresh());                                // (real requests reload the user)
        $this->on(self::SUB)->getJson('/api/wall/auth/me')->assertJson(['authenticated' => false]);
        $this->assertGuest();
    }

    public function test_plain_logout_ends_only_this_session_sign_out_everywhere_raises_the_epoch(): void
    {
        // item 36 (EZ-REL-20261017-3): an ordinary logout no longer signs out everywhere
        $this->assertFalse((bool) config('fleet.logout_everywhere'), 'default is false (config/fleet.php)');
        $this->actingAs($this->u);
        Auth::guard('web')->logout();
        $this->assertSame(0, (int) $this->u->fresh()->session_epoch, 'plain logout: other sessions stay');
        // ... unless SSO_LOGOUT_EVERYWHERE=true forces the old behaviour
        config(['fleet.logout_everywhere' => true]);
        $this->actingAs($this->u->fresh());
        Auth::guard('web')->logout();
        $this->assertSame(1, (int) $this->u->fresh()->session_epoch, 'forced: plain logout = everywhere');
        config(['fleet.logout_everywhere' => false]);
        // the explicit "Sign out everywhere" action always raises it
        $h = $this->csrf();
        $this->on('ez.wiki')->actingAs($this->u->fresh())->postJson('/auth/logout-everywhere', [], $h)->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) $this->u->fresh()->session_epoch);
    }

    // ---------- strict same-origin + CSRF on wall routes ----------

    public function test_owner_actions_need_exact_same_origin_and_csrf(): void
    {
        Wall::create(['slug' => 'w25', 'meta' => ['user_id' => $this->u->id]]);
        $this->actingAs($this->u);
        $this->withoutCsrf();
        $this->on(self::SUB)->postJson('/api/wall/w25/settings', ['view' => 'beads'])->assertStatus(419);
        $h = $this->csrf();
        $this->on(self::SUB)->postJson('/api/wall/w25/settings', ['view' => 'beads'], $h + ['Sec-Fetch-Site' => 'same-site'])->assertStatus(403);
        $this->on(self::SUB)->postJson('/api/wall/w25/settings', ['view' => 'beads'], $h + ['Origin' => 'https://evil.ez.wiki'])->assertStatus(403);
        $this->on(self::SUB)->postJson('/api/wall/w25/settings', ['view' => 'beads'], $h + ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://' . self::SUB])->assertOk();
        $this->on(self::SUB2)->postJson('/api/wall/w25/settings', ['view' => 'cards'], $h + ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://' . self::SUB2])->assertOk();
    }

    public function test_owner_default_beads_applies_to_guests_on_a_subdomain(): void
    {
        Wall::create(['slug' => 'w25', 'meta' => ['user_id' => $this->u->id]]);
        $h = $this->csrf();
        $this->on('ez.wiki')->actingAs($this->u)->postJson('/api/wall/w25/settings', ['view' => 'beads'], $h + ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        Auth::guard('web')->logout();
        $this->on(self::SUB)->getJson('/api/wall/w25/cards')->assertOk()->assertJson(['view' => 'beads']);
        $this->on(self::SUB2)->getJson('/api/wall/w25/cards')->assertOk()->assertJson(['view' => 'beads']);
    }

    public function test_anonymous_guest_posts_are_not_blocked_by_csrf(): void
    {
        $this->withoutCsrf();
        $res = $this->on(self::SUB)->postJson('/api/wall/w26/cards', ['url' => 'https://example.com/']);
        $this->assertNotSame(419, $res->status());
    }
}
