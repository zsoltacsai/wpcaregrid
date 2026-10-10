<?php

namespace WPCareGrid\Connector\Backups;

/** Production restore lookup backed by this Connector's canonical index. */
final class WordPressBackupIndexLookup implements BackupIndexLookup
{
    public function find(string $uuid): ?array
    {
        return BackupIndex::find($uuid);
    }
}
