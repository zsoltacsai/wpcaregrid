<?php

namespace App\Backups;

/** A backup (or restore) was not started. The code is shown to customers; the message explains it. */
class BackupRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message ?: $errorCode);
    }
}
