<?php

namespace App\Backups\Storage;

/** Optional deletion read-back capability. Absence proves only that the logical key is not readable. */
interface InspectableBackupStorage extends BackupStorage
{
    public function presence(string $key): ObjectPresence;
}
