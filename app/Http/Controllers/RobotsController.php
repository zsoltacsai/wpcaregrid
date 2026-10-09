<?php

namespace App\Http\Controllers;

use App\Seo\CanonicalUrl;
use App\Seo\IndexingPolicy;
use Illuminate\Http\Request;

final class RobotsController extends Controller
{
    public function __invoke(Request $request, IndexingPolicy $policy, CanonicalUrl $canonical)
    {
        $body = "User-agent: *\nDisallow:\n";
        if ($policy->allowsPublicMarketingIndexing(true, $request)) {
            $body .= 'Sitemap: '.$canonical->forPath('/sitemap.xml')."\n";
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
