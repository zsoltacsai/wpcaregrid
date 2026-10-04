<?php

namespace App\Http\Controllers;

use App\Content\ContentRepository;
use App\Pricing\PricingCatalog;
use Illuminate\Contracts\View\View;

class PricingController extends Controller
{
    public function __invoke(PricingCatalog $pricing, ContentRepository $content): View
    {
        return view('pages.pricing', [
            'pricing' => $pricing,
            'intro' => $content->section('home.pricing_intro'),
            'seo' => $content->section('seo.pricing'),
        ]);
    }
}
