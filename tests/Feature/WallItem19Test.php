<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Models\WallCard;
use App\Services\WallHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Item 19: owner pin / unpin. Stored in walls.meta.pins (no migration). */
class WallItem19Test extends TestCase
{
    use RefreshDatabase;

    private const OWNER_TOKEN = 'owner-token-1919191919';
    private const OWN = ['x-wall-owner' => self::OWNER_TOKEN, 'Accept' => 'application/json'];
    private Wall $wall;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->wall = Wall::create(['slug' => 'w19', 'owner_hash' => WallHasher::owner(self::OWNER_TOKEN), 'meta' => []]);
        $this->withHeaders(self::OWN)->postJson('/api/wall/w19/push-to-wall', ['raw' => "Topic title\n===\nAd text\n###\nm1\n---\nm2\n---\nm3"])->assertOk();
        $this->flushHeaders();
    }

    private function m(string $raw): WallCard
    {
        return WallCard::where('wall_id', $this->wall->id)->whereNull('mother_id')->where('content', $raw)->firstOrFail();
    }

    private function child(string $motherCid, string $raw, bool $approve): string
    {
        $r = $this->postJson('/api/wall/w19/cards', ['raw' => $raw, 'anchor_cid' => $motherCid], ['Accept' => 'application/json']);
        $r->assertSuccessful();
        $cid = WallCard::where('content', $raw)->firstOrFail()->cid;
        if ($approve) {
            $this->withHeaders(self::OWN)->postJson("/api/wall/w19/cards/$cid/approve")->assertOk();
            $this->flushHeaders();
        }
        return $cid;
    }

    private function pin(string $cid, bool $on = true, array $h = self::OWN)
    {
        $r = $this->withHeaders($h)->postJson("/api/wall/w19/cards/$cid/pin", ['pinned' => $on]);
        $this->flushHeaders();
        return $r;
    }

    private function order(): array
    {
        $d = $this->getJson('/api/wall/w19/cards')->json();
        $by = collect($d['cards'])->keyBy('cid');
        return array_map(fn ($cid) => $by[$cid]['content'] . ($by[$cid]['pinned'] ? '*' : ''), $d['order']);
    }

    public function test_content_zone_layout_assumption(): void
    {
        $this->assertSame('b', $this->m('m1')->zone);
        $this->assertSame('a', $this->m('Topic title')->zone);
        $this->assertSame('c', $this->m('Ad text')->zone);
    }

    public function test_guest_and_signed_in_non_owner_get_403(): void
    {
        $cid = $this->m('m2')->cid;
        $this->pin($cid, true, ['Accept' => 'application/json'])->assertStatus(403);
        $u = User::factory()->create();
        $this->actingAs($u)->postJson("/api/wall/w19/cards/$cid/pin", ['pinned' => true], ['Sec-Fetch-Site' => 'same-origin'])->assertStatus(403);
        $this->assertArrayNotHasKey('pins', (array) Wall::find($this->wall->id)->meta);
    }

    public function test_pinned_mothers_go_first_newest_pin_first_with_family_then_unpin_restores(): void
    {
        $before = $this->order();
        $c3 = $this->child($this->m('m3')->cid, 'kid3', true);
        $this->pin($this->m('m2')->cid)->assertOk();
        usleep(3000);
        $this->pin($this->m('m3')->cid)->assertOk()->assertJsonPath('ok', true);
        $o = $this->order();
        $this->assertSame(['Topic title', 'm3*', 'kid3', 'm2*', 'm1', 'Ad text'], $o);
        $this->pin($this->m('m3')->cid, false)->assertOk();
        $this->pin($this->m('m2')->cid, false)->assertOk();
        $this->assertSame(['Topic title', 'm1', 'm2', 'm3', 'kid3', 'Ad text'], $this->order());
        $this->assertSame(['Topic title', 'm1', 'm2', 'm3', 'Ad text'], $before);
    }

    public function test_only_approved_child_can_be_pinned_and_sits_right_after_its_mother(): void
    {
        $m = $this->m('m1')->cid;
        $old = $this->child($m, 'old', true);
        usleep(3000);
        $pend = $this->child($m, 'pend', false);
        $this->pin($pend)->assertStatus(422)->assertJson(['error' => 'pin_not_approved']);
        $this->pin($old)->assertOk();
        $this->assertSame(['Topic title', 'm1', 'old*', 'pend', 'm2', 'm3', 'Ad text'], $this->order());
    }

    public function test_pins_work_in_topic_and_infomercial_and_stay_in_their_zone(): void
    {
        // item 26: pin/unpin works in every zone; a pin only reorders inside its own zone
        $this->pin($this->m('Topic title')->cid)->assertOk();
        $this->pin($this->m('Ad text')->cid)->assertOk();
        $this->assertSame(['Topic title*', 'm1', 'm2', 'm3', 'Ad text*'], $this->order());
        $this->pin('nope')->assertStatus(404);
    }

    public function test_deleting_or_unapproving_a_child_clears_its_pin(): void
    {
        $m = $this->m('m1')->cid;
        $a = $this->child($m, 'a', true);
        $b = $this->child($m, 'b', true);
        $this->pin($a)->assertOk();
        $this->pin($b)->assertOk();
        $this->withHeaders(self::OWN)->deleteJson("/api/wall/w19/cards/$a")->assertOk();
        $this->flushHeaders();
        $pins = (array) (Wall::find($this->wall->id)->meta['pins'] ?? []);
        $this->assertArrayNotHasKey($a, $pins);
        $this->assertArrayHasKey($b, $pins);
        // unapproved by any path: the pin no longer shows and is pruned on the next write
        WallCard::where('cid', $b)->update(['status' => 'pending']);
        $this->assertSame(['Topic title', 'm1', 'b', 'm2', 'm3', 'Ad text'], $this->order());
        $this->pin($this->m('m2')->cid)->assertOk();
        $this->assertArrayNotHasKey($b, (array) Wall::find($this->wall->id)->meta['pins']);
    }
}
