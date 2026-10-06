<?php

namespace Tests\Feature\Owner;

use App\Cloudflare\WpCareGridInfrastructureDns;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

class CloudflareDnsTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_customer_and_operator_cannot_access_dns_management(): void
    {
        Http::fake();
        $this->get(route('owner.dns.cloudflare.index'))->assertRedirect(route('owner.login'));

        $customer = Customer::factory()->withUser()->create()->user;
        $this->actingAs($customer, 'owner')->get(route('owner.dns.cloudflare.index'))->assertRedirect(route('owner.login'));

        $operator = User::factory()->operator(Permission::cases())->create();
        $this->actingAs($operator, 'owner')->get(route('owner.dns.cloudflare.index'))->assertRedirect(route('owner.login'));
        Http::assertNothingSent();
    }

    public function test_owner_token_is_encrypted_and_never_rendered_on_owner_dns_page(): void
    {
        $this->asOwner();
        $token = 'cloudflare-test-token-do-not-render';
        app(IntegrationCredentials::class)->store('cloudflare_api_token', $token);
        config(['services.cloudflare.api_token' => $token, 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        app(IntegrationCredentials::class)->apply();
        $this->assertSame($token, config('services.cloudflare.api_token'));
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/user/tokens/verify')) {
                return Http::response(['success' => true, 'result' => ['status' => 'active']]);
            }
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]);
        });

        $stored = DB::table('integration_credentials')->where('id', 'cloudflare_api_token')->value('value');
        $this->assertNotSame($token, $stored);
        $this->assertSame($token, Crypt::decryptString($stored));
        $response = $this->get(route('owner.dns.cloudflare.index'))->assertOk();
        $response->assertDontSee($token, false)->assertSee('API reachable');
    }

    public function test_cloudflare_credential_replacement_and_clear_preserve_environment_fallback_state(): void
    {
        $credentials = app(IntegrationCredentials::class);
        config(['owner_integration_credentials.environment_fallbacks.cloudflare_api_token' => true]);
        $credentials->store('cloudflare_api_token', 'first-synthetic-token');
        $credentials->store('cloudflare_api_token', 'replacement-synthetic-token');

        $encrypted = DB::table('integration_credentials')->where('id', 'cloudflare_api_token')->value('value');
        $this->assertSame('replacement-synthetic-token', Crypt::decryptString($encrypted));
        $this->assertSame(['owner_managed' => true, 'environment_fallback' => true], $credentials->configured()['cloudflare_api_token']);

        $credentials->clear('cloudflare_api_token');
        $this->assertSame(['owner_managed' => false, 'environment_fallback' => true], $credentials->configured()['cloudflare_api_token']);
        $this->assertDatabaseMissing('integration_credentials', ['id' => 'cloudflare_api_token']);
    }

    public function test_owner_can_only_preview_changes_for_exact_allowlisted_zone_and_routes_are_csrf_protected(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'cloudflare-test-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]);
        });

        $this->from(route('owner.dns.cloudflare.index'))->post(route('owner.dns.cloudflare.records.plan'), [
            'zone' => 'example.net', 'type' => 'A', 'name' => 'x.example.net', 'content' => '192.0.2.1',
        ])->assertRedirect(route('owner.dns.cloudflare.index'))->assertSessionHas('dns_error', 'zone_not_allowed');

        $this->post(route('owner.dns.cloudflare.records.plan'), [
            'zone' => 'wpcaregrid.com', 'type' => 'A', 'name' => 'test.wpcaregrid.com', 'content' => '192.0.2.1',
        ])->assertRedirect();
        Http::assertSent(fn ($request) => $request->method() === 'GET');

        foreach (['owner.dns.cloudflare.records.plan', 'owner.dns.cloudflare.records.apply', 'owner.dns.cloudflare.records.delete.plan', 'owner.dns.cloudflare.records.delete.apply'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('can:owner.dns.manage', $route->gatherMiddleware());
            $this->assertContains('throttle:owner-sensitive', $route->gatherMiddleware());
            $this->assertContains('throttle:owner-external', $route->gatherMiddleware());
        }
    }

    public function test_owner_without_the_dns_ability_is_denied_server_side(): void
    {
        $this->asOwner();
        Gate::define('owner.dns.manage', fn () => false);
        Http::fake();

        $this->get(route('owner.dns.cloudflare.index'))->assertForbidden();
        $this->post(route('owner.dns.cloudflare.records.delete.apply'), ['preview_id' => str_repeat('a', 40), 'confirmed' => '1'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_protected_write_is_audited_without_record_content_or_credentials(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'synthetic-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [['id' => 'abcdef0123456789abcdef0123456789', 'type' => 'A', 'name' => 'staging.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 1, 'proxied' => false]], 'result_info' => ['total_pages' => 1]]);
        });

        $this->from(route('owner.dns.cloudflare.index'))->post(route('owner.dns.cloudflare.records.plan'), [
            'zone' => 'wpcaregrid.com', 'type' => 'A', 'name' => 'STAGING.WPCAREGRID.COM.', 'content' => '192.0.2.2',
        ])->assertRedirect(route('owner.dns.cloudflare.index'))->assertSessionHas('dns_error', 'record_protected');

        $event = AdminAuditEvent::where('event', 'dns.record.failed')->firstOrFail();
        $this->assertSame(['operation' => 'ensure', 'error' => 'record_protected'], $event->data);
        $this->assertStringNotContainsString('192.0.2.2', $event->toJson());
        $this->assertStringNotContainsString('synthetic-token', $event->toJson());
    }

    public function test_dns_record_content_is_html_escaped_in_the_owner_view(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'synthetic-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [['id' => 'abcdef0123456789abcdef0123456789', 'type' => 'TXT', 'name' => '_dmarc.wpcaregrid.com', 'content' => '<script>alert(1)</script>', 'ttl' => 1, 'proxied' => false]], 'result_info' => ['total_pages' => 1]]);
        });

        $this->get(route('owner.dns.cloudflare.zone', ['zone' => 'wpcaregrid.com']))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_confirmed_dns_change_is_audited_without_token_or_authorization_header(): void
    {
        $this->asOwner();
        $token = 'audit-test-cloudflare-token';
        config(['services.cloudflare.api_token' => $token, 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        $records = [];
        Http::fake(function ($request) use (&$records) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if ($request->method() === 'POST') {
                $record = ['id' => 'abcdef0123456789abcdef0123456789', ...$request->data()];
                $records[] = $record;

                return Http::response(['success' => true, 'result' => $record]);
            }

            return Http::response(['success' => true, 'result' => $records, 'result_info' => ['total_pages' => 1]]);
        });

        $response = $this->post(route('owner.dns.cloudflare.records.plan'), [
            'zone' => 'wpcaregrid.com', 'type' => 'TXT', 'name' => 'acceptance.wpcaregrid.com', 'content' => 'synthetic-test-record',
        ])->assertRedirect();
        $previewId = session('dns_preview_id');
        $this->assertIsString($previewId);
        $this->get($response->headers->get('Location'))->assertOk();
        $this->post(route('owner.dns.cloudflare.records.apply'), ['preview_id' => $previewId, 'confirmed' => '1'])->assertRedirect();

        $this->assertTrue(AdminAuditEvent::whereIn('event', ['dns.record.create_requested', 'dns.record.created'])->exists());
        $auditJson = AdminAuditEvent::where('event', 'like', 'dns.record.%')->get()->toJson();
        $this->assertStringNotContainsString($token, $auditJson);
        $this->assertStringNotContainsString('Authorization', $auditJson);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/dns_records'));
    }

    public function test_managed_infrastructure_dns_requires_a_fixed_preview_and_explicit_approval(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'synthetic-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        $records = [];
        $writes = 0;
        Http::fake(function ($request) use (&$records, &$writes) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => '0123456789abcdef0123456789abcdef', 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $records, 'result_info' => ['total_pages' => 1]]);
            }
            $writes++;
            $record = ['id' => 'abcdef0123456789abcdef0123456789', ...$request->data()];
            $records[] = $record;

            return Http::response(['success' => true, 'result' => $record]);
        });

        $this->get(route('owner.dns.cloudflare.infrastructure'))->assertOk()
            ->assertSee('wp-test.staging.wpcaregrid.com')
            ->assertSee('mail.wpcaregrid.com');
        $this->assertSame(0, $writes);

        $this->post(route('owner.dns.cloudflare.infrastructure.plan'), ['definition_id' => 'staging_wp_test_a'])->assertRedirect();
        $previewId = session('dns_preview_id');
        $this->assertIsString($previewId);
        $this->assertSame(0, $writes, 'Planning must not mutate Cloudflare DNS.');

        $this->get(route('owner.dns.cloudflare.infrastructure'))
            ->assertOk()
            ->assertSee(route('owner.dns.cloudflare.infrastructure.apply'), false);
        $this->post(route('owner.dns.cloudflare.infrastructure.apply'), ['preview_id' => $previewId, 'confirmed' => '1'])->assertRedirect();

        $this->assertSame(1, $writes);
        $this->assertSame('wp-test.staging.wpcaregrid.com', $records[0]['name']);
        $this->assertSame('85.155.243.164', $records[0]['content']);
        $this->assertFalse($records[0]['proxied']);
        $event = AdminAuditEvent::where('event', 'dns.infrastructure.applied')->firstOrFail();
        $this->assertSame('staging_wp_test_a', $event->subject_key);
        $this->assertSame('wpcaregrid.com', $event->data['zone']);
        $this->assertSame('A', $event->data['type']);
        $this->assertSame('wp-test.staging.wpcaregrid.com', $event->data['name']);
        $this->assertSame('created', $event->data['result']);
        $this->assertNotNull($event->ip_address);
        $this->assertStringNotContainsString('synthetic-token', $event->toJson());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/dns_records'));
    }

    public function test_managed_infrastructure_page_shows_matches_and_missing_states_without_writes(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'synthetic-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        $desired = WpCareGridInfrastructureDns::definitions()['staging_wp_test_a']['record'];
        $record = [
            'id' => 'abcdef0123456789abcdef0123456789',
            'zone_id' => '0123456789abcdef0123456789abcdef',
            ...$desired,
        ];
        Http::fake(function ($request) use ($record) {
            if (str_contains($request->url(), '/zones') && ! str_contains($request->url(), '/dns_records')) {
                return Http::response(['success' => true, 'result' => [['id' => $record['zone_id'], 'name' => 'wpcaregrid.com']], 'result_info' => ['total_pages' => 1]]);
            }

            return Http::response(['success' => true, 'result' => [$record], 'result_info' => ['total_pages' => 1]]);
        });

        $this->get(route('owner.dns.cloudflare.infrastructure'))->assertOk()
            ->assertSee('wp-test.staging.wpcaregrid.com')
            ->assertSee(__('owner.dns.infrastructure.status.matches'))
            ->assertSee('mail.wpcaregrid.com')
            ->assertSee(__('owner.dns.infrastructure.status.missing'));

        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_managed_infrastructure_rejects_arbitrary_ids_and_requires_confirmation(): void
    {
        $this->asOwner();
        config(['services.cloudflare.api_token' => 'synthetic-token', 'cloudflare.allowed_zones' => ['wpcaregrid.com']]);
        Http::fake();

        $this->post(route('owner.dns.cloudflare.infrastructure.plan'), ['definition_id' => 'attacker_record'])
            ->assertSessionHasErrors('definition_id');
        $this->post(route('owner.dns.cloudflare.infrastructure.apply'), ['preview_id' => str_repeat('a', 40)])
            ->assertSessionHasErrors('confirmed');
        Http::assertNothingSent();

        foreach (['owner.dns.cloudflare.infrastructure.plan', 'owner.dns.cloudflare.infrastructure.apply'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('can:owner.dns.manage', $route->gatherMiddleware());
            $this->assertContains('throttle:owner-sensitive', $route->gatherMiddleware());
            $this->assertContains('throttle:owner-external', $route->gatherMiddleware());
        }
        $this->assertContains('GET', app('router')->getRoutes()->getByName('owner.dns.cloudflare.infrastructure')->methods());
        $this->assertNotContains('POST', app('router')->getRoutes()->getByName('owner.dns.cloudflare.infrastructure')->methods());
        Http::assertNothingSent();
    }
}
