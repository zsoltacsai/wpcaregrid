<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Marketing\Analytics\MarketingAnalyticsPolicy;
use Illuminate\Http\Request;
use Tests\TestCase;

class MarketingAnalyticsPolicyTest extends TestCase
{
    public function test_analytics_is_disabled_without_explicit_production_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'seo.production_host' => 'wpcaregrid.com',
            'marketing_analytics.enabled' => true,
            'marketing_analytics.policy_authorized' => false,
            'marketing_analytics.measurement_id' => 'G-ABC1234567',
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'wpcaregrid.com', 'HTTPS' => 'on'])->get('/en/')
            ->assertOk()
            ->assertSee('data-enabled="false"', false)
            ->assertDontSee('data-measurement-id="G-ABC1234567"', false);
    }

    public function test_analytics_preferences_are_localized_and_no_marketing_query_is_rendered(): void
    {
        $this->get('https://wpcaregrid.com/en/?utm_source=private-value')
            ->assertOk()
            ->assertSee('Analytics preferences')
            ->assertSee('Allow analytics')
            ->assertSee('Reject analytics')
            ->assertSee('data-canonical-path="/en/"', false)
            ->assertDontSee('utm_source=private-value', false);

        $this->get('https://wpcaregrid.com/hu/')
            ->assertOk()
            ->assertSee('Analitikai beállítások')
            ->assertSee('Analitika engedélyezése')
            ->assertSee('Analitika elutasítása');
    }

    public function test_only_exact_production_host_with_valid_id_receives_analytics_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'seo.production_host' => 'wpcaregrid.com',
            'marketing_analytics.enabled' => true,
            'marketing_analytics.policy_authorized' => true,
            'marketing_analytics.measurement_id' => 'G-ABC1234567',
            'http.csp.mode' => 'report-only',
        ]);

        $this->get('https://wpcaregrid.com/en/')
            ->assertOk()
            ->assertSee('data-enabled="true"', false)
            ->assertSee('data-measurement-id="G-ABC1234567"', false)
            ->assertHeader('Content-Security-Policy-Report-Only');

        $stagingRequest = Request::create('https://staging.wpcaregrid.com/en/');
        $policy = app(MarketingAnalyticsPolicy::class);
        $this->assertFalse($policy->forRequest($stagingRequest)->enabled);
        $this->assertStringNotContainsString('googletagmanager.com', SecurityHeaders::policy(null, $policy->mayAddGoogleToContentSecurityPolicy($stagingRequest)));
    }

    public function test_invalid_measurement_id_fails_closed_and_does_not_extend_csp(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://wpcaregrid.com',
            'seo.production_host' => 'wpcaregrid.com',
            'marketing_analytics.enabled' => true,
            'marketing_analytics.policy_authorized' => true,
            'marketing_analytics.measurement_id' => 'G-<script>bad</script>',
            'http.csp.mode' => 'report-only',
        ]);

        $response = $this->withServerVariables(['HTTP_HOST' => 'wpcaregrid.com', 'HTTPS' => 'on'])->get('/en/');
        $response->assertOk()
            ->assertSee('data-enabled="false"', false)
            ->assertDontSee('data-measurement-id="G-&lt;script&gt;bad&lt;/script&gt;"', false);
        $this->assertStringNotContainsString('googletagmanager.com', (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_staging_and_non_trackable_routes_never_receive_a_measurement_id(): void
    {
        config([
            'app.env' => 'staging',
            'app.url' => 'https://wpcaregrid.com',
            'seo.production_host' => 'wpcaregrid.com',
            'marketing_analytics.enabled' => true,
            'marketing_analytics.policy_authorized' => true,
            'marketing_analytics.measurement_id' => 'G-ABC1234567',
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'wpcaregrid.com', 'HTTPS' => 'on'])->get('/en/')
            ->assertOk()
            ->assertSee('data-enabled="false"', false)
            ->assertDontSee('data-measurement-id="G-ABC1234567"', false);

        config(['app.env' => 'production']);
        $this->withServerVariables(['HTTP_HOST' => 'wpcaregrid.com', 'HTTPS' => 'on'])->get('/login')
            ->assertOk()
            ->assertDontSee('data-enabled="true"', false)
            ->assertDontSee('data-measurement-id="G-ABC1234567"', false);
    }
}
