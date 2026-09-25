<?php

namespace App\Backups;

use Illuminate\Support\Number;

/** Customer-facing wording for codes: never raw internals or stack traces. */
final class BackupText
{
    public static function error(?string $code): string
    {
        return self::lookup('backups.errors.', $code);
    }

    public static function refusal(?string $code): string
    {
        return self::lookup('backups.refusals.', $code);
    }

    public static function size(?int $bytes): string
    {
        return $bytes === null ? '—' : Number::fileSize($bytes, $bytes >= 1024 * 1024 ? 1 : 0);
    }

    private static function lookup(string $prefix, ?string $code): string
    {
        $key = $prefix.$code;

        return $code !== null && __($key) !== $key ? __($key) : __($prefix.'default');
    }
}
