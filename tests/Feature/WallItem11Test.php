<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Models\WallCard;
use App\Services\WallHasher;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Item 11 (EZ-REL-20261014-1): a pushed new card is always added, or the push says why not. Replica of W0000076. */
class WallItem11Test extends TestCase
{
    use RefreshDatabase;

    private const ALI = 'https://alibabacocreate.com/zh';
    private const WIKI = 'https://en.wikipedia.org/wiki/Wiki';
    private const YT = 'https://www.youtube.com/watch?v=kJQP7kiw5Fk';
    private const SP = 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT';
    private const EBOOK = 'https://yoursilentlaunch.com/eBook';
    private const NEW = 'https://example.org/new-card';

    private ?User $owner = null;

    private function w76(bool $withChild = true): array
    {
        $owner = $this->owner ??= User::factory()->create();
        $wall = Wall::create(['slug' => 'W0000076', 'owner_hash' => WallHasher::owner('tok-1234567890'), 'meta' => ['user_id' => $owner->id]]);
        $mk = fn ($cid, $c, $z, $p) => WallCard::create(['wall_id' => $wall->id, 'cid' => $cid, 'content' => $c, 'zone' => $z, 'status' => 'approved', 'origin' => 'wiki', 'position' => $p]);
        $wiki = $mk('248c547f497bb924', self::WIKI, 'b', 0);
        $mk('9c6355ab6ab8426d', self::YT, 'b', 1);
        $mk('4e71dc979ea213ab', self::SP, 'b', 2);
        $mk('1a4aeab2e5c085c6', self::ALI, 'c', 0);
        if ($withChild) {
            WallCard::create(['wall_id' => $wall->id, 'cid' => 'bab4bb26fd06f7d9', 'content' => self::EBOOK, 'zone' => 'b', 'status' => 'approved',
                'origin' => 'cowiki', 'mother_id' => $wiki->id, 'anchor' => '248c547f497bb924', 'position' => 0]);
        }
        return [$wall, $owner];
    }

    private function push(User $u, string $raw, array $extra = [])
    {
        return $this->withSession(['login_web_' . sha1(SessionGuard::class) => $u->id])
            ->postJson('/api/wall/W0000076/push-to-wall', ['raw' => $raw] + $extra);
    }

    private function tagged(User $u): string
    {
        return $this->withSession(['login_web_' . sha1(SessionGuard::class) => $u->id])->getJson('/api/wall/W0000076/sheet')->json('sheet');
    }

    private function mothers(): array
    {
        return WallCard::whereNull('mother_id')->whereNull('anchor')->orderBy('zone')->orderBy('position')->get()
            ->map(fn ($c) => $c->zone . ':' . $c->content)->all();
    }

    /** Roy's sheet, with the second URL added in each place. */
    public static function placements(): array
    {
        $s = fn ($x) => implode("\n", $x);
        return [
            'infomercial_with_sep'  => fn ($u) => $s([self::ALI, '---', $u, '###', self::WIKI, '---', self::YT, '---', self::SP]),
            'infomercial_bare_line' => fn ($u) => $s([self::ALI, $u, '###', self::WIKI, '---', self::YT, '---', self::SP]),
            'infomercial_enter_div' => fn ($u) => $s([self::ALI, '━━━━━━━━', $u, '###', self::WIKI, '---', self::YT, '---', self::SP]),
            'content_with_sep'      => fn ($u) => $s([self::ALI, '###', self::WIKI, '---', $u, '---', self::YT, '---', self::SP]),
            'content_bare_line'     => fn ($u) => $s([self::ALI, '###', self::WIKI, $u, '---', self::YT, '---', self::SP]),
            'content_at_end'        => fn ($u) => $s([self::ALI, '###', self::WIKI, '---', self::YT, '---', self::SP, $u]),
        ];
    }

    private function expectAdded(User $owner, string $raw, string $url, string $zone, string $case, array $extra = []): void
    {
        $before = count($this->mothers());
        $res = $this->push($owner, $raw, $extra);
        $res->assertOk();
        $this->assertSame($before + 1, count($this->mothers()), "$case: new card must be added: " . json_encode($res->json('stats')));
        $this->assertContains("$zone:$url", $this->mothers(), $case);
        $this->assertSame(1, $res->json('stats.created'), $case);
        $this->assertSame([], $res->json('not_added') ?? [], $case);
    }

