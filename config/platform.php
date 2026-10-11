<?php

return [
    'deployment_environment' => env('CAREGRID_DEPLOYMENT_ENV'),
    // Enable only during platform recovery. While active, Connectors may poll
    // read-only inventory/health work, but site-changing WebsiteJobs are held.
    'recovery_mode' => (bool) env('PLATFORM_RECOVERY_MODE', false),
];
