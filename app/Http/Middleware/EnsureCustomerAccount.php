<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer area needs a verified login linked to an active customer.
 * The customer is shared with controllers as the `customer` request attribute.
 */
class EnsureCustomerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $customer = $user?->customer;

        if (! $user?->hasVerifiedEmail() || ! $customer?->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('account.auth.no_customer')]);
        }

        $request->attributes->set('customer', $customer);

        return $next($request);
    }
}
