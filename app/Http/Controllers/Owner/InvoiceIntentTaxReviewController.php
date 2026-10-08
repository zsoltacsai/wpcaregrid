<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Invoicing\InvoiceIntentTaxReviewService;
use App\Models\InvoiceIntent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class InvoiceIntentTaxReviewController extends Controller
{
    public function store(InvoiceIntent $invoiceIntent, Request $request, InvoiceIntentTaxReviewService $reviews): RedirectResponse
    {
        try {
            $reviews->propose($invoiceIntent, $request->only('provider_tax_code', 'proposed_treatment', 'justification', 'basis_reference'), $request->user('owner'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput([]);
        }

        return back()->with('status', __('owner.invoice_intents.review_proposed'));
    }
}
