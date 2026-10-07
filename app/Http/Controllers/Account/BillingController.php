<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __invoke(Request $request): View
    {
        $customer = $request->attributes->get('customer');
        $this->authorize('view', $customer);

        return view('account.billing', [
            'customer' => $customer,
            'subscription' => $customer->primarySubscription(),
            'invoices' => $customer->billingInvoices()->limit(25)->get(),
        ]);
    }
}
