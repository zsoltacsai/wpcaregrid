<?php

namespace App\Cloudflare;

final class DnsNames
{
    public static function normalizeZone(string $zone): string
    {
        $zone = strtolower(self::removeOptionalTrailingDot(trim($zone)));
        if (! self::validHost($zone, allowUnderscore: false) || ! str_contains($zone, '.')) {
            throw new CloudflareDnsException('invalid_record');
        }

        return $zone;
    }

    public static function normalizeRecordName(string $name, string $zone): string
    {
        $name = strtolower(self::removeOptionalTrailingDot(trim($name)));
        if ($name === '@') {
            return $zone;
        }

        if (! self::validHost($name, allowUnderscore: true)
            || ($name !== $zone && ! str_ends_with($name, '.'.$zone))) {
            throw new CloudflareDnsException('invalid_record');
        }

        return $name;
    }

    public static function normalizeTarget(string $target): string
    {
        $target = strtolower(self::removeOptionalTrailingDot(trim($target)));
        if (! self::validHost($target, allowUnderscore: false) || ! str_contains($target, '.')) {
            throw new CloudflareDnsException('invalid_record');
        }

        return $target;
    }

    private static function validHost(string $name, bool $allowUnderscore): bool
    {
        if ($name === '' || strlen($name) > 253 || str_contains($name, '*')) {
            return false;
        }

        $labelPattern = $allowUnderscore
            ? '/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\z/i'
            : '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i';

        foreach (explode('.', $name) as $label) {
            if (! preg_match($labelPattern, $label)) {
                return false;
            }
        }

        return true;
    }

    private static function removeOptionalTrailingDot(string $name): string
    {
        return str_ends_with($name, '.') ? substr($name, 0, -1) : $name;
    }
}
