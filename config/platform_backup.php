<?php

return [
    'enabled' => (bool) env('PLATFORM_DB_BACKUP_ENABLED', false),
    'disk' => env('PLATFORM_DB_BACKUP_DISK', 'platform_backups'),
    'storage_driver' => env('PLATFORM_DB_BACKUP_DRIVER', 'local'),
    'prefix' => trim((string) env('PLATFORM_DB_BACKUP_PREFIX', 'platform-database'), '/'),
    'local_root' => env('PLATFORM_DB_BACKUP_LOCAL_ROOT', storage_path('app/private/platform-database-backups')),
    'source_connection' => env('PLATFORM_DB_BACKUP_SOURCE_CONNECTION'),
    'mysql_dump_binary' => env('PLATFORM_DB_BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql_binary' => env('PLATFORM_DB_BACKUP_MYSQL', 'mysql'),
    'timeout' => (int) env('PLATFORM_DB_BACKUP_TIMEOUT', 3600),
    'verification_connection' => env('PLATFORM_DB_BACKUP_VERIFY_CONNECTION'),
    'verification_database_prefix' => env('PLATFORM_DB_BACKUP_VERIFY_DATABASE_PREFIX', ''),
    'verification_isolated' => (bool) env('PLATFORM_DB_BACKUP_VERIFY_ISOLATED', false),
    'release_sha' => env('APP_RELEASE_SHA'),
    'retention' => [
        'daily' => (int) env('PLATFORM_DB_BACKUP_RETAIN_DAILY', 7),
        'weekly' => (int) env('PLATFORM_DB_BACKUP_RETAIN_WEEKLY', 4),
        'monthly' => (int) env('PLATFORM_DB_BACKUP_RETAIN_MONTHLY', 12),
    ],
    'retention_days' => ['daily' => 7, 'weekly' => 28, 'monthly' => 365],
    'critical_tables' => ['users', 'customers', 'websites', 'subscriptions', 'connectors', 'backups', 'backup_objects', 'backup_restores', 'website_jobs', 'stripe_events', 'admin_audit_events', 'security_incidents', 'billing_invoices', 'integration_credentials'],
    'encryption' => [
        'current_key_id' => env('PLATFORM_DB_BACKUP_ENCRYPTION_CURRENT_KEY_ID', ''),
        // JSON map of key IDs to base64-encoded 32-byte keys supplied by the secret manager.
        'keys' => json_decode((string) env('PLATFORM_DB_BACKUP_ENCRYPTION_KEYRING', '{}'), true) ?: [],
    ],
];
