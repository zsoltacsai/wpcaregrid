<?php

namespace App\Connector;

/**
 * What a connector can do. A connector advertises only what it has
 * implemented; the server only sends jobs the connector advertised.
 * backup / restore / security are reserved for later milestones.
 */
enum Capability: string
{
    case Inventory = 'inventory';
    case Health = 'health';
    case Updates = 'updates';
    case LicenseCheck = 'license_check';

    /** @param  array<mixed>  $values  Unknown values are dropped. */
    public static function listFrom(array $values): array
    {
        return array_values(array_unique(array_map(
            fn (self $c) => $c->value,
            array_filter(array_map(fn ($v) => is_string($v) ? self::tryFrom($v) : null, $values)),
        )));
    }
}
