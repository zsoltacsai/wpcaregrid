<?php

namespace App\Backups;

/** The only objects a backup may consist of, with their fixed file names. */
enum BackupObjectName: string
{
    case Files = 'files';
    case Database = 'database';
    case Manifest = 'manifest';

    public function filename(): string
    {
        return match ($this) {
            self::Files => 'files.tar.gz',
            self::Database => 'database.sql.gz',
            self::Manifest => 'manifest.json',
        };
    }
}
