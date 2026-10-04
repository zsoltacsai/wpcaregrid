<?php

/*
|--------------------------------------------------------------------------
| Marketing site links
|--------------------------------------------------------------------------
|
| Destinations for calls to action. Login, contact and legal pages are not
| part of this build yet, so they are configurable and can point at the
| customer dashboard or a contact form once those exist.
|
*/

// An empty value ("MARKETING_LOGIN_URL=", as in .env.example) counts as unset.
$link = fn (string $name, string $default): string => filled(env($name)) ? (string) env($name) : $default;

return [

    'links' => [
        'login' => $link('MARKETING_LOGIN_URL', '/login'),
        'contact' => $link('MARKETING_CONTACT_URL', '/contact'),
        'terms' => $link('MARKETING_TERMS_URL', '/terms'),
        'privacy' => $link('MARKETING_PRIVACY_URL', '/privacy'),
        'cookies' => $link('MARKETING_COOKIES_URL', '/cookie-policy'),
    ],

];
