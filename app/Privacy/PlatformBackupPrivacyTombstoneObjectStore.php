<?php

namespace App\Privacy;

use App\Platform\DisasterRecovery\PlatformBackupStorage;
use Illuminate\Contracts\Filesystem\Filesystem;

final class PlatformBackupPrivacyTombstoneObjectStore implements PrivacyTombstoneObjectStore
{
    public function __construct(private readonly PlatformBackupStorage $storage) {}

    public function configured(): bool
    {
        return $this->storage->configured();
    }

    public function driver(): string
    {
        return $this->storage->driver();
    }

    public function disk(): Filesystem
    {
        return $this->storage->disk();
    }
}
