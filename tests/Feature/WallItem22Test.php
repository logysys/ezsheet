<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Services\WallHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Item 22: the wall's default view (Cards/Beads) is owner-only and survives EzSheet pushes. */
class WallItem22Test extends TestCase
{
    use RefreshDatabase;

    private const TOK = 'owner-token-2222222222';
    private const OWN = ['x-wall-owner' => self::TOK, 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Wall::create(['slug' => 'w22', 'owner_hash' => WallHasher::owner(self::TOK), 'meta' => []]);
    }

    private function wallView(): string
    {
        return $this->getJson('/api/wall/w22/cards')->assertOk()->json('view');
    }

    public function test_default_is_cards_and_only_the_owner_can_change_it(): void
    {
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/push-to-wall', ['raw' => "a\n---\nb"])->assertOk();
        $this->flushHeaders();
        $this->assertSame('cards', $this->wallView());
        $this->postJson('/api/wall/w22/settings', ['view' => 'beads'])->assertStatus(403);
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/wall/w22/settings', ['view' => 'beads'], ['Sec-Fetch-Site' => 'same-origin'])->assertStatus(403);
        $this->assertSame('cards', $this->wallView());
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/settings', ['view' => 'beads'])->assertOk();
        $this->flushHeaders();
        $this->assertSame('beads', $this->wallView());
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/settings', ['view' => 'bogus'])->assertOk();
        $this->flushHeaders();
        $this->assertSame('beads', $this->wallView(), 'unknown values are ignored');
    }

    public function test_pushing_the_ezsheet_keeps_the_default_view(): void
    {
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/push-to-wall', ['raw' => "a\n---\nb"])->assertOk();
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/settings', ['view' => 'beads'])->assertOk();
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/push-to-wall', ['raw' => "a\n---\nb\n---\nc"])->assertOk();
        $this->withHeaders(self::OWN)->postJson('/api/wall/w22/push-to-wall', ['raw' => 'only one'])->assertOk();
        $this->flushHeaders();
        $this->assertSame('beads', $this->wallView());
    }
}
