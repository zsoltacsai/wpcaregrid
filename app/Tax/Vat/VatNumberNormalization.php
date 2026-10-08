<?php

namespace App\Tax\Vat;

use InvalidArgumentException;

final class VatNumberNormalization
{
    /**
     * Normalize the country prefix separately from the identifier. This is a
     * transport/input canonicalization only; it does not establish VAT status.
     */
    public function normalize(string $countryCode, string $input): NormalizedVatNumber
    {
        $country = strtoupper(trim($countryCode));
        $viesCountry = config('tax.vies.prefix_aliases.'.$country, $country);
        $value = strtoupper(trim($input));

        if (! preg_match('/^[A-Z]{2}$/', $country) || ! in_array($viesCountry, config('tax.vies.supported_country_codes', []), true)) {
            throw new InvalidArgumentException('Unsupported VAT country prefix.');
        }

        $value = preg_replace('/[\s.\-\/]/u', '', $value) ?? '';
        if (str_starts_with($value, $viesCountry)) {
            $value = substr($value, 2);
        } elseif ($viesCountry !== $country && str_starts_with($value, $country)) {
            $value = substr($value, 2);
        }

        // A second country prefix is almost always a country/number mismatch.
        if ($value === '' || preg_match('/^[A-Z]{2}/', $value) || ! preg_match('/^[A-Z0-9]{2,20}$/', $value)) {
            throw new InvalidArgumentException('Malformed VAT identifier.');
        }

        $key = (string) (config('tax.identifiers.fingerprint_key') ?: config('app.key'));
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return new NormalizedVatNumber(
            countryCode: $viesCountry,
            number: $value,
            fingerprint: hash_hmac('sha256', $viesCountry.'|'.$value, $key),
        );
    }
}
