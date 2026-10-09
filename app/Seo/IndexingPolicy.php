<?php

namespace App\Seo;

use Illuminate\Http\Request;

final class IndexingPolicy
{
    public function allowsPublicMarketingIndexing(bool $approvedPage, Request $request): bool
    {
        if (! $approvedPage || config('app.env') !== 'production' || config('seo.indexing_enabled') !== true) {
            return false;
        }

        $configuredHost = $this->normalizeHost((string) config('seo.production_host'));
        $applicationHost = $this->normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $requestHost = $this->normalizeHost($request->getHost());

        return $configuredHost !== ''
            && $applicationHost === $configuredHost
            && $requestHost === $configuredHost;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
