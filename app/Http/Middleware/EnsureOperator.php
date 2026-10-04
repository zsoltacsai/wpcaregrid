<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator console needs a verified, enabled login with the operator role and no
 * customer account: operator and customer identities are never mixed.
 * Runs after auth:operator. Permissions are checked per route (can:operator.*).
 */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('operator')->user();

        if (! $user?->isActiveOperator() || $user->customer()->exists()) {
            Auth::guard('operator')->logout();
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();

            return redirect()->route('operator.login')->withErrors(['email' => __('operator.auth.not_operator')]);
        }

        $response = $next($request);

        // Operational data: never cached by the browser or a proxy, never indexed.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
