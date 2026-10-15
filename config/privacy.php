<?php

return [
    // Absence and false both fail closed. No destructive executor is part of Wave 1.
    'destructive_retention_enabled' => env('M12_DESTRUCTIVE_RETENTION_ENABLED', false),
    // Wave 7 execution capabilities are individually OFF; no scheduler consumes these values.
    'profile_anonymization_enabled' => env('M12_PROFILE_ANONYMIZATION_ENABLED', false),
    'backup_deletion_enabled' => env('M12_BACKUP_DELETION_ENABLED', false),
    'provider_deletion_enabled' => env('M12_PROVIDER_DELETION_ENABLED', false),
    'bulk_retention_execution_enabled' => env('M12_BULK_RETENTION_EXECUTION_ENABLED', false),
    // This is a test-harness-only path. The service also hard-requires APP_ENV=testing.
    'synthetic_profile_anonymization_enabled' => env('M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED', false),

    // Stable, PII-free event names for a later M10 monitoring adapter; Wave 1 does not emit incidents.
    'monitoring_event_codes' => [
        'request_backlog' => 'privacy.request_backlog',
        'provider_task_failed' => 'privacy.provider_task_failed',
        'provider_task_manual_review' => 'privacy.provider_task_manual_review',
        'retention_gate_disabled' => 'privacy.retention_gate_disabled',
    ],
];
