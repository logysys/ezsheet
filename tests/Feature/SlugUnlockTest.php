<?php

namespace Tests\Feature;

use App\Mail\SlugPasswordRequestMail;
use App\Mail\SlugUnlockMail;
use App\Models\AISearchHistory;
use App\Models\EzFunnel;
use App\Models\EzFunnelField;
use App\Models\PrivateAccessLog;
use App\Models\SlugPasswordRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EZ-LOCK-20261017 P2: Private slugs can be opened by (1) email verification with ANY email and/or
 * (2) the owner-set password; "Request password" mails the owner. Owner enables either, both or neither.
 */
class SlugUnlockTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'SECRET TITLE 限閱';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['slug_access.unlock_enabled' => true]);
    }

    private function slug(User $owner, string $slug, array $unlock = []): AISearchHistory
    {
        $m = AISearchHistory::create([
            'user_id' => $owner->id, 'slug' => $slug, 'conversation_id' => 'conv-' . $slug,
            'conversation_title' => self::TITLE, 'message_role' => 'user', 'content_type' => 'social', 'status' => 'private',
        ]);
        if ($unlock) {
            $m->forceFill($unlock)->save();
        }
        return $m->fresh();
    }

    private function mailedCode(string $email): array
    {
        $last = null;
        Mail::assertSent(SlugUnlockMail::class, function ($m) use ($email, &$last) {
            if ($m->hasTo($email)) { $last = [$m->url, $m->code]; }
            return true;
        });
        $this->assertNotNull($last);
        return $last;
    }

    public function test_owner_only_slug_is_404_and_cannot_be_unlocked(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conv-u1');
        $this->get('/X/conv-u1')->assertStatus(404);
        $this->postJson('/slug-unlock/email/send', ['ref' => 'conv-u1', 'email' => 'a@example.com'])->assertStatus(404)->assertJson(['error' => 'not_available']);
        $this->postJson('/slug-unlock/password', ['ref' => 'conv-u1', 'password' => 'whatever'])->assertStatus(404);
        Mail::assertNothingSent();
    }

    public function test_lock_screen_has_no_slug_data(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $m = $this->slug($owner, 'conv-u2', ['unlock_email_enabled' => true]);
        $res = $this->get('/X/conv-u2')->assertStatus(403);
        $body = html_entity_decode((string) $res->getContent(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('SlugLocked', $body);
        foreach ([self::TITLE, $m->conversation_id, 'royfang-test', '"conversation_id"'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
    }

    public function test_any_email_unlocks_with_code_and_owner_sees_the_log(): void
    {
        $owner = User::factory()->create();
        $m = $this->slug($owner, 'conv-u3', ['unlock_email_enabled' => true]);
        $this->postJson('/slug-unlock/email/send', ['ref' => 'conv-u3', 'email' => 'Visitor@Example.com', 'return_to' => '/X/conv-u3'])->assertOk();
        [, $code] = $this->mailedCode('visitor@example.com');
        $this->postJson('/slug-unlock/email/verify', ['ref' => 'conv-u3', 'email' => 'visitor@example.com', 'code' => $code])->assertOk();
        $this->get('/X/conv-u3')->assertOk();
        $log = PrivateAccessLog::where('conversation_id', $m->conversation_id)->firstOrFail();
        $this->assertSame('email', $log->method);
        $this->assertSame('visitor@example.com', $log->email);
        $this->assertNull($log->access_number);
        // code is single use
        $this->postJson('/slug-unlock/email/verify', ['ref' => 'conv-u3', 'email' => 'visitor@example.com', 'code' => $code])->assertStatus(422);
    }

    public function test_magic_link_unlocks_via_handle(): void
    {
        $owner = User::factory()->create();
        $m = $this->slug($owner, 'conv-u4', ['unlock_email_enabled' => true]);
        $f = EzFunnel::create(['user_id' => $owner->id, 'displaymode' => 'ai', 'aiid' => $m->id]);
        EzFunnelField::create(['ez_funnel_id' => $f->id, 'user_id' => $owner->id, 'position' => 1]);
        $f->refresh();
        $this->get('/' . $f->token)->assertStatus(403);
        $this->postJson('/slug-unlock/email/send', ['ref' => $f->token, 'email' => 'b@example.com', 'return_to' => '/' . $f->token])->assertOk();
        [$url] = $this->mailedCode('b@example.com');
        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect('/' . $f->token);
        $this->get('/' . $f->token)->assertOk();
    }

    public function test_five_wrong_codes_cancel_the_code(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conv-u5', ['unlock_email_enabled' => true]);
        $this->postJson('/slug-unlock/email/send', ['ref' => 'conv-u5', 'email' => 'c@example.com'])->assertOk();
        [, $code] = $this->mailedCode('c@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.' . $i])
                ->postJson('/slug-unlock/email/verify', ['ref' => 'conv-u5', 'email' => 'c@example.com', 'code' => $wrong])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->postJson('/slug-unlock/email/verify', ['ref' => 'conv-u5', 'email' => 'c@example.com', 'code' => $code])->assertStatus(422);
        $this->get('/X/conv-u5')->assertStatus(403);
    }

    public function test_password_unlocks_and_changing_it_signs_everyone_out(): void
    {
        $owner = User::factory()->create();
        $m = $this->slug($owner, 'conv-u6', ['unlock_password_enabled' => true, 'unlock_password_hash' => Hash::make('open-sesame')]);
        $this->postJson('/slug-unlock/password', ['ref' => 'conv-u6', 'password' => 'nope'])->assertStatus(422);
        $this->postJson('/slug-unlock/password', ['ref' => 'conv-u6', 'password' => 'open-sesame'])->assertOk();
        $this->get('/X/conv-u6')->assertOk();

        // owner changes the password -> epoch bump -> the visitor's grant is void
        AISearchHistory::where('conversation_id', $m->conversation_id)->update([
            'unlock_password_hash' => Hash::make('new-secret'),
            'unlock_epoch' => \Illuminate\Support\Facades\DB::raw('unlock_epoch + 1'),
        ]);
        $this->get('/X/conv-u6')->assertStatus(403);
    }

    public function test_password_lockout_after_five_wrong_tries(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conv-u7', ['unlock_password_enabled' => true, 'unlock_password_hash' => Hash::make('right-one')]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/slug-unlock/password', ['ref' => 'conv-u7', 'password' => 'wrong' . $i])->assertStatus(422);
        }
        $this->postJson('/slug-unlock/password', ['ref' => 'conv-u7', 'password' => 'right-one'])->assertStatus(429);
    }

    public function test_request_password_needs_sign_in_and_mails_the_owner(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $asker = User::factory()->create(['email' => 'asker@example.com']);
        $m = $this->slug($owner, 'conv-u8', ['unlock_password_enabled' => true, 'unlock_password_hash' => Hash::make('pw-123456')]);
        $this->postJson('/slug-unlock/request-password', ['ref' => 'conv-u8'])->assertStatus(401);
        $this->actingAs($asker)->postJson('/slug-unlock/request-password', ['ref' => 'conv-u8', 'note' => 'Hi Roy'])->assertOk();
        $this->assertSame(1, SlugPasswordRequest::where('conversation_id', $m->conversation_id)->count());
        Mail::assertSent(SlugPasswordRequestMail::class, fn ($mail) => $mail->hasTo('owner@example.com') && $mail->requesterEmail === 'asker@example.com');
        $this->actingAs($owner)->getJson('/ai/conversation/' . $m->conversation_id . '/password-requests')
            ->assertOk()->assertJson(['open' => 1]);
    }

    public function test_owner_settings_hash_the_password_and_never_return_it(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $m = $this->slug($owner, 'conv-u9');
        $url = '/ai/conversation/' . $m->conversation_id . '/private-settings';
        $this->actingAs($owner)->patchJson($url, ['email_enabled' => true, 'password' => 'secret-pass', 'password_enabled' => true])
            ->assertOk()->assertJson(['email_enabled' => true, 'password_enabled' => true, 'has_password' => true, 'access_number' => null]);
        $root = $m->fresh();
        $this->assertTrue(Hash::check('secret-pass', $root->unlock_password_hash));
        $this->assertSame(1, (int) $root->unlock_epoch);
        $body = (string) $this->actingAs($owner)->getJson($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('secret-pass', $body);
        $this->assertStringNotContainsString((string) $root->unlock_password_hash, $body);
        // old UI payload (4-digit number) still works and becomes the password
        $this->actingAs($owner)->patchJson($url, ['access_number' => '4321', 'access_limit' => 10])->assertOk();
        $this->assertTrue(Hash::check('4321', $m->fresh()->unlock_password_hash));
        $this->assertNull($m->fresh()->private_access_number);
    }

    public function test_flag_off_means_owner_only_even_with_methods_on(): void
    {
        config(['slug_access.unlock_enabled' => false]);
        $owner = User::factory()->create();
        $this->slug($owner, 'conv-u10', ['unlock_email_enabled' => true]);
        $this->get('/X/conv-u10')->assertStatus(404);
    }
}
