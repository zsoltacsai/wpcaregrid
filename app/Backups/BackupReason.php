<?php

namespace App\Backups;

enum BackupReason: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case PreUpdate = 'pre_update';
    case PreRestore = 'pre_restore';
    case Recovery = 'recovery';

    public function label(): string
    {
        return __('backups.reasons.'.$this->value);
    }
}
