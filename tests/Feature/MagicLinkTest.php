<?php

namespace Tests\Feature;

use App\Http\Controllers\MagicLinkController;
use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Item 21: magic-link sign-in replaces the 4-digit OTP. */
class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SEND = '/api/wall/auth/magic/send';
    private const VERIFY = '/api/wall/auth/magic/verify';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['magic_link.test_emails' => []]);
    }

    /** @return array{0:string,1:string} [url, code] of the last mail to $email */
    private function mailed(string $email): array
    {
        $last = null;
        Mail::assertSent(MagicLinkMail::class, function ($m) use ($email, &$last) {
            if ($m->hasTo($email)) { $last = [$m->url, $m->code]; }
            return true;
        });
        $this->assertNotNull($last);
        return $last;
    }

    private function send(string $email, string $return = '/W0000042', string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::SEND, ['email' => $email, 'return_to' => $return]);
    }

    private function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH);
    }

    public function test_link_signs_in_old_user_by_email_regenerates_session_and_returns_to_wall(): void
    {
        $old = User::factory()->create(['email' => 'Roy@Example.com']);
        $this->send('roy@example.com', '/W0000042?x=1')->assertOk()->assertJson(['success' => true, 'message' => MagicLinkController::GENERIC]);
        [$url, $code] = $this->mailed('roy@example.com');
        $this->assertMatchesRegularExpression('#/login/magic/[A-Za-z0-9_-]{43}$#', $url);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        // hashed at rest
        $row = LoginToken::firstOrFail();
        $raw = substr($url, -43);
        $this->assertNotSame($raw, $row->token_hash);
        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertStringNotContainsString($code, $row->code_hash);
        $this->assertSame(1, User::count());

        $this->get($this->path($url))->assertRedirect('/W0000042?x=1');
        $this->assertAuthenticatedAs($old);
        $this->assertSame(1, User::count(), 'old account matched by email');
    }

    public function test_link_is_single_use(): void
    {
        $this->send('a@example.com');
        [$url] = $this->mailed('a@example.com');
        $this->get($this->path($url))->assertRedirect();
        auth()->logout();
        $this->get($this->path($url))->assertStatus(410);
        $this->assertGuest();
    }

    public function test_link_and_code_expire_after_15_minutes(): void
    {
        $this->send('b@example.com');
        [$url, $code] = $this->mailed('b@example.com');
        $this->travel(16)->minutes();
        $this->get($this->path($url))->assertStatus(410);
        $this->postJson(self::VERIFY, ['email' => 'b@example.com', 'code' => $code])->assertStatus(422)->assertJson(['error' => 'expired']);
        $this->assertGuest();
    }

    public function test_backup_code_signs_in_new_user_once(): void
    {
        $this->send('new@example.com', '/W7');
        [, $code] = $this->mailed('new@example.com');
        $this->postJson(self::VERIFY, ['email' => 'NEW@example.com', 'code' => $code])
            ->assertOk()->assertJson(['success' => true, 'user' => ['email' => 'new@example.com'], 'return_to' => '/W7']);
        $this->assertAuthenticated();
        auth()->logout();
        $this->postJson(self::VERIFY, ['email' => 'new@example.com', 'code' => $code])->assertStatus(422);
    }

    public function test_five_wrong_codes_cancel_the_code_and_the_link(): void
    {
        $this->send('c@example.com');
        [$url, $code] = $this->mailed('c@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson(self::VERIFY, ['email' => 'c@example.com', 'code' => $wrong])->assertStatus(422)->assertJson(['error' => 'wrong_code']);
        }
        $this->postJson(self::VERIFY, ['email' => 'c@example.com', 'code' => $wrong])->assertStatus(422)->assertJson(['error' => 'locked']);
        $this->travel(2)->minutes();   // past the verify throttle
        $this->postJson(self::VERIFY, ['email' => 'c@example.com', 'code' => $code])->assertStatus(422)->assertJson(['error' => 'expired']);
        $this->get($this->path($url))->assertStatus(410);
        $this->assertGuest();
    }

    public function test_verify_throttle_5_per_minute_per_ip_and_email(): void
    {
        $this->send('d@example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::VERIFY, ['email' => 'd@example.com', 'code' => '12345x'])->assertStatus(422);
        }
        $this->postJson(self::VERIFY, ['email' => 'd@example.com', 'code' => '123456'])->assertStatus(429);
        // another email from the same IP is a different bucket
        $this->postJson(self::VERIFY, ['email' => 'e@example.com', 'code' => '123456'])->assertStatus(422);
    }

    public function test_send_throttle_per_email_and_per_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->send('f@example.com', '/', '10.0.0.' . ($i + 1))->assertOk();
        }
        $this->send('f@example.com', '/', '10.0.0.9')->assertStatus(429);
        Mail::assertSent(MagicLinkMail::class, 3);
        for ($i = 0; $i < 10; $i++) {
            $this->send("ip$i@example.com", '/', '10.9.9.9')->assertOk();
        }
        $this->send('ip99@example.com', '/', '10.9.9.9')->assertStatus(429);
    }

    public function test_no_enumeration_and_no_debug_otp(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        $a = $this->send('known@example.com', '/', '10.1.1.1')->assertOk()->json();
        $b = $this->send('unknown@example.com', '/', '10.1.1.2')->assertOk()->json();
        $this->assertSame($a, $b);
        $this->assertSame(['success', 'message', 'expires_in'], array_keys($a));
        foreach ([$a, $b] as $j) {
            $this->assertArrayNotHasKey('debug_otp', $j);
            $this->assertArrayNotHasKey('test_link', $j);
        }
        // old endpoint names now use the magic link too
        $c = $this->withServerVariables(['REMOTE_ADDR' => '10.1.1.3'])->postJson('/api/wall/auth/send-otp', ['email' => 'x@example.com'])->assertOk()->json();
        $this->assertArrayNotHasKey('debug_otp', $c);
        $this->assertSame(0, User::where('email', 'unknown@example.com')->count(), 'no account until the link/code is used');
    }

    public function test_return_to_is_same_site_only(): void
    {
        $r = Request::create('https://ez.wiki/api/wall/auth/magic/send', 'POST');
        $ok = ['/W0000042' => '/W0000042', '/W1?a=1#b' => '/W1?a=1#b', 'https://ez.wiki/W5?x=1' => '/W5?x=1', 'HTTPS://EZ.WIKI/W6' => '/W6'];
        foreach ($ok as $in => $out) {
            $this->assertSame($out, MagicLinkController::safeReturn($in, $r), $in);
        }
        foreach (['//evil.com/x', '/\\evil.com', 'https://evil.com/W1', 'https://ez.wiki.evil.com/', 'javascript:alert(1)', 'http://user@ez.wiki/x',
                  "/W1\r\nLocation: https://evil.com", 'data:text/html,x', '', 'W1', 'https://evil.com@ez.wiki/', 'ftp://ez.wiki/'] as $bad) {
            $this->assertSame('/', MagicLinkController::safeReturn($bad, $r), var_export($bad, true));
        }
        // end to end: a hostile return_to lands on "/"
        $this->send('g@example.com', 'https://evil.com/phish');
        [$url] = $this->mailed('g@example.com');
        $this->get($this->path($url))->assertRedirect('/');
    }

    public function test_test_email_bypass_only_when_env_list_is_set(): void
    {
        $this->send('roy-test@example.com', '/', '10.2.2.1')->assertOk()->assertJsonMissingPath('test_link');
        config(['magic_link.test_emails' => ['roy-test@example.com']]);
        $j = $this->send('Roy-Test@example.com', '/', '10.2.2.2')->assertOk()->json();
        $this->assertMatchesRegularExpression('#/login/magic/[A-Za-z0-9_-]{43}$#', $j['test_link']);
        $this->send('other@example.com', '/', '10.2.2.3')->assertOk()->assertJsonMissingPath('test_link');
        // the config file reads the env list (off when unset)
        putenv('MAGIC_LINK_TEST_EMAILS');
        $this->assertSame([], (require base_path('config/magic_link.php'))['test_emails']);
        putenv('MAGIC_LINK_TEST_EMAILS= A@x.com ,b@x.com');
        $this->assertSame(['a@x.com', 'b@x.com'], (require base_path('config/magic_link.php'))['test_emails']);
        putenv('MAGIC_LINK_TEST_EMAILS');
    }

    public function test_a_new_link_cancels_the_previous_one(): void
    {
        $this->send('h@example.com', '/', '10.3.3.1');
        [$first] = $this->mailed('h@example.com');
        $this->send('h@example.com', '/', '10.3.3.2');
        $this->get($this->path($first))->assertStatus(410);
    }
}
