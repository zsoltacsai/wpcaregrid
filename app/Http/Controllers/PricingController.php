<?php

namespace App\Http\Controllers;

use App\Pricing\PricingCatalog;
use Illuminate\Contracts\View\View;

class PricingController extends Controller
{
    public function __invoke(PricingCatalog $pricing): View
    {
        return view('pages.pricing', ['pricing' => $pricing]);
    }
}
