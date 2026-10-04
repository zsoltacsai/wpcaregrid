<?php

/*
|--------------------------------------------------------------------------
| Content management (docs/CONTENT_MANAGEMENT.md)
|--------------------------------------------------------------------------
|
| Locales the owner can write content in. Adding a locale here is enough for
| the editor; public pages use the application locale (APP_LOCALE) and fall
| back to `fallback_locale` one whole section at a time, never field by field.
|
*/

return [

    'locales' => [
        'en' => 'English',
        'hu' => 'Magyar',
    ],

    'fallback_locale' => 'en',

    // How long a draft preview link stays valid (minutes). Owner sign-in is required as well.
    'preview_minutes' => (int) env('CONTENT_PREVIEW_MINUTES', 15),

];
