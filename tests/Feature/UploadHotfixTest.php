<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wall;
use App\Services\WallHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** EZ-REL-20261015-3-hotfix: uploads need a signed-in session (or wall ownership), same origin, safe types. */
class UploadHotfixTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const SAME = ['Accept' => 'application/json', 'Sec-Fetch-Site' => 'same-origin'];

    protected function tearDown(): void
    {
        foreach (['upload/ezsheet', 'upload/wiki', 'upload/wall/W0000916', 'uploads', 'aicontent'] as $d) {
            File::deleteDirectory(public_path($d));
        }
        parent::tearDown();
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function files(string $dir): array
    {
        return is_dir(public_path($dir)) ? array_values(array_diff(scandir(public_path($dir)), ['.', '..'])) : [];
    }

    public function test_guest_gets_401_and_nothing_is_written(): void
    {
        foreach (['/api/wall/upload-ezsheet-file', '/content/wiki/upload-file', '/content/upload'] as $url) {
            $r = $this->post($url, ['file' => $this->png()], self::SAME);
            $r->assertStatus(401);
            $this->assertSame('login_required', $r->json('error'), $url);
        }
        $this->post('/api/wall/upload-ezsheet-file', ['filename' => 'x.png', 'data' => 'data:image/png;base64,' . self::PNG], self::SAME)->assertStatus(401);
        $this->assertSame([], $this->files('upload/ezsheet'));
        $this->assertSame([], $this->files('upload/wiki'));
        $this->assertSame([], $this->files('aicontent'));
    }

    public function test_logged_in_upload_works_file_and_base64(): void
    {
        $u = User::factory()->create();
        $r = $this->actingAs($u)->post('/api/wall/upload-ezsheet-file', ['file' => $this->png('My Photo.jpg')], self::SAME);
        $r->assertOk()->assertJson(['success' => true, 'kind' => 'image', 'extension' => 'png']);
        $this->assertMatchesRegularExpression('#^/upload/ezsheet/u' . $u->id . '_\d+_[a-z0-9]{16}\.png$#', $r->json('url'));
        $this->assertFileExists(public_path(ltrim($r->json('url'), '/')));

        $r = $this->actingAs($u)->postJson('/api/wall/upload-ezsheet-file', ['filename' => 'x.php', 'data' => 'data:image/png;base64,' . self::PNG], self::SAME);
        $r->assertOk();
        $this->assertStringEndsWith('.png', $r->json('url'));

        $r = $this->actingAs($u)->post('/content/wiki/upload-file', ['file' => $this->png()], self::SAME);
        $r->assertOk();
        $this->assertStringStartsWith('/upload/wiki/', $r->json('url'));
        // only public/upload/<area>/ is written (no uploads/ or storage copies)
        $this->assertDirectoryDoesNotExist(public_path('uploads/ezsheet'));
        $this->assertDirectoryDoesNotExist(public_path('uploads/wiki'));
    }

    public function test_banned_types_are_422(): void
    {
        $u = User::factory()->create();
        $cases = [
            'page.html' => "<!doctype html><html><body><script>alert(1)</script></body></html>",
            'pic.svg'   => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'notes.md'  => "# Title\n\nsome *markdown*\n",
            'a.zip'     => "PK\x03\x04" . str_repeat("\x00", 40),
            'run.exe'   => "MZ" . str_repeat("\x00", 200),
            'x.sh'      => "#!/bin/sh\nrm -rf /\n",
        ];
        foreach ($cases as $name => $bytes) {
            foreach (['/api/wall/upload-ezsheet-file', '/content/wiki/upload-file'] as $url) {
                $this->actingAs($u)->post($url, ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], self::SAME)->assertStatus(422);
            }
        }
        $this->assertSame([], $this->files('upload/ezsheet'));
        $this->assertSame([], $this->files('upload/wiki'));
    }

    public function test_php_disguised_as_png_is_refused(): void
    {
        $u = User::factory()->create();
        $polyglot = base64_decode(self::PNG) . "<?php system(\$_GET['c']); ?>";
        foreach ([['shell.png', "<?php system(\$_GET['c']); ?>"], ['poly.png', $polyglot], ['poly.php', $polyglot], ['x.png', "GIF89a<?= `id` ?>"]] as [$name, $bytes]) {
            foreach (['/api/wall/upload-ezsheet-file', '/content/wiki/upload-file', '/content/upload'] as $url) {
                $this->actingAs($u)->post($url, ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], self::SAME)->assertStatus(422);
            }
        }
        $this->actingAs($u)->postJson('/api/wall/upload-ezsheet-file', ['filename' => 'a.php', 'data' => base64_encode($polyglot)], self::SAME)->assertStatus(422);
        $this->assertSame([], $this->files('upload/ezsheet'));
        $this->assertSame([], $this->files('aicontent'));
    }

    public function test_cross_site_is_403_even_when_logged_in(): void
    {
        $u = User::factory()->create();
        $hdrs = [
            ['Sec-Fetch-Site' => 'cross-site'],
            ['Sec-Fetch-Site' => 'same-site'],          // e.g. a user subdomain page
            ['Origin' => 'https://evil.example'],
            ['Origin' => 'null'],
            [],                                           // no Origin, no Sec-Fetch-Site, no X-Requested-With
        ];
        foreach ($hdrs as $h) {
            foreach (['/api/wall/upload-ezsheet-file', '/content/wiki/upload-file', '/content/upload'] as $url) {
                $this->actingAs($u)->post($url, ['file' => $this->png()], ['Accept' => 'application/json'] + $h)->assertStatus(403);
            }
        }
        // legacy client without Fetch Metadata but with the XHR header is allowed
        $this->actingAs($u)->post('/api/wall/upload-ezsheet-file', ['file' => $this->png()], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->actingAs($u)->post('/api/wall/upload-ezsheet-file', ['file' => $this->png()], ['Accept' => 'application/json', 'Origin' => 'http://localhost'])->assertOk();
    }

    public function test_size_cap_100mb(): void
    {
        $u = User::factory()->create();
        $big = UploadedFile::fake()->create('big.mp4', 100 * 1024 + 1, 'video/mp4');
        $this->actingAs($u)->post('/api/wall/upload-ezsheet-file', ['file' => $big], self::SAME)->assertStatus(422);
    }

    public function test_wall_media_owner_only_and_no_ezsheet_bypass(): void
    {
        $owner = User::factory()->create();
        Wall::create(['slug' => 'W0000916', 'owner_hash' => WallHasher::owner('tok-916-0123456789'), 'meta' => ['user_id' => $owner->id]]);
        $stranger = User::factory()->create();
        // stranger (logged in) and guest: 403; the old wall_id=ezsheet bypass no longer reaches the homepage uploader
        $this->actingAs($stranger)->post('/api/wall/W0000916/media', ['file' => $this->png(), 'wall_id' => 'ezsheet'], self::SAME)->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->post('/api/wall/W0000916/media', ['file' => $this->png(), 'wall_id' => 'ezsheet'], self::SAME)->assertStatus(403);
        $this->post('/api/wall/ezsheet/media', ['file' => $this->png()], self::SAME)->assertStatus(404);
        $this->assertSame(0, Wall::where('slug', 'ezsheet')->count());
        $this->assertSame([], $this->files('upload/ezsheet'));
        // owner via the owner token: OK, extension from the bytes
        $r = $this->post('/api/wall/W0000916/media', ['file' => $this->png('a.php5')], self::SAME + ['x-wall-owner' => 'tok-916-0123456789']);
        $r->assertOk();
        $this->assertMatchesRegularExpression('#^/upload/wall/W0000916/u\d+_\d+_[a-z0-9]{16}\.png$#', $r->json('url'));
        $this->post('/api/wall/W0000916/media', ['file' => $this->png()], ['Accept' => 'application/json', 'Sec-Fetch-Site' => 'cross-site', 'x-wall-owner' => 'tok-916-0123456789'])->assertStatus(403);
    }
}
