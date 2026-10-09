<?php

namespace App\Http\Controllers;

use App\Content\ContentRepository;
use App\Seo\CanonicalUrl;
use App\Seo\IndexingPolicy;
use App\Seo\MarketingTranslationPolicy;
use Illuminate\Http\Request;

final class SitemapController extends Controller
{
    public function __invoke(Request $request, IndexingPolicy $policy, MarketingTranslationPolicy $translations, ContentRepository $content, CanonicalUrl $canonical)
    {
        abort_unless($policy->allowsPublicMarketingIndexing(true, $request), 404);

        $pairs = [
            ['path' => '/en/', 'page' => 'home'],
            ['path' => '/hu/', 'page' => 'home'],
            ['path' => '/en/pricing', 'page' => 'pricing'],
            ['path' => '/hu/pricing', 'page' => 'pricing'],
        ];
        $entries = [];
        foreach ($pairs as $pair) {
            $entries[] = [
                'location' => $canonical->forPath($pair['path']),
                'alternates' => $translations->alternates($pair['page'], $content),
            ];
        }

        return response()->view('seo.sitemap', ['entries' => $entries], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
