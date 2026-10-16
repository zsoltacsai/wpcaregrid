<?php

namespace App\Marketing\Analytics;

use Illuminate\Http\Request;

/** Fail-closed production and canonical-host gate for client-side GA4. */
final class MarketingAnalyticsPolicy
{
    public function forRequest(Request $request): MarketingAnalyticsAvailability
    {
        $measurementId = trim((string) config('marketing_analytics.measurement_id', ''));
        $productionHost = $this->normalizeHost((string) config('seo.production_host', ''));
        $applicationHost = $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $requestHost = $this->normalizeHost($request->getHost());

        $enabled = config('app.env') === 'production'
            && config('marketing_analytics.enabled') === true
            && config('marketing_analytics.policy_authorized') === true
            && preg_match('/\AG-[A-Z0-9]{5,20}\z/', $measurementId) === 1
            && $productionHost !== ''
            && $applicationHost === $productionHost
            && $requestHost === $productionHost;

        $version = (string) config('marketing_analytics.consent_version', 'technical-v1');
        if (preg_match('/\A[a-zA-Z0-9._-]{1,40}\z/', $version) !== 1) {
            $version = 'technical-v1';
        }

        return new MarketingAnalyticsAvailability(
            enabled: $enabled,
            measurementId: $enabled ? $measurementId : null,
            consentVersion: $version,
        );
    }

    public function mayAddGoogleToContentSecurityPolicy(Request $request): bool
    {
        return $this->forRequest($request)->enabled;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
