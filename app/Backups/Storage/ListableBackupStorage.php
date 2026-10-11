<?php

namespace App\Backups\Storage;

/** Optional read-only listing capability used only for metadata reconciliation. */
interface ListableBackupStorage extends BackupStorage
{
    /** @return iterable<string> Valid logical backup object keys only; never deletes. */
    public function listKeys(): iterable;
}
