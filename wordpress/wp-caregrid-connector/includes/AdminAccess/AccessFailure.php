<?php

namespace WPCareGrid\Connector\AdminAccess;

/** Admin access could not be granted. The code is reported; the message is safe to show. */
final class AccessFailure extends \RuntimeException
{
    public function __construct(public readonly string $error_code, string $message)
    {
        parent::__construct($message);
    }
}
