<?php

namespace App\Pricing\StripeCatalogue;

use RuntimeException;

/**
 * A failed Stripe call, reduced to a fixed code (authentication, permission, rate_limited,
 * invalid_request, idempotency_conflict, api_connection, api_error, mode_mismatch,
 * catalogue_too_large). Stripe's message text is never kept: it can quote a key.
 */
final class CatalogueGatewayException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** Failures after which the remaining requests of a run would fail the same way. */
    public function stopsTheRun(): bool
    {
        return in_array($this->reason, ['authentication', 'permission', 'rate_limited', 'api_connection', 'mode_mismatch'], true);
    }
}
