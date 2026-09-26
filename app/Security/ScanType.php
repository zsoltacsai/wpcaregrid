<?php

namespace App\Security;

/**
 * What a scan looks at. Vulnerability matching runs on the server (inventory
 * against a configured source); integrity and malware run in the connector.
 * FULL is every scanner that is actually available.
 */
enum ScanType: string
{
    case Vulnerability = 'vulnerability';
    case Integrity = 'integrity';
    case Malware = 'malware';
    case Full = 'full';

    /** @return list<string> Scanners this type includes: vulnerability, integrity, malware. */
    public function scanners(): array
    {
        return match ($this) {
            self::Full => ['vulnerability', 'integrity', 'malware'],
            default => [$this->value],
        };
    }

    /** Whether part of the scan runs in the connector. */
    public function needsConnector(): bool
    {
        return $this !== self::Vulnerability;
    }

    /** The connector's part of the scan (it never does vulnerability matching). */
    public function connectorType(): ?string
    {
        return match ($this) {
            self::Vulnerability => null,
            default => $this->value,
        };
    }

    public function label(): string
    {
        return __('security.scan_types.'.$this->value);
    }
}
