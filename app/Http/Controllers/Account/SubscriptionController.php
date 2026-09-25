<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.subscription.index', [
            'subscriptions' => $request->attributes->get('customer')->subscriptions()->with('website')->latest('id')->get(),
        ]);
    }

    public function show(Subscription $subscription): View
    {
        $this->authorize('view', $subscription);

        return view('account.subscription.index', ['subscriptions' => collect([$subscription->load('website')])]);
    }
}
