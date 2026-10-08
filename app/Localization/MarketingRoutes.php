<?php

namespace App\Localization;

use Illuminate\Http\Request;

/** URL helpers for the fixed set of public localized marketing pages. */
final class MarketingRoutes
{
    public function home(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'hu' ? '/hu/' : '/en/';
    }

    public function pricing(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'hu' ? '/hu/pricing' : '/en/pricing';
    }

    public function equivalent(Request $request, string $locale): string
    {
        return match ($request->route()?->getName()) {
            'home' => $this->home($locale),
            'pricing' => $this->pricing($locale),
            default => $this->home($locale),
        };
    }

    /** Localize known first-party marketing destinations; leave other links untouched. */
    public function localize(string $url, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        $host = $parts['host'] ?? null;
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host !== null && strcasecmp($host, (string) $configuredHost) !== 0) {
            return $url;
        }

        $localized = match ($path) {
            '', '/' => $this->home($locale),
            '/pricing' => $this->pricing($locale),
            default => null,
        };
        if ($localized === null) {
            return $url;
        }

        $suffix = isset($parts['query']) ? '?'.$parts['query'] : '';
        $suffix .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $localized.$suffix;
    }
}
