<?php

namespace App\Pricing;

/** A billing market and one currency-specific price list. */
final readonly class Market
{
    /** @param  list<string>  $countries  ISO 3166-1 alpha-2 codes; '*' matches any country. */
    public function __construct(
        public string $key,
        public array $countries,
        public string $currencyCode,
        public TaxTreatment $taxTreatment,
        public string $group = 'INTERNATIONAL',
    ) {}

    public function covers(string $country): bool
    {
        return in_array('*', $this->countries, true)
            || in_array(strtoupper($country), $this->countries, true);
    }
}
