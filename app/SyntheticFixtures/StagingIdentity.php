<?php

namespace App\SyntheticFixtures;

final class StagingIdentity
{
    public function allowsCurrentDeployment(): bool
    {
        return self::matches(
            (string) config('app.deployment_environment'),
            (string) config('app.url'),
            (string) config('app.deployment_hostname'),
        );
    }

    public static function matches(string $deployment, string $appUrl, string $serverHostname): bool
    {
        return $deployment === 'staging'
            && strtolower((string) parse_url($appUrl, PHP_URL_HOST)) === config('synthetic_fixtures.staging_app_host')
            && strtolower($serverHostname) === config('synthetic_fixtures.staging_server_hostname');
    }
}
