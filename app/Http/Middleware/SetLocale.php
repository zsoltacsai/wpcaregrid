<?php

namespace App\Http\Middleware;

use App\Localization\Locales;
use App\Pricing\PricingCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the display language of the request (App\Localization\Locales) before
 * anything renders, and keeps a signed-in customer's saved preference in step
 * with the language chosen on this device.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::resolve($request);

        if (app()->getLocale() !== $locale) {
            app()->setLocale($locale);
            // Money and plan wording are formatted for the locale when the catalogue is built.
            app()->forgetInstance(PricingCatalog::class);
        }

        // A signed-in customer's saved preference follows the choice made on this device (also one made before signing in).
        $user = $request->user('web');
        $remembered = $request->cookie((string) config('localization.cookie'));
        if ($user && Locales::isSupported($remembered) && ! Locales::isEnglishOnly($request) && $user->locale !== $remembered) {
            $user->forceFill(['locale' => $remembered])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
