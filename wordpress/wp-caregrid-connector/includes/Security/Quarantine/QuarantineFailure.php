<?php

namespace WPCareGrid\Connector\Security\Quarantine;

/** A quarantine or repair step refused or failed; the code is reported to WP CareGrid. */
final class QuarantineFailure extends \RuntimeException
{
    public function __construct(public readonly string $error_code, string $message)
    {
        parent::__construct($message);
    }
}
