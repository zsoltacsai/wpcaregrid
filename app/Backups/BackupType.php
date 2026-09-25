<?php

namespace App\Backups;

use App\Connector\Capability;
use App\Connector\JobType;

/** What a backup contains, and what a restore puts back. */
enum BackupType: string
{
    case Files = 'files';
    case Database = 'database';
    case Full = 'full';

    /** @return list<string> Data objects a backup of this type consists of (the manifest comes on top). */
    public function objects(): array
    {
        return match ($this) {
            self::Files => [BackupObjectName::Files->value],
            self::Database => [BackupObjectName::Database->value],
            self::Full => [BackupObjectName::Files->value, BackupObjectName::Database->value],
        };
    }

    public function jobType(): JobType
    {
        return match ($this) {
            self::Files => JobType::BackupFiles,
            self::Database => JobType::BackupDatabase,
            self::Full => JobType::BackupFull,
        };
    }

    public function backupCapability(): Capability
    {
        return Capability::from('backup_'.$this->value);
    }

    public function restoreCapability(): Capability
    {
        return Capability::from('restore_'.$this->value);
    }

    /** A restore may only use what the backup contains, and exactly what was asked for. */
    public function canRestore(self $scope): bool
    {
        return $this === self::Full || $this === $scope;
    }

    public function label(): string
    {
        return __('backups.types.'.$this->value);
    }
}
