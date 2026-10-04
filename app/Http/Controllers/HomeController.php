<?php

namespace App\Http\Controllers;

use App\Content\ContentRepository;
use App\Content\DemoDashboard;
use App\Content\HomepageContent;
use App\Pricing\PricingCatalog;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(PricingCatalog $pricing, ContentRepository $content): View
    {
        return view('pages.home', [
            'content' => new HomepageContent($content),
            'demo' => new DemoDashboard,
            'pricing' => $pricing,
            'seo' => $content->section('seo.home'),
        ]);
    }
}
