<?php

return [
    'website_batch_size' => (int) env('MONITORING_WEBSITE_BATCH_SIZE', 250),
    'worker_queues' => [
        'default' => ['connection' => env('QUEUE_CONNECTION', 'database'), 'queue' => env('DB_QUEUE', 'default')],
        'backups' => ['connection' => env('BACKUP_QUEUE_CONNECTION', 'backups'), 'queue' => env('BACKUP_QUEUE', 'backups')],
    ],
    'confirmation' => [
        'website_http_failures' => 3,
        'website_http_recoveries' => 2,
        'worker_failures' => 2,
        'queue_backlog_age_seconds' => 900,
        'repeat_after_minutes' => 30,
    ],
];
