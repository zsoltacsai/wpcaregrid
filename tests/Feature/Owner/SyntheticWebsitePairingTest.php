<?php

namespace Tests\Feature\Owner;

use App\Connector\PairingCodes;
use App\Models\Connector;
use App\Models\ConnectorPairingCode;
use App\Models\Website;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

final class SyntheticWebsitePairingTest extends TestCase
{
    use ActsAsOwner, PairsConnectors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_unauthenticated_and_customer_sessions_cannot_use_owner_pairing(): void
    {
        $this->configureStaging();
        $website = $this->syntheticWebsite();

        $this->post(route('owner.websites.pairing-code', $website))
            ->assertRedirect(route('owner.login'));

        $customer = $this->websiteWithPlan()->customer->user;
        $this->actingAs($customer, 'owner')
            ->post(route('owner.websites.pairing-code', $website))
            ->assertRedirect(route('owner.login'));

        $this->assertDatabaseCount('connector_pairing_codes', 0);
    }

    public function test_owner_generates_the_normal_one_time_pairing_code_for_exact_synthetic_site(): void
    {
        $this->freezeTime();
        $this->configureStaging();
        $owner = $this->asOwner();
        $website = $this->syntheticWebsite();
        Log::spy();

        $response = $this->followingRedirects()->post(route('owner.websites.pairing-code', $website));
        $response->assertOk()
            ->assertSee(__('owner.synthetic_fixture_badge'))
            ->assertSee(__('owner.websites.pairing_code_once'));

        $this->assertSame(1, preg_match('/\b([A-HJ-KM-NP-Z2-9]{4}(?:-[A-HJ-KM-NP-Z2-9]{4}){2})\b/', $response->getContent(), $matches));
        $code = $matches[1];
        $stored = ConnectorPairingCode::sole();
        $this->assertSame($website->id, $stored->website_id);
        $this->assertSame($owner->id, $stored->created_by);
        $this->assertSame(PairingCodes::hash($code), $stored->code_hash);
        $this->assertEquals(now()->addSeconds(config('connector.pairing_code_ttl'))->startOfSecond(), $stored->expires_at);
        $this->assertStringNotContainsString($code, json_encode($stored->getAttributes(), JSON_THROW_ON_ERROR));
        $response->assertSessionMissing('pairing_code');

        Log::shouldHaveReceived('info')->once()->with(
            'Owner issued a pairing code for a synthetic website.',
            Mockery::on(fn (array $context) => ($context['owner'] ?? null) === $owner->id
                && ($context['website_uuid'] ?? null) === $website->uuid
                && ! str_contains(json_encode($context), $code)),
        );

        $this->pair($code, $website->url)->assertCreated();
        $this->pair($code, $website->url)->assertStatus(422);
        $this->assertSame(1, Connector::count());
    }

    public function test_owner_issued_code_expires_at_the_existing_pairing_ttl(): void
    {
        $this->freezeTime();
        $this->configureStaging();
        $this->asOwner();
        $website = $this->syntheticWebsite();

        $response = $this->followingRedirects()->post(route('owner.websites.pairing-code', $website));
        $response->assertOk();
        preg_match('/\b([A-HJ-KM-NP-Z2-9]{4}(?:-[A-HJ-KM-NP-Z2-9]{4}){2})\b/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $code = $matches[1];
        $this->assertEquals(now()->addSeconds(config('connector.pairing_code_ttl'))->startOfSecond(), ConnectorPairingCode::sole()->expires_at);

        $this->travel(config('connector.pairing_code_ttl') + 1)->seconds();
        $this->pair($code, $website->url)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAIRING_CODE');
        $this->assertSame(0, Connector::count());
    }

    public function test_owner_pairing_refuses_ordinary_sites_wrong_uuid_and_production(): void
    {
        $this->configureStaging();
        $this->asOwner();
        $synthetic = $this->syntheticWebsite();
        $ordinary = $this->websiteWithPlan()->fresh();

        $this->post(route('owner.websites.pairing-code', $ordinary))->assertNotFound();
        $this->assertDatabaseCount('connector_pairing_codes', 0);

        config(['synthetic_fixtures.site_uuid' => '00000000-0000-4000-8000-000000000001']);
        $this->post(route('owner.websites.pairing-code', $synthetic))->assertNotFound();
        $this->assertDatabaseCount('connector_pairing_codes', 0);

        config([
            'synthetic_fixtures.site_uuid' => $synthetic->uuid,
            'app.deployment_environment' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'app.deployment_hostname' => 'prod1.wpcaregrid.com',
        ]);
        $this->post(route('owner.websites.pairing-code', $synthetic))->assertNotFound();
        $this->assertDatabaseCount('connector_pairing_codes', 0);
    }

    public function test_pairing_code_is_not_shown_for_an_ordinary_website(): void
    {
        $this->configureStaging();
        $this->asOwner();
        $website = $this->websiteWithPlan()->fresh();

        $this->get(route('owner.websites.show', $website))
            ->assertOk()
            ->assertDontSee(__('owner.websites.generate_pairing_code'));
    }

    private function syntheticWebsite(): Website
    {
        config(['synthetic_fixtures.provisioning_enabled' => true]);

        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];

        config(['synthetic_fixtures.site_uuid' => $website->uuid]);

        return $website->refresh();
    }

    private function configureStaging(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'synthetic_fixtures.site_uuid' => null,
        ]);
    }
}
