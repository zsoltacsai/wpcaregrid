<?php

/*
|--------------------------------------------------------------------------
| Backup Engine
|--------------------------------------------------------------------------
|
| See docs/BACKUP_ENGINE.md. Storage credentials live here (from .env) and
| never leave the server: connectors upload to WP CareGrid, which moves the
| data to storage.
|
*/

$mib = 1024 * 1024;

return [

    /*
    | Active storage provider: "s3", "sftp" or "local" (development only).
    | Empty means backups are unavailable: nothing is queued.
    */
    'storage' => env('BACKUP_STORAGE'),

    'providers' => [
        // Any S3-compatible service (AWS, Wasabi, Backblaze B2, MinIO, Cloudflare R2…).
        's3' => [
            'driver' => 's3',
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'region' => env('BACKUP_S3_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'prefix' => env('BACKUP_S3_PREFIX', 'backups'),
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', false),
            // Server-side encryption at rest; empty to leave it to the bucket policy.
            'server_side_encryption' => env('BACKUP_S3_SSE', 'AES256'),
            // Objects up to this size are sent in one PUT with a SHA-256 checksum S3 verifies.
            'multipart_threshold' => (int) env('BACKUP_S3_MULTIPART_THRESHOLD', 100 * $mib),
            'part_size' => (int) env('BACKUP_S3_PART_SIZE', 16 * $mib),
        ],

        'sftp' => [
            'driver' => 'sftp',
            'host' => env('BACKUP_SFTP_HOST'),
            'port' => (int) env('BACKUP_SFTP_PORT', 22),
            'username' => env('BACKUP_SFTP_USERNAME'),
            'password' => env('BACKUP_SFTP_PASSWORD'),
            // Path to a private key file on this server (preferred over a password).
            'private_key' => env('BACKUP_SFTP_PRIVATE_KEY'),
            'passphrase' => env('BACKUP_SFTP_PASSPHRASE'),
            // Required: SHA256 fingerprint of the server's host key ("SHA256:…"), against MITM.
            'host_fingerprint' => env('BACKUP_SFTP_HOST_FINGERPRINT'),
            'root' => env('BACKUP_SFTP_ROOT', '/backups'),
            'timeout' => (int) env('BACKUP_SFTP_TIMEOUT', 30),
        ],

        // Development and tests only: a directory on this server.
        'local' => [
            'driver' => 'local',
            'root' => env('BACKUP_LOCAL_ROOT', storage_path('app/private/backup-storage')),
        ],
    ],

    'upload' => [
        // Chunk size the connector is told to use. Must stay below PHP's post_max_size.
        'chunk_size' => (int) env('BACKUP_CHUNK_SIZE', 4 * $mib),
        'max_chunk_size' => 8 * $mib,
        'max_object_size' => (int) env('BACKUP_MAX_OBJECT_SIZE', 50 * 1024 * $mib),
        // Received data waits here until it is stored and verified.
        'staging_path' => env('BACKUP_STAGING_PATH', storage_path('app/private/backup-staging')),
        // Restore downloads are served in ranges of at most this size.
        'download_chunk_size' => 8 * $mib,
    ],

    'transfer' => [
        // Attempts to store and verify an uploaded backup before it fails.
        'tries' => (int) env('BACKUP_TRANSFER_TRIES', 3),
        'backoff' => [60, 300, 900],
        'timeout' => (int) env('BACKUP_TRANSFER_TIMEOUT', 3600),
        // Where storage has no native SHA-256, read the object back and hash it.
        'readback' => (bool) env('BACKUP_VERIFY_READBACK', true),
    ],

    // A backup that has not finished uploading after this long has failed.
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 6),
    // Staged data of failed backups is removed after this long.
    'staging_retention_hours' => (int) env('BACKUP_STAGING_RETENTION_HOURS', 24),
    // Minimum time between two customer-requested backups of one website.
    'manual_cooldown_minutes' => (int) env('BACKUP_MANUAL_COOLDOWN_MINUTES', 60),

    /*
    | Per plan. Frequency comes from config/pricing.php (backup_frequency),
    | the single source of truth for what is sold.
    |
    | Retention is a commercial decision and has no default: while both
    | values are null, nothing is ever deleted. Set them per plan, e.g.
    | BACKUP_RETENTION_WORDPRESS_BASIC_COUNT=3.
    */
    'plans' => collect(['wordpress', 'woocommerce'])->mapWithKeys(fn (string $product) => [
        $product => collect(['basic', 'business', 'secure'])->mapWithKeys(function (string $tier) use ($product) {
            $env = strtoupper("BACKUP_RETENTION_{$product}_{$tier}");

            return [$tier => [
                'type' => 'full',
                'retention_count' => env("{$env}_COUNT") !== null ? (int) env("{$env}_COUNT") : null,
                'retention_days' => env("{$env}_DAYS") !== null ? (int) env("{$env}_DAYS") : null,
                'manual' => true,
                'pre_update_backup' => (bool) env('BACKUP_PRE_UPDATE_REQUIRED', true),
                'restore' => true,
            ]];
        })->all(),
    ])->all(),

    'restore' => [
        // Live restore runs only when enabled. Requests are refused otherwise.
        'enabled' => (bool) env('BACKUP_RESTORE_ENABLED', false),
    ],

];
