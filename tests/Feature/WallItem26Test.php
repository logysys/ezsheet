<?php

namespace Tests\Feature;

use App\Models\Wall;
use App\Models\WallCard;
use App\Services\WallHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Item 26: Co-WiKi works on WiKi cards in all three zones; families move between zones together. */
class WallItem26Test extends TestCase
{
    use RefreshDatabase;

    private const TOK = 'owner-token-2626262626';
    private const OWN = ['x-wall-owner' => self::TOK, 'Accept' => 'application/json'];
    private Wall $wall;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->wall = Wall::create(['slug' => 'w26', 'owner_hash' => WallHasher::owner(self::TOK), 'meta' => []]);
        $this->push("Topic title\n===\nAd text\n###\nm1\n---\nm2");
    }

    private function push(string $raw)
    {
        $r = $this->withHeaders(self::OWN)->postJson('/api/wall/w26/push-to-wall', ['raw' => $raw]);
        $this->flushHeaders();
        return $r->assertOk();
    }

    private function m(string $raw): WallCard
    {
        return WallCard::where('wall_id', $this->wall->id)->whereNull('mother_id')->where('content', $raw)->firstOrFail();
    }

    private function add(string $motherCid, string $raw, bool $approve = false): string
    {
        $this->postJson('/api/wall/w26/cards', ['raw' => $raw, 'anchor_cid' => $motherCid], ['Accept' => 'application/json'])->assertCreated();
        $cid = WallCard::where('content', $raw)->firstOrFail()->cid;
        if ($approve) {
            $this->withHeaders(self::OWN)->postJson("/api/wall/w26/cards/$cid/approve")->assertOk();
            $this->flushHeaders();
        }
        return $cid;
    }

    /** [content, zone] in server order */
    private function rows(): array
    {
        $d = $this->getJson('/api/wall/w26/cards')->json();
        $by = collect($d['cards'])->keyBy('cid');
        return array_map(fn ($cid) => $by[$cid]['content'] . '@' . $by[$cid]['zone'] . ($by[$cid]['pinned'] ? '*' : ''), $d['order']);
    }

    public function test_cowiki_in_topic_and_infomercial_sits_after_its_mother_newest_first(): void
    {
        $t = $this->m('Topic title')->cid;
        $this->add($t, 'old topic child');
        usleep(3000);
        $this->add($t, 'new topic child');
        $this->add($this->m('Ad text')->cid, 'ad child');
        $this->assertSame([
            'Topic title@a', 'new topic child@a', 'old topic child@a',
            'm1@b', 'm2@b',
            'Ad text@c', 'ad child@c',
        ], $this->rows());
    }

    public function test_family_moves_between_zones_together_and_keeps_pins(): void
    {
        $m1 = $this->m('m1');
        $kid = $this->add($m1->cid, 'kid', true);
        $this->withHeaders(self::OWN)->postJson("/api/wall/w26/cards/$kid/pin", ['pinned' => true])->assertOk();
        $this->flushHeaders();
        // m1 moves into Topic
        $this->push("Topic title\n---\nm1\n===\nAd text\n###\nm2");
        $this->assertSame('a', $m1->fresh()->zone);
        $this->assertSame('a', WallCard::where('cid', $kid)->value('zone'));
        $this->assertSame(['Topic title@a', 'm1@a', 'kid@a*', 'm2@b', 'Ad text@c'], $this->rows());
        // and on into InfoMercial
        $this->push("Topic title\n===\nm1\n---\nAd text\n###\nm2");
        $this->assertSame('c', WallCard::where('cid', $kid)->value('zone'));
        $this->assertSame(['Topic title@a', 'm2@b', 'm1@c', 'kid@c*', 'Ad text@c'], $this->rows());
    }

    public function test_legacy_child_row_with_zone_b_is_served_in_its_mothers_zone(): void
    {
        $t = $this->m('Topic title');
        $cid = $this->add($t->cid, 'legacy');
        WallCard::where('cid', $cid)->update(['zone' => 'b']);
        $this->assertContains('legacy@a', $this->rows());
    }

    public function test_owner_deletes_a_topic_cowiki_card(): void
    {
        $cid = $this->add($this->m('Topic title')->cid, 'bye', true);
        $this->withHeaders(self::OWN)->deleteJson("/api/wall/w26/cards/$cid")->assertSuccessful();
        $this->assertSame(0, WallCard::where('cid', $cid)->count());
    }

    public function test_fireworks_setting_is_ignored_and_not_served(): void
    {
        // item 27 rev 2: the effect is gone; the input is ignored and nothing is echoed back
        $r = $this->withHeaders(self::OWN)->postJson('/api/wall/w26/settings', ['fireworks' => true])->assertOk();
        $this->flushHeaders();
        $this->assertArrayNotHasKey('fireworks', (array) $r->json('wall'));
        $this->assertArrayNotHasKey('fireworks', $this->getJson('/api/wall/w26/cards')->json());
        $this->assertArrayNotHasKey('fireworks', (array) $this->wall->fresh()->meta);
    }
}
