<?php

namespace App\Http\Middleware;

use App\Localization\Locales;
use App\Pricing\PricingCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the display language of the request (App\Localization\Locales) before
 * anything renders. Marketing routes may display their explicit language,
 * while an authenticated customer's saved preference controls account pages.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $originalLocale = app()->getLocale();
        $user = $request->user('web');
        $marketingLocaleIsExplicit = Locales::hasExplicitRouteLocale($request);
        $locale = Locales::resolve($request);

        // A marketing URL is allowed to render in its explicit language, but
        // must not override a signed-in customer's saved account preference.
        // Guests still carry that choice through the unprefixed auth flow in
        // the locale cookie; after authentication the saved preference wins.
        if ($user && ! Locales::isEnglishOnly($request) && ! $marketingLocaleIsExplicit
            && Locales::isSupported($user->locale)) {
            $locale = $user->locale;
        }

        URL::defaults(['locale' => $locale]);

        // A guest's marketing choice carries through login/registration. Do
        // not write an authenticated visitor's marketing view to the cookie;
        // their preference changes only via the customer language selector.
        if (! $user && $marketingLocaleIsExplicit) {
            Cookie::queue(Locales::cookie($locale));
        }

        if (app()->getLocale() !== $locale) {
            app()->setLocale($locale);
            // Money and plan wording are formatted for the locale when the catalogue is built.
            app()->forgetInstance(PricingCatalog::class);
        }

        try {
            $response = $next($request);
            $response->headers->set('Content-Language', $locale);

            return $response;
        } finally {
            if (app()->getLocale() !== $originalLocale) {
                app()->setLocale($originalLocale);
                app()->forgetInstance(PricingCatalog::class);
            }
            URL::defaults(['locale' => Locales::default()]);
        }
    }
}
