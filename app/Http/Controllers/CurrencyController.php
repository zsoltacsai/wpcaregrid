<?php

namespace App\Http\Controllers;

use App\Localization\Locales;
use App\Pricing\DisplayCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chooses the currency the pricing is displayed in, from the currencies that
 * really have prices. It changes presentation only: checkout resolves the
 * price on the server from the billing country (see DisplayCurrency).
 */
class CurrencyController extends Controller
{
    public function __invoke(Request $request, DisplayCurrency $currency): RedirectResponse
    {
        $request->validate(['currency' => ['required', 'string', Rule::in($currency->available())]]);
        $currency->select($request->input('currency'));

        return redirect()->to(Locales::returnUrl($request));
    }
}
