<?php

namespace Tests\Feature;

use App\Models\AISearchHistory;
use App\Models\EzFunnel;
use App\Models\EzFunnelField;
use App\Models\User;
use App\Models\Wall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EZ-LOCK-20261017 P0: a private slug shows NOTHING to a viewer who may not see it.
 * Live leak on 2026-10-17: /X0003636 and /X/conversation-122 returned 200 with the private
 * title, ids, owner and timestamps in the Inertia props; the sitemap listed the handle on 7 domains.
 */
class SlugPrivacyP0Test extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'SECRET TITLE 點數制度規格（限閱）';

    private function slug(User $owner, string $slug, string $status = 'private'): AISearchHistory
    {
        return AISearchHistory::create([
            'user_id'            => $owner->id,
            'slug'               => $slug,
            'conversation_id'    => 'conv-' . $slug,
            'conversation_title' => self::TITLE,
            'query'              => 'secret query text',
            'message_role'       => 'user',
            'content_type'       => 'social',
            'status'             => $status,
        ]);
    }

    private function handle(AISearchHistory $m): EzFunnel
    {
        $f = EzFunnel::create(['user_id' => $m->user_id, 'displaymode' => 'ai', 'aiid' => $m->id]);
        EzFunnelField::create(['ez_funnel_id' => $f->id, 'user_id' => $m->user_id, 'position' => 1]);
        return $f->fresh();
    }

    private function assertNoLeak($res, AISearchHistory $m, User $owner): void
    {
        $body = html_entity_decode((string) $res->getContent(), ENT_QUOTES | ENT_HTML5);
        foreach ([self::TITLE, $m->conversation_id, 'secret query text', $owner->name, '"status":"private"'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "leaked: $needle");
        }
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_guest_gets_404_without_props_on_x_slug(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $m = $this->slug($owner, 'conversation-p0a');
        $res = $this->get('/X/conversation-p0a')->assertStatus(404);
        $this->assertNoLeak($res, $m, $owner);
        $this->assertStringNotContainsString('data-page', (string) $res->getContent());
    }

    public function test_guest_gets_404_json_on_api_x_slug(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $m = $this->slug($owner, 'conversation-p0b');
        $res = $this->getJson('/api/X/conversation-p0b')->assertStatus(404)->assertJson(['success' => false]);
        $this->assertNoLeak($res, $m, $owner);
    }

    public function test_guest_gets_404_on_handle_and_custom_fleet_host(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $m = $this->slug($owner, 'conversation-p0c');
        $f = $this->handle($m);
        $this->assertNoLeak($this->get('/' . $f->token)->assertStatus(404), $m, $owner);
        $this->assertNoLeak($this->get('http://7.wiki/' . $f->token)->assertStatus(404), $m, $owner);
    }

    public function test_hidden_slug_is_404_for_guests(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conversation-p0d', 'hidden');
        $this->get('/X/conversation-p0d')->assertStatus(404);
    }

    public function test_old_access_number_grant_in_session_no_longer_opens_it(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $m = $this->slug($owner, 'conversation-p0e');
        $res = $this->withSession(['private_access_' . $m->conversation_id => ['token' => 'old', 'email' => 'x@example.com']])
            ->get('/X/conversation-p0e')->assertStatus(404);
        $this->assertNoLeak($res, $m, $owner);
    }

    public function test_other_signed_in_user_gets_404(): void
    {
        $owner = User::factory()->create(['name' => 'royfang-test']);
        $other = User::factory()->create();
        $m = $this->slug($owner, 'conversation-p0f');
        $this->assertNoLeak($this->actingAs($other)->get('/X/conversation-p0f')->assertStatus(404), $m, $owner);
    }

    public function test_owner_sees_it_with_noindex_and_no_store(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conversation-p0g');
        $res = $this->actingAs($owner)->get('/X/conversation-p0g')->assertOk();
        $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('name="robots" content="noindex', (string) $res->getContent());
    }

    public function test_public_slug_unchanged_for_guests(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conversation-p0h', 'public');
        $res = $this->get('/X/conversation-p0h')->assertOk();
        $this->assertNull($res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString(self::TITLE, html_entity_decode((string) $res->getContent(), ENT_QUOTES | ENT_HTML5));
    }

    public function test_sitemap_lists_public_handles_only(): void
    {
        $owner = User::factory()->create();
        $private = $this->handle($this->slug($owner, 'conversation-p0i'));
        $public = $this->handle($this->slug($owner, 'conversation-p0j', 'public'));
        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/' . $private->token . '<', $xml);
        // the public handle is listed once per active admindomain (none in a bare test DB), so only
        // assert it is not filtered out by the query:
        $this->assertTrue(\App\Support\SlugAccess::whereNotInOrNull(EzFunnel::query(), 'aiid', \App\Support\SlugAccess::nonPublicMessageIds())
            ->whereKey($public->id)->exists());
        $this->assertFalse(\App\Support\SlugAccess::whereNotInOrNull(EzFunnel::query(), 'aiid', \App\Support\SlugAccess::nonPublicMessageIds())
            ->whereKey($private->id)->exists());
    }

    public function test_legacy_access_number_endpoints_are_retired_and_send_no_mail(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $m = $this->slug($owner, 'conversation-p0k');
        $m->forceFill(['private_access_number' => '1234'])->save();
        $this->postJson('/searchai/private/request-access', ['slug' => 'conversation-p0k', 'email' => 'a@example.com'])->assertStatus(410);
        $this->postJson('/searchai/private/verify-access', ['slug' => 'conversation-p0k', 'email' => 'a@example.com', 'access_number' => '1234'])->assertStatus(410);
        Mail::assertNothingSent();
        $this->getJson('/searchai/private/check-access?slug=conversation-p0k')->assertStatus(404);
        $this->get('/X/conversation-p0k')->assertStatus(404);
    }

    public function test_guest_route_list_is_reduced(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conversation-p0l', 'public');
        $props = function ($res) {
            preg_match('/data-page="([^"]+)"/', (string) $res->getContent(), $mm);
            return json_decode(html_entity_decode($mm[1] ?? '{}', ENT_QUOTES | ENT_HTML5), true)['props'] ?? [];
        };
        $guest = $props($this->get('/X/conversation-p0l'));
        $this->assertArrayNotHasKey('ai.conversation.private-settings', $guest['ziggy']['routes'] ?? []);
        $this->assertArrayHasKey('login', $guest['ziggy']['routes'] ?? []);
        $mine = $props($this->actingAs($owner)->get('/X/conversation-p0l'));
        $this->assertArrayHasKey('ai.conversation.private-settings', $mine['ziggy']['routes'] ?? []);
    }

    public function test_suggestions_never_offer_private_slugs(): void
    {
        $owner = User::factory()->create();
        $this->slug($owner, 'conversation-p0n');
        $this->slug($owner, 'conversation-p0o', 'public');
        $json = (string) $this->getJson('/searchai/suggestions?query=conversation-p0')->assertOk()->getContent();
        $this->assertStringNotContainsString('conversation-p0n', $json);
        $this->assertStringContainsString('conversation-p0o', $json);
    }

    public function test_wall_of_a_private_slug_is_404_for_guests(): void
    {
        $owner = User::factory()->create();
        $m = $this->slug($owner, 'conversation-p0m');
        Wall::create(['slug' => 'W0000901', 'meta' => ['message_id' => $m->id]]);
        $this->getJson('/api/wall/W0000901/cards')->assertStatus(404);
        $this->get('/api/wall/W0000901/sheet/download')->assertStatus(404);
        $this->actingAs($owner)->getJson('/api/wall/W0000901/cards')->assertOk();
    }
}
