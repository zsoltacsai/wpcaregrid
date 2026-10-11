<?php

namespace App\Platform\DisasterRecovery;

use App\Owner\IntegrationCredentials;
use App\Owner\OwnerSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

/** Builds the platform-only destination from current Owner settings and credentials. */
final class PlatformBackupStorage
{
    public function __construct(
        private readonly IntegrationCredentials $credentials,
        private readonly OwnerSettings $settings,
        private readonly FilesystemManager $filesystem,
        private readonly PlatformBackupS3BucketProber $probe,
    ) {}

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $settingsOverride Owner-validated draft values */
    public function configuration(array $settingsOverride = []): array
    {
        $diskName = (string) config('platform_backup.disk', 'platform_backups');
        if ($diskName !== 'platform_backups') {
            throw new RuntimeException('Platform backups must use the dedicated platform_backups disk.');
        }

        $base = (array) config('filesystems.disks.platform_backups', []);
        $owner = array_replace($this->settings->group('backups'), $settingsOverride);
        $driver = (string) $owner['backups.primary.storage_driver'];
        if ($driver !== 's3') {
            return array_merge($base, ['driver' => $driver]);
        }

        $key = $this->credentials->resolve('platform_backup_s3_access_key');
        $secret = $this->credentials->resolve('platform_backup_s3_secret_key');
        $bucket = $owner['backups.primary.bucket'];
        $endpoint = $owner['backups.primary.endpoint'];
        $region = $owner['backups.primary.region'];

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('Dedicated platform backup S3 credentials are not configured.');
        }
        if (! is_string($bucket) || $bucket === '' || ! is_string($endpoint) || $endpoint === '' || ! is_string($region) || $region === '') {
            throw new RuntimeException('Platform backup S3 destination settings are incomplete.');
        }

        return array_merge($base, [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'region' => $region,
            'use_path_style_endpoint' => (bool) $owner['backups.primary.path_style'],
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ]);
    }

    /** @param array<string, mixed> $settingsOverride Owner-validated draft values */
    public function configured(array $settingsOverride = []): bool
    {
        try {
            $configuration = $this->configuration($settingsOverride);
        } catch (RuntimeException) {
            return false;
        }

        return ($configuration['driver'] ?? null) === 's3'
            && filled($configuration['key'] ?? null)
            && filled($configuration['secret'] ?? null)
            && filled($configuration['bucket'] ?? null)
            && filled($configuration['endpoint'] ?? null)
            && filled($configuration['region'] ?? null);
    }

    /** @param array<string, mixed> $settingsOverride Owner-validated draft values */
    public function driver(array $settingsOverride = []): string
    {
        return (string) ($settingsOverride['backups.primary.storage_driver']
            ?? $this->settings->get('backups.primary.storage_driver'));
    }

    /** Resolve a fresh adapter per operation so long-running workers see credential rotations. */
    public function disk(string $diskName = 'platform_backups'): Filesystem
    {
        if ($diskName !== 'platform_backups') {
            throw new RuntimeException('Platform backup metadata references an unsupported storage disk.');
        }
        $configuration = $this->configuration();
        if (($configuration['driver'] ?? null) === 's3') {
            return $this->filesystem->build($configuration);
        }

        // Local storage remains available only to local/test workflows; the
        // backup service independently refuses it on staging and production.
        return $this->filesystem->disk('platform_backups');
    }

    /** Non-destructive provider/authentication check. Does not create or delete objects. */
    public function testConnection(): void
    {
        $configuration = $this->configuration();
        if (($configuration['driver'] ?? null) !== 's3') {
            throw new RuntimeException('Platform backup storage must use S3 before testing the provider connection.');
        }

        $this->probe->headBucket($configuration);
    }
}
