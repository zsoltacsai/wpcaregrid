<?php

namespace App\Seo;

use App\Content\SafeUrl;
use App\Content\Schema\ContentSchema;
use ArrayAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class MarketingMetadataFactory
{
    public function __construct(
        private readonly CanonicalUrl $canonicalUrl,
        private readonly IndexingPolicy $indexingPolicy,
        private readonly MarketingStructuredData $structuredData,
    ) {}

    /**
     * @param  array<string, mixed>|ArrayAccess<string, mixed>|null  $seo
     * @param  array<string, string>  $alternates
     * @param  list<array{question: string, answer: string}>  $faqs
     */
    public function make(
        Request $request,
        ?string $title,
        ?string $description,
        array|ArrayAccess|null $seo,
        bool $indexable,
        bool $preview,
        array $alternates = [],
        ?string $structuredDataType = null,
        array $faqs = [],
        ?string $faqTitle = null,
    ): PageMetadata {
        $title = filled($seo['title'] ?? null) ? (string) $seo['title'] : (string) $title;
        $description = filled($seo['description'] ?? null) ? (string) $seo['description'] : (string) $description;
        $canonicalPath = match ($request->route()?->getName()) {
            'home' => '/'.(string) $request->route('locale').'/',
            'pricing' => '/'.(string) $request->route('locale').'/pricing',
            default => $request->getPathInfo(),
        };
        $canonicalFallback = $this->canonicalUrl->forPath($canonicalPath);
        $canonicalOverride = $seo['canonical_url'] ?? null;
        $canonical = $canonicalFallback;
        if (filled($canonicalOverride)) {
            if (is_string($canonicalOverride)
                && hash_equals($canonicalFallback, $canonicalOverride)
                && SafeUrl::isOwnHost($canonicalOverride)) {
                $canonical = $canonicalOverride;
            } elseif ($request->routeIs('home', 'pricing')) {
                Log::warning('Ignored SEO canonical override that does not match the localized route.', [
                    'route' => $request->route()?->getName(),
                    'locale' => app()->getLocale(),
                ]);
            }
        }

        $mayIndex = $this->indexingPolicy->allowsPublicMarketingIndexing($indexable, $request);
        $requestedRobots = in_array($seo['robots'] ?? null, ContentSchema::ROBOTS, true)
            ? $seo['robots']
            : null;
        $robots = $preview
            ? 'noindex,nofollow'
            : (! $mayIndex
                ? (str_starts_with((string) $requestedRobots, 'noindex,') ? $requestedRobots : 'noindex,nofollow')
                : ($requestedRobots ?? 'index,follow'));

        $alternates = $this->safeAlternates($alternates);
        $locale = str_replace('_', '-', app()->getLocale());
        $openGraphAlternates = [];
        foreach (array_keys($alternates) as $language) {
            if ($language !== 'x-default' && $language !== $locale) {
                $openGraphAlternates[$language] = $language;
            }
        }

        $socialImage = config('seo.social_image');
        if (! is_string($socialImage) || ! SafeUrl::isOwnHost($socialImage)) {
            $socialImage = null;
        }

        return new PageMetadata(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $robots,
            locale: $locale,
            alternates: $alternates,
            openGraphTitle: filled($seo['og_title'] ?? null) ? (string) $seo['og_title'] : $title,
            openGraphDescription: filled($seo['og_description'] ?? null) ? (string) $seo['og_description'] : $description,
            openGraphLocale: match ($locale) {
                'hu' => 'hu_HU',
                default => 'en_US',
            },
            openGraphAlternates: $openGraphAlternates,
            socialImage: $socialImage,
            twitterCard: $socialImage === null ? 'summary' : 'summary_large_image',
            structuredData: $preview || $structuredDataType === null
                ? []
                : $this->structuredData->forPage(
                    $structuredDataType,
                    $canonical,
                    $title,
                    $description,
                    $locale,
                    $faqs,
                    $faqTitle,
                ),
        );
    }

    /** @param  array<string, string>  $alternates
     * @return array<string, string>
     */
    private function safeAlternates(array $alternates): array
    {
        $safe = [];
        foreach ($alternates as $locale => $url) {
            if (in_array($locale, ['en', 'hu', 'x-default'], true)
                && is_string($url)
                && SafeUrl::isOwnHost($url)) {
                $safe[$locale] = $url;
            }
        }

        return $safe;
    }
}
