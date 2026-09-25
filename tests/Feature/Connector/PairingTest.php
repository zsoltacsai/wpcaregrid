<?php

namespace Tests\Feature\Connector;

use App\Connector\JobType;
use App\Connector\PairingCodes;
use App\Models\Connector;
use App\Models\ConnectorPairingCode;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class PairingTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_successful_pairing_connects_the_website_and_issues_a_credential_once(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->assertSame(ConnectorStatus::NotInstalled, $website->connector_status);

        $response = $this->pair($code)->assertCreated()
            ->assertJsonStructure(['website_uuid', 'connector_uuid', 'credential', 'capabilities', 'heartbeat_interval', 'poll_interval']);

        $credential = $response->json('credential');
        $this->assertMatchesRegularExpression('/^cgc_[A-Za-z0-9]{48}$/', $credential);
        $this->assertSame($website->uuid, $response->json('website_uuid'));

        $connector = Connector::sole();
        // Only the hash is stored.
        $this->assertSame(hash('sha256', $credential), $connector->credential_hash);
        $this->assertStringNotContainsString($credential, json_encode($connector->getAttributes()));
        $this->assertArrayNotHasKey('credential_hash', $connector->toArray());

        $website->refresh();
        $this->assertSame(ConnectorStatus::Connected, $website->connector_status);
        $this->assertSame(WebsiteStatus::Connected, $website->status);
        $this->assertSame('7.1.2', $website->wordpress_version);

        // The initial check is queued.
        $this->assertEqualsCanonicalizing(
            [JobType::GetInventory, JobType::SiteHealth, JobType::LicenseCheck],
            $website->jobs->pluck('type')->all(),
        );
    }

    public function test_code_is_single_use(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->pair($code)->assertCreated();
        $this->pair($code)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');

        $this->assertSame(1, Connector::count());
    }

    public function test_expired_code_is_rejected(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->travel(config('connector.pairing_code_ttl') + 1)->seconds();

        $this->pair($code)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');
        $this->assertSame(ConnectorStatus::NotInstalled, $website->refresh()->connector_status);
    }

    public function test_wrong_code_is_rejected_without_revealing_why(): void
    {
        $website = $this->websiteWithPlan();
        app(PairingCodes::class)->issue($website);

        $this->pair('AAAA-BBBB-CCCC')->assertStatus(422)->assertExactJson(['error' => ['code' => 'INVALID_PAIRING_CODE', 'message' => 'The pairing code is invalid or has expired.']]);
        $this->assertDatabaseCount('connectors', 0);
    }

    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $website = $this->websiteWithPlan();
        $first = app(PairingCodes::class)->issue($website);
        $second = app(PairingCodes::class)->issue($website);

        $this->pair($first)->assertStatus(422);
        $this->pair($second)->assertCreated();
    }

    public function test_code_is_accepted_regardless_of_case_and_dashes(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->pair(strtolower(str_replace('-', ' ', $code)))->assertCreated();
    }

    public function test_code_only_pairs_the_website_it_was_issued_for(): void
    {
        $website = $this->websiteWithPlan('https://company.example');
        $code = app(PairingCodes::class)->issue($website);

        $this->pair($code, 'https://other.example')->assertStatus(422)->assertJsonPath('error.code', 'SITE_URL_MISMATCH');
        $this->assertDatabaseCount('connectors', 0);

        // www. and scheme differences are the same site.
        $this->pair($code, 'http://www.company.example/')->assertCreated();
    }

    public function test_pairing_needs_an_active_subscription(): void
    {
        $website = $this->websiteWithPlan(status: 'past_due');
        $code = app(PairingCodes::class)->issue($website);

        $this->pair($code)->assertStatus(403)->assertJsonPath('error.code', 'SERVICE_INACTIVE');
    }

    public function test_pairing_input_is_validated(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->pair($code, overrides: ['installation_id' => '../../etc'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REQUEST');
        $this->pair($code, 'javascript:alert(1)')->assertStatus(422);
        $this->assertTrue(ConnectorPairingCode::sole()->isUsable(), 'A malformed request does not consume the code.');
    }

    public function test_unknown_capabilities_are_ignored(): void
    {
        $website = $this->websiteWithPlan();
        $code = app(PairingCodes::class)->issue($website);

        $this->pair($code, overrides: ['capabilities' => ['inventory', 'run_php', 'backup', 'shell']])->assertCreated();

        $this->assertSame(['inventory'], Connector::sole()->capabilities);
        $this->assertSame([JobType::GetInventory], $website->jobs()->pluck('type')->all());
    }

    public function test_repairing_revokes_the_previous_credential(): void
    {
        ['website' => $website, 'credential' => $old] = $this->pairedWebsite();

        $this->pair(app(PairingCodes::class)->issue($website))->assertCreated();

        $this->withHeaders(['Authorization' => "Bearer {$old}", 'X-CareGrid-Website' => $website->uuid])
            ->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())
            ->assertStatus(401)->assertJsonPath('error.code', 'CONNECTOR_REVOKED');
        $this->assertSame(1, Connector::where('status', 'active')->count());
    }

    public function test_pairing_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pair('WRNG-CODE-000'.$i)->assertStatus(422);
        }

        $this->pair('WRNG-CODE-0005')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
