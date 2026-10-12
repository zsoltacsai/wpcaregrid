<?php

namespace App\Monitoring;

enum MonitoringScope: string
{
    case Platform = 'platform';
    case Website = 'website';
    case Connector = 'connector';
    case Provider = 'provider';
}
