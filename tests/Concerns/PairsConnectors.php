<?php

namespace Tests\Concerns;

use App\Connector\PairingCodes;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Sets up customers, websites and paired connectors for API tests. */
trait PairsConnectors
{
    protected function websiteWithPlan(string $url = 'https://company.example', string $status = 'active'): Website
    {
        $customer = Customer::factory()->withUser()->create();
        $subscription = Subscription::factory()->for($customer)->create(['status' => $status]);

        return Website::factory()->for($customer)->create(['url' => $url, 'subscription_id' => $subscription->id]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function pair(string $code, string $siteUrl = 'https://company.example', array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.v1.connector.pair'), $overrides + [
            'pairing_code' => $code,
            'installation_id' => (string) Str::uuid(),
            'site_url' => $siteUrl,
            'connector_version' => '0.1.0',
            'wp_version' => '7.1.2',
            'php_version' => '8.4.19',
            'capabilities' => ['inventory', 'health', 'updates', 'license_check'],
        ]);
    }

    /** @return array{website: Website, credential: string} */
    protected function pairedWebsite(string $url = 'https://company.example'): array
    {
        $website = $this->websiteWithPlan($url);
        $code = app(PairingCodes::class)->issue($website);
        $credential = $this->pair($code, $url)->assertCreated()->json('credential');

        return ['website' => $website->refresh(), 'credential' => $credential];
    }

    /** @param  array{website: Website, credential: string}  $site */
    protected function connectorHeaders(array $site): array
    {
        return ['Authorization' => 'Bearer '.$site['credential'], 'X-CareGrid-Website' => $site['website']->uuid];
    }

    protected function heartbeatPayload(array $overrides = []): array
    {
        return $overrides + [
            'connector_version' => '0.1.0',
            'wp_version' => '7.1.2',
            'php_version' => '8.4.19',
            'site_url' => 'https://company.example',
            'woocommerce' => ['installed' => false, 'active' => false, 'version' => null],
            'capabilities' => ['inventory', 'health', 'updates', 'license_check'],
            'health' => ['status' => 'healthy', 'findings' => [['id' => 'rest_api', 'status' => 'pass', 'label' => 'REST API']]],
        ];
    }

    protected function inventoryPayload(): array
    {
        return [
            'core' => ['version' => '7.1.2', 'update_available' => true, 'latest_version' => '7.1.3'],
            'php_version' => '8.4.19',
            'plugins' => [
                ['file' => 'akismet/akismet.php', 'slug' => 'akismet', 'name' => 'Akismet', 'version' => '5.3', 'active' => true, 'update_available' => true, 'new_version' => '5.4', 'update_source' => 'wordpress.org', 'license' => ['status' => 'not_available']],
                ['file' => 'wp-rocket/wp-rocket.php', 'slug' => 'wp-rocket', 'name' => 'WP Rocket', 'version' => '3.16', 'active' => true, 'update_available' => null, 'license' => ['status' => 'unknown']],
            ],
            'themes' => [
                ['stylesheet' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'active' => true, 'update_available' => false],
            ],
            'woocommerce' => ['installed' => false, 'active' => false, 'version' => null],
        ];
    }
}
