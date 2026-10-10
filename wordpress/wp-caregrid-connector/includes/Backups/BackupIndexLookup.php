<?php

namespace WPCareGrid\Connector\Backups;

/** Read-only lookup for restore trust metadata. */
interface BackupIndexLookup
{
    public function find(string $uuid): ?array;
}
