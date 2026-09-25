<?php

namespace WPCareGrid\Connector\Backups;

/**
 * A backup or restore step failed. The code is reported to WP CareGrid;
 * the message never contains paths, credentials or SQL.
 */
final class BackupFailure extends \RuntimeException
{
    public function __construct(public readonly string $error_code, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
