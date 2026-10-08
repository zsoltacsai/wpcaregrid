<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\InvoiceIntent;
use Illuminate\View\View;

final class InvoiceIntentController extends Controller
{
    public function index(): View
    {
        return view('owner.billing.invoice-intents', [
            'intents' => InvoiceIntent::query()
                ->with(['customer', 'subscription', 'billingInvoice'])
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function show(InvoiceIntent $invoiceIntent): View
    {
        $invoiceIntent->load(['customer', 'subscription', 'billingInvoice', 'events']);

        return view('owner.billing.invoice-intent', ['intent' => $invoiceIntent]);
    }
}
