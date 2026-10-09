<?php

namespace App\Seo;

use App\Content\ContentRepository;
use App\Content\Schema\ContentSchema;

/** Emit language alternates only when both rendered variants use native-locale content. */
final class MarketingTranslationPolicy
{
    /** @return array<string, string> */
    public function alternates(string $page, ContentRepository $content): array
    {
        $keys = match ($page) {
            'home' => array_merge(
                array_keys(ContentSchema::group('homepage')),
                array_keys(ContentSchema::group('site')),
                array_keys(ContentSchema::group('pricing')),
                ['seo.home'],
            ),
            'pricing' => array_merge(
                array_keys(ContentSchema::group('site')),
                array_keys(ContentSchema::group('pricing')),
                ['home.pricing_intro', 'seo.pricing'],
            ),
            default => [],
        };

        if ($keys === []) {
            return [];
        }

        foreach (['en', 'hu'] as $locale) {
            foreach ($keys as $key) {
                if ($content->section($key, $locale)->isFallback()) {
                    return [];
                }
            }
        }

        $canonical = app(CanonicalUrl::class);

        return $page === 'home'
            ? ['en' => $canonical->forPath('/en/'), 'hu' => $canonical->forPath('/hu/'), 'x-default' => $canonical->forPath('/en/')]
            : ['en' => $canonical->forPath('/en/pricing'), 'hu' => $canonical->forPath('/hu/pricing'), 'x-default' => $canonical->forPath('/en/pricing')];
    }
}
