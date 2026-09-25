<?php

namespace App\Websites;

/**
 * State of the WP CareGrid Connector on a website. Only the connector itself
 * (a later milestone) may set `Connected`; until then a website is never
 * shown as connected.
 */
enum ConnectorStatus: string
{
    case NotInstalled = 'not_installed';
    case AwaitingConnection = 'awaiting_connection';
    case Connected = 'connected';
    case Error = 'error';
    case Revoked = 'revoked';

    public function isConfirmed(): bool
    {
        return $this === self::Connected;
    }

    public function label(): string
    {
        return __("account.connector_statuses.{$this->value}");
    }
}
