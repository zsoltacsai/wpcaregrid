<?php

return [
    // Absence and false both fail closed. No destructive executor is part of Wave 1.
    'destructive_retention_enabled' => env('M12_DESTRUCTIVE_RETENTION_ENABLED', false),

    // Stable, PII-free event names for a later M10 monitoring adapter; Wave 1 does not emit incidents.
    'monitoring_event_codes' => [
        'request_backlog' => 'privacy.request_backlog',
        'provider_task_failed' => 'privacy.provider_task_failed',
        'provider_task_manual_review' => 'privacy.provider_task_manual_review',
        'retention_gate_disabled' => 'privacy.retention_gate_disabled',
    ],
];
