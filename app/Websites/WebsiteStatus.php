<?php

namespace App\Websites;

enum WebsiteStatus: string
{
    case Pending = 'pending';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Attention = 'attention';
    case Critical = 'critical';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return __("account.website_statuses.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'positive',
            self::Attention => 'warning',
            self::Critical => 'danger',
            self::Pending, self::Connecting, self::Disconnected => 'neutral',
        };
    }
}
