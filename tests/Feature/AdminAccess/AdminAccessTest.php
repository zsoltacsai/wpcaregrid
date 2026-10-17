<?php

namespace Tests\Feature\AdminAccess;

use App\AdminAccess\AccessLevel;
use App\AdminAccess\ActorType;
use App\AdminAccess\AdminAccessRefused;
use App\AdminAccess\AdminAccessService;
use App\AdminAccess\AdminAccessStatus;
use App\Connector\JobType;
use App\Models\AdminAccessRequest;
use App\Models\User;
use App\Models\WebsiteEvent;
use App\Models\WebsiteJob;
use App\Operator\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    /* ----- Requesting access ----- */

    public function test_maintenance_access_hands_a_one_time_token_to_the_browser_only(): void
    {
        $site = $this->accessReadySite();
        $user = $site['website']->customer->user;

        $response = $this->actingAs($user)->post(route('account.websites.admin-access.maintenance', $site['website']));

        $response->assertOk()
            ->assertSee(__('admin_access.opening.title'))
            ->assertSee('action="https://company.example/wp-login.php?action=wp_caregrid_access"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        [$uuid, $token] = $this->formValues($response);
        $request = AdminAccessRequest::sole();
        $this->assertSame($uuid, $request->uuid);
        $this->assertSame(AdminAccessStatus::Pending, $request->status);
        $this->assertSame(AccessLevel::Maintenance, $request->access_level);
        $this->assertSame(ActorType::Customer, $request->actor_type);
        $this->assertSame($user->id, $request->requested_by_user_id);
        $this->assertSame(hash('sha256', $token), $request->token_hash, 'Only the hash is stored.');
        $this->assertStringNotContainsString($token, json_encode(AdminAccessRequest::all()->makeVisible('token_hash')->toArray()));
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertTrue($request->token_expires_at->between(now()->addSeconds(100), now()->addSeconds(125)));

        // The session job exists but is never handed out by polling.
        $this->assertSame('CREATE_ADMIN_SESSION', $request->job->type->value);
        $this->assertSame([], $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('jobs'));

        $this->assertSame(['admin_access.requested'], WebsiteEvent::where('event', 'like', 'admin_access.%')->pluck('event')->all());
    }

    public function test_full_flow_to_an_active_session_and_its_expiry(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);

        $job = $this->claim($site, $uuid, $token)->assertOk()->json('job');

        $this->assertSame('CREATE_ADMIN_SESSION', $job['type']);
        $this->assertEqualsCanonicalizing(['request_uuid', 'access_level', 'website_uuid', 'session_expires_at'], array_keys($job['payload']));
        $this->assertSame(['request_uuid' => $uuid, 'access_level' => 'maintenance', 'website_uuid' => $site['website']->uuid], array_slice($job['payload'], 0, 3));
        $request = AdminAccessRequest::sole();
        $this->assertSame(AdminAccessStatus::Authorized, $request->status);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $request->session_expires_at->timestamp, 2);

        $this->report($site, $job['uuid'], 'success', 'ADMIN_SESSION_STARTED');
        $this->assertSame(AdminAccessStatus::Active, $request->refresh()->status);

        // The site is told what it may keep, with every poll and heartbeat.
        $live = $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('admin_access');
        $this->assertSame([['request_uuid' => $uuid, 'access_level' => 'maintenance', 'session_expires_at' => $request->session_expires_at->toIso8601String()]], $live);
        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))
            ->assertSee(__('admin_access.account.active'))->assertSee('Expires in 29 min')->assertSee(__('admin_access.account.revoke'))->assertDontSee($token);

        $this->travel(31)->minutes();
        $this->artisan('admin-access:expire')->assertSuccessful();

        $this->assertSame(AdminAccessStatus::Expired, $request->refresh()->status);
        $this->assertSame([], $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->json('admin_access'));
        $this->assertSame(['admin_access.requested', 'admin_access.authorized', 'admin_access.started', 'admin_access.expired'], WebsiteEvent::where('event', 'like', 'admin_access.%')->orderBy('id')->pluck('event')->all());
        $this->assertSame(4, WebsiteEvent::where('admin_access_request_id', $request->id)->count());
    }

    public function test_full_admin_needs_explicit_confirmation(): void
    {
        $site = $this->accessReadySite();
        $user = $site['website']->customer->user;

        $this->actingAs($user)->get(route('account.websites.admin-access.full-admin', $site['website']))
            ->assertOk()->assertSee('Grant temporary full administrator access for 15 minutes?');

        $this->actingAs($user)->post(route('account.websites.admin-access.full-admin.store', $site['website']))->assertSessionHasErrors('confirm');
        $this->assertSame(0, AdminAccessRequest::count());

        $this->assertRefused('CONFIRMATION_REQUIRED', fn () => app(AdminAccessService::class)->request($site['website'], $user, ActorType::Customer, AccessLevel::FullAdmin, false));

        $response = $this->actingAs($user)->post(route('account.websites.admin-access.full-admin.store', $site['website']), ['confirm' => '1']);
        [$uuid, $token] = $this->formValues($response->assertOk());

        $request = AdminAccessRequest::where('uuid', $uuid)->sole();
        $this->assertSame(AccessLevel::FullAdmin, $request->access_level);
        $this->assertNotNull($request->confirmed_at);
        $this->claim($site, $uuid, $token)->assertOk();
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $request->refresh()->session_expires_at->timestamp, 2);
    }

    public function test_plan_can_withhold_full_admin(): void
    {
        foreach (['wordpress', 'woocommerce'] as $product) {
            foreach (['basic', 'business', 'secure'] as $tier) {
                config(["admin_access.plans.{$product}.{$tier}.full_admin" => false]);
            }
        }
        $site = $this->accessReadySite();

        $this->actingAs($site['website']->customer->user)->post(route('account.websites.admin-access.full-admin.store', $site['website']), ['confirm' => '1'])
            ->assertRedirect()->assertSessionHas('account_error', __('admin_access.refusals.LEVEL_NOT_ALLOWED'));
    }

    public function test_request_refusals(): void
    {
        $service = app(AdminAccessService::class);

        $inactive = $this->accessReadySite('https://inactive.example');
        $inactive['website']->subscription->update(['status' => 'past_due']);
        $this->assertRefused('SUBSCRIPTION_INACTIVE', fn () => $service->request($inactive['website']->refresh(), $inactive['website']->customer->user, ActorType::Customer, AccessLevel::Maintenance));

        $offline = $this->accessReadySite('https://offline.example');
        $offline['website']->activeConnector->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        $this->assertRefused('CONNECTOR_OFFLINE', fn () => $service->request($offline['website']->refresh(), $offline['website']->customer->user, ActorType::Customer, AccessLevel::Maintenance));

        $old = $this->accessReadySite('https://old.example');
        $old['website']->activeConnector->update(['capabilities' => ['inventory']]);
        $this->assertRefused('CAPABILITY_MISSING', fn () => $service->request($old['website']->refresh(), $old['website']->customer->user, ActorType::Customer, AccessLevel::Maintenance));

        // The login URL the site reports must belong to the website.
        $foreign = $this->accessReadySite('https://foreign.example');
        $foreign['website']->activeConnector->update(['admin_access_url' => 'https://evil.example/wp-login.php?action=wp_caregrid_access']);
        $this->assertRefused('CAPABILITY_MISSING', fn () => $service->request($foreign['website']->refresh(), $foreign['website']->customer->user, ActorType::Customer, AccessLevel::Maintenance));

        $disconnected = $this->accessReadySite('https://gone.example');
        $disconnected['website']->activeConnector->revoke('test');
        $this->assertRefused('NOT_CONNECTED', fn () => $service->request($disconnected['website']->refresh(), $disconnected['website']->customer->user, ActorType::Customer, AccessLevel::Maintenance));

        $this->assertSame(0, AdminAccessRequest::count());
        $this->assertSame(5, WebsiteEvent::where('event', 'admin_access.failed')->count());
    }

    public function test_operators_are_limited_to_maintenance(): void
    {
        $site = $this->accessReadySite();
        $service = app(AdminAccessService::class);
        $operator = User::factory()->operator([Permission::AdminAccessRequest, Permission::AdminAccessFull])->create();

        // Full admin for operators stays off unless explicitly enabled (admin_access.operator_full_admin).
        $this->assertRefused('FULL_ADMIN_REQUIRES_CUSTOMER', fn () => $service->request($site['website'], $operator, ActorType::Operator, AccessLevel::FullAdmin, true));
        // An operator request always names the operator, and needs the permission.
        $this->assertRefused('NOT_AUTHORIZED', fn () => $service->request($site['website'], null, ActorType::Operator, AccessLevel::Maintenance));

        [$request] = $service->request($site['website'], $operator, ActorType::Operator, AccessLevel::Maintenance);
        $this->assertSame(ActorType::Operator, $request->actor_type);
        $this->assertSame($operator->id, $request->requested_by_user_id);
    }

    /* ----- One-time use, expiry, revocation ----- */

    public function test_a_token_works_once(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);

        $this->claim($site, $uuid, $token)->assertOk();
        $this->claim($site, $uuid, $token)->assertForbidden()->assertJsonPath('error.code', 'ACCESS_ALREADY_USED');

        $this->assertTrue(WebsiteEvent::where('event', 'admin_access.failed')->whereJsonContains('data->code', 'ACCESS_ALREADY_USED')->exists());
    }

    public function test_an_unused_token_expires(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);

        $this->travel(3)->minutes();
        $this->claim($site, $uuid, $token)->assertForbidden()->assertJsonPath('error.code', 'ACCESS_EXPIRED');
        $this->assertSame(AdminAccessStatus::Expired, AdminAccessRequest::sole()->status);

        [$uuid2] = $this->requestAccess($site);
        $this->travel(3)->minutes();
        $this->artisan('admin-access:expire')->assertSuccessful();
        $this->assertSame(AdminAccessStatus::Expired, AdminAccessRequest::where('uuid', $uuid2)->sole()->status);
    }

    public function test_wrong_tokens_are_counted_and_end_the_request(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);

        foreach (range(1, 5) as $i) {
            $this->claim($site, $uuid, str_repeat('0', 64))->assertForbidden()->assertJsonPath('error.code', 'ACCESS_INVALID');
        }

        $this->assertSame(AdminAccessStatus::Failed, AdminAccessRequest::sole()->status);
        $this->claim($site, $uuid, $token)->assertForbidden();
    }

    public function test_revoke_ends_access_and_the_site_learns_it(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);
        $job = $this->claim($site, $uuid, $token)->json('job');
        $this->report($site, $job['uuid'], 'success', 'ADMIN_SESSION_STARTED');
        $request = AdminAccessRequest::sole();

        $this->actingAs($site['website']->customer->user)->post(route('account.websites.admin-access.revoke', [$site['website'], $request]))
            ->assertRedirect()->assertSessionHas('status', __('admin_access.account.revoked'));

        $request->refresh();
        $this->assertSame(AdminAccessStatus::Revoked, $request->status);
        $this->assertSame('revoked_by_customer', $request->end_reason);
        $this->assertSame($site['website']->customer->user->id, $request->revoked_by_user_id);
        $this->assertSame([], $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())->json('admin_access'));
        $this->assertTrue(WebsiteEvent::where('event', 'admin_access.revoked')->exists());
    }

    public function test_one_open_request_per_level(): void
    {
        $site = $this->accessReadySite();
        [$first] = $this->requestAccess($site);
        [$second] = $this->requestAccess($site);

        $this->assertSame(AdminAccessStatus::Revoked, AdminAccessRequest::where('uuid', $first)->sole()->status);
        $this->assertSame('superseded', AdminAccessRequest::where('uuid', $first)->sole()->end_reason);
        $this->assertSame(1, AdminAccessRequest::whereIn('status', ['pending', 'authorized', 'active'])->count());
        $this->assertNotSame($first, $second);
    }

    public function test_disconnecting_the_connector_ends_all_access(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);
        $this->claim($site, $uuid, $token);

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.disconnect'))->assertOk();

        $request = AdminAccessRequest::sole();
        $this->assertSame(AdminAccessStatus::Revoked, $request->status);
        $this->assertSame('connector_disconnected_by_site', $request->end_reason);
        // The revoked connector can do nothing more.
        $this->claim($site, $uuid, $token)->assertStatus(401)->assertJsonPath('error.code', 'CONNECTOR_REVOKED');
    }

    public function test_customer_disconnect_and_inactive_subscription_stop_access(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);
        $site['website']->subscription->update(['status' => 'unpaid']);
        $this->claim($site, $uuid, $token)->assertForbidden()->assertJsonPath('error.code', 'SUBSCRIPTION_INACTIVE');

        $site['website']->subscription->update(['status' => 'active']);
        [$uuid] = $this->requestAccess($site);
        $this->actingAs($site['website']->customer->user)->post(route('account.websites.disconnect', $site['website']));
        $this->assertSame(AdminAccessStatus::Revoked, AdminAccessRequest::where('uuid', $uuid)->sole()->status);
    }

    public function test_the_site_reports_sessions_it_ended(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);
        $this->claim($site, $uuid, $token);
        $other = $this->accessReadySite('https://other.example');

        $this->withHeaders($this->connectorHeaders($other))->postJson(route('api.v1.connector.admin-access.ended', $uuid), ['reason' => 'expired'])->assertNotFound();
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.admin-access.ended', $uuid), ['reason' => 'revoked_on_site'])->assertOk();

        $this->assertSame('revoked_on_site', AdminAccessRequest::sole()->end_reason);
    }

    /* ----- Isolation and attacks ----- */

    public function test_another_websites_connector_cannot_consume_the_token(): void
    {
        $a = $this->accessReadySite('https://a.example');
        $b = $this->accessReadySite('https://b.example');
        [$uuid, $token] = $this->requestAccess($a);

        $this->claim($b, $uuid, $token)->assertForbidden()->assertJsonPath('error.code', 'ACCESS_INVALID');

        $request = AdminAccessRequest::sole();
        $this->assertSame(AdminAccessStatus::Pending, $request->status);
        $this->assertSame(0, $request->failed_attempts, 'B cannot even count attempts against A.');
        $this->claim($a, $uuid, $token)->assertOk();
    }

    public function test_customers_cannot_act_on_other_customers_websites(): void
    {
        $a = $this->accessReadySite('https://a.example');
        $b = $this->accessReadySite('https://b.example');
        $userB = $b['website']->customer->user;
        [$uuid] = $this->requestAccess($a);
        $requestA = AdminAccessRequest::where('uuid', $uuid)->sole();

        $this->actingAs($userB)->post(route('account.websites.admin-access.maintenance', $a['website']))->assertForbidden();
        $this->actingAs($userB)->get(route('account.websites.admin-access.full-admin', $a['website']))->assertForbidden();
        $this->actingAs($userB)->post(route('account.websites.admin-access.full-admin.store', $a['website']), ['confirm' => '1'])->assertForbidden();
        $this->actingAs($userB)->post(route('account.websites.admin-access.revoke', [$a['website'], $requestA]))->assertForbidden();
        // A's request under B's own website: not found.
        $this->actingAs($userB)->post(route('account.websites.admin-access.revoke', [$b['website'], $requestA]))->assertNotFound();

        $this->assertRefused('NOT_AUTHORIZED', fn () => app(AdminAccessService::class)->request($a['website'], $userB, ActorType::Customer, AccessLevel::Maintenance));
        $this->assertSame(AdminAccessStatus::Pending, $requestA->refresh()->status);
        $this->assertSame(1, AdminAccessRequest::count());
    }

    public function test_swapping_uuids_or_tokens_between_requests_fails(): void
    {
        $site = $this->accessReadySite();
        [$maintenance, $maintenanceToken] = $this->requestAccess($site);
        [$full, $fullToken] = $this->formValues($this->actingAs($site['website']->customer->user)->post(route('account.websites.admin-access.full-admin.store', $site['website']), ['confirm' => '1']));

        // The maintenance token cannot open the full-admin request (escalation), nor the other way round.
        $this->claim($site, $full, $maintenanceToken)->assertForbidden()->assertJsonPath('error.code', 'ACCESS_INVALID');
        $this->claim($site, $maintenance, $fullToken)->assertForbidden();

        // The level always comes from the server's record, whatever is sent.
        $job = $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.admin-access.claim'), ['request_uuid' => $maintenance, 'token' => $maintenanceToken, 'access_level' => 'full_admin'])->json('job');
        $this->assertSame('maintenance', $job['payload']['access_level']);
    }

    public function test_connector_credential_and_access_token_are_separate(): void
    {
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);

        // The connector credential is not an access token…
        $this->claim($site, $uuid, $site['credential'])->assertForbidden();
        // …and the access token is not a connector credential.
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-CareGrid-Website' => $site['website']->uuid])
            ->postJson(route('api.v1.connector.admin-access.claim'), ['request_uuid' => $uuid, 'token' => $token])->assertUnauthorized();
        // A claim needs the connector at all.
        $this->postJson(route('api.v1.connector.admin-access.claim'), ['request_uuid' => $uuid, 'token' => $token])->assertUnauthorized();
        $this->assertSame(AdminAccessStatus::Pending, AdminAccessRequest::sole()->status);
    }

    public function test_session_payload_levels_are_validated(): void
    {
        $this->expectException(ValidationException::class);
        JobType::CreateAdminSession->validatePayload(['request_uuid' => (string) Str::uuid(), 'access_level' => 'super_admin']);
    }

    public function test_rate_limits(): void
    {
        $site = $this->accessReadySite();
        $user = $site['website']->customer->user;

        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->post(route('account.websites.admin-access.maintenance', $site['website']))->assertOk();
        }
        $this->actingAs($user)->post(route('account.websites.admin-access.maintenance', $site['website']))->assertStatus(429);

        // And per website per hour, whatever the route limits.
        config(['admin_access.max_requests_per_hour' => 5]);
        $this->travel(2)->minutes();
        $this->actingAs($user)->post(route('account.websites.admin-access.maintenance', $site['website']))
            ->assertSessionHas('account_error', __('admin_access.refusals.TOO_MANY_REQUESTS'));
    }

    public function test_tokens_and_credentials_never_reach_logs_or_events(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });
        $site = $this->accessReadySite();
        [$uuid, $token] = $this->requestAccess($site);
        $job = $this->claim($site, $uuid, $token)->json('job');
        $this->claim($site, $uuid, $token);
        $this->report($site, $job['uuid'], 'success', 'ADMIN_SESSION_STARTED');

        $everything = implode('', $logged).json_encode(WebsiteEvent::all()->toArray()).json_encode(WebsiteJob::all()->toArray());
        $this->assertStringNotContainsString($token, $everything);
        $this->assertStringNotContainsString($site['credential'], $everything);
        $this->assertStringNotContainsString(hash('sha256', $token), $everything);
    }

    public function test_website_page_without_access(): void
    {
        $site = $this->accessReadySite();

        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))
            ->assertSee(__('admin_access.account.none'))->assertSee(__('admin_access.account.open'))->assertSee(__('admin_access.account.enable_full'));
    }

    /* ----- helpers ----- */

    private function accessReadySite(string $url = 'https://company.example'): array
    {
        $site = $this->pairedWebsite($url);
        $site['website']->activeConnector->update([
            'capabilities' => ['inventory', 'health', 'updates', 'license_check', 'admin_access'],
            'admin_access_url' => rtrim($url, '/').'/wp-login.php?action=wp_caregrid_access',
        ]);
        $this->refreshCapabilityEvidence($site['website']);
        $site['website']->jobs()->delete();

        return ['website' => $site['website']->refresh(), 'credential' => $site['credential']];
    }

    /** @return array{0: string, 1: string} uuid and token, as the browser receives them */
    private function requestAccess(array $site): array
    {
        return $this->formValues($this->actingAs($site['website']->customer->user)->post(route('account.websites.admin-access.maintenance', $site['website']))->assertOk());
    }

    private function formValues(TestResponse $response): array
    {
        preg_match('/name="request" value="([0-9a-f-]{36})"/', $response->getContent(), $uuid);
        preg_match('/name="token" value="([a-f0-9]{64})"/', $response->getContent(), $token);

        return [$uuid[1] ?? $this->fail('No request in the form.'), $token[1] ?? $this->fail('No token in the form.')];
    }

    private function claim(array $site, string $uuid, string $token): TestResponse
    {
        return $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.admin-access.claim'), ['request_uuid' => $uuid, 'token' => $token]);
    }

    private function report(array $site, string $job, string $status, string $code): void
    {
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.jobs.result', $job), ['status' => $status, 'result_code' => $code, 'data' => ['access_level' => 'maintenance']])->assertStatus(202);
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected refusal {$code}.");
        } catch (AdminAccessRefused $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
