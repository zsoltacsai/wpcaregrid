<?php

namespace App\Connector;

/** Overall health reported by the connector, with its findings kept alongside. */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __("connector.health.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Healthy => 'positive',
            self::Attention => 'warning',
            self::Critical => 'danger',
            self::Unknown => 'neutral',
        };
    }
}
