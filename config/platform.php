<?php

return [
    'deployment_environment' => env('CAREGRID_DEPLOYMENT_ENV'),
    // Enable only during platform recovery. While active, Connectors may poll
    // read-only inventory/health work, but site-changing WebsiteJobs are held.
    'recovery_mode' => (bool) env('PLATFORM_RECOVERY_MODE', false),
    // Set out of band for each restore; these values bind readiness to this exact snapshot.
    'recovery_id' => env('PLATFORM_RECOVERY_ID'),
    'recovery_source_backup_uuid' => env('PLATFORM_RECOVERY_SOURCE_BACKUP_UUID'),
    'recovery_snapshot_watermark' => env('PLATFORM_RECOVERY_SNAPSHOT_WATERMARK'),
];
