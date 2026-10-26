<?php

namespace App\Backups\CustomerDownloads;

use RuntimeException;

/** A fixed, non-sensitive refusal code suitable for an API response or audit. */
final class BackupDownloadRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
