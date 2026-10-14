<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Services\WallHasher;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Item 15: media upload hardening on top of HEAD 95ebadf. */
class WallItem15Test extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('upload/wall/W0000915'));
        File::deleteDirectory(public_path('upload/ezsheet'));
        parent::tearDown();
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        Wall::create(['slug' => 'W0000915', 'owner_hash' => WallHasher::owner('tok-915-0123456789'), 'meta' => ['user_id' => $u->id]]);
        return $u;
    }

    private function up(?User $u, string $wall, string $name, string $bytes, array $extra = [])
    {
        $req = $u ? $this->withSession(['login_web_' . sha1(SessionGuard::class) => $u->id]) : $this;
        return $req->post('/api/wall/' . $wall . '/media', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $extra, ['Accept' => 'application/json']);
    }

    public function test_extension_comes_from_the_sniffed_type_not_the_client_name(): void
    {
        $u = $this->owner();
        foreach (['photo.png' => 'png', 'renamed.jpg' => 'png', 'weird.PnG5' => 'png'] as $name => $ext) {
            $r = $this->up($u, 'W0000915', $name, base64_decode(self::PNG));
            $r->assertOk();
            $this->assertMatchesRegularExpression('#^/upload/wall/W0000915/media_\d+_[a-z0-9]{12}\.' . $ext . '$#', $r->json('url'), $name);
            $this->assertFileExists(public_path(ltrim($r->json('url'), '/')));
        }
        $wav = 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 0);
        $r = $this->up($u, 'W0000915', 'clip.mp3', $wav);
        $r->assertOk();
        $this->assertSame('audio', $r->json('kind'));
        $this->assertStringEndsWith('.wav', $r->json('url'));
    }

    public function test_wall_id_body_override_and_ezsheet_delegation_are_gone(): void
    {
        $u = $this->owner();
        // A stranger can no longer reach the unauthenticated uploader via wall_id=ezsheet.
        $this->up(null, 'W0000915', 'x.png', base64_decode(self::PNG), ['wall_id' => 'ezsheet'])->assertStatus(403);
        $this->up(null, 'ezsheet', 'x.php', "<?php echo 1;")->assertStatus(404);
        $this->assertDirectoryDoesNotExist(public_path('upload/ezsheet'));
        // Owner of W0000915 posting with wall_id of another wall still uploads to the route wall.
        $r = $this->up($u, 'W0000915', 'a.png', base64_decode(self::PNG), ['wall_id' => 'W0000999']);
        $r->assertOk();
        $this->assertStringStartsWith('/upload/wall/W0000915/', $r->json('url'));
    }

    public function test_upload_never_creates_a_wall(): void
    {
        $this->up(null, 'W0000777', 'x.png', base64_decode(self::PNG))->assertStatus(404);
        $this->assertSame(0, Wall::where('slug', 'W0000777')->count());
    }

    public function test_upload_ezsheet_file_stub_is_410_and_writes_nothing(): void
    {
        $this->post('/api/wall/upload-ezsheet-file', ['filename' => 'shell.php', 'data' => base64_encode('<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(410);
        $this->post('/api/wall/upload-ezsheet-file', ['file' => UploadedFile::fake()->createWithContent('s.php', '<?php 1;')], ['Accept' => 'application/json'])->assertStatus(410);
        $this->assertDirectoryDoesNotExist(public_path('upload/ezsheet'));
    }

    public function test_scripts_are_still_rejected(): void
    {
        $u = $this->owner();
        $this->up($u, 'W0000915', 'a.png', "<?php system('id'); ?>")->assertStatus(422);
        $this->up($u, 'W0000915', 'a.png', "<html><script>alert(1)</script>")->assertStatus(422);
    }
}
