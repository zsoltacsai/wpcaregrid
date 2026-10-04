<?php

namespace Tests\Feature\Connector;

use App\Connector\Presence;
use App\Models\Connector;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class ConnectorApiTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_requests_need_a_valid_credential(): void
    {
        $site = $this->pairedWebsite();
        $url = route('api.v1.connector.heartbeat');

        $this->postJson($url, $this->heartbeatPayload())->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeaders(['Authorization' => 'Bearer not-a-token', 'X-CareGrid-Website' => $site['website']->uuid])
            ->postJson($url, $this->heartbeatPayload())->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer cgc_'.str_repeat('x', 48), 'X-CareGrid-Website' => $site['website']->uuid])
            ->postJson($url, $this->heartbeatPayload())->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeaders($this->connectorHeaders($site))->postJson($url, $this->heartbeatPayload())->assertOk();
    }

    public function test_heartbeat_updates_versions_health_and_contact_time(): void
    {
        $site = $this->pairedWebsite();

        $this->travel(10)->minutes();
        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload(['connector_version' => '0.0.9', 'wp_version' => '7.1.3']))
            ->assertOk()
            ->assertJson(['ok' => true, 'jobs_pending' => 3, 'latest_connector_version' => '0.4.1', 'connector_outdated' => true, 'heartbeat_interval' => 900, 'poll_interval' => 300]);

        $website = $site['website']->refresh();
        $connector = $website->activeConnector;
        $this->assertSame('7.1.3', $website->wordpress_version);
        $this->assertSame('healthy', $website->health_status->value);
        $this->assertTrue($connector->isOutdated());
        $this->assertTrue($connector->last_heartbeat_at->isToday());
        $this->assertSame(Presence::Connected, $website->presence());
    }

    public function test_first_heartbeat_without_a_health_report_is_accepted(): void
    {
        // Exactly what the plugin sends right after pairing, before its first check.
        $site = $this->pairedWebsite();

        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload(['health' => null, 'last_successful_poll_at' => null, 'site_url' => 'https://company.example/']))
            ->assertOk();

        $this->assertNull($site['website']->refresh()->health_checked_at);
    }

    public function test_heartbeat_is_validated(): void
    {
        $site = $this->pairedWebsite();

        $this->withHeaders($this->connectorHeaders($site))
            ->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload(['health' => ['status' => 'great', 'findings' => []]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REQUEST');
    }

    public function test_inventory_is_stored_without_unexpected_fields(): void
    {
        $site = $this->pairedWebsite();
        $payload = $this->inventoryPayload();
        $payload['plugins'][0]['settings'] = ['api_key' => 'secret-value'];
        $payload['wp_options'] = ['admin_email' => 'x@company.example'];

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $payload)->assertStatus(202);

        $inventory = $site['website']->inventory()->sole();
        $this->assertSame('7.1.2', $inventory->core['version']);
        $this->assertCount(2, $inventory->plugins);
        $this->assertSame('unknown', $inventory->plugins[1]['license']['status']);
        $this->assertSame(2, $inventory->updatesAvailable());
        $this->assertStringNotContainsString('secret-value', json_encode($inventory->getAttributes()));
        $this->assertStringNotContainsString('admin_email', json_encode($inventory->getAttributes()));
    }

    public function test_inventory_rejects_unsafe_identifiers_and_license_states(): void
    {
        $site = $this->pairedWebsite();
        $bad = $this->inventoryPayload();
        $bad['plugins'][0]['file'] = '../../wp-config.php';
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $bad)->assertStatus(422);

        $bad = $this->inventoryPayload();
        $bad['plugins'][0]['license']['status'] = 'probably_fine';
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $bad)->assertStatus(422);
    }

    public function test_connector_cannot_act_for_another_website(): void
    {
        $alice = $this->pairedWebsite('https://alice.example');
        $bob = $this->pairedWebsite('https://bob.example');

        // Alice's credential with Bob's website ID.
        $headers = ['Authorization' => 'Bearer '.$alice['credential'], 'X-CareGrid-Website' => $bob['website']->uuid];

        $this->withHeaders($headers)->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())->assertStatus(403)->assertJsonPath('error.code', 'WEBSITE_MISMATCH');
        $this->withHeaders($headers)->getJson(route('api.v1.connector.jobs'))->assertStatus(403);
        $this->withHeaders($headers)->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())->assertStatus(403);
        $this->withHeaders($headers)->postJson(route('api.v1.connector.disconnect'))->assertStatus(403);

        // Alice's own polling never returns Bob's jobs.
        $aliceJobs = $this->withHeaders($this->connectorHeaders($alice))->getJson(route('api.v1.connector.jobs'))->json('jobs');
        $bobJobUuids = $bob['website']->jobs()->pluck('uuid')->all();
        $this->assertNotEmpty($aliceJobs);
        $this->assertEmpty(array_intersect(array_column($aliceJobs, 'uuid'), $bobJobUuids));

        // Nor can Alice submit a result for Bob's job.
        $this->withHeaders($this->connectorHeaders($alice))
            ->postJson(route('api.v1.connector.jobs.result', $bobJobUuids[0]), ['status' => 'success', 'result_code' => 'OK'])
            ->assertStatus(404)->assertJsonPath('error.code', 'JOB_NOT_FOUND');

        $this->assertSame('active', Connector::where('website_id', $bob['website']->id)->sole()->status);
        $this->assertNull($bob['website']->refresh()->inventory);
    }

    public function test_connector_disconnect_revokes_its_credential(): void
    {
        $site = $this->pairedWebsite();

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.disconnect'))->assertOk();

        $website = $site['website']->refresh();
        $this->assertSame(ConnectorStatus::Revoked, $website->connector_status);
        $this->assertSame(WebsiteStatus::Disconnected, $website->status);
        $this->assertSame(0, $website->jobs()->whereIn('status', ['queued', 'delivered'])->count());

        $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))
            ->assertStatus(401)->assertJsonPath('error.code', 'CONNECTOR_REVOKED');
    }

    public function test_stale_and_disconnected_presence_from_timestamps(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];

        $this->assertSame(Presence::Connected, $website->presence());

        $this->travel(config('connector.stale_after') + 60)->seconds();
        $this->assertSame(Presence::Stale, $website->refresh()->presence());

        $this->travel(config('connector.disconnected_after'))->seconds();
        $this->assertSame(Presence::Disconnected, $website->refresh()->presence());

        Artisan::call('connector:update-presence');
        $this->assertSame(WebsiteStatus::Disconnected, $website->refresh()->status);

        // Reporting in again reconnects.
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())->assertOk();
        $this->assertSame(Presence::Connected, $website->refresh()->presence());
        $this->assertNotSame(WebsiteStatus::Disconnected, $website->status);
    }

    public function test_api_traffic_is_rate_limited_per_connector(): void
    {
        $site = $this->pairedWebsite();

        for ($i = 0; $i < 30; $i++) {
            $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->assertOk();
        }

        $this->withHeaders($this->connectorHeaders($site))->getJson(route('api.v1.connector.jobs'))->assertStatus(429);

        // Another connector is unaffected.
        $other = $this->pairedWebsite('https://other.example');
        $this->withHeaders($this->connectorHeaders($other))->getJson(route('api.v1.connector.jobs'))->assertOk();
    }

    public function test_https_is_required_when_enabled(): void
    {
        $site = $this->pairedWebsite();
        config(['connector.require_https' => true]);

        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())
            ->assertStatus(403)->assertJsonPath('error.code', 'HTTPS_REQUIRED');
    }
}
