<?php

namespace App\Backups\Storage;

/**
 * Builds storage providers from config('backup.providers'). The backup
 * domain only knows the BackupStorage interface.
 */
class StorageManager
{
    /** @var array<string, BackupStorage> */
    private array $providers = [];

    /** Settings each driver needs before it can be used. */
    private const REQUIRED = [
        's3' => ['bucket', 'key', 'secret'],
        'sftp' => ['host', 'username', 'host_fingerprint', 'root'],
        'local' => ['root'],
    ];

    /** The provider new backups go to, or null when backups are unavailable. */
    public function defaultName(): ?string
    {
        $name = config('backup.storage');

        return is_string($name) && $name !== '' && $this->isConfigured($name) ? $name : null;
    }

    public function isConfigured(string $name): bool
    {
        $config = config("backup.providers.{$name}");
        $driver = $config['driver'] ?? null;

        if (! is_array($config) || ! isset(self::REQUIRED[$driver])) {
            return false;
        }

        foreach (self::REQUIRED[$driver] as $key) {
            if (blank($config[$key] ?? null)) {
                return false;
            }
        }

        return $driver !== 'sftp' || filled($config['password'] ?? null) || filled($config['private_key'] ?? null);
    }

    public function provider(string $name): BackupStorage
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        if (! $this->isConfigured($name)) {
            throw new StorageNotConfigured("Backup storage \"{$name}\" is not configured.");
        }

        $config = config("backup.providers.{$name}");

        return $this->providers[$name] = match ($config['driver']) {
            's3' => new S3Storage($config),
            'sftp' => new SftpStorage($config),
            'local' => new LocalStorage($config['root']),
        };
    }

    /** For tests. */
    public function set(string $name, BackupStorage $storage): void
    {
        $this->providers[$name] = $storage;
    }
}
