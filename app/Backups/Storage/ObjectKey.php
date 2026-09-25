<?php

namespace App\Backups\Storage;

use App\Backups\BackupObjectName;

/**
 * The only object keys that exist: {website_uuid}/{backup_uuid}/{file}.
 * Anything else (traversal, absolute paths, other names) is refused before
 * it reaches a storage provider.
 */
final class ObjectKey
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function assert(string $key): string
    {
        $files = implode('|', array_map(fn (BackupObjectName $name) => preg_quote($name->filename(), '#'), BackupObjectName::cases()));

        if (! preg_match('#^'.self::UUID.'/'.self::UUID.'/('.$files.')$#D', $key)) {
            throw new \InvalidArgumentException('Invalid backup object key.');
        }

        return $key;
    }
}
