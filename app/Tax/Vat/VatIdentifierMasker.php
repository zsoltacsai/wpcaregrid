<?php

namespace App\Tax\Vat;

final class VatIdentifierMasker
{
    public static function mask(?string $countryCode, ?string $identifier): string
    {
        $country = strtoupper(trim((string) $countryCode));
        $value = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $identifier) ?? '');
        if ($value === '') {
            return '—';
        }

        if ($country === '' && preg_match('/^[A-Z]{2}/', $value)) {
            $country = substr($value, 0, 2);
        }
        $prefix = in_array($country, ['GR', 'EL'], true) ? 'EL' : $country;
        if ($prefix !== '' && str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }

        $masked = strlen($value) <= 4
            ? str_repeat('•', max(4, strlen($value)))
            : str_repeat('•', min(10, strlen($value) - 4)).substr($value, -4);

        return ($prefix !== '' ? $prefix : '').$masked;
    }
}
