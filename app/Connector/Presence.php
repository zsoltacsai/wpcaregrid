<?php

namespace App\Connector;

/** Whether a website's connector is in contact, derived from timestamps. */
enum Presence: string
{
    case NeverConnected = 'never_connected';
    case Connected = 'connected';
    case Stale = 'stale';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return __("connector.presence.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'positive',
            self::Stale => 'warning',
            self::Disconnected => 'danger',
            self::NeverConnected => 'neutral',
        };
    }
}