    public function test_new_url_is_added_in_every_placement_untagged(): void
    {
        foreach (self::placements() as $case => $f) {
            WallCard::query()->delete(); Wall::query()->delete(); [$w, $owner] = $this->w76();
            $this->expectAdded($owner, $f(self::NEW), self::NEW, str_starts_with($case, 'info') ? 'c' : 'b', "untagged $case");
        }
    }

    /** The W0000076 bug: the eBook link was already a Co-WiKi card under "Wiki". Adding it to the
        sheet used to return 200 with created=0 and no message. Now: reported, then addable. */
    public function test_url_that_is_also_a_cowiki_card_is_reported_then_addable(): void
    {
        foreach (self::placements() as $case => $f) {
            WallCard::query()->delete(); Wall::query()->delete(); [$w, $owner] = $this->w76();
            $zone = str_starts_with($case, 'info') ? 'c' : 'b';
            $raw = $f(self::EBOOK);
            $res = $this->push($owner, $raw)->assertOk();
            $this->assertSame(4, count($this->mothers()), "$case: not silently turned into a WiKi card");
            $na = $res->json('not_added');
            $this->assertCount(1, $na, "$case: must be reported");
            $this->assertSame(['raw' => self::EBOOK, 'zone' => $zone, 'reason' => 'same_as_cowiki_card', 'cowiki_cid' => 'bab4bb26fd06f7d9',
                'mother_cid' => '248c547f497bb924', 'mother_raw' => self::WIKI], $na[0], $case);
            // Owner confirms "Add as WiKi card": same text again, with add_cowiki_raws.
            $this->expectAdded($owner, $raw, self::EBOOK, $zone, "$case confirmed", ['add_cowiki_raws' => [self::EBOOK]]);
            $this->assertSame(1, WallCard::where('cid', 'bab4bb26fd06f7d9')->count(), 'the Co-WiKi card is kept');
        }
    }

    public function test_tagged_sheet_from_the_editor_reports_then_adds_the_cowiki_url(): void
    {
        [$w, $owner] = $this->w76();
        $sheet = $this->tagged($owner);
        $raw = str_replace(self::ALI . "\n###", self::ALI . "\n---\n" . self::EBOOK . "\n###", $sheet);
        $this->assertNotSame($sheet, $raw);
        $res = $this->push($owner, $raw)->assertOk();
        $this->assertSame('same_as_cowiki_card', $res->json('not_added.0.reason'));
        $res = $this->push($owner, $raw, ['add_cowiki_raws' => [self::EBOOK]])->assertOk();
        $this->assertContains('c:' . self::EBOOK, $this->mothers());
        $this->assertStringContainsString(self::EBOOK, $res->json('sheet'), 'the editor sheet keeps the new card');
        $this->assertSame([], $res->json('not_added'));
    }

    public function test_tagged_child_echo_is_reported_not_silent(): void
    {
        [$w, $owner] = $this->w76();
        $raw = $this->tagged($owner) . "---\n<!-- cid:bab4bb26fd06f7d9 -->\n" . self::EBOOK . "\n";
        $res = $this->push($owner, $raw)->assertOk();
        $this->assertSame(4, count($this->mothers()), 'a block tagged as the Co-WiKi card itself is not a new WiKi card');
        $this->assertSame('is_cowiki_card', $res->json('not_added.0.reason'));
        $this->assertSame(self::WIKI, $res->json('not_added.0.mother_raw'));
    }

    public function test_mother_zone_locked_is_a_clear_422_and_saves_nothing(): void
    {
        [$w, $owner] = $this->w76();
        // Wiki (has a Co-WiKi card) moved above ### with a new card: rejected with the reason, nothing saved.
        $res = $this->push($owner, implode("\n", [self::ALI, '---', self::WIKI, '---', self::NEW, '###', self::YT, '---', self::SP]));
        $res->assertStatus(422)->assertJson(['error' => 'mother_zone_locked']);
        $this->assertSame(self::WIKI, $res->json('cards.0.raw'));
        $this->assertSame(4, count($this->mothers()));
    }
}
