<?php

namespace App\Security\Vulnerabilities;

use RuntimeException;

/** A safe, stable failure code for Wordfence feed synchronization logs. */
final class WordfenceSyncFailure extends RuntimeException
{
    public function __construct(public readonly string $classification, string $safeMessage, ?\Throwable $previous = null)
    {
        parent::__construct($safeMessage, 0, $previous);
    }
}
