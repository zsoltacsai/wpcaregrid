<?php

/*
|--------------------------------------------------------------------------
| Marketing site links
|--------------------------------------------------------------------------
|
| Destinations for calls to action. Login, sign-up and contact pages are not
| part of this build yet, so they are configurable and can point at the
| customer dashboard or a contact form once those exist.
|
*/

return [

    'links' => [
        'login' => env('MARKETING_LOGIN_URL', '/login'),
        'signup' => env('MARKETING_SIGNUP_URL', '/get-started'),
        'contact' => env('MARKETING_CONTACT_URL', '/contact'),
        'terms' => env('MARKETING_TERMS_URL', '/terms'),
        'privacy' => env('MARKETING_PRIVACY_URL', '/privacy'),
        'cookies' => env('MARKETING_COOKIES_URL', '/cookie-policy'),
    ],

];
