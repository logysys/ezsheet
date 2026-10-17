<?php

namespace Tests\Feature;

use App\Models\AISearchHistory;
use App\Models\User;
use App\Models\Wall;
use App\Services\WallHasher;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EZ-REL-20261017-3, item 36: who owns a wall, and why a push is refused.
 *  - 401 signed_out / 403 cross_origin / 403 not_owner (+ masked signed-in email)
 *  - a wall without owner_hash no longer makes every signed-in user its owner
 *  - owner = meta.user_id, else the owner of the wall's message
 *  - GET /api/wall/{id}/me
 */
class WallOwnerStatusTest extends TestCase
{
    use RefreshDatabase;

    private const TOK = 'tok-owner-1234567890';

    private function asSession(User $u): static
    {
        $this->app['auth']->forgetGuards();   // a new "request" as $u (the guard caches the last user)
        return $this->withSession(['login_web_' . sha1(SessionGuard::class) => $u->id]);
    }

    private function same(): array
    {
        return ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://localhost'];
    }

    private function push(string $slug, array $extra = [], array $headers = [])
    {
        return $this->withHeaders($headers + $this->same())->postJson("/api/wall/$slug/push-to-wall", ['raw' => "B1\n---\nB2"] + $extra);
    }

    public function test_signed_out_push_is_401_signed_out(): void
    {
        $owner = User::factory()->create();
        Wall::create(['slug' => 'w36a', 'meta' => ['user_id' => $owner->id]]);
        $this->push('w36a')->assertStatus(401)->assertJson(['success' => false, 'error' => 'signed_out']);
    }

