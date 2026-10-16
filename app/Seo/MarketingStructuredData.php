<?php

namespace App\Seo;

use App\Content\HtmlSanitizer;

/** Whitelisted schema.org graph for visible public marketing content only. */
final class MarketingStructuredData
{
    /**
     * @param  list<array{question: string, answer: string}>  $faqs
     * @return array{\@context: string, \@graph: list<array<string, mixed>>}
     */
    public function forPage(
        string $pageType,
        string $canonical,
        string $title,
        string $description,
        string $locale,
        array $faqs = [],
        ?string $faqTitle = null,
    ): array {
        if (! in_array($pageType, ['home', 'pricing'], true)) {
            return [];
        }

        $siteUrl = app(CanonicalUrl::class)->forPath('/');
        $organizationId = $siteUrl.'#organization';
        $websiteId = $siteUrl.'#website';
        $pageId = $canonical.'#webpage';

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => 'WP CareGrid',
                'url' => $siteUrl,
            ],
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'name' => 'WP CareGrid',
                'url' => $siteUrl,
                'publisher' => ['@id' => $organizationId],
                'inLanguage' => ['en', 'hu'],
            ],
            [
                '@type' => 'WebPage',
                '@id' => $pageId,
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'inLanguage' => $locale,
                'isPartOf' => ['@id' => $websiteId],
            ],
        ];

        $questions = [];
        foreach ($faqs as $faq) {
            $question = trim($faq['question'] ?? '');
            $answer = $this->plainText($faq['answer'] ?? '');
            if ($question === '' || $answer === '') {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        if ($pageType === 'home' && $questions !== []) {
            $graph[] = [
                '@type' => 'FAQPage',
                '@id' => $canonical.'#faq',
                'url' => $canonical.'#faq',
                'name' => filled($faqTitle) ? $faqTitle : $title,
                'inLanguage' => $locale,
                'isPartOf' => ['@id' => $pageId],
                'mainEntity' => $questions,
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private function plainText(string $html): string
    {
        $safe = HtmlSanitizer::clean($html);
        $text = html_entity_decode(strip_tags($safe), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
