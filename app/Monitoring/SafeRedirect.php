<?php

namespace App\Monitoring;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class SafeRedirect
{
    public static function resolve(Uri $current, string $location, string $expectedHost, ?PublicAddressLookup $lookup = null): array
    {
        $target = UriResolver::resolve($current, new Uri($location));
        $targetHost = strtolower(rtrim($target->getHost(), '.'));
        if ($targetHost !== strtolower(rtrim($expectedHost, '.'))) {
            throw new \DomainException('monitoring.redirect_refused');
        }
        $origin = PublicOrigin::resolve((string) $target, $lookup);
        if (strtolower($current->getScheme()) === 'https' && $origin->scheme !== 'https') {
            throw new \DomainException('monitoring.redirect_refused');
        }

        return [$target, $origin];
    }
}
