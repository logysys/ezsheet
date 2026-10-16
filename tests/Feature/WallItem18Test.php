<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Models\WallCard;
use App\Services\WallHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Items 16 + 18: Co-WiKi media upload from the + box, and the owner's Cards/Beads view setting. */
class WallItem18Test extends TestCase
{
    use RefreshDatabase;

    private const OWNER_TOKEN = 'owner-token-1818181818';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const SAME = ['Accept' => 'application/json', 'Sec-Fetch-Site' => 'same-origin'];
    private Wall $wall;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        $this->wall = Wall::create(['slug' => 'w18', 'owner_hash' => WallHasher::owner(self::OWNER_TOKEN), 'meta' => []]);
        $this->withHeaders(['x-wall-owner' => self::OWNER_TOKEN])
            ->postJson('/api/wall/w18/push-to-wall', ['raw' => "Topic title\n===\nAd text\n###\nmother one\n---\nmother two"])->assertOk();
        $this->flushHeaders();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('upload/cowiki'));
        parent::tearDown();
    }

    private function mother(string $raw): WallCard
    {
        return WallCard::where('wall_id', $this->wall->id)->whereNull('mother_id')->where('content', $raw)->firstOrFail();
    }

    private function up(?User $u, string $cid, string $name, string $bytes, array $h = [])
    {
        $req = $u ? $this->actingAs($u) : $this;
        return $req->post('/api/wall/w18/cards/' . $cid . '/media', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], self::SAME + $h);
    }

    private function files(): array
    {
        $d = public_path('upload/cowiki');
        return is_dir($d) ? array_values(array_diff(scandir($d), ['.', '..'])) : [];
    }

    public function test_guest_401_and_nothing_written(): void
    {
        $m = $this->mother('mother one');
        $this->up(null, $m->cid, 'a.png', base64_decode(self::PNG))->assertStatus(401)->assertJson(['error' => 'login_required']);
        $this->assertSame(0, WallCard::whereNotNull('mother_id')->count());
        $this->assertSame([], $this->files());
    }

    public function test_signed_in_upload_creates_one_pending_child_with_the_media(): void
    {
        $m = $this->mother('mother one');
        $walls = Wall::count();
        $mothers = WallCard::whereNull('mother_id')->count();
        $u = User::factory()->create(['name' => 'Ana Silva']);
        $r = $this->up($u, $m->cid, 'Tokio.JPG', base64_decode(self::PNG));
        $r->assertStatus(201)->assertJson(['success' => true, 'card' => ['role' => 'child', 'status' => 'pending', 'anchor' => $m->cid, 'author' => 'Ana']]);
        $this->assertMatchesRegularExpression('#^/upload/cowiki/u' . $u->id . '_\d+_[a-z0-9]{16}\.png$#', $r->json('card.content'));
        $this->assertFileExists(public_path(ltrim($r->json('card.content'), '/')));
        $child = WallCard::whereNotNull('mother_id')->firstOrFail();
        $this->assertSame($m->id, (int) $child->mother_id);
        $this->assertSame('pending', $child->status);
        $this->assertSame($u->id, (int) $child->user_id);
        $this->assertSame($walls, Wall::count());                                   // no new wall
        $this->assertSame($mothers, WallCard::whereNull('mother_id')->count());     // no new mother
        $this->assertContains($child->cid, array_column($r->json('cards'), 'cid')); // snapshot returned
    }

    public function test_video_and_pdf_become_html_children_with_server_urls(): void
    {
        $m = $this->mother('mother two');
        $u = User::factory()->create();
        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);
        $r = $this->up($u, $m->cid, 'clip.mov', $mp4)->assertStatus(201);
        $this->assertMatchesRegularExpression('#^<video src="/upload/cowiki/[^"]+\.mp4" controls playsinline preload="metadata"#', $r->json('card.content'));
        $r = $this->up($u, $m->cid, '<b>x.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")->assertStatus(201);
        $this->assertStringContainsString('&lt;b&gt;x.pdf', $r->json('card.content'));
        $this->assertStringContainsString('.pdf" target="_blank"', $r->json('card.content'));
    }

    public function test_owner_upload_is_approved(): void
    {
        $m = $this->mother('mother one');
        $u = User::factory()->create();
        $this->up($u, $m->cid, 'a.png', base64_decode(self::PNG), ['x-wall-owner' => self::OWNER_TOKEN])
            ->assertStatus(201)->assertJson(['card' => ['status' => 'approved']]);
    }

    public function test_banned_types_422(): void
    {
        $m = $this->mother('mother one');
        $u = User::factory()->create();
        foreach (['p.html' => '<!doctype html><script>alert(1)</script>', 's.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                  'x.png' => "<?php system('id');", 'poly.png' => base64_decode(self::PNG) . '<?php echo 1;', 'z.zip' => "PK\x03\x04" . str_repeat("\x00", 30)] as $n => $b) {
            $this->up($u, $m->cid, $n, $b)->assertStatus(422);
        }
        $this->assertSame(0, WallCard::whereNotNull('mother_id')->count());
        $this->assertSame([], $this->files());
    }

    public function test_cross_site_403(): void
    {
        $m = $this->mother('mother one');
        $u = User::factory()->create();
        foreach ([['Sec-Fetch-Site' => 'cross-site'], ['Sec-Fetch-Site' => 'same-site']] as $h) {
            $this->actingAs($u)->post('/api/wall/w18/cards/' . $m->cid . '/media', ['file' => UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG))], ['Accept' => 'application/json'] + $h)->assertStatus(403);
        }
        $this->actingAs($u)->post('/api/wall/w18/cards/' . $m->cid . '/media', ['file' => UploadedFile::fake()->createWithContent('a.png', base64_decode(self::PNG))], ['Accept' => 'application/json', 'Origin' => 'https://evil.example'])->assertStatus(403);
        $this->assertSame(0, WallCard::whereNotNull('mother_id')->count());
    }

    public function test_upload_works_in_topic_infomercial_but_not_children_unknown_or_missing_wall(): void
    {
        $u = User::factory()->create();
        $png = base64_decode(self::PNG);
        // item 26: media Co-WiKi works on Topic and InfoMercial WiKi cards too, in their zone
        $this->assertSame('a', $this->up($u, $this->mother('Topic title')->cid, 'a.png', $png)->assertStatus(201)->json('card.zone'));
        $this->assertSame('c', $this->up($u, $this->mother('Ad text')->cid, 'a.png', $png)->assertStatus(201)->json('card.zone'));
        $child = $this->up($u, $this->mother('mother one')->cid, 'a.png', $png)->assertStatus(201)->json('card.cid');
        $this->up($u, $child, 'a.png', $png)->assertStatus(422)->assertJson(['error' => 'anchor_not_mother']);
        $this->up($u, 'nope123456', 'a.png', $png)->assertStatus(422);
        $walls = Wall::count();
        $this->actingAs($u)->post('/api/wall/W0009999/cards/abc/media', ['file' => UploadedFile::fake()->createWithContent('a.png', $png)], self::SAME)->assertStatus(404);
        $this->assertSame($walls, Wall::count());
        $this->assertSame(3, WallCard::whereNotNull('mother_id')->count());
        // a wall that switched Co-WiKi off
        $this->wall->update(['meta' => ['cowiki' => false]]);
        $this->up($u, $this->mother('mother two')->cid, 'a.png', $png)->assertStatus(403)->assertJson(['error' => 'cowiki_off']);
    }

    public function test_view_setting_is_owner_only_and_defaults_to_beads(): void
    {
        $this->getJson('/api/wall/w18/cards')->assertJson(['view' => 'beads']); // item 27: system default
        $this->withHeaders(['x-wall-me' => 'guest-zzzzzzzz'])->postJson('/api/wall/w18/settings', ['view' => 'cards'])->assertStatus(403);
        $this->flushHeaders();
        $this->getJson('/api/wall/w18/cards')->assertJson(['view' => 'beads']);
        $this->withHeaders(['x-wall-owner' => self::OWNER_TOKEN])->postJson('/api/wall/w18/settings', ['view' => 'cards'])->assertOk();
        $this->flushHeaders();
        $this->getJson('/api/wall/w18/cards')->assertJson(['view' => 'cards']);
        $this->withHeaders(['x-wall-owner' => self::OWNER_TOKEN])->postJson('/api/wall/w18/settings', ['view' => '<script>'])->assertOk();
        $this->flushHeaders();
        $this->getJson('/api/wall/w18/cards')->assertJson(['view' => 'cards']); // item 22: invalid values are ignored
    }
}