    public function test_other_account_is_403_not_owner_with_masked_email(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['email' => 'someone@example.org']);
        Wall::create(['slug' => 'w36b', 'meta' => ['user_id' => $owner->id]]);
        $r = $this->asSession($other)->push('w36b')->assertStatus(403)->assertJson(['error' => 'not_owner', 'signed_in_as' => 'so***@***.org']);
        $this->assertStringNotContainsString('someone@example.org', $r->getContent(), 'never the full email');
    }

    public function test_owner_session_from_another_site_is_403_cross_origin(): void
    {
        $owner = User::factory()->create();
        Wall::create(['slug' => 'w36c', 'meta' => ['user_id' => $owner->id]]);
        $this->asSession($owner)->push('w36c', [], ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example'])
            ->assertStatus(403)->assertJson(['error' => 'cross_origin']);
    }

    public function test_meta_owner_pushes(): void
    {
        $owner = User::factory()->create();
        Wall::create(['slug' => 'w36d', 'meta' => ['user_id' => $owner->id]]);
        $this->asSession($owner)->push('w36d')->assertOk()->assertJson(['success' => true]);
    }

    public function test_message_owner_via_meta_message_id_pushes_and_others_do_not(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $m = AISearchHistory::create(['user_id' => $owner->id, 'slug' => 'conversation-36', 'conversation_id' => 'conversation-36']);
        Wall::create(['slug' => 'w36e', 'meta' => ['message_id' => $m->id]]);
        $this->asSession($other)->push('w36e')->assertStatus(403)->assertJson(['error' => 'not_owner']);
        $this->asSession($owner)->push('w36e')->assertOk();
    }

    public function test_message_naming_the_wall_gives_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        AISearchHistory::create(['user_id' => $owner->id, 'slug' => 'conversation-37', 'social_media_metadata' => ['wall_id' => 'w36f']]);
        Wall::create(['slug' => 'w36f', 'meta' => []]);
        $this->asSession($other)->push('w36f')->assertStatus(403);
        $this->asSession($owner)->push('w36f')->assertOk();
    }

    public function test_hole_closed_no_owner_hash_no_longer_means_anyone_signed_in(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Wall::create(['slug' => 'w36g', 'owner_hash' => null, 'meta' => ['user_id' => $owner->id]]);
        $this->asSession($other)->push('w36g')->assertStatus(403)->assertJson(['error' => 'not_owner']);
        // and an orphan wall (no owner at all) is not open to every signed-in user either
        Wall::create(['slug' => 'w36h', 'owner_hash' => null, 'meta' => []]);
        $this->asSession($other)->push('w36h')->assertStatus(403);
    }

    public function test_owner_key_still_works_on_its_own_and_a_wrong_key_does_not(): void
    {
        $owner = User::factory()->create();
        Wall::create(['slug' => 'w36i', 'owner_hash' => WallHasher::owner(self::TOK), 'meta' => ['user_id' => $owner->id]]);
        $this->push('w36i', [], ['x-wall-owner' => self::TOK])->assertOk();
        $this->push('w36i', [], ['x-wall-owner' => 'wrong-key'])->assertStatus(401);
    }

    public function test_orphan_claim_only_with_a_message_that_names_this_wall_then_bound(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        AISearchHistory::create(['user_id' => $other->id, 'slug' => 'conv-other', 'social_media_metadata' => ['wall_id' => 'zzz']]);
        AISearchHistory::create(['user_id' => $owner->id, 'slug' => 'conv-mine-elsewhere', 'social_media_metadata' => ['wall_id' => 'zzz']]);
        $wall = Wall::create(['slug' => 'w36j', 'meta' => []]);
        // other user's message that names another wall: no
        $this->asSession($other)->push('w36j', ['slug' => 'conv-other'])->assertStatus(403);
        // my own message, but it names another wall: no
        $this->asSession($owner)->push('w36j', ['slug' => 'conv-mine-elsewhere'])->assertStatus(403);
        AISearchHistory::create(['user_id' => $owner->id, 'slug' => 'conv-mine', 'social_media_metadata' => ['wall_id' => 'zzz-not-yet']]);
        $this->asSession($owner)->push('w36j', ['slug' => 'conv-mine'])->assertStatus(403);
        // a message of mine that names THIS wall -> claim, then meta.user_id is stored
        AISearchHistory::where('slug', 'conv-mine')->first()->update(['social_media_metadata' => ['wall_id' => 'w36k']]);
        $wall2 = Wall::create(['slug' => 'w36k', 'meta' => []]);
        // (w36k is now found by social_media_metadata.wall_id directly, so it is owned, not claimed)
        $this->asSession($owner)->push('w36k')->assertOk();
        $this->assertSame($owner->id, (int) ($wall2->fresh()->meta['user_id'] ?? 0), 'first push binds meta.user_id');
        $this->assertTrue(empty($wall->fresh()->meta['user_id']), 'refused pushes bind nothing');
        $this->asSession($other)->push('w36k')->assertStatus(403);
    }

    public function test_me_endpoint(): void
    {
        $owner = User::factory()->create(['email' => 'royfang@m-commerce.com', 'name' => 'Roy']);
        $other = User::factory()->create();
        Wall::create(['slug' => 'w36m', 'owner_hash' => WallHasher::owner(self::TOK), 'meta' => ['user_id' => $owner->id]]);

        $r = $this->getJson('/api/wall/w36m/me')->assertOk()
            ->assertJson(['authenticated' => false, 'user' => null, 'is_owner' => false, 'reason' => 'signed_out', 'wall' => 'w36m']);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));

        $this->withHeaders(['x-wall-owner' => self::TOK])->getJson('/api/wall/w36m/me')
            ->assertJson(['authenticated' => false, 'is_owner' => true, 'via' => 'token']);
        $this->flushHeaders();

        $r = $this->asSession($owner)->getJson('/api/wall/w36m/me')
            ->assertJson(['authenticated' => true, 'is_owner' => true, 'via' => 'session', 'user' => ['id' => $owner->id, 'email' => 'ro***@***.com']]);
        $this->assertStringNotContainsString('royfang@', $r->getContent());

        $this->asSession($other)->getJson('/api/wall/w36m/me')
            ->assertJson(['authenticated' => true, 'is_owner' => false, 'reason' => 'not_owner']);

        // unknown wall: answers, creates nothing
        $this->getJson('/api/wall/nope36/me')->assertOk()->assertJson(['is_owner' => false, 'wall' => null]);
        $this->assertNull(Wall::where('slug', 'nope36')->first());
        // the auth/me route is not shadowed
        $this->getJson('/api/wall/auth/me')->assertOk()->assertJsonMissing(['wall' => 'auth']);
    }
}
