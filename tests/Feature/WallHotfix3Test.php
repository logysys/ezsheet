<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Models\WallCard;
use App\Services\WallHasher;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** hotfix3: owner auth via the Laravel session on /api/wall/*, Co-WiKi-only review / delete-all. */
class WallHotfix3Test extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wall = Wall::create(['slug' => 'hf3', 'owner_hash' => WallHasher::owner('tok-1234567890'), 'meta' => ['user_id' => $owner->id]]);
        $m = WallCard::create(['wall_id' => $wall->id, 'cid' => 'm1', 'content' => 'Mother', 'zone' => 'b', 'status' => 'approved', 'position' => 0]);
        WallCard::create(['wall_id' => $wall->id, 'cid' => 'c-ok', 'content' => 'Child ok', 'zone' => 'b', 'status' => 'approved', 'mother_id' => $m->id, 'anchor' => 'm1']);
        WallCard::create(['wall_id' => $wall->id, 'cid' => 'c-pend', 'content' => 'Child pend', 'zone' => 'b', 'status' => 'pending', 'mother_id' => $m->id, 'anchor' => 'm1']);
        return [$wall, $owner, $other];
    }

    /** Real session login (cookie -> StartSession -> SessionGuard), not actingAs(). */
    private function asSession(User $u): static
    {
        return $this->withSession(['login_web_' . sha1(SessionGuard::class) => $u->id]);
    }

    public function test_wall_api_routes_run_with_session_middleware(): void
    {
        foreach ([['GET', '/api/wall/x/review'], ['POST', '/api/wall/x/cards/c/approve'], ['DELETE', '/api/wall/x/cards/c'],
                  ['DELETE', '/api/wall/x/cards'], ['GET', '/api/wall/x/sheet'], ['POST', '/api/wall/x/push-to-wall'],
                  ['POST', '/api/wall/x/settings'], ['GET', '/api/wall/x/cards']] as [$m, $u]) {
            $route = Route::getRoutes()->match(Request::create($u, $m));
            $this->assertContains('web', $route->gatherMiddleware(), "$m $u must have the web (session) middleware");
        }
        $stream = Route::getRoutes()->match(Request::create('/api/wall/x/stream', 'GET'));
        $this->assertNotContains('web', $stream->gatherMiddleware(), 'SSE stream must not hold a session');
    }

    public function test_session_owner_can_review_approve_drop_sheet(): void
    {
        [$wall, $owner] = $this->setup3();
        $this->asSession($owner)->getJson('/api/wall/hf3/review')->assertOk();
        $this->asSession($owner)->getJson('/api/wall/hf3/sheet')->assertOk();
        $this->asSession($owner)->postJson('/api/wall/hf3/cards/c-pend/approve')->assertOk();
        $this->assertSame('approved', WallCard::where('cid', 'c-pend')->value('status'));
        $this->asSession($owner)->deleteJson('/api/wall/hf3/cards/c-ok')->assertOk();
        $this->assertNull(WallCard::where('cid', 'c-ok')->first());
    }

    public function test_anonymous_other_user_and_client_flags_are_denied(): void
    {
        [$wall, , $other] = $this->setup3();
        $this->getJson('/api/wall/hf3/review')->assertStatus(403);
        $this->withHeaders(['x-is-owner' => '1', 'x-user-id' => '1'])->getJson('/api/wall/hf3/review?is_owner=1')->assertStatus(403);
        $this->asSession($other)->getJson('/api/wall/hf3/review')->assertStatus(403);
        $this->asSession($other)->postJson('/api/wall/hf3/cards/c-pend/approve')->assertStatus(403);
        $this->deleteJson('/api/wall/hf3/cards')->assertStatus(403);
        $this->getJson('/api/wall/hf3/sheet')->assertStatus(403);
        $this->postJson('/api/wall/hf3/push-to-wall', ['raw' => 'x'])->assertStatus(403);
        $this->assertSame(3, WallCard::count());
    }

    public function test_cross_site_write_with_owner_session_is_denied(): void
    {
        [$wall, $owner] = $this->setup3();
        $this->asSession($owner)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->deleteJson('/api/wall/hf3/cards')->assertStatus(403);
        $this->asSession($owner)->withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/wall/hf3/cards/c-pend/approve')->assertStatus(403);
        $this->assertSame(3, WallCard::count());
        // Same-origin write is fine.
        $this->asSession($owner)->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://localhost'])
            ->postJson('/api/wall/hf3/cards/c-pend/approve')->assertOk();
    }

    public function test_owner_token_still_works(): void
    {
        $this->setup3();
        $this->withHeaders(['x-wall-owner' => 'tok-1234567890'])->getJson('/api/wall/hf3/review')->assertOk();
        $this->withHeaders(['x-wall-owner' => 'wrong'])->getJson('/api/wall/hf3/review')->assertStatus(403);
    }

    public function test_review_lists_cowiki_children_only_pending_first(): void
    {
        [$wall, $owner] = $this->setup3();
        $j = $this->asSession($owner)->getJson('/api/wall/hf3/review')->assertOk()->json();
        $this->assertSame(['c-pend', 'c-ok'], array_column($j['cards'], 'cid'));
        $this->assertSame(['child', 'child'], array_column($j['cards'], 'role'));
        $this->assertSame(1, $j['pending_count']);
    }

    public function test_review_of_unknown_wall_is_404_and_creates_nothing(): void
    {
        $this->getJson('/api/wall/nope-hf3/review')->assertStatus(404);
        $this->assertSame(0, Wall::where('slug', 'nope-hf3')->count());
    }

    public function test_drop_all_deletes_children_only(): void
    {
        [$wall, $owner] = $this->setup3();
        $j = $this->asSession($owner)->deleteJson('/api/wall/hf3/cards')->assertOk()->json();
        $this->assertSame(2, $j['deleted']);
        $this->assertSame(['m1'], WallCard::pluck('cid')->all());
    }
}
