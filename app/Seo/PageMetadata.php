<?php

namespace App\Seo;

/** The complete, route-specific metadata rendered by the public layout. */
final readonly class PageMetadata
{
    /**
     * @param  array<string, string>  $alternates
     * @param  array<string, string>  $openGraphAlternates
     * @param  array<string, mixed>  $structuredData
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $robots,
        public string $locale,
        public array $alternates,
        public string $openGraphTitle,
        public string $openGraphDescription,
        public string $openGraphLocale,
        public array $openGraphAlternates,
        public ?string $socialImage,
        public string $twitterCard,
        public array $structuredData,
    ) {}
}
