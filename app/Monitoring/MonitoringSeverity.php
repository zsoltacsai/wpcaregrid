<?php

namespace App\Monitoring;

enum MonitoringSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
