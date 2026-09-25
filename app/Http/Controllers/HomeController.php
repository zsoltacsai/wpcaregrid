<?php

namespace App\Http\Controllers;

use App\Content\DemoDashboard;
use App\Content\HomepageContent;
use App\Pricing\PricingCatalog;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(PricingCatalog $pricing): View
    {
        return view('pages.home', [
            'content' => new HomepageContent,
            'demo' => new DemoDashboard,
            'pricing' => $pricing,
        ]);
    }
}
