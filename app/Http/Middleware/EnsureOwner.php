<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner area needs a verified login with the owner role and no customer
 * account. Runs after auth:owner. Each route also names its ability
 * (can:owner.*).
 */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('owner')->user();

        if (! $user?->isOwner() || ! $user->hasVerifiedEmail() || $user->customer()->exists()) {
            Auth::guard('owner')->logout();
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();

            return redirect()->route('owner.login')->withErrors(['email' => __('owner.auth.not_owner')]);
        }

        $response = $next($request);

        // Business data: never cached by the browser or a proxy, never indexed.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
