<?php

namespace App\Backups\Storage;

/** A provider that can scan a single object in bounded-memory chunks. */
interface StreamableBackupStorage extends BackupStorage
{
    /**
     * Stream the complete object to a consumer without materializing it.
     *
     * @param  callable(string): void  $consume
     * @return int Number of bytes delivered to the consumer
     */
    public function streamObject(string $key, callable $consume): int;
}
