<?php

return [
    // Exact zone names only. A token's Cloudflare scope must be narrowed to these zones too.
    'allowed_zones' => array_values(array_filter(array_map(
        static fn (string $zone): string => strtolower(rtrim(trim($zone), '.')),
        explode(',', (string) env('CLOUDFLARE_ALLOWED_ZONES', 'wpcaregrid.com')),
    ))),

    // All record types at these names are protected from deletion by default.
    'protected_record_names' => array_values(array_filter(array_map(
        static fn (string $name): string => strtolower(rtrim(trim($name), '.')),
        explode(',', (string) env('CLOUDFLARE_PROTECTED_RECORD_NAMES', 'wpcaregrid.com,www.wpcaregrid.com,staging.wpcaregrid.com,stg1.wpcaregrid.com,wp-test.staging.wpcaregrid.com,mail.wpcaregrid.com')),
    ))),
];
