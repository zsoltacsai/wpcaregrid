<?php

return [
    /*
    | Public marketing pages are indexable only after an explicit production
    | opt-in. The configured host is deployment-owned; the request Host header
    | is an additional match check, never the source of the environment.
    */
    'indexing_enabled' => env('SEO_INDEXING_ENABLED', false),
    'production_host' => env('SEO_INDEXING_PRODUCTION_HOST', ''),
];
