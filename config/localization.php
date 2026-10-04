<?php

/*
|--------------------------------------------------------------------------
| Display language of the public site, customer login and customer portal
|--------------------------------------------------------------------------
|
| The languages a visitor or customer can choose (code => name in its own
| language), in the order the selector shows them. Only these codes are ever
| accepted; anything else is refused. APP_LOCALE stays the default for a
| visitor who has not chosen. Public content (lang/{locale}, owner-published
| sections) is a separate concept: config/content.php.
|
| The owner area, the operator console and staff invitations always render in
| English (`english_only`, matched against the request path), whatever the
| visitor chose.
|
*/

return [

    'locales' => [
        'hu' => 'Magyar',
        'en' => 'English',
    ],

    // Language for a visitor who has not chosen. Read from APP_LOCALE once at boot: App::setLocale() rewrites
    // config('app.locale') during a request, which must never leak into the next request of a long-lived process.
    'default' => env('APP_LOCALE', 'en'),

    // Remembers the choice of a visitor who is not signed in (and a signed-in customer's current device).
    'cookie' => 'caregrid_locale',
    'cookie_days' => 365,

    // Request paths that are always English.
    'english_only' => ['owner', 'owner/*', 'operator', 'operator/*', 'staff/*'],

];
