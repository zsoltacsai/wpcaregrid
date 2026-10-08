<?php

namespace App\Invoicing;

use App\Owner\IntegrationCredentials;

final class SyntheticSzamlazzTestGuard
{
    public function permits(?string $requestHost = null): bool
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $allowedHost = (string) config('szamlazz.synthetic_test.allowed_host');
        $requestHost = strtolower((string) $requestHost);
        $credential = app(IntegrationCredentials::class)->configured()['szamlazz_agent'] ?? [];

        return (bool) config('szamlazz.synthetic_test.enabled')
            && $host !== ''
            && hash_equals($allowedHost, $host)
            && ($requestHost === '' || hash_equals($allowedHost, $requestHost))
            && app()->environment('production')
            && ! (bool) config('szamlazz.provider_enabled')
            && (bool) config('szamlazz.dry_run')
            && ! (bool) config('szamlazz.issuance_enabled')
            && ($credential['owner_managed'] ?? false)
            && filled(config('szamlazz.agent_api_key'));
    }
}
