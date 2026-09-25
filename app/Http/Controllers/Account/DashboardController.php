<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $customer = $request->attributes->get('customer');

        return view('account.dashboard', [
            'customer' => $customer,
            'subscription' => $customer->primarySubscription(),
            'websiteCount' => $customer->websites()->count(),
            'connectedCount' => $customer->websites()->where('connector_status', 'connected')->count(),
            'canAddWebsite' => $request->user()->can('create', Website::class),
        ]);
    }
}
