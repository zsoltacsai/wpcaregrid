<?php

namespace Tests\Feature\Connector;

use App\Models\ConnectorPairingCode;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Website;
use App\Rules\PublicWebsiteUrl;
use App\Websites\ConnectorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;

class AccountConnectorTest extends TestCase
{
    use PairsConnectors, RefreshDatabase;

    public function test_customer_generates_a_one_time_code_shown_once_and_stored_hashed(): void
    {
        $website = $this->websiteWithPlan();
        $user = $website->customer->user;

        $response = $this->actingAs($user)->post(route('account.websites.pairing-code', $website));
        $response->assertRedirect(route('account.websites.show', $website));
        $this->assertStringNotContainsString('code=', $response->headers->get('Location'));

        $code = session('pairing_code')['code'];
        // Sessions are JSON-serialised in production: the flashed value must survive that.
        $this->assertSame(session('pairing_code'), json_decode(json_encode(session('pairing_code')), true));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        $stored = ConnectorPairingCode::sole();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString(str_replace('-', '', $code), json_encode($stored->getAttributes()));

        $this->followRedirects($response)->assertSee($code);
        // Not shown again on the next visit.
        $this->get(route('account.websites.show', $website))->assertDontSee($code);
    }

    public function test_pairing_code_needs_an_active_plan_and_ownership(): void
    {
        $inactive = $this->websiteWithPlan('https://inactive.example', 'unpaid');
        $this->actingAs($inactive->customer->user)->post(route('account.websites.pairing-code', $inactive))->assertForbidden();

        $other = $this->websiteWithPlan('https://other.example');
        $this->actingAs($inactive->customer->user)->post(route('account.websites.pairing-code', $other))->assertForbidden();
        $this->assertDatabaseCount('connector_pairing_codes', 0);
    }

    public function test_customer_disconnect_revokes_the_connector_permanently(): void
    {
        $site = $this->pairedWebsite();
        $website = $site['website'];

        $this->actingAs($website->customer->user)->post(route('account.websites.disconnect', $website))->assertRedirect();

        $this->assertSame(ConnectorStatus::Revoked, $website->refresh()->connector_status);
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.heartbeat'), $this->heartbeatPayload())
            ->assertStatus(401)->assertJsonPath('error.code', 'CONNECTOR_REVOKED');
    }

    public function test_customer_cannot_disconnect_another_customers_site(): void
    {
        $site = $this->pairedWebsite();
        $stranger = Customer::factory()->withUser()->create();

        $this->actingAs($stranger->user)->post(route('account.websites.disconnect', $site['website']))->assertForbidden();
        $this->assertSame('active', $site['website']->activeConnector->status);
    }

    public function test_website_page_shows_connector_state_inventory_and_unknown_licenses(): void
    {
        $site = $this->pairedWebsite();
        $this->withHeaders($this->connectorHeaders($site))->postJson(route('api.v1.connector.inventory'), $this->inventoryPayload())->assertStatus(202);

        $this->actingAs($site['website']->customer->user)->get(route('account.websites.show', $site['website']))
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('WP Rocket')
            ->assertSee('Not checked yet') // Unchecked is not shown as "Up to date".
            ->assertSee('Unknown')
            ->assertSee(__('connector.account.license_note'))
            ->assertDontSee($site['credential']);
    }

    public function test_plugin_download_is_for_logged_in_customers_only(): void
    {
        $this->get(route('account.connector.download'))->assertRedirect(route('login'));

        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->create();
        $this->artisan('connector:package')->assertSuccessful();

        $this->actingAs($customer->user)->get(route('account.connector.download'))
            ->assertOk()
            ->assertDownload('wp-caregrid-connector-'.config('connector.latest_version').'.zip');
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'public https' => ['https://company.example', true],
            'public http with path' => ['http://shop.company.example/store', true],
            'localhost' => ['http://localhost', false],
            'loopback IP' => ['http://127.0.0.1', false],
            'private IP' => ['https://10.0.0.5', false],
            'private IP 192' => ['https://192.168.1.10', false],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data', false],
            'IPv6 loopback' => ['http://[::1]/', false],
            'decimal IP' => ['http://2130706433', false],
            'internal name' => ['https://intranet.corp', false],
            '.local' => ['https://printer.local', false],
            'single label' => ['https://router', false],
            'odd port' => ['https://company.example:8080', false],
            'credentials in URL' => ['https://user:pass@company.example', false],
            'ftp' => ['ftp://company.example', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_website_urls_must_be_public(string $url, bool $valid): void
    {
        $this->assertSame($valid, Validator::make(['url' => $url], ['url' => [new PublicWebsiteUrl]])->passes(), $url);
    }

    public function test_onboarding_rejects_internal_addresses(): void
    {
        $customer = Customer::factory()->withUser()->create();
        Subscription::factory()->for($customer)->create();

        $this->actingAs($customer->user)->post(route('account.websites.store'), ['url' => 'http://169.254.169.254', 'type' => 'wordpress'])
            ->assertSessionHasErrors('url');
        $this->assertSame(0, Website::count());
    }
}
