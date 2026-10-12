<?php

namespace App\SiteHealth;

enum Dimension: string
{
    case Connector = 'connector';
    case WordPress = 'wordpress';
    case Backups = 'backups';
    case Updates = 'updates';
    case Security = 'security';
    case Availability = 'availability';
    case Tls = 'tls';

    public function label(): string
    {
        return __('site_health.dimensions.'.$this->value);
    }
}
