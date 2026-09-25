<?php

namespace App\Connector;

/**
 * License state of a plugin or theme. `valid` and `expired` need evidence
 * from the plugin itself; "could not check" is `unknown`, and a plugin
 * without any license concept is `not_available`. Never inferred.
 */
enum LicenseStatus: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Unknown = 'unknown';
    case NotAvailable = 'not_available';

    public function label(): string
    {
        return __("connector.licenses.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Valid => 'positive',
            self::Expired => 'danger',
            self::Unknown, self::NotAvailable => 'neutral',
        };
    }
}
