<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hold customer/billing/webhook HTTP operations while the platform is recovering. */
final class EnsurePlatformRecoveryQuarantine
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('platform.recovery_mode')) {
            return response('WP CareGrid is temporarily unavailable during platform recovery.', 503, [
                'Retry-After' => '300',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }
}
