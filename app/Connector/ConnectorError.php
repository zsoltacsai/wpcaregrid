<?php

namespace App\Connector;

use RuntimeException;

/**
 * A refusal the API reports to the connector as {"error": {"code", "message"}}.
 * Codes are stable identifiers the plugin can act on; messages never
 * contain secrets or internal details.
 */
final class ConnectorError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function invalidPairingCode(): self
    {
        // Unknown, used and expired codes look the same from outside.
        return new self('INVALID_PAIRING_CODE', 'The pairing code is invalid or has expired.');
    }

    public static function siteUrlMismatch(): self
    {
        return new self('SITE_URL_MISMATCH', 'This pairing code belongs to a different website address.');
    }

    public static function serviceInactive(): self
    {
        return new self('SERVICE_INACTIVE', 'The subscription for this website is not active.', 403);
    }

    public static function jobNotFound(): self
    {
        return new self('JOB_NOT_FOUND', 'No such job for this website.', 404);
    }

    public static function jobNotDelivered(): self
    {
        return new self('JOB_NOT_DELIVERED', 'This job was not delivered to this connector.', 409);
    }

    public static function jobNotAllowed(string $reason): self
    {
        return new self('JOB_NOT_ALLOWED', "Job not allowed: {$reason}.", 403);
    }
}
