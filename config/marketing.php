<?php

/*
|--------------------------------------------------------------------------
| Marketing site links
|--------------------------------------------------------------------------
|
| Destinations for calls to action. There is no contact route or contact form;
| public contact links use the Owner-approved email address.
|
*/

// An empty value ("MARKETING_LOGIN_URL=", as in .env.example) counts as unset.
$link = fn (string $name, string $default): string => filled(env($name)) ? (string) env($name) : $default;

return [

    'links' => [
        'login' => $link('MARKETING_LOGIN_URL', '/login'),
        'contact' => 'mailto:contact@zsoltacsai.com',
        'terms' => $link('MARKETING_TERMS_URL', '/terms'),
        'privacy' => $link('MARKETING_PRIVACY_URL', '/privacy'),
        'cookies' => $link('MARKETING_COOKIES_URL', '/cookie-policy'),
    ],

];
